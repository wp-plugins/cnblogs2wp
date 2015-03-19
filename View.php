<?php
// 图形界面
class Lv_ui
{
	public function getPath($target = '')
	{
		return plugin_dir_path(__FILE__).$target;
	}

	public function getURL($target = '')
	{
		plugins_url('images/icon.png', __FILE__);
	}

	public function template($file, $var = array())
	{
		do_action('template_levi_'.$file);

		$var && extract($var);
		include $this->getPath(sprintf('template/%s.htm', $file));
	}
}

class Cnblog2wp extends Lv_ui
{
	public static $type = '';
	public $up_patch = false;
	public $val = array();

	private $_mod;

	public function __construct()
	{
		add_filter('add_import_method', array($this, 'append'));
		if (false != ($this->_mod = get_option('cnblog2wp-levi')))
		{
			self::$type = $this->_mod['type'];
		}
		else
		{
			self::$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'cnblogs';
		}
	}

	public function append($value)
	{
		$slug = isset($value['slug']) ? $value['slug'] : '';

		if (!$slug) return false;
		$this->val[$slug] = array(
			'slug' => $slug,
			'title' => isset($value['title']) && $value['title'] ? $value['title'] : $slug,
			'category' => isset($value['category']) ? $value['category'] : true,
			'description' => isset($value['description']) ? $value['description'] : '',
			'sort' => isset($value['sort']) ? (int)$value['sort'] : 10,
			'img' => plugins_url('img/'.$slug.'.png', __FILE__)
		);
	}

	public function dispatch()
	{
		$map = isset($_GET['map']) ? (int)$_GET['map'] : 0;

		wp_enqueue_style('cnblog2wp');
		$this->template('header', array('map' => $map));

		if ($map)
		{
			$this->template('log', array(
				'log' => get_option('imp_levi_log')
			));
		}
		else
		{
			$this->_impMod();
		}
	}

	private function _impMod()
	{
		global $import;

		$step = isset($_GET['step']) ? (int)trim($_GET['step']) : 1;
		uasort($this->val, array($this, 'sort_num'));

		if (!array_key_exists(self::$type, $this->val))
		{
			$step = 1;
		}

		if (isset($this->_mod['status']))
		{
			switch ($this->_mod['status'])
			{
				case -1: $step = 2; break;
				case 1: $step = 4; break;
				default: $step = 1;
			}
		}

		switch($step)
		{
			case 2:
				wp_localize_script('cnblog2wp', 'plupload_init', $this->_plupload_init());
				do_action('import_display_start_'.self::$type, isset($this->_mod['status']) && $this->_mod['status'] == -1);
				break;
			case 3:
				try
				{
					check_admin_referer('import-upload');
					$id = $this->_handleUpload();

					$import->impPress($id);
				}
				catch (Exception $e)
				{
					update_option('cnblog2wp-levi', array(
					'status' => -1,
					'type' => self::$type,
					'fetch_attachments' => isset($_POST['fetch_attachments']) ? (int)$_POST['fetch_attachments'] : 0,
					'msg' => $e->getMessage()
					));
				}
			case 4:
				wp_enqueue_script('press_data_init');
				$this->template('import');
				return ;
		}

		wp_enqueue_script('cnblog2wp');
		$this->template('mod', array(
			'group' => $this->val,
			'type' => self::$type,
			'step' => $step,
			'blog' => $this
		));
	}

	/**
	 * 选择补丁
	 */
	public function activeWpPatch()
	{
		if (is_plugin_active('wp-patch-levi/wp-patch-levi.php') || (false != ($mu = get_mu_plugins()) && isset($mu['wp-patch-levi.php'])))
		{
			// 优先级调低，方便其他plugin优先
			add_action('remote_file', array($this, 'upPatchTmp'), 100);
		}
		elseif (get_plugins('/wp-patch-levi') && current_user_can('activate_plugins'))
		{
			$activate = activate_plugin('wp-patch-levi/wp-patch-levi.php');
			if (!is_wp_error($activate))
			{
				add_action('remote_file', array($this, 'upPatchTmp'), 100);
			}
		}
	}

	/**
	 * 使用上传补丁
	 */
	public function upPatchTmp($num)
	{
		$type = self::$type;

		include 'template/mod_up-patch.htm';
		include ABSPATH.'wp-admin/admin-footer.php';
		exit;
	}

	/**
	 * 导入完成后的页面顶部的消息提醒
	 */
	public function importOverMessage()
	{
		if (!$this->_mod)
		{
			return;
		}

		$status = $this->_mod['status'];
		if ($status != 1)
		{
			delete_option('cnblog2wp-levi');
				
			printf('<div id="message" class="%s">', $status == -1 ? 'error' : 'updated');
			switch ($status)
			{
				case 0: $msg = '已终止数据导入，您还可以重新导入未完成的数据'; break;
				case 2: $msg = '数据已导入完毕'; break;
				default: $msg = '导入数据失败，失败原因：'.$this->_mod['msg'];
			}
				
			printf('<p><strong>%s</strong></p></div>', $msg);
		}
	}

	/**
	 * ajax 获取导入状态
	 */
	public function getImportProgress()
	{
		global $step;

		$def = array('status' => 0, 'type' => self::$type, 'msg' => '没有执行任何操作');
		$mod = get_option('cnblog2wp-levi', $def);

		$path = $step->getPath();
		$mod['log'] = $path['log_url'];

		echo json_encode($mod);
		exit;
	}

	public function sort_num($a, $b)
	{
		if ($a['sort'] == $b['sort']) return 0;
		return $a['sort'] > $b['sort'] ? 1 : -1;
	}

	public function sort_url($a, $b)
	{
		return strlen($b) - strlen($a);
	}

	public function allow_create_users()
	{
		return apply_filters('import_allow_create_users', true );
	}

	public function allow_fetch_attachments()
	{
		return apply_filters('import_allow_fetch_attachments', true);
	}

	public function is_valid_meta_key($key)
	{
		// skip attachment metadata since we'll regenerate it from scratch
		// skip _edit_lock as not relevant for import
		if (in_array($key, array('_wp_attached_file', '_wp_attachment_metadata', '_edit_lock')))
			return false;
		return $key;
	}

	private function _plupload_init()
	{
		$info = array(
			'runtimes'            => 'html5,flash,silverlight,html4',
			'browse_button'       => 'plupload-browse-button',
			'container'           => 'plupload-upload-ui',
			'file_data_name'      => 'async-upload',
			'multi_selection'     => false,
			'url'                 => admin_url('async-upload.php'),
			'flash_swf_url'       => includes_url( 'js/plupload/plupload.flash.swf' ),
			'silverlight_xap_url' => includes_url( 'js/plupload/plupload.silverlight.xap' ),
			'filters' => array(
				'max_file_size'   => '1073742848b',
			),
			'multipart_params'    => array(
				'_wpnonce' => wp_create_nonce('media-form'),
				'type' => 'cnblog2wp'
			)
		);

		return apply_filters('plupload_init', $info);
	}

	/**
	 * 处理WXR上传和为文件初步分析作准备
	 * 显示作者导入选项
	 *
	 * @return bool False if error uploading or invalid file, true otherwise
	 */
	private function _handleUpload()
	{
		if (false != ($id = apply_filters('get_import_file_'.self::$type, 0)))
		{
			return $id;
		}

		$file = wp_import_handle_upload();
		$str = '上传文件出现错误，请重新上传！错误原因：';
		if (isset($file['error']))
		{
			throw new Exception(sprintf('<p><strong>%s</strong><br />%s</p></div>', $str, esc_html($file['error'])));
		}
		else if (!file_exists($file['file']))
		{
			throw new Exception(sprintf('<p><strong>%s</strong><br />没有找到导入的xml文件：%s</p></div>', $str, esc_html($file['file'])));
		}

		return (int)$file['id'];
	}
}