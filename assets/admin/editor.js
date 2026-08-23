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
		var source = root.querySelector('[data-oy-combobox-options]');

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

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-oy-combobox]').forEach(initCombobox);
		initRail();
		initAudience();
		initUnlistedBuilder();
		initMap();
		initEnterGuard();
	});
})();
