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
const dir = 'tests/results/';
const markup = fs.readFileSync(dir + 'taxonomy-controls-render.html', 'utf8');
const responses = JSON.parse(fs.readFileSync(dir + 'taxonomy-controls-posts.json', 'utf8'));
const ids = JSON.parse(fs.readFileSync(dir + 'taxonomy-controls-ids.json', 'utf8'));
const scripts = [
	'shared/post-loader.js',
	'shared/tree.js',
	'archive/archive.js',
	'taxonomy/taxonomy.js',
].map((file) => fs.readFileSync('' + file, 'utf8'));
const errors = [];
async function setup(mode, pretty = false) {
	const page = await browser.newPage();
	page.on('pageerror', (error) => errors.push(error.message));
	await page.setContent(
		'<style>' + fs.readFileSync('shared/archive.css', 'utf8') + '</style>' + markup,
	);
	await page.evaluate(
		({ responses, mode, ids, pretty }) => {
			window.requests = [];
			window.active = 0;
			window.maxActive = 0;
			const attempts = {};
			const first = document.querySelector('.ctrees-taxonomy-tree');
			first.dataset.currentId = responses['category/' + ids.a].items[0].id;
			first.dataset.postSort = 'modified';
			first.dataset.postOrder = 'ASC';
			first.dataset.exclude = '999999';
			if (pretty)
				first.dataset.taxonomyEndpoint =
					'http://fixture.invalid/wp-json/content-trees/v1/terms/category';
			const archive = document.querySelector('.ctrees[data-endpoint]');
			archive.replaceChildren();
			archive.insertAdjacentHTML(
				'beforeend',
				'<details class="ctrees-month" data-month="2020-01"><summary>January</summary></details>',
			);
			const originalTimer = window.setTimeout;
			if (mode === 'timeout')
				window.setTimeout = (callback, delay, ...args) =>
					originalTimer(callback, delay === 15000 ? 50 : delay, ...args);
			window.fetch = (url, options) =>
				new Promise((resolve, reject) => {
					const address = new URL(url);
					const route = address.searchParams.get('rest_route') || address.pathname;
					const match = route.match(/\/terms\/(category|post_tag)\/(\d+)\/posts/);
					const key = match ? match[1] + '/' + match[2] : 'archive';
					attempts[key] = (attempts[key] || 0) + 1;
					window.requests.push({ url: String(url), credentials: options.credentials });
					window.active++;
					window.maxActive = Math.max(window.maxActive, window.active);
					let done = false;
					const finish = (callback) => {
						if (!done) {
							done = true;
							window.active--;
							callback();
						}
					};
					options.signal.addEventListener('abort', () =>
						finish(() => reject(new DOMException('Aborted', 'AbortError'))),
					);
					if (mode === 'timeout' && attempts[key] === 1) return;
					originalTimer(
						() =>
							finish(() => {
								let result = structuredClone(
									responses[key] || {
										items: [
											{
												id: 123,
												label: 'Archive post',
												url: 'http://fixture.invalid/post',
											},
										],
									},
								);
								if (mode === 'empty') result = { items: [] };
								if (mode === 'invalid' && key === 'category/' + ids.a) {
									if (attempts[key] === 1)
										result.items[0].url = 'javascript:alert(1)';
									else
										result.items[0].label =
											'<img src=x onerror="window.postsContentPwned=1">';
								}
								resolve({ ok: true, json: async () => result });
							}),
						mode === 'cancel' ? 400 : mode === 'timeout' ? 5 : 80,
					);
				});
		},
		{ responses, mode, ids, pretty },
	);
	for (const script of scripts) await page.addScriptTag({ content: script });
	assert.equal(
		await page.evaluate(() => window.requests.length),
		0,
		'Closed outlines do not request posts.',
	);
	return page;
}
try {
	const page = await setup('invalid');
	const first = page.locator('.ctrees-taxonomy-tree').first();
	const root = first.locator(`details[data-term-id="${ids.a}"]`);
	await root.locator(':scope > summary > .ctrees-toggle').click();
	await root.locator(':scope > .ctrees-retry').waitFor({ state: 'visible' });
	assert.equal(
		await root.locator(':scope > .ctrees-posts a').count(),
		0,
		'Unsafe responses produce no partial links.',
	);
	await root.locator(':scope > .ctrees-retry').focus();
	await page.keyboard.press('Enter');
	await root.locator(':scope[data-loaded="1"]').waitFor();
	assert.equal(await root.locator(':scope > .ctrees-posts img').count(), 0);
	assert.equal(
		await root.locator(':scope > .ctrees-posts a').first().textContent(),
		'<img src=x onerror="window.postsContentPwned=1">',
	);
	assert.equal(await root.locator(':scope > .ctrees-posts a[aria-current="page"]').count(), 1);
	assert.equal(
		await root
			.locator(':scope > .ctrees-posts a')
			.first()
			.evaluate((node) => node === document.activeElement),
		true,
	);
	const before = await page.evaluate(() => window.requests.length);
	await root.locator(':scope > summary > .ctrees-toggle').click();
	await root.locator(':scope > summary > .ctrees-toggle').click();
	await page.waitForTimeout(120);
	assert.equal(
		await page.evaluate(() => window.requests.length),
		before,
		'Reopening a loaded branch reuses its complete list.',
	);
	await page.evaluate(() => {
		for (const button of document.querySelectorAll(
			'.ctrees-taxonomy-tree [data-ctrees-action="open"]',
		))
			button.click();
		document.querySelector('.ctrees-month').open = true;
	});
	await page.waitForFunction(() =>
		[...document.querySelectorAll('[data-has-posts="1"], .ctrees-month')].every(
			(branch) => branch.dataset.loaded === '1',
		),
	);
	assert.equal(
		await page.evaluate(() => window.maxActive),
		3,
		'Archive and taxonomy loads share one three-request queue.',
	);
	const requested = await page.evaluate(() => window.requests);
	assert(requested.every((request) => request.credentials === 'omit'));
	const firstUrl = new URL(requested[0].url);
	assert.equal(firstUrl.searchParams.get('sort_by'), 'modified');
	assert.equal(firstUrl.searchParams.get('order'), 'ASC');
	assert.equal(firstUrl.searchParams.get('excluded_posts'), '999999');
	assert.equal(await page.evaluate(() => Boolean(window.postsContentPwned)), false);
	await page.close();

	const cancel = await setup('cancel');
	await cancel.evaluate(() =>
		document
			.querySelectorAll('.ctrees-taxonomy-tree [data-ctrees-action="open"]')
			.forEach((button) => button.click()),
	);
	await cancel.waitForFunction(() => window.active === 3);
	await cancel.evaluate(() =>
		document
			.querySelectorAll('.ctrees-taxonomy-tree [data-ctrees-action="close"]')
			.forEach((button) => button.click()),
	);
	await cancel.waitForFunction(() => window.active === 0);
	assert.equal(
		await cancel.evaluate(() => window.requests.length),
		3,
		'Close All cancels queued work after the active requests.',
	);
	assert.equal(await cancel.locator('.ctrees-taxonomy-tree details[open]').count(), 0);
	await cancel.close();

	for (const mode of ['empty', 'timeout', 'normal']) {
		const check = await setup(mode, mode === 'normal');
		const branch = check
			.locator(`.ctrees-taxonomy-tree details[data-term-id="${ids.a}"]`)
			.first();
		await branch.locator(':scope > summary > .ctrees-toggle').click();
		if (mode === 'timeout') {
			await branch.locator(':scope > .ctrees-retry').waitFor({ state: 'visible' });
			await branch.locator(':scope > .ctrees-retry').click();
		}
		await branch.locator(':scope[data-loaded="1"]').waitFor();
		if (mode === 'empty')
			assert.equal(
				await branch.locator(':scope > .ctrees-status').textContent(),
				'No posts in this term.',
			);
		if (mode === 'normal')
			assert(
				new URL((await check.evaluate(() => window.requests))[0].url).pathname.endsWith(
					`/terms/category/${ids.a}/posts`,
				),
			);
		assert.equal(
			await branch.locator(':scope > .ctrees-posts').getAttribute('aria-busy'),
			null,
		);
		await check.close();
	}
	const nojs = await browser.newContext({ javaScriptEnabled: false });
	const fallback = await nojs.newPage();
	await fallback.setContent(
		'<style>' + fs.readFileSync('shared/archive.css', 'utf8') + '</style>' + markup,
	);
	const branch = fallback
		.locator(`.ctrees-taxonomy-tree details[data-term-id="${ids.a}"]`)
		.first();
	await branch.locator(':scope > summary > .ctrees-toggle').click();
	assert.equal(await branch.locator(':scope > summary > a').isVisible(), true);
	assert.match(await branch.locator(':scope > summary > a').getAttribute('href'), /^http/);
	await nojs.close();
	assert.deepEqual(errors, []);
	console.log(
		'PASS: lazy lists, global Archive/taxonomy concurrency, safe text/URLs, retry/focus/timeout, current highlights, cached reopen, sorting/exclusions, Close All cancellation, empty/plain/pretty routes and no-JavaScript term links.',
	);
} finally {
	await browser.close();
}
