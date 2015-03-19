<?php
/**
 * Cnblog xml file parser implementations
 */

include 'mod/Csdn_parse.php';
include 'mod/Osc_parse.php';
include 'mod/Cnblogs_parse.php';
include 'mod/Lofter_parse.php';
include 'mod/Diandian_parse.php';

function waiting_import() 
{
	echo '<p class="waiting">即将开放，尽情期待...</p></div>';
	exit;
}

function add_importer_method()
{
// 	add_action('import_display_start_lofter', 'waiting_import');
	
	add_filter('parse_import_data_lofter', 'lofter_parse', 10, 2);
	add_action('blogs_levi_import_insert_post_lofter', 'lofter_filter', 10, 5);
	apply_filters('add_import_method', array(
		'slug' => 'lofter',
		'title' => 'Lofter',
		'category' => false,
		'description' => '将发表在Lofter（lofter.com）中的文章导入到当前wordpress'
	));

	$diandian = new Diandian_parse();
	add_filter('blogs_levi_import_post_data_raw_diandian', array($diandian, 'postRaw'), 10, 4);
	add_action('blogs_levi_import_insert_post_diandian', array($diandian, 'postFilter'), 10, 5);
	
	add_filter('parse_import_data_diandian', array($diandian, 'get'), 10, 2);
	apply_filters('add_import_method', array(
		'slug' => 'diandian',
		'title' => '点点',
		'category' => false,
		'description' => '将发表在点点（diandian.com）中的文章导入到当前wordpress'
	));

	$csdn = new CSDN_parse();
	add_action('import_display_start_csdn', array($csdn, 'display'));
	add_filter('blogs_levi_import_post_data_raw_csdn', array($csdn, 'postRaw'), 10, 4);
	add_action('blogs_levi_import_insert_post_csdn', array($csdn, 'postFilter'), 10, 5);
	
	add_filter('parse_import_data_csdn', array($csdn, 'get'), 10, 2);
	apply_filters('add_import_method', array(
		'slug' => 'csdn',
		'title' => 'CSDN',
		'category' => true,
		'description' => '将CSDN（csdn.net）博客中的文章导入到当前wordpress'
	));

	add_filter('parse_import_data_osc', array(new Osc_parse(), 'get'), 10, 2);
	apply_filters('add_import_method', array(
		'slug' => 'osc',
		'title' => '开源中国',
		'category' => true,
		'description' => '将开源中国（oschina.net）博客中的文章导入到当前wordpress中'
	));
	
	add_filter('parse_import_data_cnblogs', 'cnblogs_parse', 10, 2);
	apply_filters('add_import_method', array(
		'slug' => 'cnblogs',
		'title' => '博客园',
		'category' => false,
		'description' => '将博客园（cnblogs.com）博客中的文章导入到当前wordpress中'
	));
}