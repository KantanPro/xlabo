(function ($) {
	'use strict';

	$(function () {
		$('.xlabo-manual-share').on('click', function () {
			var $button = $(this);
			var $result = $button.closest('.xlabo-metabox').find('.xlabo-manual-share-result');
			var postId = $button.data('post-id');

			$button.prop('disabled', true);
			$result.removeClass('is-error is-success').text(xlaboPostEditor.i18n.sharing);

			$.post(xlaboPostEditor.ajaxUrl, {
				action: 'xlabo_manual_share',
				nonce: xlaboPostEditor.nonce,
				post_id: postId
			})
				.done(function (response) {
					if (response && response.success) {
						$result.addClass('is-success').text(response.data.message || '');
					} else {
						var message =
							response && response.data && response.data.message
								? response.data.message
								: 'Share failed.';
						$result.addClass('is-error').text(message);
					}
				})
				.fail(function (xhr) {
					var message =
						xhr.responseJSON &&
						xhr.responseJSON.data &&
						xhr.responseJSON.data.message
							? xhr.responseJSON.data.message
							: 'Share failed.';
					$result.addClass('is-error').text(message);
				})
				.always(function () {
					$button.prop('disabled', false).text(xlaboPostEditor.i18n.share);
				});
		});
	});
})(jQuery);
