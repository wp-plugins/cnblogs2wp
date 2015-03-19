function up_patch($) {
	var uploader = new plupload.Uploader(plupload_init);

	uploader.init();
	uploader.bind('FilesAdded', function(up, files) {
		var html = '';
		plupload.each(files, function(file) {
			html += '<li id="' + file.id + '">' + file.name + ' (' + plupload.formatSize(file.size) + ') <b></b></li>';
		});

		$('#container').find('a').hide().next('span').html('正在上传数据文件...');
		$('#send_parse_import').attr('disabled', 'disabled');
		$('#filelist').html(html);

		uploader.start();
	});

	uploader.bind('UploadProgress', function(up, file) {
		$('#' + file.id).find('b').html('<span>' + file.percent + '%</span>');
	});

	uploader.bind('Error', function(up, err) {
		$('#console').html("\nError #" + err.code + ": " + err.message);
	});

	uploader.bind('FileUploaded', function(up, file, res) {
		$('#container').find('a').show().next('span').html(' (数据文件已上传成功)');
		$('#send_parse_import').removeAttr('disabled');
		$('#import_loadfile_id').val(res.response);
	});
}

(function($) {
	$('#imp-list').on('click', 'a', function() {
		var type = $(this).addClass('current').siblings('a').removeClass('current').end().data('type');
		$('#action .next').attr('href', location.origin + location.pathname + '?import=cn_blog&step=2&type=' + type);
		if (type == 'csdn') {
			$('#csdn_s').show().prev('.step_head').hide();
		} else  {
			$('#csdn_s').hide().prev('.step_head').show();
		}

		return false;
	});

	$('#patch-msg').insertAfter($('.nav-tab-wrapper'));
	$('#import-upload-form').on({
		submit: function() {
			var $this = $(this);
			
			$this.trigger('add', ['selet_author', $('[name="selet_author"]:checked').val()]);
			$this.trigger('add', ['user_new', $('[name="user_new"]').val()]);
			$this.trigger('add', ['user_map', $('[name="user_map"]').val()]);
			$this.trigger('add', ['selet_category', $('[name="selet_category"]:checked').val()]);
			$this.trigger('add', ['category_new', $('[name="category_new"]').val()]);
			$this.trigger('add', ['category_map', $('[name="category_map"]').val()]);
			$this.trigger('add', ['fetch_attachments', $('[name="fetch_attachments"]').is(':checked')|0]);
		},

		add: function(e, key, val) {
			$('<input type="hidden" name="' + key + '" value="' + val + '">').appendTo(this);
		}
	});

	$('#plupload-upload-ui').length && up_patch($);
})(jQuery);