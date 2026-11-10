<?php
/**
 * Plugin Name: AS SMTP2GO Monitor
 * Description: Pauses SMTP2GO mail after a sending failure and safely queues mail for manual recovery.
 * Version: 0.1.1
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/cchatterton/as-smtp2go-monitor
 * Author: AlphaSys
 * Author URI: https://alphasys.com.au
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * AlphaSys Controller API: 1
 * Text Domain: as-smtp2go-monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AS_SMTP2GO_MONITOR_VERSION', '0.1.1' );
define( 'AS_SMTP2GO_MONITOR_FILE', __FILE__ );

require_once __DIR__ . '/includes/controller-client.php';
require_once __DIR__ . '/includes/queue.php';
require_once __DIR__ . '/includes/monitor.php';
require_once __DIR__ . '/includes/admin.php';

asuc_client_register( __FILE__, 'as-smtp2go-monitor' );
register_activation_hook( __FILE__, array( 'AS_SMTP2GO_Queue', 'install' ) );
register_deactivation_hook( __FILE__, array( 'AS_SMTP2GO_Monitor', 'deactivate' ) );

// Network activation loads this file in each site context, including new sites.
AS_SMTP2GO_Queue::ensure_installed();

AS_SMTP2GO_Monitor::register();
AS_SMTP2GO_Admin::register();
