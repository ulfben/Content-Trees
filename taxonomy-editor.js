/* Two focused blocks share this small form; their native previews stay inert. */
(function (wp) {
    'use strict';
    const el = wp.element.createElement;
    const __ = wp.i18n.__;
    const {InspectorControls, useBlockProps} = wp.blockEditor;
    const {PanelBody, TextControl, ToggleControl, Disabled} = wp.components;
    const ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;
    const definitions = [
        {name: 'categories', title: __('Categories', 'content-trees'), panel: __('Category settings', 'content-trees')},
        {name: 'tags', title: __('Tags', 'content-trees'), panel: __('Tag settings', 'content-trees')}
    ];
    definitions.forEach(definition => {
        const block = 'content-trees/' + definition.name;
        wp.blocks.registerBlockType(block, {
            edit: function ({attributes, setAttributes}) {
                const title = attributes.title === undefined ? definition.title : attributes.title;
                return el(wp.element.Fragment, null,
                    el(InspectorControls, null,
                        el(PanelBody, {title: definition.panel, initialOpen: true},
                            el(TextControl, {label: __('Title', 'content-trees'),
                                help: __('Leave blank to hide the heading.', 'content-trees'),
                                value: title, onChange: value => setAttributes({title: value})}),
                            el(ToggleControl, {label: __('Show individual posts', 'content-trees'),
                                checked: attributes.showPosts, onChange: showPosts => setAttributes({showPosts})}))),
                    el('div', useBlockProps(),
                        el(Disabled, null, el(ServerSideRender, {block, attributes: {...attributes, title}})),
                        el('p', {className: 'description'}, __('Preview: readers can open branches on your site.', 'content-trees'))));
            },
            save: function () { return null; }
        });
    });
})(window.wp);
