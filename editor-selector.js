/* Shared editor control; provider scripts supply their endpoint and wording. */
(function (wp) {
    'use strict';
    const el = wp.element.createElement;
    const {useState, useEffect, useRef} = wp.element;
    const {TextControl, Button} = wp.components;
    const __ = wp.i18n.__;

    function Selector({value, onChange, endpoint, label, help, single = false, messages = {}}) {
        const [search, setSearch] = useState('');
        const [results, setResults] = useState([]);
        const [labels, setLabels] = useState({endpoint, items: {}});
        const [status, setStatus] = useState('');
        const [lookupStatus, setLookupStatus] = useState('');
        const root = useRef(null);
        const knownLabels = labels.endpoint === endpoint ? labels.items : {};
        function remember(items) {
            setLabels(previous => ({endpoint, items: {
                ...(previous.endpoint === endpoint ? previous.items : {}),
                ...Object.fromEntries(items.map(item => [item.id, item.label]))
            }}));
        }
        function change(next) {
            onChange(next);
            root.current?.querySelector('input')?.focus();
        }
        useEffect(() => {
            let cancelled = false;
            setLookupStatus('');
            if (value.length) wp.apiFetch({path: endpoint + '?include=' + value.join(',')})
                .then(data => { if (!cancelled) remember(data.items); })
                .catch(() => {
                    if (!cancelled) setLookupStatus(messages.lookupError || __('Saved labels could not be loaded. Choices are retained.', 'content-trees'));
                });
            return () => { cancelled = true; };
        }, [endpoint, value.join(',')]);
        useEffect(() => {
            let cancelled = false;
            setResults([]);
            setStatus('');
            if (!search.trim()) return;
            setStatus(__('Searching…', 'content-trees'));
            const timer = setTimeout(() => {
                wp.apiFetch({path: endpoint + '?search=' + encodeURIComponent(search)})
                    .then(data => {
                        if (cancelled) return;
                        remember(data.items);
                        setResults(data.items);
                        setStatus(data.items.length
                            ? messages.found || __('Select an item. Refine your search if needed.', 'content-trees')
                            : messages.empty || __('No matching public items.', 'content-trees'));
                    })
                    .catch(() => { if (!cancelled) setStatus(__('Search failed. Change the search to try again.', 'content-trees')); });
            }, 300);
            return () => { cancelled = true; clearTimeout(timer); };
        }, [endpoint, search]);
        return el('div', {ref: root},
            el(TextControl, {label, help, value: search, onChange: setSearch}),
            el('p', {role: 'status'}, status || lookupStatus),
            el('ul', null, value.map(id => el('li', {key: id},
                el(Button, {variant: 'tertiary', onClick: () => change(value.filter(item => item !== id))},
                    /* translators: %s: selected item label or ID. */
                    wp.i18n.sprintf(messages.remove || __('Remove selection: %s', 'content-trees'), knownLabels[id] || '#' + id))))),
            el('ul', null, results.filter(item => !value.includes(item.id)).map(item => el('li', {key: item.id},
                el(Button, {variant: 'secondary', onClick: () => change(single ? [item.id] : [...value, item.id])}, item.label + ' (#' + item.id + ')')))));
    }

    window.ContentTreesEditor = {Selector};
})(window.wp);
