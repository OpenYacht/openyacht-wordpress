/**
 * Minimal lightbox for the synced-listing preview gallery. Thumbs are
 * anchors marked data-openyacht-lightbox whose href is the largest
 * rendition; arrow keys / buttons navigate, Esc or backdrop closes.
 * No dependencies, built lazily on first open.
 */
(function () {
	'use strict';

	var overlay = null;
	var items = [];
	var index = 0;

	function collect() {
		items = Array.prototype.slice.call(document.querySelectorAll('[data-openyacht-lightbox]'));
	}

	function build() {
		overlay = document.createElement('div');
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.style.cssText = 'position:fixed;inset:0;z-index:100200;background:rgba(10,15,20,.92);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;';

		var img = document.createElement('img');
		img.style.cssText = 'max-width:92vw;max-height:84vh;border-radius:4px;box-shadow:0 8px 40px rgba(0,0,0,.5);';
		overlay.appendChild(img);

		var bar = document.createElement('div');
		bar.style.cssText = 'color:#c3c4c7;font-size:13px;display:flex;gap:16px;align-items:center;';
		var caption = document.createElement('span');
		var counter = document.createElement('span');
		bar.appendChild(caption);
		bar.appendChild(counter);
		overlay.appendChild(bar);

		function navButton(label, delta, side) {
			var button = document.createElement('button');
			button.type = 'button';
			button.textContent = label;
			button.setAttribute('aria-label', delta < 0 ? 'Previous image' : 'Next image');
			button.style.cssText = 'position:absolute;top:50%;transform:translateY(-50%);' + side + ':16px;background:rgba(255,255,255,.12);color:#fff;border:0;border-radius:4px;font-size:26px;line-height:1;padding:10px 14px;cursor:pointer;';
			button.addEventListener('click', function (event) {
				event.stopPropagation();
				show(index + delta);
			});
			overlay.appendChild(button);
		}

		navButton('‹', -1, 'left');
		navButton('›', 1, 'right');

		var close = document.createElement('button');
		close.type = 'button';
		close.textContent = '×';
		close.setAttribute('aria-label', 'Close');
		close.style.cssText = 'position:absolute;top:12px;right:16px;background:none;color:#fff;border:0;font-size:32px;line-height:1;cursor:pointer;';
		close.addEventListener('click', hide);
		overlay.appendChild(close);

		overlay.addEventListener('click', function (event) {
			if (event.target === overlay) {
				hide();
			}
		});

		overlay.update = function () {
			var item = items[index];
			img.src = item.getAttribute('href');
			caption.textContent = item.getAttribute('data-caption') || '';
			counter.textContent = (index + 1) + ' / ' + items.length;
		};

		document.body.appendChild(overlay);
	}

	function show(next) {
		index = (next + items.length) % items.length;
		if (!overlay) {
			build();
		}
		overlay.style.display = 'flex';
		overlay.update();
	}

	function hide() {
		if (overlay) {
			overlay.style.display = 'none';
		}
	}

	document.addEventListener('click', function (event) {
		var anchor = event.target.closest ? event.target.closest('[data-openyacht-lightbox]') : null;
		if (!anchor) {
			return;
		}
		event.preventDefault();
		collect();
		show(items.indexOf(anchor));
	});

	document.addEventListener('keydown', function (event) {
		if (!overlay || overlay.style.display === 'none') {
			return;
		}
		if (event.key === 'Escape') {
			hide();
		} else if (event.key === 'ArrowLeft') {
			show(index - 1);
		} else if (event.key === 'ArrowRight') {
			show(index + 1);
		}
	});
})();
