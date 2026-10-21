/* Uses WordPress-provided editor packages; no front-end framework dependency. */
(function (wp) {
    'use strict';
    const el = wp.element.createElement;
    const __ = wp.i18n.__;
    const {InspectorControls, useBlockProps} = wp.blockEditor;
    const {PanelBody, TextControl, ToggleControl, SelectControl, Button, Disabled} = wp.components;
    const {useState, useEffect, useRef} = wp.element;
    const ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;
    function Exclusions({value, onChange}) {
        const [search, setSearch] = useState('');
        const [results, setResults] = useState([]);
        const [labels, setLabels] = useState({});
        const [status, setStatus] = useState('');
        const root = useRef(null);
        function change(next) {
            onChange(next);
            root.current?.querySelector('input')?.focus();
        }
        useEffect(() => {
            let cancelled = false;
            if (value.length) wp.apiFetch({path: '/content-trees/v1/post-options?include=' + value.join(',')})
                .then(data => { if (!cancelled) setLabels(previous => ({...previous, ...Object.fromEntries(data.items.map(item => [item.id, item.label]))})); })
                .catch(() => { if (!cancelled) setStatus(__('Post titles could not be loaded. Saved exclusions are retained.', 'content-trees')); });
            return () => { cancelled = true; };
        }, [value.join(',')]);
        useEffect(() => {
            let cancelled = false;
            setResults([]);
            setStatus('');
            if (!search.trim()) return;
            setStatus(__('Searching…', 'content-trees'));
            const timer = setTimeout(() => {
                wp.apiFetch({path: '/content-trees/v1/post-options?search=' + encodeURIComponent(search)})
                    .then(data => {
                        if (cancelled) return;
                        setResults(data.items);
                        setLabels(previous => ({...previous, ...Object.fromEntries(data.items.map(item => [item.id, item.label]))}));
                        setStatus(data.items.length ? __('Select a post to exclude. Refine your search if needed.', 'content-trees') : __('No matching public posts.', 'content-trees'));
                    })
                    .catch(() => { if (!cancelled) setStatus(__('Search failed. Change the search to try again.', 'content-trees')); });
            }, 300);
            return () => { cancelled = true; clearTimeout(timer); };
        }, [search]);
        return el('div', {ref: root},
            el(TextControl, {label: __('Exclude posts', 'content-trees'), value: search, onChange: setSearch,
                help: __('Search by title. Exclusions affect this tree and its counts; linked archive pages still show the full archive.', 'content-trees')}),
            el('p', {role: 'status'}, status),
            el('ul', null, value.map(id => el('li', {key: id},
                el(Button, {variant: 'tertiary', onClick: () => change(value.filter(item => item !== id))},
                    /* translators: %s: post title or ID. */
                    wp.i18n.sprintf(__('Remove exclusion: %s', 'content-trees'), labels[id] || '#' + id))))),
            el('ul', null, results.filter(item => !value.includes(item.id)).map(item => el('li', {key: item.id},
                el(Button, {variant: 'secondary', onClick: () => change([...value, item.id])}, item.label + ' (#' + item.id + ')'))))
        );
    }
    wp.blocks.registerBlockType('content-trees/archive', {
        edit: function ({attributes, setAttributes}) {
            return el(wp.element.Fragment, null,
                el(InspectorControls, null,
                    el(PanelBody, {title: __('Archive settings', 'content-trees'), initialOpen: true},
                        el(TextControl, {
                            label: __('Title', 'content-trees'),
                            help: __('Leave blank to hide the heading.', 'content-trees'),
                            value: attributes.title === undefined ? __('Archives', 'content-trees') : attributes.title,
                            onChange: title => setAttributes({title})
                        }),
                        el(ToggleControl, {
                            label: __('Show individual posts', 'content-trees'),
                            checked: attributes.showPosts,
                            onChange: showPosts => setAttributes({showPosts})
                        }),
                        el(ToggleControl, {
                            label: __('Group months by year', 'content-trees'),
                            checked: attributes.groupYears,
                            onChange: groupYears => setAttributes({groupYears})
                        }),
                        el(SelectControl, {
                            label: __('Order', 'content-trees'), value: attributes.order,
                            options: [{label: __('Newest first', 'content-trees'), value: 'DESC'}, {label: __('Oldest first', 'content-trees'), value: 'ASC'}],
                            onChange: order => setAttributes({order})
                        }),
                        el(ToggleControl, {
                            label: __('Show post counts', 'content-trees'),
                            checked: attributes.showCounts,
                            onChange: showCounts => setAttributes({showCounts})
                        }),
                        el(ToggleControl, {
                            label: __('Link year and month names', 'content-trees'),
                            help: attributes.showPosts ? __('Open the archive by selecting its year or month name.', 'content-trees') : __('Archive links are always available when individual posts are hidden.', 'content-trees'),
                            checked: !attributes.showPosts || attributes.showArchiveLinks,
                            disabled: !attributes.showPosts,
                            onChange: showArchiveLinks => setAttributes({showArchiveLinks})
                        }),
                        el(ToggleControl, {
                            label: __('Show Open All / Close All', 'content-trees'),
                            checked: attributes.showControls,
                            onChange: showControls => setAttributes({showControls})
                        }),
                        el(ToggleControl, {
                            label: __('Open the current branch automatically', 'content-trees'),
                            help: __('Open the matching year and month when viewing a post or date archive.', 'content-trees'),
                            checked: attributes.autoOpen,
                            onChange: autoOpen => setAttributes({autoOpen})
                        })
                    )
                ),
                el(InspectorControls, {group: 'advanced'},
                    el(ToggleControl, {label: __('Close other branches at the same level', 'content-trees'),
                        help: __('Opening a month closes the other months in that year. Opening a year closes the other years. Open All still opens everything.', 'content-trees'),
                        checked: attributes.closeSiblings, onChange: closeSiblings => setAttributes({closeSiblings})}),
                    el(Exclusions, {value: attributes.exclude || [], onChange: exclude => setAttributes({exclude})})
                ),
                el('div', useBlockProps(),
                    el(Disabled, null, el(ServerSideRender, {block: 'content-trees/archive', attributes})),
                    el('p', {className: 'description'}, __('Preview: readers can open years and months on your site.', 'content-trees'))
                )
            );
        },
        save: function () { return null; }
    });
})(window.wp);
