<?php
function cnblog2wp_plugin_action_links($links, $file)
{
	static $this_plugin;

	if (!$this_plugin) $this_plugin = plugin_basename(__FILE__);
	if ($file == $this_plugin)
	{
		array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?import=cn_blog')) . '">开始导入</a>');
	}

	return $links;
}

function imp_upload_mimes($mime)
{
	$type = isset($_POST['type']) ? trim($_POST['type']) : '';
	if ($type == 'cnblog2wp')
	{
		$mime['xml'] = 'application/xml';
		$mime['htm|html'] = 'text/html';
	}

	return $mime;
}

function clean_imp($id,  $temp = DAY_IN_SECONDS)
{
	/*
	 * Schedule a cleanup for one day from now in case of failed
	 * import or missing wp_import_cleanup() call.
	 */
	wp_schedule_single_event(time() + $temp, 'importer_scheduled_cleanup', array($id));
	return $id;
}

function after_imp()
{
	$import = new ParseImport();
	$import->import();
}

function load_press_script()
{
	wp_enqueue_script('cnblog_levi_imp', plugins_url('js/import.js', __FILE__), array('jquery'), false, true);
	wp_localize_script('cnblog_levi_imp', 'imp_data', array(
		'ajaxurl' => admin_url('admin-ajax.php'),
		'_wpnonce' => wp_create_nonce('parse_import_cnblogs2wp')
	));
}