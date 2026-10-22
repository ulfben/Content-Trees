/* Uses WordPress-provided editor packages; no front-end framework dependency. */
(function (wp) {
    'use strict';
    const el = wp.element.createElement;
    const __ = wp.i18n.__;
    const {InspectorControls, useBlockProps} = wp.blockEditor;
    const {PanelBody, TextControl, Disabled} = wp.components;
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
                        })
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
