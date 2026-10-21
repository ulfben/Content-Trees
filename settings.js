/* Classic Widgets/Customizer only. Core apiFetch supplies cookie authentication and the REST nonce. */
(() => {
    'use strict';
    const initialized = new WeakSet();
    function initialize() {
        document.querySelectorAll('.ctrees-exclusions').forEach(root => {
            if (initialized.has(root)) return;
            initialized.add(root);
            const input = root.querySelector('.ctrees-post-search');
            const saved = root.querySelector('.ctrees-excluded-ids');
            const selected = root.querySelector('.ctrees-selected-posts');
            const results = root.querySelector('.ctrees-search-results');
            const status = root.querySelector('.ctrees-search-status');
            let timer, revision = 0;
            const ids = () => saved.value.split(',').filter(Boolean).map(Number);
            function persist(values) {
                saved.value = values.join(',');
                saved.dispatchEvent(new Event('change', {bubbles: true}));
            }
            root.addEventListener('click', event => {
                const button = event.target.closest('[data-remove-id]');
                if (!button) return;
                persist(ids().filter(id => id !== Number(button.dataset.removeId)));
                button.closest('li').remove();
                input.focus();
            });
            input.addEventListener('input', () => {
                clearTimeout(timer);
                const current = ++revision;
                results.replaceChildren();
                status.textContent = '';
                if (!input.value.trim()) return;
                timer = setTimeout(async () => {
                    try {
                        const data = await wp.apiFetch({path: '/content-trees/v1/post-options?search=' + encodeURIComponent(input.value)});
                        if (current !== revision || !root.isConnected) return;
                        status.textContent = data.items.length ? root.dataset.found : root.dataset.empty;
                        data.items.filter(item => !ids().includes(item.id)).forEach(item => {
                            const li = document.createElement('li');
                            const button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'button-link';
                            button.textContent = item.label + ' (#' + item.id + ')';
                            button.addEventListener('click', () => {
                                if (ids().includes(item.id)) return;
                                persist([...ids(), item.id]);
                                const chosen = document.createElement('li');
                                const remove = document.createElement('button');
                                remove.type = 'button';
                                remove.className = 'button-link';
                                remove.dataset.removeId = item.id;
                                remove.textContent = root.dataset.remove + ': ' + button.textContent;
                                chosen.append(remove); selected.append(chosen);
                                li.remove(); input.focus();
                            });
                            li.append(button); results.append(li);
                        });
                    } catch (error) {
                        if (current === revision && root.isConnected) status.textContent = root.dataset.error;
                    }
                }, 300);
            });
        });
    }
    initialize();
    new MutationObserver(initialize).observe(document.body, {subtree: true, childList: true});
})();
