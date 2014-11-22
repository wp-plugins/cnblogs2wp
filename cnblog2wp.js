(function($) {
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
})(jQuery);