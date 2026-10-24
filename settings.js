/* Classic Widgets/Customizer only. Core apiFetch supplies cookie authentication and the REST nonce. */
(() => {
    'use strict';
    const initialized = new WeakSet();
    function find(scope, selector) {
        return [...(scope.matches?.(selector) ? [scope] : []), ...scope.querySelectorAll(selector)];
    }
    function initialize(scope) {
        find(scope, '.ctrees-taxonomy-settings').forEach(root => {
            if (initialized.has(root)) return;
            initialized.add(root);
            const showPosts = root.querySelector('.ctrees-taxonomy-show-posts');
            const postOptions = root.querySelector('.ctrees-taxonomy-post-options');
            const visibility = () => { postOptions.hidden = !showPosts.checked; };
            showPosts.addEventListener('change', visibility);
            visibility();
            root.querySelectorAll('.ctrees-taxonomy-ordering').forEach(group => {
                const sort = group.querySelector('.ctrees-taxonomy-sort');
                const order = group.querySelector('.ctrees-taxonomy-order');
                const labels = JSON.parse(group.dataset.orderLabels);
                function updateLabels() {
                    order.querySelector('[value="ASC"]').textContent = labels[sort.value][0];
                    order.querySelector('[value="DESC"]').textContent = labels[sort.value][1];
                }
                sort.addEventListener('change', updateLabels);
                updateLabels();
            });
        });
        find(scope, '.ctrees-page-sort').forEach(sort => {
            if (initialized.has(sort)) return;
            const order = (sort.closest('.widget-content') || sort.closest('form'))?.querySelector('.ctrees-page-order');
            if (!order) return;
            initialized.add(sort);
            function labels() {
                const dateSort = ['date', 'modified'].includes(sort.value);
                order.querySelector('[value="ASC"]').textContent = sort.value === 'title' ? order.dataset.titleAsc : (dateSort ? order.dataset.dateAsc : order.dataset.asc);
                order.querySelector('[value="DESC"]').textContent = sort.value === 'title' ? order.dataset.titleDesc : (dateSort ? order.dataset.dateDesc : order.dataset.desc);
            }
            sort.addEventListener('change', labels);
            labels();
        });
        find(scope, '.ctrees-exclusions').forEach(root => {
            if (initialized.has(root)) return;
            initialized.add(root);
            const input = root.querySelector('.ctrees-post-search');
            const saved = root.querySelector('.ctrees-excluded-ids');
            const selected = root.querySelector('.ctrees-selected-posts');
            const results = root.querySelector('.ctrees-search-results');
            const status = root.querySelector('.ctrees-search-status');
            const single = root.dataset.single === '1';
            let timer, revision = 0;
            const ids = () => saved.value.split(',').filter(Boolean).map(Number);
            function persist(values) {
                saved.value = values.join(',');
                saved.dispatchEvent(new Event('change', {bubbles: true}));
            }
            root.addEventListener('click', event => {
                const button = event.target.closest('[data-remove-id]');
                if (!button) return;
                persist(single ? [] : ids().filter(id => id !== Number(button.dataset.removeId)));
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
                        const endpoint = root.dataset.endpoint || '/content-trees/v1/post-options';
                        const data = await wp.apiFetch({path: endpoint + '?search=' + encodeURIComponent(input.value)});
                        if (current !== revision || !root.isConnected) return;
                        status.textContent = data.items.length ? root.dataset.found : root.dataset.empty;
                        data.items.filter(item => single || !ids().includes(item.id)).forEach(item => {
                            const li = document.createElement('li');
                            const button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'button-link';
                            button.textContent = item.label + ' (#' + item.id + ')';
                            button.addEventListener('click', () => {
                                if (!single && ids().includes(item.id)) return;
                                persist(single ? [item.id] : [...ids(), item.id]);
                                if (single) selected.replaceChildren();
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
    // Core emits these for classic widgets, the Customizer and legacy widget blocks.
    jQuery(document).on('widget-added widget-updated', (event, widget) => {
        if (widget?.[0]) initialize(widget[0]);
    });
    jQuery(() => initialize(document));
})();
