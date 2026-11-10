<?php
/** Minimal queue round-trip check without a running WordPress installation. */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

class Queue_Test_DB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $row = null;
	public function insert( $table, $data, $format ) {
		$this->row = $data;
		$this->insert_id = 1;
		return 1;
	}
}

$wpdb = new Queue_Test_DB();
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain ) { return $value; }
function current_time( $type, $gmt ) { return '2026-11-10 00:00:00'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^a-zA-Z0-9._-]/', '', $value ); }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }

require_once __DIR__ . '/../as-smtp2go-monitor/includes/queue.php';

$source = tempnam( sys_get_temp_dir(), 'as-smtp2go-test-' );
file_put_contents( $source, 'attachment contents' );
$mail = array( 'to' => 'person@example.com', 'subject' => 'Queued subject', 'message' => 'Queued body', 'headers' => array( 'Content-Type: text/plain' ), 'attachments' => array( $source ), 'embeds' => array() );
$id = AS_SMTP2GO_Queue::enqueue( $mail );
if ( 1 !== $id ) { throw new RuntimeException( 'Queue insert failed' ); }
$restored = AS_SMTP2GO_Queue::unpack( $wpdb->row['payload'] );
if ( is_wp_error( $restored ) ) { throw new RuntimeException( $restored->get_error_message() ); }
list( $round_trip, $temporary ) = $restored;
if ( 'Queued subject' !== $round_trip['subject'] || 'attachment contents' !== file_get_contents( $round_trip['attachments'][0] ) ) {
	throw new RuntimeException( 'Queue round-trip changed the message' );
}
AS_SMTP2GO_Queue::cleanup( $temporary );
if ( file_exists( $temporary[0] ) ) { throw new RuntimeException( 'Temporary attachment was not removed' ); }
unlink( $source );
echo "Queue round-trip OK\n";
