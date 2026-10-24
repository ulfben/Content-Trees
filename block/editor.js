/* Uses WordPress-provided editor packages; no front-end framework dependency. */
(function (wp) {
    'use strict';
    const el = wp.element.createElement;
    const __ = wp.i18n.__;
    const {InspectorControls, useBlockProps} = wp.blockEditor;
    const {PanelBody, TextControl, ToggleControl, SelectControl, Disabled} = wp.components;
    const ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;
    const Selector = window.ContentTreesEditor.Selector;
    const postSelector = {
        endpoint: '/content-trees/v1/post-options',
        label: __('Exclude posts', 'content-trees'),
        help: __('Search by title. Exclusions affect this tree and its counts; linked archive pages still show the full archive.', 'content-trees'),
        messages: {
            found: __('Select a post to exclude. Refine your search if needed.', 'content-trees'),
            empty: __('No matching public posts.', 'content-trees'),
            lookupError: __('Post titles could not be loaded. Saved exclusions are retained.', 'content-trees'),
            /* translators: %s: post title or ID. */
            remove: __('Remove exclusion: %s', 'content-trees')
        }
    };

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
                    el(Selector, {...postSelector, value: attributes.exclude || [], onChange: exclude => setAttributes({exclude})})
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
