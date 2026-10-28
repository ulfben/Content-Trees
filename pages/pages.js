/* Page branches are complete in HTML and need only disclosure controls. */
(() => {
	'use strict';
	document.querySelectorAll('.ctrees-pages-tree[data-page-messages]').forEach((tree) => {
		if (tree.dataset.pagesInitialized === '1') return;
		tree.dataset.pagesInitialized = '1';
		window.ContentTrees.create(tree, {
			messages: JSON.parse(tree.dataset.pageMessages),
			branchSelector: 'details.ctrees-page-branch',
		});
	});
})();
