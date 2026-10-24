/* Uses WordPress-provided editor packages; no front-end framework dependency. */
(function (wp) {
    'use strict';
    const el = wp.element.createElement;
    const __ = wp.i18n.__;
    const {InspectorControls, useBlockProps} = wp.blockEditor;
    const {PanelBody, TextControl, SelectControl, ToggleControl, Disabled} = wp.components;
    const ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;

    const Selector = window.ContentTreesEditor.Selector;
    const pageSelector = {
        endpoint: '/content-trees/v1/page-options',
        messages: {
            found: __('Select a page. Refine your search if needed.', 'content-trees'),
            empty: __('No matching public pages.', 'content-trees'),
            lookupError: __('Page titles could not be loaded. Saved choices are retained.', 'content-trees'),
            /* translators: %s: page title or ID. */
            remove: __('Remove page: %s', 'content-trees')
        }
    };

    wp.blocks.registerBlockType('content-trees/pages', {
        edit: function ({attributes, setAttributes}) {
            const title = attributes.title === undefined ? __('Pages', 'content-trees') : attributes.title;
            return el(wp.element.Fragment, null,
                el(InspectorControls, null,
                    el(PanelBody, {title: __('Page settings', 'content-trees'), initialOpen: true},
                        el(TextControl, {
                            label: __('Title', 'content-trees'),
                            help: __('Leave blank to hide the heading.', 'content-trees'),
                            value: title,
                            onChange: value => setAttributes({title: value})
                        }),
                        el(Selector, {...pageSelector, single: true, label: __('Start at page', 'content-trees'),
                            help: __('Show the selected page and its child pages. Leave empty to show all pages.', 'content-trees'),
                            value: attributes.root ? [attributes.root] : [], onChange: ids => setAttributes({root: ids[0] || 0})}),
                        el(SelectControl, {label: __('Sort by', 'content-trees'), value: attributes.sortBy,
                            options: [{label: __('Menu order', 'content-trees'), value: 'menu_order'}, {label: __('Title', 'content-trees'), value: 'title'},
                                {label: __('Published date', 'content-trees'), value: 'date'}, {label: __('Last updated', 'content-trees'), value: 'modified'}],
                            onChange: sortBy => setAttributes({sortBy})}),
                        el(SelectControl, {label: __('Order', 'content-trees'), value: attributes.order,
                            options: attributes.sortBy === 'title'
                                ? [{label: __('A–Z', 'content-trees'), value: 'ASC'}, {label: __('Z–A', 'content-trees'), value: 'DESC'}]
                                : ['date', 'modified'].includes(attributes.sortBy)
                                    ? [{label: __('Oldest first', 'content-trees'), value: 'ASC'}, {label: __('Newest first', 'content-trees'), value: 'DESC'}]
                                    : [{label: __('Ascending', 'content-trees'), value: 'ASC'}, {label: __('Descending', 'content-trees'), value: 'DESC'}],
                            onChange: order => setAttributes({order})}),
                        el(ToggleControl, {label: __('Show Open All / Close All', 'content-trees'),
                            checked: attributes.showControls, onChange: showControls => setAttributes({showControls})}),
                        el(ToggleControl, {label: __('Open the current branch automatically', 'content-trees'),
                            help: __('Open the parent branches when viewing a page in this tree.', 'content-trees'),
                            checked: attributes.autoOpen, onChange: autoOpen => setAttributes({autoOpen})})
                    )
                ),
                el(InspectorControls, {group: 'advanced'},
                    el(ToggleControl, {label: __('Close other branches at the same level', 'content-trees'),
                        help: __('Opening a branch closes its siblings and their child branches. Open All still opens everything.', 'content-trees'),
                        checked: attributes.closeSiblings, onChange: closeSiblings => setAttributes({closeSiblings})}),
                    el(Selector, {...pageSelector, label: __('Exclude pages', 'content-trees'),
                        help: __('Hide selected pages and all their child pages from this tree.', 'content-trees'),
                        value: attributes.exclude, onChange: exclude => setAttributes({exclude})})
                ),
                el('div', useBlockProps(),
                    el(Disabled, null, el(ServerSideRender, {block: 'content-trees/pages', attributes: {...attributes, title}})),
                    el('p', {className: 'description'}, __('Preview: readers can open page branches on your site.', 'content-trees'))
                )
            );
        },
        save: function () { return null; }
    });
})(window.wp);
