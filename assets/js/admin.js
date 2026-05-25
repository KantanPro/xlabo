(function ($) {
	'use strict';

	$(function () {
		$('.xlabo-auth-method').on('change', function () {
			var method = $('.xlabo-auth-method:checked').val();

			$('.xlabo-auth-panel').hide();

			if (method === 'oauth1') {
				$('.xlabo-auth-oauth1').show();
			} else {
				$('.xlabo-auth-oauth2').show();
			}
		});
	});
})(jQuery);
