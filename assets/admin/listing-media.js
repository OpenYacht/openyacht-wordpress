/**
 * Media handling for the listing form: a single profile image plus three
 * orderable lists (gallery with categories, layouts, documents) and two
 * external-link lists (videos, tours). Attachment IDs land in ordered
 * hidden inputs — DOM order is submission order is wire sort — and the
 * server computes the wire facts (URL, hash, dimensions, caption) at save
 * time. Alt text and captions are edited on the attachment itself, in the
 * media dialog's sidebar.
 */
(function ($) {
	'use strict';

	var counter = 5000;
	var CATEGORIES = ['exterior', 'interior', 'lifestyle', 'crew'];
	var DRAG_HANDLE_SVG = '<svg viewBox="0 0 8 14" width="8" height="14" fill="currentColor" aria-hidden="true"><circle cx="2" cy="2" r="1.3"/><circle cx="6" cy="2" r="1.3"/><circle cx="2" cy="7" r="1.3"/><circle cx="6" cy="7" r="1.3"/><circle cx="2" cy="12" r="1.3"/><circle cx="6" cy="12" r="1.3"/></svg>';

	function thumbUrl(attachment) {
		if (attachment.sizes && attachment.sizes.thumbnail) {
			return attachment.sizes.thumbnail.url;
		}
		if (attachment.type === 'image') {
			return attachment.url;
		}
		return attachment.icon || '';
	}

	function refreshPlaceholder(list) {
		var placeholder = list.querySelector('[data-oy-media-placeholder]');
		var hasItems = !!list.querySelector('[data-oy-media-item]');
		if (placeholder) {
			placeholder.style.display = hasItems ? 'none' : '';
		}
	}

	/* ---- Orderable attachment lists ---- */

	function existingIds(list) {
		return Array.prototype.map.call(list.querySelectorAll('input[type="hidden"]'), function (input) {
			return parseInt(input.value, 10);
		});
	}

	function buildItem(inputName, attachment, withCategory) {
		var index = counter++;
		var item = document.createElement('div');
		item.className = 'oy-media-item';
		item.draggable = true;
		item.setAttribute('data-oy-media-item', '');

		var handle = document.createElement('span');
		handle.className = 'oy-drag';
		handle.title = 'Drag to reorder';
		handle.setAttribute('aria-hidden', 'true');
		handle.innerHTML = DRAG_HANDLE_SVG;
		item.appendChild(handle);

		var src = thumbUrl(attachment);
		if (attachment.type === 'image' && src) {
			var img = document.createElement('img');
			img.className = 'oy-thumb-sm';
			img.src = src;
			img.alt = '';
			item.appendChild(img);
		} else {
			var icon = document.createElement('span');
			icon.className = 'dashicons dashicons-media-document';
			icon.style.cssText = 'font-size:26px;width:44px;height:33px;color:var(--color-slate);display:flex;align-items:center;justify-content:center;';
			item.appendChild(icon);
		}

		var name = document.createElement('span');
		name.className = 'oy-media-name';
		name.textContent = attachment.title || attachment.filename || ('#' + attachment.id);
		item.appendChild(name);

		if (withCategory) {
			var select = document.createElement('select');
			select.className = 'oy-select !w-auto';
			select.name = 'oy[' + inputName + '][' + index + '][category]';
			select.setAttribute('aria-label', 'Image category');
			select.appendChild(new Option('— category —', ''));
			CATEGORIES.forEach(function (value) {
				select.appendChild(new Option(value.charAt(0).toUpperCase() + value.slice(1), value));
			});
			item.appendChild(select);
		}

		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = 'oy[' + inputName + '][' + index + '][id]';
		input.value = attachment.id;
		item.appendChild(input);

		var remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'oy-row-x';
		remove.setAttribute('data-oy-media-remove', '');
		remove.setAttribute('aria-label', 'Remove');
		remove.innerHTML = '&times;';
		item.appendChild(remove);

		return item;
	}

	function initMediaList(list) {
		var inputName = list.getAttribute('data-oy-media-list');
		var withCategory = list.hasAttribute('data-with-category');
		var addButton = document.querySelector('[data-oy-media-add="' + inputName + '"]');
		var mediaType = addButton ? addButton.getAttribute('data-media-type') : 'image';

		if (addButton) {
			addButton.addEventListener('click', function () {
				var frame = wp.media({
					title: addButton.textContent.trim(),
					library: { type: mediaType },
					multiple: 'add'
				});
				frame.on('select', function () {
					var present = existingIds(list);
					frame.state().get('selection').each(function (attachment) {
						if (present.indexOf(attachment.id) === -1) {
							list.appendChild(buildItem(inputName, attachment.attributes, withCategory));
						}
					});
					refreshPlaceholder(list);
				});
				frame.open();
			});
		}

		list.addEventListener('click', function (event) {
			if (event.target.closest('[data-oy-media-remove]')) {
				event.target.closest('[data-oy-media-item]').remove();
				refreshPlaceholder(list);
			}
		});

		initDragReorder(list);
	}

	/* ---- Drag to reorder (DOM order = wire sort) ---- */

	function initDragReorder(list) {
		var dragged = null;

		list.addEventListener('dragstart', function (event) {
			dragged = event.target.closest('[data-oy-media-item]');
			if (!dragged) {
				return;
			}
			dragged.classList.add('oy-dragging');
			event.dataTransfer.effectAllowed = 'move';
			// Firefox needs data set for the drag to start at all.
			event.dataTransfer.setData('text/plain', '');
		});

		list.addEventListener('dragend', function () {
			if (dragged) {
				dragged.classList.remove('oy-dragging');
				dragged = null;
			}
		});

		list.addEventListener('dragover', function (event) {
			if (!dragged) {
				return;
			}
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
			var over = event.target.closest('[data-oy-media-item]');
			if (!over || over === dragged) {
				return;
			}
			var rect = over.getBoundingClientRect();
			var before = event.clientY < rect.top + rect.height / 2;
			list.insertBefore(dragged, before ? over : over.nextSibling);
		});

		list.addEventListener('drop', function (event) {
			event.preventDefault();
		});
	}

	/* ---- Profile image ---- */

	function renderProfile(input, container) {
		var id = parseInt(input.value, 10);
		container.innerHTML = '';

		if (isNaN(id) || id <= 0) {
			var empty = document.createElement('span');
			empty.className = 'oy-media-empty w-full';
			empty.style.minWidth = '220px';
			empty.textContent = 'No profile image';
			container.appendChild(empty);
			return;
		}

		var attachment = wp.media.attachment(id);
		attachment.fetch().done(function () {
			var img = document.createElement('img');
			img.className = 'oy-thumb';
			img.alt = '';
			img.src = thumbUrl(attachment.attributes);
			container.appendChild(img);
		});
	}

	/* ---- External link rows (videos, tours) ---- */

	function initLinkRows(container) {
		var key = container.getAttribute('data-oy-link-rows');
		var template = document.querySelector('[data-oy-link-template="' + key + '"]');
		var addButton = document.querySelector('[data-oy-link-add="' + key + '"]');

		if (addButton && template) {
			addButton.addEventListener('click', function () {
				var html = template.innerHTML.replace(/__INDEX__/g, String(counter++));
				container.insertAdjacentHTML('beforeend', html);
				var row = container.lastElementChild;
				var url = row.querySelector('input[type="url"]');
				if (url) {
					url.focus();
				}
			});
		}

		container.addEventListener('click', function (event) {
			if (event.target.closest('[data-oy-remove-block]')) {
				var rows = container.querySelectorAll('[data-oy-block]');
				var row = event.target.closest('[data-oy-block]');
				if (rows.length === 1) {
					// Keep one row on screen; just blank it.
					row.querySelectorAll('input').forEach(function (input) {
						input.value = '';
					});
				} else {
					row.remove();
				}
			}
		});
	}

	$(function () {
		var profileInput = document.getElementById('oy_profile_id');

		if (!profileInput) {
			return;
		}

		var profilePreview = document.getElementById('oy_profile_preview');
		renderProfile(profileInput, profilePreview);

		document.getElementById('oy_profile_pick').addEventListener('click', function () {
			var frame = wp.media({
				title: 'Choose profile image',
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				profileInput.value = frame.state().get('selection').first().id;
				renderProfile(profileInput, profilePreview);
			});
			frame.open();
		});

		document.getElementById('oy_profile_clear').addEventListener('click', function () {
			profileInput.value = '';
			renderProfile(profileInput, profilePreview);
		});

		document.querySelectorAll('[data-oy-media-list]').forEach(initMediaList);
		document.querySelectorAll('[data-oy-link-rows]').forEach(initLinkRows);
	});
})(jQuery);
