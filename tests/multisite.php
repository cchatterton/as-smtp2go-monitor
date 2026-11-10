<?php
/** Check network-style per-site schema setup and state isolation. */

define( 'ABSPATH', __DIR__ . '/' );
class Multisite_Test_DB {
	public $prefix = 'wp_';
	public $tables = array();
	public function get_charset_collate() { return ''; }
	public function esc_like( $value ) { return $value; }
	public function prepare( $sql, $value ) { return str_replace( '%s', "'" . $value . "'", $sql ); }
	public function get_var( $sql ) {
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $sql, $match ) ) {
			return isset( $this->tables[ $match[1] ] ) ? $match[1] : null;
		}
		return null;
	}
}
$wpdb = new Multisite_Test_DB();
$site = 1;
$options = array( 1 => array(), 2 => array(), 3 => array() );
function get_option( $name, $default = false ) { global $options, $site; return isset( $options[ $site ][ $name ] ) ? $options[ $site ][ $name ] : $default; }
function add_option( $name, $value ) { global $options, $site; if ( ! isset( $options[ $site ][ $name ] ) ) { $options[ $site ][ $name ] = $value; } }
function update_option( $name, $value ) { global $options, $site; $options[ $site ][ $name ] = $value; }
function __( $value, $domain ) { return $value; }

require_once __DIR__ . '/../as-smtp2go-monitor/includes/queue.php';
require_once __DIR__ . '/../as-smtp2go-monitor/includes/monitor.php';

if ( ! AS_SMTP2GO_Queue::ensure_installed() || ! isset( $wpdb->tables['wp_as_smtp2go_queue'] ) ) { throw new RuntimeException( 'Main site setup failed' ); }
$options[1]['as_smtp2go_monitor_state'] = 'tripped';
$site = 2;
$wpdb->prefix = 'wp_2_';
if ( ! AS_SMTP2GO_Queue::ensure_installed() || ! isset( $wpdb->tables['wp_2_as_smtp2go_queue'] ) ) { throw new RuntimeException( 'Existing subsite setup failed' ); }
if ( 'armed' !== $options[2]['as_smtp2go_monitor_state'] || 'tripped' !== $options[1]['as_smtp2go_monitor_state'] ) { throw new RuntimeException( 'Site state leaked across subsites' ); }
$site = 3;
$wpdb->prefix = 'wp_3_';
AS_SMTP2GO_Monitor::remember_mail( array( 'to' => 'test@example.com' ) );
if ( ! isset( $wpdb->tables['wp_3_as_smtp2go_queue'] ) ) { throw new RuntimeException( 'Switched or newly added subsite was not initialised before mail' ); }
echo "Multisite setup OK\n";
