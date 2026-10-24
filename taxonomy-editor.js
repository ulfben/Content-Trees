/* Focused taxonomy forms use WordPress controls and inert server-rendered previews. */
(function (wp) {
    'use strict';
    const el = wp.element.createElement;
    const __ = wp.i18n.__;
    const {InspectorControls, useBlockProps} = wp.blockEditor;
    const {PanelBody, TextControl, ToggleControl, SelectControl, Disabled} = wp.components;
    const ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;
    const Selector = window.ContentTreesEditor.Selector;

    const choices = (first, second) => [{label: first, value: 'ASC'}, {label: second, value: 'DESC'}];
    const definitions = [
        {name: 'categories', title: __('Categories', 'content-trees'), panel: __('Category settings', 'content-trees'), endpoint: '/content-trees/v1/category-options',
            hide: __('Hide empty categories', 'content-trees'), exclude: __('Exclude categories', 'content-trees'),
            exclusionHelp: __('Hide selected categories and all their child categories from this tree. Linked archives keep their full contents.', 'content-trees'),
            countHelp: __('Count unique public posts in each category and its visible child categories.', 'content-trees'),
            openingHelp: __('Open matching branches when viewing a category or a post in this tree.', 'content-trees')},
        {name: 'tags', title: __('Tags', 'content-trees'), panel: __('Tag settings', 'content-trees'), endpoint: '/content-trees/v1/tag-options',
            hide: __('Hide empty tags', 'content-trees'), exclude: __('Exclude tags', 'content-trees'),
            exclusionHelp: __('Hide selected tags from this tree. Linked archives keep their full contents.', 'content-trees'),
            countHelp: __('Count public posts assigned directly to each tag.', 'content-trees'),
            openingHelp: __('Open matching branches when viewing a tag or a post in this tree.', 'content-trees')}
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
                            definition.name === 'categories' && el(Selector, {label: __('Start at category', 'content-trees'),
                                help: __('Show the selected category and its child categories. Leave empty to show all categories.', 'content-trees'),
                                endpoint: definition.endpoint, single: true, value: attributes.root ? [attributes.root] : [], onChange: ids => setAttributes({root: ids[0] || 0})}),
                            el(ToggleControl, {label: __('Show individual posts', 'content-trees'), checked: attributes.showPosts, onChange: showPosts => setAttributes({showPosts})}),
                            el(SelectControl, {label: __('Sort by', 'content-trees'), value: attributes.sortBy,
                                options: [{label: __('Name', 'content-trees'), value: 'name'}, {label: __('Post count', 'content-trees'), value: 'count'}], onChange: sortBy => setAttributes({sortBy})}),
                            el(SelectControl, {label: __('Order', 'content-trees'), value: attributes.order,
                                options: attributes.sortBy === 'count' ? choices(__('Fewest first', 'content-trees'), __('Most first', 'content-trees')) : choices(__('A–Z', 'content-trees'), __('Z–A', 'content-trees')),
                                onChange: order => setAttributes({order})}),
                            attributes.showPosts && el(wp.element.Fragment, null,
                                el(SelectControl, {label: __('Sort posts by', 'content-trees'), value: attributes.postSortBy,
                                    options: [{label: __('Published date', 'content-trees'), value: 'date'}, {label: __('Last updated', 'content-trees'), value: 'modified'}, {label: __('Title', 'content-trees'), value: 'title'}], onChange: postSortBy => setAttributes({postSortBy})}),
                                el(SelectControl, {label: __('Post order', 'content-trees'), value: attributes.postOrder,
                                    options: attributes.postSortBy === 'title' ? choices(__('A–Z', 'content-trees'), __('Z–A', 'content-trees')) : choices(__('Oldest first', 'content-trees'), __('Newest first', 'content-trees')),
                                    onChange: postOrder => setAttributes({postOrder})})),
                            el(ToggleControl, {label: __('Show post counts', 'content-trees'), help: definition.countHelp, checked: attributes.showCounts, onChange: showCounts => setAttributes({showCounts})}),
                            el(ToggleControl, {label: definition.hide, checked: attributes.hideEmpty, onChange: hideEmpty => setAttributes({hideEmpty})}),
                            el(ToggleControl, {label: __('Show Open All / Close All', 'content-trees'), checked: attributes.showControls, onChange: showControls => setAttributes({showControls})}),
                            el(ToggleControl, {label: __('Open the current branch automatically', 'content-trees'), help: definition.openingHelp, checked: attributes.autoOpen, onChange: autoOpen => setAttributes({autoOpen})}))),
                    el(InspectorControls, {group: 'advanced'},
                        el(ToggleControl, {label: __('Close other branches at the same level', 'content-trees'),
                            help: __('Opening a branch closes its siblings and their child branches. Open All still opens everything.', 'content-trees'),
                            checked: attributes.closeSiblings, onChange: closeSiblings => setAttributes({closeSiblings})}),
                        el(Selector, {label: definition.exclude, help: definition.exclusionHelp, endpoint: definition.endpoint, value: attributes.exclude, onChange: exclude => setAttributes({exclude})}),
                        el(Selector, {label: __('Exclude posts', 'content-trees'),
                            help: __('Hide selected posts from every branch and count in this tree. Linked archives keep their full contents.', 'content-trees'),
                            endpoint: '/content-trees/v1/post-options', value: attributes.excludePosts, onChange: excludePosts => setAttributes({excludePosts})})),
                    el('div', useBlockProps(),
                        el(Disabled, null, el(ServerSideRender, {block, attributes: {...attributes, title}})),
                        el('p', {className: 'description'}, __('Preview: readers can open branches on your site.', 'content-trees'))));
            },
            save: function () { return null; }
        });
    });
})(window.wp);
