/**
 * Media-library pickers for the listing form: a single profile image and a
 * multi-select gallery. Selected attachment IDs land in hidden inputs; the
 * server computes the wire facts (URL, hash, dimensions) at save time.
 */
(function ($) {
	'use strict';

	function thumbHtml(attachment) {
		var url = attachment.sizes && attachment.sizes.thumbnail
			? attachment.sizes.thumbnail.url
			: attachment.url;
		return '<img src="' + url + '" class="oy-thumb" alt="">';
	}

	function renderPreviewByIds(containerId, ids) {
		var $container = $(containerId);
		$container.empty();
		ids.forEach(function (id) {
			if (!id) {
				return;
			}
			var attachment = wp.media.attachment(id);
			attachment.fetch().done(function () {
				$container.append(thumbHtml(attachment.attributes));
			});
		});
	}

	function idsFromInput($input) {
		return $input.val().split(',').map(function (v) {
			return parseInt(v, 10);
		}).filter(function (v) {
			return !isNaN(v) && v > 0;
		});
	}

	$(function () {
		var $profileInput = $('#oy_profile_id');
		var $galleryInput = $('#oy_gallery_ids');

		if (!$profileInput.length) {
			return;
		}

		renderPreviewByIds('#oy_profile_preview', idsFromInput($profileInput));
		renderPreviewByIds('#oy_gallery_preview', idsFromInput($galleryInput));

		$('#oy_profile_pick').on('click', function () {
			var frame = wp.media({
				title: 'Choose profile image',
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first();
				$profileInput.val(attachment.id);
				$('#oy_profile_preview').html(thumbHtml(attachment.attributes));
			});
			frame.open();
		});

		$('#oy_profile_clear').on('click', function () {
			$profileInput.val('');
			$('#oy_profile_preview').empty();
		});

		$('#oy_gallery_pick').on('click', function () {
			var frame = wp.media({
				title: 'Choose gallery images',
				library: { type: 'image' },
				multiple: 'add'
			});
			frame.on('open', function () {
				var selection = frame.state().get('selection');
				idsFromInput($galleryInput).forEach(function (id) {
					var attachment = wp.media.attachment(id);
					attachment.fetch();
					selection.add(attachment);
				});
			});
			frame.on('select', function () {
				var ids = frame.state().get('selection').map(function (attachment) {
					return attachment.id;
				});
				$galleryInput.val(ids.join(','));
				renderPreviewByIds('#oy_gallery_preview', ids);
			});
			frame.open();
		});

		$('#oy_gallery_clear').on('click', function () {
			$galleryInput.val('');
			$('#oy_gallery_preview').empty();
		});
	});
})(jQuery);
