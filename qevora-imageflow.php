<?php
/**
 * Plugin Name: Qevora ImageFlow
 * Description: Generate local image alternatives and deliver them in WordPress and Elementor.
 * Version: 1.0.4
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Qevora Software
 * License: GPL-2.0-or-later
 * Text Domain: qevora-imageflow
 */
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;
define('QIF_URL', plugin_dir_url(__FILE__));
foreach (array('settings','converter','delivery','admin') as $module) {
    require_once __DIR__ . '/includes/' . $module . '.php';
}
add_filter('wp_generate_attachment_metadata', function ($meta, $id) {
    if (settings()['auto'] && wp_attachment_is_image($id) && !wp_next_scheduled('qif_convert', array($id))) {
        wp_schedule_single_event(time() + 5, 'qif_convert', array($id));
    }
    return $meta;
}, 20, 2);
add_action('qif_convert', __NAMESPACE__ . '\\convert');
add_action('delete_attachment', __NAMESPACE__ . '\\remove_files');
register_deactivation_hook(__FILE__, function () { wp_unschedule_hook('qif_convert'); });
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('imageflow convert', function ($args) {
        foreach ($args as $id) { \WP_CLI::log(intval($id) . ' ' . convert(absint($id))); }
    });
}
