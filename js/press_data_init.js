(function($) {
	var time = {
		status: function(num) {
			setTimeout(function() {
				$.ajax({
					type: 'GET',
					dataType: 'json',
					cache: false,
					timeout: 8000,
					url: ajaxurl,
					data: {
						action: 'get_import_progress'
					},
					success: function(data) {
						if (data.status == 1) {
							time.get(data.log);
						} else {
							window.location.href = location.origin + location.pathname + '?import=cn_blog&&type=' + data.type
						}
					},
					error: function(xhr, textStatus, errorThrown) {
						// timeout or 502
						window.location.reload();
					}
				});
			}, num||3000);
		},

		get: function(url) {
			$.ajax({
				type: 'GET',
				dataType: 'text',
				cache: false,
				timeout: 8000,
				url: url,
				success: function(data) {
					var html = '', list = data.split('\n');
					for (var i = list.length - 1; i >= 0; i--) {
						if (list[i].length) {
							html += '<li>' + list[i] + '</li>';
						}
					}

					$('#msg_list').html('<ol>' + html + '</ol>');
					time.status();
				},
				error: function() {
					time.status();
				}
			});
		}
	};

	time.status(1000);
	$('.stop').click(function(event) {
		$.ajax({
			type: 'GET',
			url: ajaxurl,
			data: {
				action: 'stop_import'
			},
			success: function() {
				window.location.href = location.origin + location.pathname + '?import=cn_blog';
			}
		});
	});
})(jQuery);