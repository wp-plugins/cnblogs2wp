<?php
/**
 * Plugin Name: 博客搬家到wordpress
 * Plugin URI: http://levi.cg.am
 * Description: 支持从以下站点搬家到wordpress：博客园、OSChina、CSDN、点点、LOFTER
 * Version: 0.6.5
 * Network: true
 * Depends: wp-patch-levi
 * Author: Levi
 * Author URI: http://levi.cg.am
 * Text Domain: cnblogs-importer
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

include 'func_glob.php';

add_filter('upload_mimes', 'imp_upload_mimes');
add_filter('async_upload_cnblog2wp', 'clean_imp');
add_filter('plugin_action_links', 'cnblog2wp_plugin_action_links', 2, 2);

add_action('wp_ajax_nopriv_press_import', 'after_imp');
add_action('wp_ajax_press_import', 'after_imp');
add_action('init', 'load_press_script');

if (!defined('WP_LOAD_IMPORTERS') && strpos($_SERVER['REQUEST_URI'], 'wp-admin/admin-ajax.php') === false)
	return;

/** Display verbose errors */
define('IMPORT_DEBUG', true);
define('CNBLOGS_IMPORT', true);

include 'StepImport.php';
include 'RemoteAttach.php';
include 'ParseImport.php';
include 'View.php';
include 'XML_Parse.php';
include 'func_imop.php';

$cnblogs = new Cnblog2wp();
$step = new StepImport();

// $cnblogs, $step
$import = new ParseImport();

add_action('wp_ajax_stop_import', array($import, 'stop'));
add_action('wp_ajax_get_import_progress', array($cnblogs, 'getImportProgress'));

add_action('admin_notices',  array($cnblogs, 'importOverMessage'));
add_action('admin_init', 'cnblog2wp_lv_importer_init', 15);

add_action('template_levi_mod', 'check_imp_dir');

add_action('admin_init', 'add_importer_method');

add_action('import_test_msg', array($step, 'testing'));
add_action('import_display_start_'.Cnblog2wp::$type, array($cnblogs, 'activeWpPatch'));
add_filter('get_import_file_'.Cnblog2wp::$type, 'get_import_file_id');
