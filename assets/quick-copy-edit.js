(function () {
	'use strict';

	const config = window.AITranslationWorkflowQuickCopyEdit;
	if (!config || !config.postId || !config.endpoint || !config.nonce) {
		return;
	}

	const labels = config.labels || {};
	const editableSelector = '[data-devenia-qce-path][data-devenia-qce-hash]';
	let active = false;
	let toolbar;
	let statusNode;
	let currentElement;

	function label(name, fallback) {
		return labels[name] || fallback;
	}

	function elements() {
		return Array.from(document.querySelectorAll(editableSelector));
	}

	function requestSave(element, nextText) {
		return fetch(config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify({
				post_id: config.postId,
				path: element.dataset.deveniaQcePath,
				hash: element.dataset.deveniaQceHash,
				text: nextText
			})
		}).then((response) => response.json());
	}

	function toggle() {
		active = !active;
		document.body.classList.toggle('devenia-qce-active', active);
		if (active) {
			enable();
			showStatus(label('active', 'Click text to edit it inline.'), 'info');
		} else {
			disable();
			showStatus(label('inactive', 'Quick Copy Edit is off.'), 'info');
		}
	}

	function enable() {
		elements().forEach((element) => {
			element.dataset.deveniaQceOriginal = editableText(element);
			element.setAttribute('contenteditable', 'plaintext-only');
			element.setAttribute('spellcheck', 'true');
			element.setAttribute('role', 'textbox');
			element.setAttribute('aria-label', element.dataset.deveniaQceLabel || label('open', 'Quick Copy Edit'));
			element.addEventListener('focus', handleFocus);
			element.addEventListener('input', handleInput);
			element.addEventListener('keydown', handleKeydown);
			element.addEventListener('click', preventEditableLinkNavigation);
		});
	}

	function disable() {
		hideToolbar();
		elements().forEach((element) => {
			if (element.dataset.deveniaQceOriginal && element.classList.contains('devenia-qce-dirty')) {
				element.textContent = element.dataset.deveniaQceOriginal;
			}
			element.removeAttribute('contenteditable');
			element.removeAttribute('spellcheck');
			element.removeAttribute('role');
			element.removeAttribute('aria-label');
			element.classList.remove('devenia-qce-dirty', 'devenia-qce-saving');
			element.removeEventListener('focus', handleFocus);
			element.removeEventListener('input', handleInput);
			element.removeEventListener('keydown', handleKeydown);
			element.removeEventListener('click', preventEditableLinkNavigation);
		});
		currentElement = null;
	}

	function handleFocus(event) {
		currentElement = event.currentTarget;
		positionToolbar(currentElement);
	}

	function handleInput(event) {
		const element = event.currentTarget;
		const dirty = editableText(element).trim() !== (element.dataset.deveniaQceOriginal || '').trim();
		element.classList.toggle('devenia-qce-dirty', dirty);
		positionToolbar(element);
	}

	function handleKeydown(event) {
		if (event.key === 'Escape') {
			event.preventDefault();
			cancelEdit(event.currentTarget);
			return;
		}
		if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
			event.preventDefault();
			saveElement(event.currentTarget);
		}
	}

	function preventEditableLinkNavigation(event) {
		if (active && event.currentTarget.tagName.toLowerCase() === 'a') {
			event.preventDefault();
		}
	}

	function editableText(element) {
		return (element.innerText || element.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function ensureToolbar() {
		if (toolbar) {
			return;
		}

		toolbar = document.createElement('div');
		toolbar.className = 'devenia-qce-toolbar';
		toolbar.hidden = true;
		toolbar.innerHTML = `
			<button type="button" class="devenia-qce-toolbar__save">${escapeHtml(label('save', 'Save'))}</button>
			<button type="button" class="devenia-qce-toolbar__cancel">${escapeHtml(label('cancel', 'Cancel'))}</button>
		`;
		statusNode = document.createElement('div');
		statusNode.className = 'devenia-qce-status';
		statusNode.setAttribute('aria-live', 'polite');
		document.body.appendChild(toolbar);
		document.body.appendChild(statusNode);
		toolbar.querySelector('.devenia-qce-toolbar__save').addEventListener('click', () => {
			if (currentElement) {
				saveElement(currentElement);
			}
		});
		toolbar.querySelector('.devenia-qce-toolbar__cancel').addEventListener('click', () => {
			if (currentElement) {
				cancelEdit(currentElement);
			}
		});
	}

	function positionToolbar(element) {
		ensureToolbar();
		currentElement = element;
		const rect = element.getBoundingClientRect();
		toolbar.hidden = false;
		const top = Math.max(42, rect.top + window.scrollY - toolbar.offsetHeight - 8);
		const left = Math.min(
			window.scrollX + document.documentElement.clientWidth - toolbar.offsetWidth - 12,
			Math.max(window.scrollX + 12, rect.left + window.scrollX)
		);
		toolbar.style.top = `${top}px`;
		toolbar.style.left = `${left}px`;
	}

	function hideToolbar() {
		if (toolbar) {
			toolbar.hidden = true;
		}
	}

	function cancelEdit(element) {
		element.textContent = element.dataset.deveniaQceOriginal || '';
		element.classList.remove('devenia-qce-dirty');
		element.blur();
		hideToolbar();
		showStatus(label('unchanged', 'No text change to save.'), 'info');
	}

	function saveElement(element) {
		const next = editableText(element);
		const original = (element.dataset.deveniaQceOriginal || '').trim();
		if (next === original) {
			showStatus(label('unchanged', 'No text change to save.'), 'info');
			return;
		}

		element.classList.add('devenia-qce-saving');
		requestSave(element, next).then((data) => {
			element.classList.remove('devenia-qce-saving');
			if (!data || !data.success) {
				showStatus((data && data.message) || label('error', 'Could not save this text change.'), 'error');
				return;
			}
			if (data.item && data.item.hash) {
				element.dataset.deveniaQceHash = data.item.hash;
			}
			element.dataset.deveniaQceOriginal = next;
			element.classList.remove('devenia-qce-dirty');
			element.blur();
			hideToolbar();
			showStatus(label('saved', 'Saved.'), 'success');
		}).catch(() => {
			element.classList.remove('devenia-qce-saving');
			showStatus(label('error', 'Could not save this text change.'), 'error');
		});
	}

	function showStatus(message, mode) {
		ensureToolbar();
		statusNode.textContent = message || '';
		statusNode.dataset.mode = mode || 'info';
		statusNode.hidden = !message;
		window.clearTimeout(statusNode._deveniaTimer);
		if (message) {
			statusNode._deveniaTimer = window.setTimeout(() => {
				statusNode.hidden = true;
			}, 3000);
		}
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, (char) => ({
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		}[char]));
	}

	document.addEventListener('click', (event) => {
		const target = event.target && event.target.closest ? event.target.closest('#wp-admin-bar-devenia-quick-copy-edit a') : null;
		if (!target) {
			return;
		}
		event.preventDefault();
		toggle();
	});

	window.addEventListener('scroll', () => {
		if (active && currentElement && toolbar && !toolbar.hidden) {
			positionToolbar(currentElement);
		}
	}, { passive: true });

	window.addEventListener('resize', () => {
		if (active && currentElement && toolbar && !toolbar.hidden) {
			positionToolbar(currentElement);
		}
	});
}());
