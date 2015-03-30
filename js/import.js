(function($) {
	var action = function () {
		$.ajax({
			type: 'POST',
			cache: false,
			url: imp_data.ajaxurl,
			data: {
				action: 'press_import',
				_wpnonce: imp_data._wpnonce
			},
			success: function(data) {
				// console.log(data);
				if (+data) {
					setTimeout(action, 60000);
				}
			},
			error: function() {
				setTimeout(action, 60000);
			}
		});
	};

	action();
})(jQuery);