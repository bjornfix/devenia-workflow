(function () {
	'use strict';

	const config = window.AITranslationWorkflowHeadingFit || {};
	const minScale = Number.isFinite(Number(config.minScale)) ? Number(config.minScale) : 0.88;
	const step = Number.isFinite(Number(config.step)) ? Number(config.step) : 0.02;
	const selector = [
		'.entry-content h1.gb-headline',
		'.entry-content h2.gb-headline',
		'.entry-content h1.wp-block-heading',
		'.entry-content h2.wp-block-heading',
		'.entry-content .gb-headline[class*="-h1"]',
		'.entry-content .gb-headline[class*="-hero"]',
		'.inside-article h1.gb-headline',
		'.inside-article h2.gb-headline',
		'.inside-article h1.wp-block-heading',
		'.inside-article h2.wp-block-heading'
	].join(',');

	function textNodes(element) {
		const nodes = [];
		const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, {
			acceptNode(node) {
				return /\S/.test(node.nodeValue || '') ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
			}
		});

		let node = walker.nextNode();
		while (node) {
			nodes.push(node);
			node = walker.nextNode();
		}

		return nodes;
	}

	function lastWordRange(element) {
		const nodes = textNodes(element);
		for (let index = nodes.length - 1; index >= 0; index -= 1) {
			const node = nodes[index];
			const text = node.nodeValue || '';
			const match = text.match(/[\p{L}\p{N}][\p{L}\p{N}'’.:-]*\s*$/u);
			if (!match || match.index === undefined) {
				continue;
			}

			const start = match.index;
			const end = start + match[0].replace(/\s+$/u, '').length;
			if (end <= start) {
				continue;
			}

			const range = document.createRange();
			range.setStart(node, start);
			range.setEnd(node, end);
			return range;
		}

		return null;
	}

	function visibleRects(range) {
		return Array.from(range.getClientRects()).filter((rect) => rect.width > 1 && rect.height > 1);
	}

	function lastWordIsSplit(element) {
		const range = lastWordRange(element);
		if (!range) {
			return false;
		}

		const rects = visibleRects(range);
		range.detach();
		if (rects.length < 2) {
			return false;
		}

		const firstTop = Math.round(rects[0].top);
		return rects.some((rect) => Math.round(rect.top) !== firstTop);
	}

	function hasBadFit(element) {
		const width = element.clientWidth || element.getBoundingClientRect().width;
		if (width < 120) {
			return false;
		}

		return element.scrollWidth > width + 1 || lastWordIsSplit(element);
	}

	function fitHeading(element) {
		const computed = window.getComputedStyle(element);
		const baseSize = Number.parseFloat(computed.fontSize);
		if (!Number.isFinite(baseSize) || baseSize < 20) {
			return;
		}

		element.classList.add('devenia-heading-fit-target');
		element.style.setProperty('--devenia-heading-fit-base-size', `${baseSize}px`);
		element.style.removeProperty('--devenia-heading-fit-scale');
		element.classList.remove('devenia-heading-fit-applied');

		if (!hasBadFit(element)) {
			return;
		}

		element.classList.add('devenia-heading-fit-applied');
		for (let scale = 1 - step; scale >= minScale; scale -= step) {
			element.style.setProperty('--devenia-heading-fit-scale', scale.toFixed(2));
			if (!hasBadFit(element)) {
				return;
			}
		}

		element.style.setProperty('--devenia-heading-fit-scale', minScale.toFixed(2));
	}

	function fitAll() {
		document.querySelectorAll(selector).forEach(fitHeading);
	}

	function scheduleFit() {
		window.requestAnimationFrame(fitAll);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scheduleFit);
	} else {
		scheduleFit();
	}

	window.addEventListener('load', scheduleFit, { once: true });
	window.addEventListener('resize', scheduleFit);

	if (document.fonts && document.fonts.ready) {
		document.fonts.ready.then(scheduleFit).catch(function () {});
	}
}());
