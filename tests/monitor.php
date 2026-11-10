<?php
/** Exercise trip, interception, and queue drain with WordPress API doubles. */

namespace SMTP2GO\App {
	class SMTP2GOMailer { public function isError() { return false; } }
	class SettingsHelper { public static function getOption( $name ) { return 'smtp2go_enabled' === $name; } }
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'SMTP2GO_WORDPRESS_PLUGIN_VERSION', '1.17.0' );
	class WP_Error {
		private $message;
		public function __construct( $code, $message ) { $this->message = $message; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return array(); }
	}
	class Monitor_Test_DB {
		public $prefix = 'wp_';
		public $options = 'wp_options';
		public $insert_id = 0;
		public $rows = array();
		public function insert( $table, $data, $formats ) {
			$this->insert_id++;
			$data['id'] = $this->insert_id;
			$data['attempts'] = 0;
			$this->rows[ $this->insert_id ] = $data;
			return 1;
		}
		public function update( $table, $data, $where, $format, $where_format ) {
			global $test_options;
			if ( $table === $this->options ) {
				$name = $where['option_name'];
				if ( $test_options[ $name ] !== $where['option_value'] ) { return 0; }
				$test_options[ $name ] = $data['option_value'];
				return 1;
			}
			$id = $where['id'];
			if ( ! isset( $this->rows[ $id ] ) || $this->rows[ $id ]['status'] !== $where['status'] ) { return 0; }
			$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
			return 1;
		}
		public function delete( $table, $where, $format ) {
			$id = $where['id'];
			if ( ! isset( $this->rows[ $id ] ) || $this->rows[ $id ]['status'] !== $where['status'] ) { return 0; }
			unset( $this->rows[ $id ] );
			return 1;
		}
		public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
		public function get_var( $sql ) {
			if ( strpos( $sql, 'GET_LOCK' ) !== false || strpos( $sql, 'RELEASE_LOCK' ) !== false || strpos( $sql, 'IS_FREE_LOCK' ) !== false ) { return 1; }
			if ( strpos( $sql, 'COUNT(*)' ) !== false ) { return count( $this->rows ); }
			if ( strpos( $sql, "status = 'sending'" ) !== false ) {
				foreach ( $this->rows as $row ) { if ( 'sending' === $row['status'] ) { return $row['id']; } }
				return null;
			}
			return null;
		}
		public function get_row( $sql ) {
			foreach ( $this->rows as $row ) { if ( 'queued' === $row['status'] ) { return (object) $row; } }
			return null;
		}
	}
	$wpdb = new Monitor_Test_DB();
	$phpmailer = new \SMTP2GO\App\SMTP2GOMailer();
	$test_options = array( 'as_smtp2go_monitor_state' => 'armed', 'as_smtp2go_monitor_schema_version' => '1' );
	$test_events = array();
	$test_fail_replay = false;
	function add_filter() {}
	function add_action() {}
	function get_option( $name, $default = false ) { global $test_options; return isset( $test_options[ $name ] ) ? $test_options[ $name ] : $default; }
	function update_option( $name, $value, $autoload = null ) { global $test_options; $test_options[ $name ] = $value; }
	function delete_option( $name ) { global $test_options; unset( $test_options[ $name ] ); }
	function wp_cache_delete() {}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function __( $value, $domain ) { return $value; }
	function current_time( $type, $gmt ) { return '2026-11-10 00:00:00'; }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_doing_ajax() { return false; }
	function current_user_can( $capability ) { return true; }
	function wp_next_scheduled( $hook ) { global $test_events; return isset( $test_events[ $hook ] ); }
	function wp_schedule_single_event( $time, $hook ) { global $test_events; $test_events[ $hook ] = $time; }
	function wp_clear_scheduled_hook( $hook ) { global $test_events; unset( $test_events[ $hook ] ); }
	function wp_mail( $to, $subject, $body, $headers, $attachments, $embeds ) {
		global $test_fail_replay;
		if ( $test_fail_replay ) {
			AS_SMTP2GO_Monitor::mail_failed( new WP_Error( 'failure', 'SMTP2GO failed again' ) );
			return false;
		}
		return true;
	}

	require_once __DIR__ . '/../as-smtp2go-monitor/includes/queue.php';
	require_once __DIR__ . '/../as-smtp2go-monitor/includes/monitor.php';

	$mail = array( 'to' => 'one@example.com', 'subject' => 'One', 'message' => 'Body', 'headers' => array(), 'attachments' => array(), 'embeds' => array() );
	AS_SMTP2GO_Monitor::remember_mail( $mail );
	AS_SMTP2GO_Monitor::mail_failed( new WP_Error( 'failure', 'SMTP2GO API failed' ) );
	if ( 'tripped' !== AS_SMTP2GO_Monitor::state() || 1 !== count( $wpdb->rows ) ) { throw new \RuntimeException( 'Failure did not trip and queue' ); }
	$mail['subject'] = 'Two';
	if ( true !== AS_SMTP2GO_Monitor::intercept( null, $mail ) || 2 !== count( $wpdb->rows ) ) { throw new \RuntimeException( 'Tripped mail was not queued' ); }
	if ( ! AS_SMTP2GO_Monitor::start_recovery() ) { throw new \RuntimeException( 'Recovery did not start' ); }
	AS_SMTP2GO_Monitor::process_batch();
	if ( 'armed' !== AS_SMTP2GO_Monitor::state() || count( $wpdb->rows ) ) { throw new \RuntimeException( 'Queue did not drain and re-arm' ); }
	$test_options['as_smtp2go_monitor_state'] = 'tripped';
	AS_SMTP2GO_Queue::enqueue( $mail );
	AS_SMTP2GO_Monitor::start_recovery();
	$test_fail_replay = true;
	AS_SMTP2GO_Monitor::process_batch();
	if ( 'tripped' !== AS_SMTP2GO_Monitor::state() || 1 !== count( $wpdb->rows ) || 'queued' !== reset( $wpdb->rows )['status'] ) {
		throw new \RuntimeException( 'Recovery failure did not stop and preserve the email' );
	}
	echo "Circuit workflow OK\n";
}
