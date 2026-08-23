/**
 * Media handling for the listing form: a single profile image, the gallery
 * as one box per wire category (drag between boxes to recategorise),
 * orderable boxes for layouts and PDF documents, and external-link rows
 * (videos, tours). Attachment IDs land in ordered hidden inputs — DOM
 * order is submission order is wire sort — and the server computes the
 * wire facts (URL, hash, dimensions, caption) at save time. Alt text is
 * edited inline and written back to the attachment; captions are edited
 * in the media dialog's sidebar.
 */
(function ($) {
	'use strict';

	var counter = 5000;
	var dragged = null; // shared so items can move between boxes in a group
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
		if (placeholder) {
			placeholder.style.display = list.querySelector('[data-oy-media-item]') ? 'none' : '';
		}
	}

	function refreshGroup(group) {
		document.querySelectorAll('[data-oy-drag-group="' + group + '"]').forEach(refreshPlaceholder);
	}

	/* ---- Orderable attachment boxes ---- */

	function groupIds(group) {
		var ids = [];
		document.querySelectorAll('[data-oy-drag-group="' + group + '"] [data-oy-media-item] input[type="hidden"][name$="[id]"]').forEach(function (input) {
			ids.push(parseInt(input.value, 10));
		});
		return ids;
	}

	function buildItem(list, attachment) {
		var inputName = list.getAttribute('data-input-name');
		var withAlt = list.hasAttribute('data-with-alt');
		var category = list.getAttribute('data-category'); // null when the box has no category axis
		var index = 'js-' + (counter++);

		var item = document.createElement('div');
		item.className = 'oy-media-item';
		item.setAttribute('data-oy-media-item', '');

		var handle = document.createElement('span');
		handle.className = 'oy-drag';
		handle.setAttribute('data-oy-drag-handle', '');
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

		var body = document.createElement('span');
		body.className = 'oy-media-body';
		var name = document.createElement('span');
		name.className = 'oy-media-name';
		name.textContent = attachment.title || attachment.filename || ('#' + attachment.id);
		body.appendChild(name);
		if (withAlt) {
			var alt = document.createElement('input');
			alt.className = 'oy-media-alt';
			alt.name = 'oy[' + inputName + '][' + index + '][alt]';
			alt.value = attachment.alt || '';
			alt.placeholder = 'Alt text';
			alt.setAttribute('aria-label', 'Image alt text');
			body.appendChild(alt);
		}
		item.appendChild(body);

		if (category !== null) {
			var categoryInput = document.createElement('input');
			categoryInput.type = 'hidden';
			categoryInput.setAttribute('data-oy-category-input', '');
			categoryInput.name = 'oy[' + inputName + '][' + index + '][category]';
			categoryInput.value = category;
			item.appendChild(categoryInput);
		}

		var id = document.createElement('input');
		id.type = 'hidden';
		id.name = 'oy[' + inputName + '][' + index + '][id]';
		id.value = attachment.id;
		item.appendChild(id);

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
		var listKey = list.getAttribute('data-oy-media-list');
		var group = list.getAttribute('data-oy-drag-group');
		var addButton = document.querySelector('[data-oy-media-add="' + listKey + '"]');

		if (addButton) {
			addButton.addEventListener('click', function () {
				var frame = wp.media({
					title: addButton.textContent.trim(),
					library: { type: addButton.getAttribute('data-media-type') || 'image' },
					multiple: 'add'
				});
				frame.on('select', function () {
					var present = groupIds(group); // an image lives in one box at a time
					frame.state().get('selection').each(function (attachment) {
						if (present.indexOf(attachment.id) === -1) {
							list.appendChild(buildItem(list, attachment.attributes));
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

		initDragTarget(list, group);
	}

	/* ---- Drag to reorder / recategorise (DOM order = wire sort) ---- */

	function initDragTarget(list, group) {
		// Dragging starts only from the handle: the card holds text inputs,
		// and a fully draggable card breaks text selection inside them.
		list.addEventListener('mousedown', function (event) {
			if (event.target.closest('[data-oy-drag-handle]')) {
				event.target.closest('[data-oy-media-item]').draggable = true;
			}
		});

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
				dragged.draggable = false;
				dragged = null;
			}
			refreshGroup(group);
		});

		list.addEventListener('dragover', function (event) {
			if (!dragged || dragged.parentElement.getAttribute('data-oy-drag-group') !== group) {
				return;
			}
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';

			var over = event.target.closest('[data-oy-media-item]');
			if (over === dragged) {
				return;
			}
			if (over) {
				var rect = over.getBoundingClientRect();
				list.insertBefore(dragged, event.clientY < rect.top + rect.height / 2 ? over : over.nextSibling);
			} else if (dragged.parentElement !== list) {
				list.appendChild(dragged); // dropped onto an empty box
			}
			syncCategory(dragged, list);
			refreshGroup(group);
		});

		list.addEventListener('drop', function (event) {
			event.preventDefault();
		});
	}

	function syncCategory(item, list) {
		var input = item.querySelector('[data-oy-category-input]');
		var category = list.getAttribute('data-category');
		if (input && category !== null) {
			input.value = category;
		}
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
				container.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(counter++)));
				var url = container.lastElementChild.querySelector('input[type="url"]');
				if (url) {
					url.focus();
				}
			});
		}

		container.addEventListener('click', function (event) {
			if (event.target.closest('[data-oy-remove-block]')) {
				var row = event.target.closest('[data-oy-block]');
				if (container.querySelectorAll('[data-oy-block]').length === 1) {
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
