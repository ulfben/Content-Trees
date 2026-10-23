/* Uses WordPress-provided editor packages; no front-end framework dependency. */
(function (wp) {
    'use strict';
    const el = wp.element.createElement;
    const __ = wp.i18n.__;
    const {InspectorControls, useBlockProps} = wp.blockEditor;
    const {PanelBody, TextControl, SelectControl, ToggleControl, Disabled} = wp.components;
    const ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;

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
                        el(SelectControl, {label: __('Sort by', 'content-trees'), value: attributes.sortBy,
                            options: [{label: __('Menu order', 'content-trees'), value: 'menu_order'}, {label: __('Title', 'content-trees'), value: 'title'}],
                            onChange: sortBy => setAttributes({sortBy})}),
                        el(SelectControl, {label: __('Order', 'content-trees'), value: attributes.order,
                            options: attributes.sortBy === 'title'
                                ? [{label: __('A–Z', 'content-trees'), value: 'ASC'}, {label: __('Z–A', 'content-trees'), value: 'DESC'}]
                                : [{label: __('Ascending', 'content-trees'), value: 'ASC'}, {label: __('Descending', 'content-trees'), value: 'DESC'}],
                            onChange: order => setAttributes({order})}),
                        el(ToggleControl, {label: __('Open the current branch automatically', 'content-trees'),
                            help: __('Open the parent branches when viewing a page in this tree.', 'content-trees'),
                            checked: attributes.autoOpen, onChange: autoOpen => setAttributes({autoOpen})})
                    )
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
