/* Complete taxonomy HTML needs only per-instance bulk/sibling enhancement. */
(() => {
    'use strict';
    document.querySelectorAll('.ctrees-taxonomy-tree[data-taxonomy-messages]').forEach(tree => {
        if (tree.dataset.taxonomyInitialized === '1') return;
        tree.dataset.taxonomyInitialized = '1';
        const controls = tree.querySelector('.ctrees-controls');
        const messages = JSON.parse(tree.dataset.taxonomyMessages);
        let announcement;
        if (controls) {
            announcement = document.createElement('p');
            announcement.className = 'ctrees-announcement';
            announcement.setAttribute('role', 'status');
            announcement.setAttribute('aria-live', 'polite');
            announcement.setAttribute('aria-atomic', 'true');
            tree.append(announcement);
            controls.hidden = false;
        }
        tree.addEventListener('click', event => {
            const button = event.target.closest('button[data-ctrees-action]');
            if (button && controls?.contains(button)) {
                const open = button.dataset.ctreesAction === 'open';
                tree.querySelectorAll('details.ctrees-term-branch').forEach(branch => { branch.open = open; });
                const message = open ? messages.expanded : messages.collapsed;
                if (announcement.textContent !== message) announcement.textContent = message;
                return;
            }
            const summary = event.target.closest('summary');
            if (tree.dataset.closeSiblings !== '1' || !summary || event.target.closest('a')) return;
            const branch = summary.parentElement;
            if (branch.open || !branch.matches('details.ctrees-term-branch')) return;
            [...branch.parentElement.parentElement.children].forEach(item => {
                const sibling = item.firstElementChild;
                if (sibling !== branch && sibling?.matches('details.ctrees-term-branch')) {
                    sibling.open = false;
                    sibling.querySelectorAll('details').forEach(child => { child.open = false; });
                }
            });
        });
    });
})();
