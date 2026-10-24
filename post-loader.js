/* Shared bounded requests and safe post links; each tree owns its branch controls. */
(() => {
    'use strict';
    if (window.ContentTreesPosts) return;
    const queue = [];
    let active = 0;
    function drain() {
        while (active < 3 && queue.length) {
            const task = queue.shift();
            active++;
            Promise.resolve().then(task).finally(() => { active--; drain(); });
        }
    }
    function endpoint(base, path, parameters = {}) {
        const url = new URL(base, document.baseURI);
        if (url.searchParams.has('rest_route')) {
            url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + path);
        } else {
            url.pathname = url.pathname.replace(/\/$/, '') + path;
        }
        Object.entries(parameters).forEach(([name, value]) => {
            if (value !== null && value !== undefined && value !== '') url.searchParams.set(name, value);
        });
        return url;
    }
    function content(result, currentId) {
        if (!result || !Array.isArray(result.items)) throw new Error('Invalid response');
        const items = result.items.map(item => {
            if (!Number.isInteger(item.id) || item.id <= 0 || typeof item.label !== 'string' || typeof item.url !== 'string') throw new Error('Invalid post');
            const url = new URL(item.url, document.baseURI);
            if (!['http:', 'https:'].includes(url.protocol)) throw new Error('Invalid URL');
            return {...item, url: url.href};
        });
        const fragment = document.createDocumentFragment();
        items.forEach(item => {
            const link = document.createElement('a');
            link.href = item.url;
            link.textContent = item.label;
            link.dataset.postId = String(item.id);
            if (String(item.id) === String(currentId)) link.setAttribute('aria-current', 'page');
            const li = document.createElement('li');
            li.append(link);
            fragment.append(li);
        });
        return {fragment, count: items.length};
    }
    window.ContentTreesPosts = {endpoint, content, enqueue(task) { queue.push(task); drain(); }};
})();
