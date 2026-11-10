<?php
/** Circuit state, SMTP2GO integration, and controlled recovery. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AS_SMTP2GO_Monitor {
	private static $last_mail = null;
	private static $replaying = false;
	private static $replay_error = '';

	public static function register() {
		add_filter( 'wp_mail', array( __CLASS__, 'remember_mail' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'intercept' ), PHP_INT_MAX, 2 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'mail_failed' ), 10, 1 );
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'mail_succeeded' ) );
		add_action( 'as_smtp2go_monitor_batch', array( __CLASS__, 'process_batch' ) );
	}

	public static function state() {
		$state = get_option( 'as_smtp2go_monitor_state', 'armed' );
		return in_array( $state, array( 'armed', 'tripped', 'recovering' ), true ) ? $state : 'tripped';
	}

	/** Compare-and-swap the option in SQL so competing requests cannot both transition it. */
	private static function transition( $from, $to ) {
		global $wpdb;
		$changed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $to ),
			array( 'option_name' => 'as_smtp2go_monitor_state', 'option_value' => $from ),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( 1 === $changed ) {
			wp_cache_delete( 'as_smtp2go_monitor_state', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			update_option( 'as_smtp2go_monitor_changed_at', current_time( 'mysql', true ), false );
			return true;
		}
		return false;
	}

	private static function smtp2go_active() {
		return defined( 'SMTP2GO_WORDPRESS_PLUGIN_VERSION' )
			&& class_exists( '\\SMTP2GO\\App\\SMTP2GOMailer' )
			&& class_exists( '\\SMTP2GO\\App\\SettingsHelper' )
			&& (bool) \SMTP2GO\App\SettingsHelper::getOption( 'smtp2go_enabled' );
	}

	private static function test_request() {
		return defined( 'SMTP2GO_TEST_MAIL' ) && SMTP2GO_TEST_MAIL
			&& wp_doing_ajax() && current_user_can( 'manage_options' )
			&& isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] )
			&& 'smtp2go_send_email' === sanitize_key( wp_unslash( $_REQUEST['action'] ) );
	}

	public static function remember_mail( $mail ) {
		if ( ! self::$replaying && ! self::test_request() ) {
			self::$last_mail = $mail;
		}
		return $mail;
	}

	public static function intercept( $pre, $mail ) {
		if ( null !== $pre || self::$replaying || self::test_request() ) {
			return $pre;
		}
		if ( 'armed' === self::state() ) {
			return $pre;
		}
		$id = AS_SMTP2GO_Queue::enqueue( $mail );
		if ( is_wp_error( $id ) ) {
			update_option( 'as_smtp2go_monitor_queue_error', $id->get_error_message(), false );
			return false;
		}
		delete_option( 'as_smtp2go_monitor_queue_error' );
		// A recovery worker may have armed the circuit while this row was being packed.
		if ( 'armed' === self::state() && self::transition( 'armed', 'recovering' ) ) {
			self::schedule_batch();
		}
		return true; // Accepted for deferred delivery; no SMTP2GO API call occurred.
	}

	public static function mail_failed( $error ) {
		if ( ! $error instanceof WP_Error || self::test_request() || ! self::smtp2go_active() ) {
			return;
		}
		global $phpmailer;
		if ( ! $phpmailer instanceof \SMTP2GO\App\SMTP2GOMailer ) {
			return;
		}
		$reason = self::safe_reason( $error->get_error_message() );
		if ( self::$replaying ) {
			self::$replay_error = $reason;
			self::trip( $reason );
			return;
		}
		$state = self::state();
		if ( 'armed' !== $state ) {
			return;
		}
		$mail = self::$last_mail;
		if ( ! is_array( $mail ) ) {
			$mail = $error->get_error_data();
		}
		if ( is_array( $mail ) ) {
			$id = AS_SMTP2GO_Queue::enqueue( $mail );
			if ( is_wp_error( $id ) ) {
				update_option( 'as_smtp2go_monitor_queue_error', $id->get_error_message(), false );
			}
		}
		self::trip( $reason );
		self::$last_mail = null;
	}

	public static function mail_succeeded() {
		global $phpmailer;
		if ( $phpmailer instanceof \SMTP2GO\App\SMTP2GOMailer && $phpmailer->isError() ) {
			self::mail_failed( new WP_Error( 'as_smtp2go_send_failed', $phpmailer->ErrorInfo ) );
			return;
		}
		if ( ! self::$replaying ) {
			self::$last_mail = null;
		}
	}

	private static function trip( $reason ) {
		$from = self::state();
		if ( 'armed' !== $from && 'recovering' !== $from ) {
			return;
		}
		if ( ! self::transition( $from, 'tripped' ) ) {
			return;
		}
		update_option( 'as_smtp2go_monitor_last_error', $reason, false );
		update_option( 'as_smtp2go_monitor_last_failure_at', current_time( 'mysql', true ), false );
		self::notify_trip( $reason );
	}

	private static function safe_reason( $reason ) {
		$reason = sanitize_text_field( $reason );
		$api_key = class_exists( '\\SMTP2GO\\App\\SettingsHelper' ) ? \SMTP2GO\App\SettingsHelper::getOption( 'smtp2go_api_key' ) : get_option( 'smtp2go_api_key', '' );
		if ( is_string( $api_key ) && '' !== $api_key ) {
			$reason = str_replace( $api_key, '[redacted]', $reason );
		}
		return substr( $reason, 0, 500 );
	}

	private static function notify_trip( $reason ) {
		$url = get_option( 'as_smtp2go_monitor_webhook', '' );
		if ( ! $url ) {
			update_option( 'as_smtp2go_monitor_notice_error', __( 'Webhook URL is not configured.', 'as-smtp2go-monitor' ), false );
			return;
		}
		$reason = self::safe_reason( $reason );
		$body = array(
			'type' => 'message',
			'attachments' => array(
				array(
					'contentType' => 'application/vnd.microsoft.card.adaptive',
					'contentUrl' => null,
					'content' => array(
						'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
						'type' => 'AdaptiveCard',
						'version' => '1.2',
						'body' => array(
							array( 'type' => 'TextBlock', 'text' => 'AS SMTP2GO Monitor: email sending paused', 'weight' => 'Bolder', 'wrap' => true ),
							array( 'type' => 'TextBlock', 'text' => get_bloginfo( 'name' ) . ' (' . home_url() . ')', 'wrap' => true ),
							array( 'type' => 'TextBlock', 'text' => 'Reason: ' . $reason, 'wrap' => true ),
							array( 'type' => 'TextBlock', 'text' => 'Queued: ' . AS_SMTP2GO_Queue::count() . ' | ' . current_time( 'mysql', true ) . ' UTC', 'wrap' => true ),
							array( 'type' => 'TextBlock', 'text' => admin_url( 'tools.php?page=as-smtp2go-monitor' ), 'wrap' => true ),
						),
					),
				),
			),
		);
		$response = wp_safe_remote_post( $url, array( 'timeout' => 10, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			update_option( 'as_smtp2go_monitor_notice_error', __( 'The alert webhook did not accept the notification.', 'as-smtp2go-monitor' ), false );
		} else {
			delete_option( 'as_smtp2go_monitor_notice_error' );
		}
	}

	public static function start_recovery() {
		if ( ! self::smtp2go_active() || ! self::transition( 'tripped', 'recovering' ) ) {
			return false;
		}
		self::schedule_batch();
		return true;
	}

	private static function schedule_batch() {
		if ( ! wp_next_scheduled( 'as_smtp2go_monitor_batch' ) ) {
			wp_schedule_single_event( time() + 60, 'as_smtp2go_monitor_batch' );
		}
	}

	public static function process_batch() {
		if ( 'recovering' !== self::state() || ! self::smtp2go_active() ) {
			return;
		}
		global $wpdb;
		$lock = self::lock_name();
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return;
		}
		try {
			self::run_batch();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function batch_idle() {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', self::lock_name() ) );
	}

	private static function lock_name() {
		global $wpdb;
		return 'as_smtp2go_' . md5( $wpdb->prefix );
	}

	private static function run_batch() {
		self::schedule_batch(); // Another event survives a PHP worker stopping mid-send.
		for ( $i = 0; $i < 10; $i++ ) {
			if ( 'recovering' !== self::state() ) {
				return;
			}
			$item = AS_SMTP2GO_Queue::next();
			if ( ! $item ) {
				if ( AS_SMTP2GO_Queue::has_sending() ) {
					self::trip( __( 'A queued send was interrupted. Check SMTP2GO before retrying it.', 'as-smtp2go-monitor' ) );
					return;
				}
				if ( 0 === AS_SMTP2GO_Queue::count() && self::transition( 'recovering', 'armed' ) && AS_SMTP2GO_Queue::count() > 0 ) {
					self::transition( 'armed', 'recovering' );
					self::schedule_batch();
				} elseif ( 'armed' === self::state() ) {
					wp_clear_scheduled_hook( 'as_smtp2go_monitor_batch' );
				}
				return;
			}
			if ( ! AS_SMTP2GO_Queue::claim( (int) $item->id ) ) {
				continue;
			}
			$restored = AS_SMTP2GO_Queue::unpack( $item->payload );
			if ( is_wp_error( $restored ) ) {
				AS_SMTP2GO_Queue::finish( (int) $item->id, false, $restored->get_error_message() );
				self::trip( $restored->get_error_message() );
				return;
			}
			list( $mail, $temporary ) = $restored;
			self::$replaying = true;
			self::$replay_error = '';
			$success = wp_mail( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'], $mail['attachments'], $mail['embeds'] );
			self::$replaying = false;
			AS_SMTP2GO_Queue::cleanup( $temporary );
			AS_SMTP2GO_Queue::finish( (int) $item->id, $success, self::$replay_error );
			if ( ! $success ) {
				if ( 'recovering' === self::state() ) {
					self::trip( self::$replay_error ? self::$replay_error : __( 'A queued email failed during recovery.', 'as-smtp2go-monitor' ) );
				}
				return;
			}
		}
		self::schedule_batch();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'as_smtp2go_monitor_batch' );
	}
}
