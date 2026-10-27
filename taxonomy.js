/* Term-specific endpoints; native term navigation remains in the HTML. */
(() => {
	'use strict';
	document.querySelectorAll('.ctrees-taxonomy-tree[data-taxonomy-messages]').forEach((tree) => {
		if (tree.dataset.taxonomyInitialized === '1') return;
		tree.dataset.taxonomyInitialized = '1';
		window.ContentTrees.create(tree, {
			messages: JSON.parse(tree.dataset.taxonomyMessages),
			branchSelector: 'details.ctrees-term-branch',
			loadSelector: 'details.ctrees-term-branch[data-has-posts="1"]',
			currentId: tree.dataset.currentId,
			url: (branch) =>
				window.ContentTreesPosts.endpoint(
					tree.dataset.taxonomyEndpoint,
					'/' + branch.dataset.termId + '/posts',
					{
						sort_by: tree.dataset.postSort,
						order: tree.dataset.postOrder,
						excluded_posts: tree.dataset.exclude,
					},
				),
		});
	});
})();
