<?php
/** Copy this file into a AlphaSys plugin, require it, then call asuc_client_register(__FILE__, 'repository-name') from the plugin main file. */
if (!defined('ABSPATH')) { exit; }
if (!function_exists('asuc_client_register')) {
    function asuc_client_register(string $main_file, string $repository): void {
        $GLOBALS['asuc_clients'][plugin_basename($main_file)] = sanitize_text_field($repository);
    }
    function asuc_client_links(array $links, string $file): array {
        if (!isset($GLOBALS['asuc_clients'][$file])) { return $links; }
        if (function_exists('asuc_available') && asuc_available() && defined('ASUC_API_VERSION') && ASUC_API_VERSION >= 1) { return $links; }
        $links[] = '<a href="' . esc_url('https://github.com/cchatterton/' . $GLOBALS['asuc_clients'][$file]) . '">GitHub</a>';
        $controller = 'as-update-controller/as-update-controller.php';
        if (!file_exists(WP_PLUGIN_DIR . '/' . $controller)) {
            if (current_user_can('install_plugins')) {
                $url = wp_nonce_url(admin_url('admin-post.php?action=asuc_bootstrap_install'), 'asuc_bootstrap_install');
                $links[] = '<a href="' . esc_url($url) . '">Install AlphaSys Update Controller</a>';
            }
        } elseif (!defined('ASUC_API_VERSION') || (is_multisite() && !is_plugin_active_for_network($controller))) {
            if (current_user_can('activate_plugin', $controller)) {
                $url = wp_nonce_url(add_query_arg(['action'=>'activate','plugin'=>$controller,'networkwide'=>is_multisite() ? 1 : 0], network_admin_url('plugins.php')), 'activate-plugin_' . $controller);
                $links[] = '<a href="' . esc_url($url) . '">Activate AlphaSys Update Controller</a>';
            }
        } elseif (current_user_can('update_plugins')) {
            $links[] = '<a href="' . esc_url(network_admin_url('plugins.php')) . '">Update AlphaSys Update Controller</a>';
        }
        return $links;
    }
    function asuc_bootstrap_install(): void {
        if (!current_user_can('install_plugins') || (is_multisite() && !current_user_can('manage_network_plugins'))) { wp_die('You cannot install this controller.'); }
        check_admin_referer('asuc_bootstrap_install');
        global $wp_version;
        if (version_compare(PHP_VERSION, '7.4', '<') || version_compare($wp_version, '6.5', '<')) {
            wp_die('AlphaSys Update Controller requires WordPress 6.5 and PHP 7.4 or later. This plugin can continue to run without it.');
        }
        if (!wp_is_file_mod_allowed('asuc_bootstrap')) { wp_die('File modifications are disabled.'); }
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $title = 'Install AlphaSys Update Controller';
        require_once ABSPATH . 'wp-admin/admin-header.php';
        $skin = new Plugin_Installer_Skin(['type'=>'web','title'=>$title,'url'=>admin_url('admin-post.php?action=asuc_bootstrap_install'),'nonce'=>'asuc_bootstrap_install']);
        $upgrader = new Plugin_Upgrader($skin);
        $upgrader->install('https://github.com/cchatterton/as-update-controller/releases/latest/download/as-update-controller.zip');
        echo '<p><a href="' . esc_url(network_admin_url('plugins.php')) . '">Return to Plugins to activate the controller</a></p>';
        require_once ABSPATH . 'wp-admin/admin-footer.php';
    }
    add_filter('plugin_row_meta', 'asuc_client_links', 20, 2);
    add_action('admin_post_asuc_bootstrap_install', 'asuc_bootstrap_install');
}
