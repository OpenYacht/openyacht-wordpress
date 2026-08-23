/**
 * OpenYacht listing editor behaviors.
 *
 * - Searchable combobox for long registries (builder: 358 entries), the
 *   ARIA combobox pattern with keyboard navigation. Filtering matches
 *   anywhere in the label; the hint column (e.g. builder country) renders
 *   right-aligned and muted.
 * - Section rail scroll-spy: the brass marker follows the section in view.
 */
(function () {
	'use strict';

	/* ---------- searchable combobox ---------- */

	function initCombobox(root) {
		var hidden = root.querySelector('input[type="hidden"]');
		var input = root.querySelector('[data-oy-combobox-input]');
		var list = root.querySelector('[role="listbox"]');
		// Repeated comboboxes (feature rows) share one options tag by id
		// instead of embedding the list in every row.
		var sourceId = root.getAttribute('data-oy-combobox-source');
		var source = sourceId ? document.getElementById(sourceId) : root.querySelector('[data-oy-combobox-options]');

		if (!hidden || !input || !list || !source) {
			return;
		}

		var options;

		try {
			options = JSON.parse(source.textContent);
		} catch (e) {
			return;
		}

		var highlighted = -1;
		var visible = [];

		function labelFor(value) {
			var match = options.find(function (option) { return option.value === value; });
			return match ? match.label : '';
		}

		function render(filter) {
			var query = (filter || '').trim().toLowerCase();
			visible = options.filter(function (option) {
				return query === '' || option.label.toLowerCase().indexOf(query) !== -1;
			});
			list.textContent = '';

			if (!visible.length) {
				var empty = document.createElement('li');
				empty.className = 'oy-combobox-empty';
				empty.setAttribute('role', 'presentation');
				empty.textContent = list.dataset.emptyText || 'No matches';
				list.appendChild(empty);
				return;
			}

			visible.forEach(function (option, index) {
				var item = document.createElement('li');
				item.className = 'oy-option';
				item.setAttribute('role', 'option');
				item.id = list.id + '-' + index;
				item.setAttribute('aria-selected', option.value === hidden.value ? 'true' : 'false');

				var label = document.createElement('span');
				label.textContent = option.label;
				item.appendChild(label);

				if (option.hint) {
					var hint = document.createElement('span');
					hint.className = 'oy-option-hint';
					hint.textContent = option.hint;
					item.appendChild(hint);
				}

				item.addEventListener('mousedown', function (event) {
					event.preventDefault();
					select(index);
				});
				list.appendChild(item);
			});

			// Open on the committed option, never index 0: ArrowDown+Enter
			// must not silently clear the selection.
			var committed = visible.findIndex(function (option) { return option.value === hidden.value; });
			highlight(committed >= 0 ? committed : 0);
		}

		function highlight(index) {
			var items = list.querySelectorAll('.oy-option');
			items.forEach(function (item) { item.classList.remove('is-highlighted'); });
			highlighted = Math.max(0, Math.min(index, visible.length - 1));

			var current = items[highlighted];

			if (current) {
				current.classList.add('is-highlighted');
				input.setAttribute('aria-activedescendant', current.id);
				current.scrollIntoView({ block: 'nearest' });
			}
		}

		function open(filter) {
			render(filter);
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		function close() {
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}

		function select(index) {
			var option = visible[index];

			if (!option) {
				return;
			}

			hidden.value = option.value;
			input.value = option.value === '' ? '' : option.label;
			close();
			input.dispatchEvent(new CustomEvent('oy:combobox-change', { bubbles: true, detail: option }));
		}

		input.addEventListener('focus', function () { open(''); input.select(); });
		input.addEventListener('input', function () { open(input.value); });
		input.addEventListener('blur', function () {
			// Restore the committed label if the typed text was never selected.
			input.value = labelFor(hidden.value);
			close();
		});
		input.addEventListener('keydown', function (event) {
			if (list.hidden && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
				open(input.value);
				event.preventDefault();
				return;
			}

			switch (event.key) {
				case 'ArrowDown':
					highlight(highlighted + 1);
					event.preventDefault();
					break;
				case 'ArrowUp':
					highlight(highlighted - 1);
					event.preventDefault();
					break;
				case 'Enter':
					if (!list.hidden) {
						select(highlighted);
						event.preventDefault();
					}
					break;
				case 'Escape':
					close();
					break;
			}
		});

		input.value = labelFor(hidden.value);
	}

	/* ---------- section rail ---------- */

	function initRail() {
		var links = Array.prototype.slice.call(document.querySelectorAll('.oy-rail-link[href^="#"]'));
		var sections = links
			.map(function (link) { return document.getElementById(link.getAttribute('href').slice(1)); })
			.filter(Boolean);

		if (!sections.length || !('IntersectionObserver' in window)) {
			return;
		}

		var current = null;

		function setCurrent(id) {
			if (current === id) {
				return;
			}

			current = id;
			links.forEach(function (link) {
				link.classList.toggle('is-current', link.getAttribute('href') === '#' + id);
			});
		}

		var observer = new IntersectionObserver(function (entries) {
			var inView = entries
				.filter(function (entry) { return entry.isIntersecting; })
				.sort(function (a, b) { return a.boundingClientRect.top - b.boundingClientRect.top; });

			if (inView.length) {
				setCurrent(inView[0].target.id);
			}
		}, { rootMargin: '-96px 0px -60% 0px' });

		sections.forEach(function (section) { observer.observe(section); });
		setCurrent(sections[0].id);
	}

	/* ---------- audience gating ---------- */

	function initAudience() {
		var radios = document.querySelectorAll('input[name="oy[audience]"]');
		var container = document.querySelector('[data-oy-audience-partners]');

		if (!radios.length || !container) {
			return;
		}

		function update() {
			var enabled = false;
			radios.forEach(function (radio) {
				if (radio.checked && radio.value === 'selected') {
					enabled = true;
				}
			});
			container.querySelectorAll('input').forEach(function (input) { input.disabled = !enabled; });
			container.style.opacity = enabled ? '' : '0.45';
		}

		radios.forEach(function (radio) { radio.addEventListener('change', update); });
		update();
	}

	/* ---------- unlisted builder visibility ---------- */

	function initUnlistedBuilder() {
		var builderInput = document.getElementById('oy_builder');
		var wrapper = document.querySelector('[data-oy-unlisted-builder]');

		if (!builderInput || !wrapper) {
			return;
		}

		builderInput.addEventListener('oy:combobox-change', function (event) {
			wrapper.hidden = event.detail.value !== '';

			if (event.detail.value !== '') {
				wrapper.querySelector('input').value = '';
			}
		});
	}

	/* ---------- exact-position map (Leaflet over OSM) ---------- */

	function initMap() {
		var el = document.getElementById('oy_map');

		if (!el || typeof window.L === 'undefined') {
			return;
		}

		var latInput = document.getElementById('oy_location_lat');
		var lonInput = document.getElementById('oy_location_lon');
		var map = window.L.map(el, { scrollWheelZoom: false });
		window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		}).addTo(map);

		var icon = window.L.divIcon({ className: 'oy-map-pin', iconSize: [16, 16], iconAnchor: [8, 8] });
		var marker = null;

		function currentPosition() {
			var lat = parseFloat(latInput.value);
			var lon = parseFloat(lonInput.value);
			return isNaN(lat) || isNaN(lon) ? null : [lat, lon];
		}

		function place(latlng, pan) {
			if (!marker) {
				marker = window.L.marker(latlng, { icon: icon, draggable: true }).addTo(map);
				marker.on('dragend', function () {
					var position = marker.getLatLng();
					latInput.value = position.lat.toFixed(6);
					lonInput.value = position.lng.toFixed(6);
				});
			} else {
				marker.setLatLng(latlng);
			}

			if (pan) {
				map.panTo(latlng);
			}
		}

		function syncFromInputs() {
			var position = currentPosition();

			if (position) {
				place(position, true);
			} else if (marker) {
				map.removeLayer(marker);
				marker = null;
			}
		}

		var initial = currentPosition();

		if (initial) {
			map.setView(initial, 10);
			place(initial, false);
		} else {
			map.setView([30, -20], 2);
		}

		map.on('click', function (event) {
			latInput.value = event.latlng.lat.toFixed(6);
			lonInput.value = event.latlng.lng.toFixed(6);
			place(event.latlng, false);
		});

		latInput.addEventListener('change', syncFromInputs);
		lonInput.addEventListener('change', syncFromInputs);
		initMapSearch(map);
	}

	/* Geocoding search (Nominatim, the OSM provider): jumps the map; the
	   precise position stays a deliberate click. */
	function initMapSearch(map) {
		var wrapper = document.querySelector('[data-oy-map-search]');

		if (!wrapper) {
			return;
		}

		var input = wrapper.querySelector('input');
		var button = wrapper.querySelector('button');
		var list = wrapper.querySelector('ul');

		function close() {
			list.hidden = true;
		}

		function showResults(results) {
			list.textContent = '';

			if (!results.length) {
				var empty = document.createElement('li');
				empty.className = 'oy-combobox-empty';
				empty.setAttribute('role', 'presentation');
				empty.textContent = 'No places found';
				list.appendChild(empty);
			}

			results.slice(0, 5).forEach(function (result) {
				var item = document.createElement('li');
				item.className = 'oy-option';
				item.setAttribute('role', 'option');
				var label = document.createElement('span');
				label.textContent = result.display_name;
				item.appendChild(label);
				item.addEventListener('mousedown', function (event) {
					event.preventDefault();
					map.setView([parseFloat(result.lat), parseFloat(result.lon)], 13);
					input.value = result.display_name;
					close();
				});
				list.appendChild(item);
			});

			list.hidden = false;
		}

		function search() {
			var query = input.value.trim();

			if (query.length < 2) {
				return;
			}

			fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&q=' + encodeURIComponent(query), {
				headers: { 'Accept': 'application/json' }
			})
				.then(function (response) { return response.json(); })
				.then(showResults)
				.catch(close);
		}

		button.addEventListener('click', search);
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				search();
			} else if (event.key === 'Escape') {
				close();
			}
		});
		input.addEventListener('blur', function () { window.setTimeout(close, 150); });
	}

	/* ---------- repeatable blocks (descriptions, features) ---------- */

	var repeaterCounter = 1000; // never collides with server-rendered indexes

	function initRepeater(containerSelector, templateId, addButtonId, onAdd, onRemove) {
		var container = document.querySelector(containerSelector);
		var template = document.getElementById(templateId);
		var addButton = document.getElementById(addButtonId);

		if (!container || !template || !addButton) {
			return;
		}

		addButton.addEventListener('click', function () {
			var html = template.innerHTML.split('__INDEX__').join(String(repeaterCounter++));
			var host = document.createElement('div');
			host.innerHTML = html;
			var block = host.firstElementChild;
			container.appendChild(block);

			if (onAdd) {
				onAdd(block);
			}
		});

		container.addEventListener('click', function (event) {
			var button = event.target.closest('[data-oy-remove-block]');

			if (!button) {
				return;
			}

			var block = button.closest('[data-oy-block]');

			if (block) {
				if (onRemove) {
					onRemove(block);
				}

				block.remove();
			}
		});
	}

	var RESTRICTED_TINYMCE = {
		toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,removeformat,undo,redo',
		toolbar2: '',
		block_formats: 'Paragraph=p;Heading 3=h3;Heading 4=h4',
		valid_elements: 'p,br,ul,ol,li,strong/b,em/i,h3,h4,a[href|rel|target]'
	};

	function initDescriptionBlocks() {
		initRepeater('[data-oy-desc-blocks]', 'oy_desc_template', 'oy_desc_add', function (block) {
			var textarea = block.querySelector('textarea');

			if (textarea && window.wp && wp.editor && wp.editor.initialize) {
				wp.editor.initialize(textarea.id, {
					tinymce: RESTRICTED_TINYMCE,
					quicktags: { buttons: 'strong,em,ul,ol,li,link' },
					mediaButtons: false
				});
			}
		}, function (block) {
			var textarea = block.querySelector('textarea');

			if (textarea && window.wp && wp.editor && wp.editor.remove) {
				wp.editor.remove(textarea.id);
			}
		});
	}

	/* ---------- no implicit submit ---------- */

	function initEnterGuard() {
		var editor = document.getElementById('openyacht-editor');
		var form = editor ? editor.querySelector('form') : null;

		if (!form) {
			return;
		}

		// A long editor should never save because Enter was pressed in a
		// text field; saving is the explicit button's job.
		form.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter') {
				return;
			}

			var target = event.target;

			if (target.tagName === 'INPUT' && target.type !== 'submit' && target.type !== 'button') {
				event.preventDefault();
			}
		});
	}

	/* ---------- feature slug linking ---------- */

	/**
	 * The slug select drives the feature row: picking a vocabulary entry
	 * autofills the name and category, which stay editable afterwards —
	 * the visible slug is the identity, the text is display truth. To
	 * mean something else, change the slug (or set it to No link).
	 */
	function initFeatureRows() {
		var container = document.querySelector('[data-oy-feature-rows]');

		if (!container) {
			return;
		}

		container.addEventListener('oy:combobox-change', function (event) {
			var slugBox = event.target.closest('[data-oy-feature-slug]');

			if (!slugBox || !event.detail || event.detail.value === '') {
				return;
			}

			var row = slugBox.closest('[data-oy-block]');
			var name = row.querySelector('[data-oy-feature-name]');
			var category = row.querySelector('[data-oy-feature-category]');

			if (name) {
				name.value = event.detail.label;
			}
			if (category && event.detail.hint) {
				category.value = event.detail.hint;
			}
		});
	}

	/* ---------- audience partner typeahead ---------- */

	/**
	 * Once the partner list outgrows checkboxes, partners are added via a
	 * typeahead: picking one appends a removable row with a hidden input
	 * and resets the picker for the next search.
	 */
	function initPartnerPicker() {
		var picker = document.querySelector('[data-oy-partner-picker]');
		var picked = document.querySelector('[data-oy-partner-picked]');

		if (!picker || !picked) {
			return;
		}

		picker.addEventListener('oy:combobox-change', function (event) {
			var option = event.detail;

			if (!option || option.value === '') {
				return;
			}

			var already = picked.querySelector('input[value="' + option.value + '"]');

			if (!already) {
				var row = document.createElement('span');
				row.className = 'flex items-center gap-2';
				row.setAttribute('data-oy-partner-row', '');

				var hidden = document.createElement('input');
				hidden.type = 'hidden';
				hidden.name = 'oy[audience_partners][]';
				hidden.value = option.value;
				row.appendChild(hidden);

				var label = document.createElement('span');
				label.className = 'oy-media-name !flex-none';
				label.textContent = option.label;
				row.appendChild(label);

				var remove = document.createElement('button');
				remove.type = 'button';
				remove.className = 'oy-row-x !w-6 !h-6';
				remove.setAttribute('data-oy-partner-remove', '');
				remove.setAttribute('aria-label', 'Remove partner');
				remove.innerHTML = '&times;';
				row.appendChild(remove);

				picked.appendChild(row);
			}

			// Reset for the next search.
			picker.querySelector('input[type="hidden"]').value = '';
			var input = picker.querySelector('[data-oy-combobox-input]');
			input.value = '';
			input.focus();
		});

		picked.addEventListener('click', function (event) {
			if (event.target.closest('[data-oy-partner-remove]')) {
				event.target.closest('[data-oy-partner-row]').remove();
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-oy-combobox]').forEach(initCombobox);
		initPartnerPicker();
		initRail();
		initAudience();
		initUnlistedBuilder();
		initMap();
		initEnterGuard();
		initDescriptionBlocks();
		initRepeater('[data-oy-feature-rows]', 'oy_feature_template', 'oy_feature_add', function (block) {
			block.querySelectorAll('[data-oy-combobox]').forEach(initCombobox);
		});
		initFeatureRows();
	});
})();
