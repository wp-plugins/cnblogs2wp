(function($) {
	var time = function(num) {
		setTimeout(function() {
			$.ajax({
				type: 'GET',
				dataType: 'json',
				cache: false,
				timeout: 8000,
				url: ajaxurl,
				data: {
					action: 'get_import_progress',
					type: press_data.type,
				},
				success: function(data) {
					switch(data.status) {
						case 1:
							var list = data.msg.split(/\n/).slice(1, -1).reverse().join('</li><li>');
							$('#msg_list').html('<ol reversed><li>' + list + '<li></ol>');
						case 0:
							$('.status').hide().filter('#status_' + data.status).show();
							time();
							break;
						default:
							window.location.href = location.href.split('?')[0] + '?import=cn_blog&type=' + data.type
					}
				},
				error: function(xhr, textStatus, errorThrown) {
					// timeout or 502
					window.location.reload();
				}
			});
		}, num||3000);
	};

	time(1000);
	press_data.attach_id > 0 && $.post(ajaxurl + '?type=' + press_data.type, $.extend(press_data, {action: 'press_import'}, function() {}));
	$('.stop').click(function(event) {
		$.ajax({
			type: 'GET',
			url: ajaxurl,
			data: {
				action: 'stop_import',
				getnonce: press_data.stop_nonce,
				type: press_data.type,
			},
			success: function(data) {
				$('.status p:even').text('当前状态：等待系统结束请求');
				$('.status p:odd, #msg_box').hide();
			}
		});
	});
})(jQuery);