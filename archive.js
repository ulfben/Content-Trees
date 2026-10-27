/* Archive-specific endpoints; native date navigation remains in the HTML. */
(() => {
	'use strict';
	document.querySelectorAll('.ctrees[data-endpoint][data-messages]').forEach((tree) => {
		if (tree.dataset.archiveInitialized === '1') return;
		tree.dataset.archiveInitialized = '1';
		const messages = JSON.parse(tree.dataset.messages);
		window.ContentTrees.create(tree, {
			announceInitially: true,
			messages: {
				...messages,
				expanded: messages.bulkOpened,
				collapsed: messages.bulkClosed,
			},
			branchSelector: '.ctrees-year, .ctrees-month',
			loadSelector: '.ctrees-month',
			currentId: tree.dataset.currentId,
			url(branch) {
				const [year, month] = branch.dataset.month.split('-');
				return window.ContentTreesPosts.endpoint(
					tree.dataset.endpoint,
					`/${year}/${Number(month)}`,
					{
						order: tree.dataset.order === 'ASC' ? 'ASC' : null,
						excluded_posts: tree.dataset.exclude,
					},
				);
			},
		});
	});
})();
