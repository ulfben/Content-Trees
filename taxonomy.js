/* Native term navigation is immediate; complete post lists load when disclosed. */
(() => {
    'use strict';
    const posts = window.ContentTreesPosts;
    document.querySelectorAll('.ctrees-taxonomy-tree[data-taxonomy-messages]').forEach(tree => {
        if (tree.dataset.taxonomyInitialized === '1') return;
        tree.dataset.taxonomyInitialized = '1';
        const controls = tree.querySelector('.ctrees-controls');
        const messages = JSON.parse(tree.dataset.taxonomyMessages);
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
            while (current) {
                if (!current.open) return false;
                current = current.parentElement?.closest('details.ctrees-term-branch');
            }
            return true;
        }
        function finish(branch, failed, message) {
            if (batch && batch.branches.delete(branch)) {
                batch.failed = batch.failed || failed;
                if (!batch.branches.size) {
                    announce(batch.failed ? messages.bulkError : messages.bulkComplete);
                    batch = null;
                }
            } else if (!batch && isVisible(branch) && message) {
                announce(message);
            }
        }
        tree.querySelectorAll('details.ctrees-term-branch[data-has-posts="1"]').forEach(branch => {
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
                if (pending || branch.dataset.loaded === '1' || !isVisible(branch)) return;
                enhance();
                pending = true;
                posts.enqueue(async () => {
                    if (!isVisible(branch)) { pending = false; finish(branch, false, ''); return; }
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
                        const url = posts.endpoint(tree.dataset.taxonomyEndpoint, '/' + branch.dataset.termId + '/posts', {
                            sort_by: tree.dataset.postSort, order: tree.dataset.postOrder, excluded_posts: tree.dataset.exclude
                        });
                        const response = await fetch(url, {signal: controller.signal, credentials: 'omit'});
                        if (!response.ok) throw new Error('Request failed');
                        const content = posts.content(await response.json(), tree.dataset.currentId);
                        list.replaceChildren(content.fragment);
                        if (isVisible(branch) && focusAfterLoad) (list.querySelector('a') || branch.querySelector('summary')).focus();
                        branch.dataset.loaded = '1';
                        retry.hidden = true;
                        status.textContent = content.count ? '' : messages.empty;
                        if (!content.count) completion = messages.empty;
                    } catch (error) {
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
        tree.querySelectorAll('details.ctrees-term-branch').forEach(branch => {
            branch.addEventListener('toggle', () => {
                if (!branch.open) return;
                loaders.get(branch)?.();
                branch.querySelectorAll('details[open]').forEach(child => loaders.get(child)?.());
            });
        });
        tree.addEventListener('click', event => {
            const button = event.target.closest('button[data-ctrees-action]');
            if (button && controls?.contains(button)) {
                const open = button.dataset.ctreesAction === 'open';
                const pending = open ? [...loaders.keys()].filter(branch => branch.dataset.loaded !== '1') : [];
                batch = pending.length ? {branches: new Set(pending), failed: false} : null;
                tree.querySelectorAll('details.ctrees-term-branch').forEach(branch => { branch.open = open; });
                announce(pending.length ? messages.bulkLoading : (open ? messages.expanded : messages.collapsed));
                if (open) loaders.forEach(load => load());
                return;
            }
            const summary = event.target.closest('summary');
            if (tree.dataset.closeSiblings !== '1' || !summary || event.target.closest('a')) return;
            const branch = summary.parentElement;
            if (branch.open || !branch.matches('details.ctrees-term-branch')) return;
            if (batch) { batch = null; announce(''); }
            [...branch.parentElement.parentElement.children].forEach(item => {
                const sibling = item.firstElementChild;
                if (sibling !== branch && sibling?.matches('details.ctrees-term-branch')) {
                    sibling.open = false;
                    sibling.querySelectorAll('details').forEach(child => { child.open = false; });
                }
            });
        });
        if (controls || loaders.size) announce('');
        if (controls) controls.hidden = false;
        loaders.forEach(load => load());
    });
})();
