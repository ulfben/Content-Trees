/* Native disclosures work without this enhancement; archive links remain available. */
(() => {
    'use strict';
    const posts = window.ContentTreesPosts;
    // Pages use the shared .ctrees styling but no archive endpoint/messages.
    // Keep this initializer scoped to archive markup, including cached 0.7 HTML.
    document.querySelectorAll('.ctrees[data-endpoint][data-messages]').forEach(tree => {
        const messages = JSON.parse(tree.dataset.messages);
        // One live region per tree: month status text remains readable in place.
        const announcement = document.createElement('p');
        announcement.className = 'ctrees-announcement';
        announcement.setAttribute('role', 'status');
        announcement.setAttribute('aria-live', 'polite');
        announcement.setAttribute('aria-atomic', 'true');
        tree.append(announcement);
        const loaders = new Map();
        let batch = null;
        function isVisible(month) {
            const year = month.closest('.ctrees-year');
            return month.open && (!year || year.open);
        }
        // Handle individual pointer/keyboard activation before the native toggle.
        // Bulk controls deliberately bypass this rule.
        tree.addEventListener('click', event => {
            const summary = event.target.closest('summary');
            if (tree.dataset.closeSiblings !== '1' || !summary || event.target.closest('a')) return;
            const branch = summary.parentElement;
            if (branch.open) return;
            batch = null;
            [...branch.parentElement.children].forEach(sibling => {
                if (sibling !== branch && sibling.tagName === 'DETAILS') {
                    sibling.open = false;
                    sibling.querySelectorAll('details').forEach(child => { child.open = false; });
                }
            });
        });
        function announce(message) {
            if (announcement.textContent !== message) announcement.textContent = message;
        }
        function finish(month, failed, message) {
            if (batch && batch.months.delete(month)) {
                batch.failed = batch.failed || failed;
                if (!batch.months.size) {
                    announce(batch.failed ? messages.bulkError : messages.bulkComplete);
                    batch = null;
                }
            } else if (!batch && isVisible(month)) {
                announce(message);
            }
        }
        tree.querySelectorAll('.ctrees-month').forEach(month => {
            let list, status, retry;
            let pending = false;
            function enhance() {
                if (list) return;
                list = month.querySelector('.ctrees-posts');
                if (!list) {
                    list = document.createElement('ul');
                    list.className = 'ctrees-posts';
                    month.append(list);
                }
                status = document.createElement('p');
                status.className = 'ctrees-status';
                retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'ctrees-retry';
                retry.textContent = messages.retry;
                retry.hidden = true;
                retry.addEventListener('click', load);
                month.append(status, retry);
            }

            function load() {
                if (pending || month.dataset.loaded === '1') return;
                pending = true;
                posts.enqueue(async () => {
                    // Close All cancels work that has not started, keeping loaded titles.
                    if (!isVisible(month)) { pending = false; finish(month, false, ''); return; }
                    const focusAfterLoad = document.activeElement === retry;
                    list.setAttribute('aria-busy', 'true');
                    status.textContent = messages.loading;
                    if (!batch) announce(messages.loading);
                    retry.disabled = true;
                    const controller = new AbortController();
                    const timeout = setTimeout(() => controller.abort(), 15000);
                    let failed = false;
                    let completion = messages.loaded;
                    try {
                        const [year, monthNumber] = month.dataset.month.split('-');
                        const endpoint = posts.endpoint(tree.dataset.endpoint, `/${year}/${Number(monthNumber)}`, {
                            order: tree.dataset.order === 'ASC' ? 'ASC' : null,
                            excluded_posts: tree.dataset.exclude
                        });
                        const response = await fetch(endpoint, {signal: controller.signal, credentials: 'omit'});
                        if (!response.ok) throw new Error('Request failed');
                        const result = await response.json();
                        const content = posts.content(result, tree.dataset.currentId);
                        list.replaceChildren(content.fragment);
                        // Keep keyboard focus useful when the retry button disappears.
                        if (month.open && focusAfterLoad) {
                            (list.querySelector('a') || month.querySelector('summary')).focus();
                        }
                        month.dataset.loaded = '1';
                        retry.hidden = true;
                        status.textContent = content.count ? '' : messages.empty;
                        if (!content.count) completion = messages.empty;
                    } catch (error) {
                        status.textContent = messages.error;
                        retry.hidden = false;
                        failed = true;
                        completion = messages.error;
                    } finally {
                        clearTimeout(timeout);
                        pending = false;
                        retry.disabled = false;
                        list.removeAttribute('aria-busy');
                        finish(month, failed, completion);
                    }
                });
            }

            loaders.set(month, () => { enhance(); load(); });
            month.addEventListener('toggle', () => {
                if (month.open) {
                    enhance();
                    if (month.dataset.loaded !== '1') load();
                }
            });
            if (month.open) {
                enhance();
                if (month.dataset.loaded !== '1') load();
            }
        });
        const controls = tree.querySelector('.ctrees-controls');
        if (controls) {
            controls.querySelectorAll('button').forEach(button => {
                button.addEventListener('click', () => {
                    const open = button.dataset.ctreesAction === 'open';
                    if (open) {
                        const months = new Set([...loaders.keys()].filter(month => month.dataset.loaded !== '1'));
                        batch = months.size ? {months, failed: false} : null;
                        announce(batch ? messages.bulkLoading : messages.bulkOpened);
                    } else {
                        // In-flight and queued work must not announce after Close All.
                        batch = null;
                        announce(messages.bulkClosed);
                    }
                    tree.querySelectorAll('details').forEach(branch => { branch.open = open; });
                    // Start the batch together instead of waiting for toggle events.
                    if (open) loaders.forEach(load => load());
                });
            });
            controls.hidden = false;
        }
    });
})();
