<?php
// 记录导入步骤
class StepImport
{
	public $base = '';
	private $_path;

	public function __construct()
	{
		global $wpdb;

// 		$dir = wp_upload_dir();
		$this->_path = array(
			'data' => plugin_dir_path(__FILE__).'data/',
			'temp' => get_temp_dir()
		);

		$this->_path['imp'] = sprintf('%simp_%d.tmp', $this->_path['data'], $wpdb->blogid);
		$this->_path['log'] = sprintf('%slog_%d.txt', $this->_path['data'], $wpdb->blogid);

		$this->_path['log_url'] = sprintf('%sdata/log_%d.txt', plugin_dir_url($this->_path['data']), $wpdb->blogid);
	}

	public function getPath()
	{
		return $this->_path;
	}

	public function save($data)
	{
		if (!file_put_contents($this->_path['imp'], serialize($data)))
		{
			throw new Exception('写入文件失败');
		}
	}

	public function init()
	{
		$str = file_exists($this->_path['log']) ? '系统即将导入下一批数据...' : '初始化成功，等待数据导入...';
		if (is_writable($this->_path['data']) && false != ($fp = fopen($this->_path['log'], 'a')))
		{
			fwrite($fp, $str.PHP_EOL);
			fclose($fp);
				
			return true;
		}

		return false;
	}

	public function write($str)
	{
		if (file_exists($this->_path['log']) && false != ($fp = fopen($this->_path['log'], 'a')))
		{
			fwrite($fp, $str.PHP_EOL);
			fclose($fp);
		}
	}

	public function closed()
	{
		file_exists($this->_path['imp']) && unlink($this->_path['imp']);
		file_exists($this->_path['log']) && unlink($this->_path['log']);
	}

	public function skip($post, $category, $post_exists)
	{
		if ($post_exists && get_post_type($post_exists) == 'post')
		{
			$this->_impLog('skip_post');
		}

		return $post;
	}

	public function roll($press, $id, $post)
	{
		if (!is_wp_error($id))
		{
			$this->write(sprintf('文章“%s”已成功导入到当前博客中。', $post['post_title']));
			$this->_impLog('add_post');
		}
		else
		{
			$this->_impLog('lost_post');
		}
	}

	public function rollAttach($id, $url)
	{
		if (is_wp_error($id))
		{
			$str = '远程图片下载失败，附件地址：'.$url;
			$this->_impLog('lost_img');
				
			if (defined('IMPORT_DEBUG') && IMPORT_DEBUG)
			{
				$str .= '<br />失败原因：'.$id->get_error_message();
			}
		}
		else
		{
			$str = '远程图片下载成功，附件地址：'.$url;
			$this->_impLog('add_img');
		}

		$this->write($str);
	}

	public function rollTerms($id, $data)
	{
		$name = $data['domain'] == 'category' ? '分类' : '标签';
		if (!is_wp_error($id))
		{
			$str = sprintf('%s导入成功，%1$s名称：%s', $name, esc_html($data['name']));
			$this->_impLog('add_'.$data['domain']);
		}
		else
		{
			$str = sprintf('导入%s失败，%1$s名称：%s', $name, esc_html($data['name']));
			$this->_impLog('lost_'.$data['domain']);
				
			if (defined('IMPORT_DEBUG') && IMPORT_DEBUG)
			{
				$str .= sprintf('；失败原因：%s', $id->get_error_message());
			}
		}

		$this->write($str);
	}

	public function setPostTerms($tt_ids, $ids, $tax, $post_id, $post)
	{
		$name = $tax == 'category' ? '分类' : '标签';
		$str = sprintf('文章“%s”设置%s%s', $post['title'], $name, is_wp_error($tt_ids) ? '失败' : '成功');

		$this->write($str);
	}

	public function testing($msg)
	{
		$this->write($msg);
	}

	private function _impLog($tag)
	{
		$key = $this->base;
		$def = array(
			'add_post' => 0, 'skip_post' => 0, 'lost_post' => 0, 'add_img' => 0, 'lost_img' => 0,
			'add_category' => 0, 'lost_category' => 0, 'add_post_tag' => 0, 'lost_post_tag' => 0
		);

		if ($key && isset($def[$tag]))
		{
			$data = get_option('imp_levi_log', array());
			!isset($data[$key]) && $data[$key] = $def;
				
			$data[$key][$tag] += 1;
			$data[$key]['time'] = time();
				
			update_option('imp_levi_log', $data);
		}
	}
}