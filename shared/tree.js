/* Shared disclosure controls and complete branch loading. */
(() => {
	'use strict';
	const posts = window.ContentTreesPosts;
	function create(
		tree,
		{ messages, branchSelector, loadSelector, url, currentId, announceInitially },
	) {
		const controls = tree.querySelector('.ctrees-controls');
		const loaders = new Map();
		let batch = null;
		let announcement;
		function announce(message) {
			if (!announcement) {
				announcement = document.createElement('p');
				announcement.className = 'ctrees-announcement';
				announcement.setAttribute('role', 'status');
				announcement.setAttribute('aria-live', 'polite');
				announcement.setAttribute('aria-atomic', 'true');
				tree.append(announcement);
			}
			if (announcement.textContent !== message) announcement.textContent = message;
		}
		function isVisible(branch) {
			if (!branch.isConnected) return false;
			let current = branch;
			while (current && tree.contains(current)) {
				if (!current.open) return false;
				current = current.parentElement?.closest('details');
			}
			return true;
		}
		function finish(branch, failed, message) {
			if (batch && batch.branches.delete(branch)) {
				batch.failed ||= failed;
				if (!batch.branches.size) {
					announce(batch.failed ? messages.bulkError : messages.bulkComplete);
					batch = null;
				}
			} else if (!batch && isVisible(branch) && message) {
				announce(message);
			}
		}
		if (loadSelector)
			tree.querySelectorAll(loadSelector).forEach((branch) => {
				let list, status, retry;
				let pending = false;
				function enhance() {
					if (list) return;
					list = branch.querySelector(':scope > .ctrees-posts');
					if (!list) {
						list = document.createElement('ul');
						list.className = 'ctrees-posts';
						branch.append(list);
					}
					status = document.createElement('p');
					status.className = 'ctrees-status';
					retry = document.createElement('button');
					retry.type = 'button';
					retry.className = 'ctrees-retry';
					retry.textContent = messages.retry;
					retry.hidden = true;
					retry.addEventListener('click', load);
					branch.append(status, retry);
				}
				function load() {
					if (pending || !isVisible(branch)) return;
					enhance();
					if (branch.dataset.loaded === '1') return;
					pending = true;
					posts.enqueue(async () => {
						if (!isVisible(branch)) {
							pending = false;
							finish(branch, false, '');
							return;
						}
						const focusAfterLoad = document.activeElement === retry;
						list.setAttribute('aria-busy', 'true');
						status.textContent = messages.loading;
						retry.disabled = true;
						if (!batch) announce(messages.loading);
						const controller = new AbortController();
						const timeout = setTimeout(() => controller.abort(), 15000);
						let failed = false;
						let completion = messages.loaded;
						try {
							const response = await fetch(url(branch), {
								signal: controller.signal,
								credentials: 'omit',
							});
							if (!response.ok) throw new Error('Request failed');
							const content = posts.content(await response.json(), currentId);
							list.replaceChildren(content.fragment);
							if (isVisible(branch) && focusAfterLoad)
								(
									list.querySelector('a') || branch.querySelector('summary')
								).focus();
							branch.dataset.loaded = '1';
							retry.hidden = true;
							status.textContent = content.count ? '' : messages.empty;
							if (!content.count) completion = messages.empty;
						} catch {
							failed = true;
							completion = messages.error;
							status.textContent = messages.error;
							retry.hidden = false;
						} finally {
							clearTimeout(timeout);
							pending = false;
							retry.disabled = false;
							list.removeAttribute('aria-busy');
							finish(branch, failed, completion);
						}
					});
				}
				loaders.set(branch, load);
			});
		tree.querySelectorAll(branchSelector).forEach((branch) => {
			branch.addEventListener('toggle', () => {
				if (!branch.open) return;
				loaders.get(branch)?.();
				branch.querySelectorAll('details[open]').forEach((child) => loaders.get(child)?.());
			});
		});
		tree.addEventListener('click', (event) => {
			const button = event.target.closest('button[data-ctrees-action]');
			if (button && controls?.contains(button)) {
				const open = button.dataset.ctreesAction === 'open';
				const pending = open
					? [...loaders.keys()].filter((branch) => branch.dataset.loaded !== '1')
					: [];
				batch = pending.length ? { branches: new Set(pending), failed: false } : null;
				tree.querySelectorAll(branchSelector).forEach((branch) => {
					branch.open = open;
				});
				announce(
					pending.length
						? messages.bulkLoading
						: open
							? messages.expanded
							: messages.collapsed,
				);
				if (open) loaders.forEach((load) => load());
				return;
			}
			const summary = event.target.closest('summary');
			if (tree.dataset.closeSiblings !== '1' || !summary || event.target.closest('a')) return;
			const branch = summary.parentElement;
			if (branch.open || !branch.matches(branchSelector)) return;
			if (batch) {
				batch = null;
				announce('');
			}
			const item = branch.parentElement;
			const siblings =
				item.tagName === 'LI'
					? [...item.parentElement.children].map((node) => node.firstElementChild)
					: [...item.children];
			siblings.forEach((sibling) => {
				if (sibling !== branch && sibling?.matches(branchSelector)) {
					sibling.open = false;
					sibling.querySelectorAll('details').forEach((child) => {
						child.open = false;
					});
				}
			});
		});
		if (announceInitially || controls || loaders.size) announce('');
		if (controls) controls.hidden = false;
		loaders.forEach((load) => load());
	}
	window.ContentTrees = { create };
})();
