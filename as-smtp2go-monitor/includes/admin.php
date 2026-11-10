<?php
/** Small WordPress admin surface for the circuit and queue. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AS_SMTP2GO_Admin {
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_as_smtp2go_save_webhook', array( __CLASS__, 'save_webhook' ) );
		add_action( 'admin_post_as_smtp2go_start_recovery', array( __CLASS__, 'start_recovery' ) );
		add_action( 'admin_post_as_smtp2go_delete_queued', array( __CLASS__, 'delete_queued' ) );
		add_action( 'admin_post_as_smtp2go_requeue_interrupted', array( __CLASS__, 'requeue_interrupted' ) );
	}

	public static function menu() {
		add_management_page(
			__( 'AS SMTP2GO Monitor', 'as-smtp2go-monitor' ),
			__( 'AS SMTP2GO Monitor', 'as-smtp2go-monitor' ),
			'manage_options',
			'as-smtp2go-monitor',
			array( __CLASS__, 'page' )
		);
	}

	private static function authorise( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this mail queue.', 'as-smtp2go-monitor' ) );
		}
		check_admin_referer( $nonce );
	}

	private static function redirect( $message ) {
		wp_safe_redirect( add_query_arg( 'as_smtp2go_notice', $message, admin_url( 'tools.php?page=as-smtp2go-monitor' ) ) );
		exit;
	}

	public static function save_webhook() {
		self::authorise( 'as_smtp2go_save_webhook' );
		$url = isset( $_POST['webhook_url'] ) && is_string( $_POST['webhook_url'] ) ? trim( wp_unslash( $_POST['webhook_url'] ) ) : '';
		if ( '' !== $url && ( ! wp_http_validate_url( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			self::redirect( 'invalid_webhook' );
		}
		update_option( 'as_smtp2go_monitor_webhook', esc_url_raw( $url ), false );
		delete_option( 'as_smtp2go_monitor_notice_error' );
		self::redirect( 'saved' );
	}

	public static function start_recovery() {
		self::authorise( 'as_smtp2go_start_recovery' );
		if ( ! isset( $_POST['acknowledge'] ) || '1' !== $_POST['acknowledge'] ) {
			self::redirect( 'acknowledge' );
		}
		self::redirect( AS_SMTP2GO_Monitor::start_recovery() ? 'recovering' : 'recovery_failed' );
	}

	public static function delete_queued() {
		self::authorise( 'as_smtp2go_delete_queued' );
		global $wpdb;
		$id = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;
		$deleted = $id ? $wpdb->delete( AS_SMTP2GO_Queue::table(), array( 'id' => $id, 'status' => 'queued' ), array( '%d', '%s' ) ) : false;
		self::redirect( $deleted ? 'deleted' : 'delete_failed' );
	}

	public static function requeue_interrupted() {
		self::authorise( 'as_smtp2go_requeue_interrupted' );
		if ( 'tripped' !== AS_SMTP2GO_Monitor::state() || ! AS_SMTP2GO_Monitor::batch_idle() || ! isset( $_POST['acknowledge'] ) || '1' !== $_POST['acknowledge'] ) {
			self::redirect( 'requeue_failed' );
		}
		global $wpdb;
		$id = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;
		$updated = $id ? $wpdb->update( AS_SMTP2GO_Queue::table(), array( 'status' => 'queued' ), array( 'id' => $id, 'status' => 'sending' ), array( '%s' ), array( '%d', '%s' ) ) : false;
		self::redirect( $updated ? 'requeued' : 'requeue_failed' );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$state = AS_SMTP2GO_Monitor::state();
		$count = AS_SMTP2GO_Queue::count();
		$table = AS_SMTP2GO_Queue::table();
		$rows = $wpdb->get_results( "SELECT id,status,payload,attempts,last_error,created_at FROM $table ORDER BY id ASC LIMIT 50" );
		$notices = array(
			'saved' => __( 'Webhook URL saved.', 'as-smtp2go-monitor' ),
			'invalid_webhook' => __( 'Enter a valid HTTPS webhook URL.', 'as-smtp2go-monitor' ),
			'acknowledge' => __( 'Confirm the recovery acknowledgement before continuing.', 'as-smtp2go-monitor' ),
			'recovering' => __( 'Recovery started. WordPress Cron will process the queue in batches.', 'as-smtp2go-monitor' ),
			'recovery_failed' => __( 'Recovery could not start. Check SMTP2GO and the current state.', 'as-smtp2go-monitor' ),
			'deleted' => __( 'Queued email deleted.', 'as-smtp2go-monitor' ),
			'delete_failed' => __( 'The queued email could not be deleted.', 'as-smtp2go-monitor' ),
			'requeued' => __( 'Interrupted email returned to the queue.', 'as-smtp2go-monitor' ),
			'requeue_failed' => __( 'The interrupted email could not be returned to the queue.', 'as-smtp2go-monitor' ),
		);
		$notice = isset( $_GET['as_smtp2go_notice'] ) ? sanitize_key( wp_unslash( $_GET['as_smtp2go_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AS SMTP2GO Monitor', 'as-smtp2go-monitor' ); ?></h1>
			<?php if ( isset( $notices[ $notice ] ) ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! defined( 'SMTP2GO_WORDPRESS_PLUGIN_VERSION' ) || ! class_exists( '\\SMTP2GO\\App\\SettingsHelper' ) || ! \SMTP2GO\App\SettingsHelper::getOption( 'smtp2go_enabled' ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The official SMTP2GO plugin must be active and enabled for monitoring and recovery.', 'as-smtp2go-monitor' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'tripped' === $state ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Email sending is paused. New outbound email is being saved to the queue.', 'as-smtp2go-monitor' ); ?></p></div>
			<?php endif; ?>
			<?php if ( get_option( 'as_smtp2go_monitor_queue_error' ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( get_option( 'as_smtp2go_monitor_queue_error' ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( get_option( 'as_smtp2go_monitor_notice_error' ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( get_option( 'as_smtp2go_monitor_notice_error' ) ); ?></p></div>
			<?php endif; ?>
			<div style="background:#fff;border:0.0625rem solid #ccd0d4;border-radius:0.75rem;padding:1.25rem;max-width:65rem">
				<p style="margin-top:0;color:#50575e"><?php esc_html_e( 'ALPHASYS MAIL OPERATIONS', 'as-smtp2go-monitor' ); ?></p>
				<h2 style="margin-top:0"><?php echo esc_html( strtoupper( $state ) ); ?></h2>
				<p><?php printf( esc_html__( '%d email(s) waiting', 'as-smtp2go-monitor' ), (int) $count ); ?></p>
				<?php if ( get_option( 'as_smtp2go_monitor_last_error' ) ) : ?>
					<p><strong><?php esc_html_e( 'Last failure:', 'as-smtp2go-monitor' ); ?></strong> <?php echo esc_html( get_option( 'as_smtp2go_monitor_last_error' ) ); ?></p>
				<?php endif; ?>
				<?php if ( get_option( 'as_smtp2go_monitor_changed_at' ) ) : ?>
					<p><?php printf( esc_html__( 'State changed: %s UTC', 'as-smtp2go-monitor' ), esc_html( get_option( 'as_smtp2go_monitor_changed_at' ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( 'tripped' === $state ) : ?>
					<p><?php esc_html_e( 'Fix SMTP2GO and send a successful test from Settings > SMTP2GO > Test before starting recovery.', 'as-smtp2go-monitor' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="as_smtp2go_start_recovery">
						<?php wp_nonce_field( 'as_smtp2go_start_recovery' ); ?>
						<p><label><input type="checkbox" name="acknowledge" value="1" required> <?php esc_html_e( 'I have confirmed that the SMTP2GO test succeeds and want to send the queued email.', 'as-smtp2go-monitor' ); ?></label></p>
						<?php submit_button( __( 'Start Recovery', 'as-smtp2go-monitor' ), 'primary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
			<h2><?php esc_html_e( 'Queue', 'as-smtp2go-monitor' ); ?></h2>
			<p><?php esc_html_e( 'Showing the oldest 50 emails. Contents are kept in the database and are not displayed here.', 'as-smtp2go-monitor' ); ?></p>
			<table class="widefat striped" style="max-width:65rem">
				<thead><tr><th><?php esc_html_e( 'ID', 'as-smtp2go-monitor' ); ?></th><th><?php esc_html_e( 'Created (UTC)', 'as-smtp2go-monitor' ); ?></th><th><?php esc_html_e( 'To', 'as-smtp2go-monitor' ); ?></th><th><?php esc_html_e( 'Subject', 'as-smtp2go-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'as-smtp2go-monitor' ); ?></th><th><?php esc_html_e( 'Attempts', 'as-smtp2go-monitor' ); ?></th><th><?php esc_html_e( 'Action', 'as-smtp2go-monitor' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$mail = json_decode( $row->payload, true );
					$to = isset( $mail['to'] ) ? $mail['to'] : '';
					$to = is_array( $to ) ? implode( ', ', $to ) : $to;
					?>
					<tr>
						<td><?php echo (int) $row->id; ?></td><td><?php echo esc_html( $row->created_at ); ?></td><td><?php echo esc_html( $to ); ?></td><td><?php echo esc_html( isset( $mail['subject'] ) ? $mail['subject'] : '' ); ?></td><td><?php echo esc_html( $row->status ); ?></td><td><?php echo (int) $row->attempts; ?></td>
						<td><?php if ( 'queued' === $row->status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this queued email?', 'as-smtp2go-monitor' ) ); ?>');">
								<input type="hidden" name="action" value="as_smtp2go_delete_queued"><input type="hidden" name="queue_id" value="<?php echo (int) $row->id; ?>">
								<?php wp_nonce_field( 'as_smtp2go_delete_queued' ); ?><button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'as-smtp2go-monitor' ); ?></button>
							</form>
						<?php elseif ( 'sending' === $row->status && 'tripped' === $state ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="as_smtp2go_requeue_interrupted"><input type="hidden" name="queue_id" value="<?php echo (int) $row->id; ?>">
								<?php wp_nonce_field( 'as_smtp2go_requeue_interrupted' ); ?>
								<label><input type="checkbox" name="acknowledge" value="1" required> <?php esc_html_e( 'I checked SMTP2GO and accept that this email may have been sent.', 'as-smtp2go-monitor' ); ?></label>
								<button type="submit" class="button"><?php esc_html_e( 'Return to queue', 'as-smtp2go-monitor' ); ?></button>
							</form>
						<?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?><tr><td colspan="7"><?php esc_html_e( 'The queue is empty.', 'as-smtp2go-monitor' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Trip notification', 'as-smtp2go-monitor' ); ?></h2>
			<p><?php esc_html_e( 'Enter an HTTPS Microsoft Teams Workflow webhook URL. It receives the site, failure reason, queue count, and admin link, but no email contents or API key.', 'as-smtp2go-monitor' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="as_smtp2go_save_webhook">
				<?php wp_nonce_field( 'as_smtp2go_save_webhook' ); ?>
				<label for="as-smtp2go-webhook"><?php esc_html_e( 'Webhook URL', 'as-smtp2go-monitor' ); ?></label><br>
				<input id="as-smtp2go-webhook" type="url" name="webhook_url" class="regular-text" value="" autocomplete="off" placeholder="<?php echo get_option( 'as_smtp2go_monitor_webhook' ) ? esc_attr__( 'Configured — enter a new URL to replace it', 'as-smtp2go-monitor' ) : esc_attr__( 'https://…', 'as-smtp2go-monitor' ); ?>">
				<p class="description"><?php esc_html_e( 'Submitting an empty field clears the URL.', 'as-smtp2go-monitor' ); ?></p>
				<?php submit_button( __( 'Save Webhook', 'as-smtp2go-monitor' ) ); ?>
			</form>
		</div>
		<?php
	}
}
