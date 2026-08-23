/**
 * Media-library pickers for the listing form: a single profile image and a
 * multi-select gallery with per-image removal. Selected attachment IDs
 * land in hidden inputs; the server computes the wire facts (URL, hash,
 * dimensions, caption) at save time. Alt text and captions are edited on
 * the attachment itself, in the media dialog's sidebar.
 */
(function ($) {
	'use strict';

	function thumbUrl(attachment) {
		return attachment.sizes && attachment.sizes.thumbnail
			? attachment.sizes.thumbnail.url
			: attachment.url;
	}

	function idsFromInput($input) {
		return $input.val().split(',').map(function (v) {
			return parseInt(v, 10);
		}).filter(function (v) {
			return !isNaN(v) && v > 0;
		});
	}

	function renderGallery($input, $container) {
		var ids = idsFromInput($input);
		$container.empty();

		if (!ids.length) {
			$container.append($('<span class="oy-media-empty w-full"></span>').text($container.data('emptyText') || 'No images'));
			return;
		}

		ids.forEach(function (id) {
			var attachment = wp.media.attachment(id);
			attachment.fetch().done(function () {
				var $wrap = $('<span class="oy-thumb-wrap"></span>');
				$wrap.append($('<img class="oy-thumb" alt="">').attr('src', thumbUrl(attachment.attributes)));
				var $remove = $('<button type="button" class="oy-thumb-x" aria-label="Remove image">&times;</button>');
				$remove.on('click', function () {
					var remaining = idsFromInput($input).filter(function (existing) { return existing !== id; });
					$input.val(remaining.join(','));
					renderGallery($input, $container);
				});
				$wrap.append($remove);
				$container.append($wrap);
			});
		});
	}

	function renderProfile($input, $container) {
		var ids = idsFromInput($input);
		$container.empty();

		if (!ids.length) {
			$container.append($('<span class="oy-media-empty w-full"></span>').text($container.data('emptyText') || 'No profile image'));
			return;
		}

		var attachment = wp.media.attachment(ids[0]);
		attachment.fetch().done(function () {
			$container.append($('<img class="oy-thumb" alt="">').attr('src', thumbUrl(attachment.attributes)));
		});
	}

	$(function () {
		var $profileInput = $('#oy_profile_id');
		var $galleryInput = $('#oy_gallery_ids');
		var $profilePreview = $('#oy_profile_preview');
		var $galleryPreview = $('#oy_gallery_preview');

		if (!$profileInput.length) {
			return;
		}

		renderProfile($profileInput, $profilePreview);
		renderGallery($galleryInput, $galleryPreview);

		$('#oy_profile_pick').on('click', function () {
			var frame = wp.media({
				title: 'Choose profile image',
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				$profileInput.val(frame.state().get('selection').first().id);
				renderProfile($profileInput, $profilePreview);
			});
			frame.open();
		});

		$('#oy_profile_clear').on('click', function () {
			$profileInput.val('');
			renderProfile($profileInput, $profilePreview);
		});

		$('#oy_gallery_pick').on('click', function () {
			var frame = wp.media({
				title: 'Add gallery images',
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
				renderGallery($galleryInput, $galleryPreview);
			});
			frame.open();
		});

		$('#oy_gallery_clear').on('click', function () {
			$galleryInput.val('');
			renderGallery($galleryInput, $galleryPreview);
		});
	});
})(jQuery);
