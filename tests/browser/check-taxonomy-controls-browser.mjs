import assert from 'node:assert/strict';
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';
const { chromium } = process.argv[2]
	? await import(pathToFileURL(process.argv[2]).href)
	: await import('playwright');
const browser = await chromium.launch({
	executablePath: process.env.CONTENT_TREES_BROWSER || process.argv[3],
	headless: true,
});
const root = 'tests/results/';
const html =
	'<style>' +
	fs.readFileSync('archive.css', 'utf8') +
	'</style>' +
	fs.readFileSync(root + 'taxonomy-controls-render.html', 'utf8');
const ids = JSON.parse(fs.readFileSync(root + 'taxonomy-controls-ids.json', 'utf8'));
try {
	const page = await browser.newPage();
	await page.setContent(html);
	const responses = JSON.parse(fs.readFileSync(root + 'taxonomy-controls-posts.json', 'utf8'));
	await page.evaluate((responses) => {
		window.requests = 0;
		window.fetch = async (url) => {
			window.requests++;
			const route = new URL(url).searchParams.get('rest_route') || new URL(url).pathname;
			const match = route.match(/\/terms\/(category|post_tag)\/(\d+)\/posts/);
			if (!match || !responses[match[1] + '/' + match[2]])
				throw new Error('Unexpected request');
			return { ok: true, json: async () => responses[match[1] + '/' + match[2]] };
		};
	}, responses);
	for (const script of [
		'post-loader.js',
		'tree.js',
		'archive.js',
		'pages.js',
		'taxonomy.js',
		'taxonomy.js',
	])
		await page.addScriptTag({ content: fs.readFileSync('' + script, 'utf8') });
	assert.equal(
		await page.evaluate(() => window.requests),
		0,
		'Closed trees make no post requests.',
	);
	const trees = page.locator('.ctrees-taxonomy-tree');
	const first = trees.first();
	assert.equal(await first.locator('.ctrees-announcement').count(), 1);
	assert.equal(await first.locator('.ctrees-controls').isVisible(), true);
	const branch = (id) => first.locator(`details[data-term-id="${id}"]`);
	await branch(ids.a).locator(':scope > summary > .ctrees-toggle').click();
	await branch(ids.middle).locator(':scope > summary > .ctrees-toggle').click();
	await branch(ids.b).locator(':scope > summary').focus();
	await page.keyboard.press('Space');
	await branch(ids.b).locator(':scope[open]').waitFor();
	await branch(ids.c).locator(':scope > summary > .ctrees-toggle').click();
	assert.equal(await branch(ids.middle).getAttribute('open'), null);
	assert.equal(await branch(ids.b).getAttribute('open'), null);
	assert.equal(await branch(ids.c).getAttribute('open'), '');
	assert.equal(await trees.nth(1).locator('details[open]').count(), 0);
	await first.getByRole('button', { name: 'Open All', exact: true }).click();
	assert.equal(
		await first.locator('details[open]').count(),
		await first.locator('details').count(),
	);
	assert.equal(
		await first.locator('.ctrees-announcement').textContent(),
		'All category branches expanded.',
	);
	await first.getByRole('button', { name: 'Close All', exact: true }).click();
	assert.equal(await first.locator('details[open]').count(), 0);
	assert.equal(
		await first.locator('.ctrees-announcement').textContent(),
		'All category branches collapsed.',
	);
	await trees.nth(2).getByRole('button', { name: 'Open All', exact: true }).click();
	await trees.nth(2).getByText('Tree loading finished.', { exact: true }).waitFor();
	assert.ok((await page.evaluate(() => window.requests)) > 0);
	assert.equal(await page.locator('.ctrees-pages-tree[data-pages-initialized="1"]').count(), 1);
	console.log(
		'PASS: taxonomy bulk/sibling controls, descendant closing, keyboard, Open All override, independent instances, idempotence and quiet mixed providers.',
	);
} finally {
	await browser.close();
}
