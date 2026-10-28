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
const artifactRoot = 'tests/results/';
const html = fs.readFileSync(artifactRoot + 'taxonomy-baseline-render.html', 'utf8');
const ids = JSON.parse(fs.readFileSync(artifactRoot + 'taxonomy-baseline-ids.json', 'utf8'));
const css = fs.readFileSync('shared/archive.css', 'utf8');
const errors = [];
try {
	const context = await browser.newContext({ javaScriptEnabled: false });
	const page = await context.newPage();
	page.on('pageerror', (error) => errors.push(error.message));
	await page.setContent('<style>' + css + '</style>' + html);
	const trees = page.locator('nav.ctrees-taxonomy-tree');
	assert.equal(await trees.count(), 4);
	assert.equal(await trees.nth(1).locator('ul.ctrees-posts').count(), 0);
	assert.equal(await trees.nth(3).locator('details').count(), 0);
	assert.equal(
		await trees
			.first()
			.locator(`details[data-term-id="${ids.b}"] > ul.ctrees-posts > li`)
			.count(),
		78,
	);
	assert.equal(
		await trees
			.nth(2)
			.locator(`details[data-term-id="${ids.x}"] > ul.ctrees-posts > li`)
			.count(),
		77,
	);
	const firstBranch = trees.first().locator(`details[data-term-id="${ids.a}"]`);
	const secondBranch = trees.nth(1).locator(`details[data-term-id="${ids.a}"]`);
	await firstBranch.locator(':scope > summary').focus();
	await page.keyboard.press('Space');
	await assert.doesNotReject(() => firstBranch.locator(':scope:not([open])').waitFor());
	assert.equal(await secondBranch.getAttribute('open'), '');
	await page.keyboard.press('Enter');
	await firstBranch.locator(':scope[open]').waitFor();
	assert.equal(
		await firstBranch.locator(':scope > summary').getAttribute('aria-description'),
		'',
	);
	assert.match(
		await firstBranch.locator(':scope > summary').getAttribute('aria-label'),
		/^Expand or collapse /,
	);

	// Native title navigation works without a live WordPress HTTP server.
	await context.route('http://127.0.0.1:8770/**', (route) =>
		route.fulfill({ contentType: 'text/html', body: '<p>Term destination</p>' }),
	);
	await firstBranch.locator(':scope > summary > a').click();
	await page.waitForURL(/^http:\/\/127\.0\.0\.1:8770\//);
	assert.match(page.url(), /^http:\/\/127\.0\.0\.1:8770\//);
	await page.setContent('<style>' + css + '</style>' + html);

	await page.setViewportSize({ width: 390, height: 844 });
	await page.screenshot({ path: artifactRoot + 'taxonomy-baseline-mobile.png' });
	assert.equal(
		await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
		false,
	);
	await context.close();

	const touch = await browser.newContext({
		hasTouch: true,
		isMobile: true,
		viewport: { width: 390, height: 844 },
	});
	const touchPage = await touch.newPage();
	await touchPage.setContent('<style>' + css + '</style>' + html);
	const target = touchPage.locator('.ctrees-taxonomy-tree summary > .ctrees-toggle').first();
	const toggleBox = await target.boundingBox();
	// Mobile emulation can round CSS coordinates just below 44 (43.999996px).
	assert.ok(toggleBox.width >= 43.99 && toggleBox.height >= 43.99);
	const linkBox = await touchPage
		.locator('.ctrees-taxonomy-tree summary > a')
		.first()
		.boundingBox();
	assert.ok(linkBox.height >= 43.99);
	await touch.close();

	const enhanced = await browser.newContext();
	const mixed = await enhanced.newPage();
	mixed.on('pageerror', (error) => errors.push(error.message));
	await mixed.setContent('<style>' + css + '</style>' + html);
	let requests = 0;
	mixed.on('request', () => {
		requests++;
	});
	await mixed.addScriptTag({ content: fs.readFileSync('shared/post-loader.js', 'utf8') });
	await mixed.addScriptTag({ content: fs.readFileSync('shared/tree.js', 'utf8') });
	await mixed.addScriptTag({ content: fs.readFileSync('archive/archive.js', 'utf8') });
	await mixed.addScriptTag({ content: fs.readFileSync('pages/pages.js', 'utf8') });
	assert.equal(await mixed.locator('.ctrees-taxonomy-tree .ctrees-announcement').count(), 0);
	assert.equal(
		await mixed
			.locator(
				'.ctrees-taxonomy-tree[data-taxonomy-initialized], .ctrees-taxonomy-tree [data-page-messages]',
			)
			.count(),
		0,
	);
	assert.equal(requests, 0);
	assert.equal(await mixed.locator('.ctrees-pages-tree[data-pages-initialized="1"]').count(), 1);
	assert.equal(
		await mixed.locator('nav[data-endpoint][data-messages] > .ctrees-announcement').count(),
		1,
	);
	assert.deepEqual(errors, []);
	await enhanced.close();
	console.log(
		'PASS: native no-JS disclosures, normal term links, complete lists, independent instances, mobile wrapping, 44px touch targets and mixed Archive/Pages isolation.',
	);
} finally {
	await browser.close();
}
