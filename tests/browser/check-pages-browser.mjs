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
const artifactPath = 'tests/results/pages-render.html';
const artifact = fs.readFileSync(artifactPath, 'utf8');
const css = fs.readFileSync('shared/archive.css', 'utf8');
const archiveJs = fs.readFileSync('archive/archive.js', 'utf8');
const pagesJs = fs.readFileSync('pages/pages.js', 'utf8');
const errors = [];

async function loadFixture(page, scripts = false, instrument = false) {
	// Inline CSS also works when the browser context disables JavaScript.
	await page.setContent(artifact.replace('<main>', `<style>${css}</style><main>`), {
		waitUntil: 'domcontentloaded',
	});
	if (scripts) {
		if (instrument) {
			// setContent writes into the current document; addInitScript only
			// runs on later navigations. Install probes in this document first.
			await page.addScriptTag({
				content: `
                window.__fetchCalls = 0;
                const originalFetch = window.fetch;
                window.fetch = (...args) => { window.__fetchCalls++; return originalFetch(...args); };
                window.__pageClickHandlers = new WeakMap();
                const originalAdd = EventTarget.prototype.addEventListener;
                EventTarget.prototype.addEventListener = function(type, listener, options) {
                    if (type === 'click' && this.matches?.('nav.ctrees-pages-tree')) {
                        window.__pageClickHandlers.set(this, (window.__pageClickHandlers.get(this) || 0) + 1);
                    }
                    return originalAdd.call(this, type, listener, options);
                };
            `,
			});
		}
		await page.addScriptTag({ content: fs.readFileSync('shared/post-loader.js', 'utf8') });
		await page.addScriptTag({ content: fs.readFileSync('shared/tree.js', 'utf8') });
		await page.addScriptTag({ content: archiveJs });
		await page.addScriptTag({ content: pagesJs });
	}
}

try {
	// Native details remain functional with JavaScript disabled; progressive
	// enhancement controls must stay hidden until their initializer runs.
	const noJsContext = await browser.newContext({ javaScriptEnabled: false });
	const noJs = await noJsContext.newPage();
	noJs.setDefaultTimeout(10000);
	noJs.setDefaultNavigationTimeout(10000);
	await loadFixture(noJs);
	const noJsTrees = noJs.locator('nav.ctrees-pages-tree');
	assert.equal(await noJsTrees.count(), 2, 'Fixture contains two independent Pages trees.');
	assert.equal(await noJs.locator('nav.ctrees-pages-tree .ctrees-controls').count(), 2);
	assert.equal(
		await noJs.locator('nav.ctrees-pages-tree .ctrees-controls').first().isVisible(),
		false,
		'Bulk controls are hidden without JavaScript.',
	);
	const noJsFirstBranch = noJsTrees.first().locator('details.ctrees-page-branch').first();
	await noJsFirstBranch.locator(':scope > summary').focus();
	await noJs.keyboard.press('Space');
	assert.equal(
		await noJsFirstBranch.evaluate((node) => node.open),
		true,
		'Native keyboard disclosure works without JavaScript.',
	);
	await noJsContext.close();

	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
	page.setDefaultTimeout(10000);
	page.setDefaultNavigationTimeout(10000);
	page.on('pageerror', (error) => errors.push(error.message));
	await loadFixture(page, true, true);

	const trees = page.locator('nav.ctrees-pages-tree');
	const firstTree = trees.nth(0);
	const secondTree = trees.nth(1);
	const firstRoots = firstTree
		.locator(':scope > ul.ctrees-pages > li > details.ctrees-page-branch')
		.filter({ hasText: /^Pages (Alpha|Beta)/ });
	const secondRoots = secondTree
		.locator(':scope > ul.ctrees-pages > li > details.ctrees-page-branch')
		.filter({ hasText: /^Pages (Alpha|Beta)/ });
	assert((await firstRoots.count()) >= 2, 'First Pages tree has sibling parent branches.');
	assert((await secondRoots.count()) >= 2, 'Second Pages tree has sibling parent branches.');
	assert(
		(await firstRoots.first().locator('ul.ctrees-pages details.ctrees-page-branch').count()) >=
			1,
		'Fixture has a deeper branch.',
	);
	assert.equal(await firstTree.getAttribute('data-close-siblings'), '1');
	assert.equal(await secondTree.getAttribute('data-close-siblings'), '1');

	const openAll = firstTree.locator('.ctrees-controls button[data-ctrees-action="open"]');
	const closeAll = firstTree.locator('.ctrees-controls button[data-ctrees-action="close"]');
	const secondOpenAll = secondTree.locator('.ctrees-controls button[data-ctrees-action="open"]');
	const secondCloseAll = secondTree.locator(
		'.ctrees-controls button[data-ctrees-action="close"]',
	);
	assert.equal(await openAll.isVisible(), true, 'Open All appears after enhancement.');
	assert.equal(await closeAll.isVisible(), true, 'Close All appears after enhancement.');
	assert.equal(
		await page.locator('nav.ctrees-pages-tree .ctrees-announcement').count(),
		2,
		'Each enhanced Pages tree owns one announcement region.',
	);
	assert.equal(
		await page.locator('.ctrees[data-endpoint][data-messages] .ctrees-announcement').count(),
		1,
		'Archive keeps its own announcement region.',
	);
	assert.equal(
		await page.evaluate(() =>
			window.__pageClickHandlers.get(document.querySelector('nav.ctrees-pages-tree')),
		),
		1,
		'One delegated Pages click handler is installed.',
	);

	// Re-evaluating the Pages asset must not duplicate listeners or live regions.
	await page.addScriptTag({ content: pagesJs });
	assert.equal(await page.locator('nav.ctrees-pages-tree .ctrees-announcement').count(), 2);
	assert.equal(
		await page.evaluate(() =>
			window.__pageClickHandlers.get(document.querySelector('nav.ctrees-pages-tree')),
		),
		1,
	);

	const rootOne = firstRoots.nth(0);
	const rootTwo = firstRoots.nth(1);
	const deepBranch = rootOne.locator('ul.ctrees-pages details.ctrees-page-branch').first();
	await rootOne.locator(':scope > summary > .ctrees-toggle').click();
	await deepBranch.locator(':scope > summary > .ctrees-toggle').click();
	assert.equal(await rootOne.evaluate((node) => node.open), true);
	assert.equal(await deepBranch.evaluate((node) => node.open), true);
	await rootTwo.locator(':scope > summary > .ctrees-toggle').click();
	assert.equal(
		await rootTwo.evaluate((node) => node.open),
		true,
		'Activating a sibling opens that branch.',
	);
	assert.equal(
		await rootOne.evaluate((node) => node.open),
		false,
		'Opening a sibling closes the previous branch.',
	);
	assert.equal(
		await deepBranch.evaluate((node) => node.open),
		false,
		'Closing a parent also closes all descendants.',
	);

	// Keyboard activation follows native summary behavior and sibling rules.
	await rootOne.locator(':scope > summary').focus();
	await page.keyboard.press('Space');
	assert.equal(await rootOne.evaluate((node) => node.open), true, 'Space opens a Pages branch.');
	assert.equal(
		await rootTwo.evaluate((node) => node.open),
		false,
		'Keyboard opening closes its sibling.',
	);

	// Prevent navigation while checking that activating the nested page link
	// does not itself toggle its containing disclosure.
	await page.evaluate(() =>
		document.addEventListener(
			'click',
			(event) => {
				if (event.target.closest('a.ctrees-page-link')) event.preventDefault();
			},
			true,
		),
	);
	const firstLink = rootOne.locator(':scope > summary a.ctrees-page-link');
	await firstLink.click();
	assert.equal(
		await rootOne.evaluate((node) => node.open),
		true,
		'Page link activation does not toggle its disclosure.',
	);

	// Open All temporarily overrides close-siblings; Close All closes every
	// depth in its own Pages instance, leaving the other instance untouched.
	await secondRoots.first().locator(':scope > summary > .ctrees-toggle').click();
	await secondRoots
		.first()
		.locator('ul.ctrees-pages details.ctrees-page-branch')
		.first()
		.locator(':scope > summary > .ctrees-toggle')
		.click();
	await openAll.click();
	assert(
		(await firstTree.locator('details.ctrees-page-branch:not([open])').count()) === 0,
		'Open All opens all levels despite sibling-closing behavior.',
	);
	assert.equal(
		await secondTree.locator('details.ctrees-page-branch[open]').count(),
		2,
		'Bulk actions leave the second Pages tree state alone.',
	);
	await closeAll.click();
	assert.equal(
		await firstTree.locator('details.ctrees-page-branch[open]').count(),
		0,
		'Close All closes every level.',
	);
	assert.equal(await secondTree.locator('details.ctrees-page-branch[open]').count(), 2);
	const firstTreeAnnouncement = await firstTree.locator('.ctrees-announcement').textContent();
	await secondOpenAll.click();
	assert((await secondTree.locator('details.ctrees-page-branch:not([open])').count()) === 0);
	await secondCloseAll.click();
	assert.equal(await secondTree.locator('details.ctrees-page-branch[open]').count(), 0);
	assert.equal(
		await firstTree.locator('.ctrees-announcement').textContent(),
		firstTreeAnnouncement,
		'Actions on the second instance do not change the first instance announcement.',
	);

	const pageAnnouncements = await page
		.locator('nav.ctrees-pages-tree .ctrees-announcement')
		.allTextContents();
	assert(
		pageAnnouncements.every((text) => text.length > 0),
		'Each Pages instance announces its own completed bulk action.',
	);
	assert.equal(
		await page.evaluate(() => window.__fetchCalls),
		0,
		'Pages enhancement makes no fetch calls.',
	);
	await page.waitForTimeout(100);
	assert.equal(
		await page.evaluate(() => window.__fetchCalls),
		0,
		'Pages enhancement performs no deferred loading.',
	);

	// Coarse-pointer sizing contract applies to both the disclosure affordance
	// and the page link; emulate the media feature through a touch context.
	const touchContext = await browser.newContext({
		viewport: { width: 390, height: 844 },
		isMobile: true,
		hasTouch: true,
	});
	const touchPage = await touchContext.newPage();
	touchPage.setDefaultTimeout(10000);
	touchPage.setDefaultNavigationTimeout(10000);
	await loadFixture(touchPage, true);
	const touchSummary = touchPage
		.locator('nav.ctrees-pages-tree details.ctrees-page-branch > summary')
		.first();
	const arrowBox = await touchSummary.locator(':scope > .ctrees-toggle').boundingBox();
	const linkBox = await touchSummary.locator(':scope > a.ctrees-page-link').boundingBox();
	assert(
		arrowBox.width >= 44 && arrowBox.height >= 44,
		'Coarse-pointer disclosure target is at least 44 by 44 pixels.',
	);
	assert(
		linkBox.width >= 44 && linkBox.height >= 44,
		'Coarse-pointer page-link target is at least 44 by 44 pixels.',
	);
	await touchContext.close();

	assert.deepEqual(errors, [], 'No uncaught browser errors.');
	console.log(
		'PASS: native no-JavaScript disclosures, hidden bulk controls before enhancement, sibling closing and descendants, keyboard and link behavior, Open All/Close All, per-instance announcements, initializer idempotence, Archive scoping, no Pages loading/fetches, and coarse-pointer targets.',
	);
} finally {
	await browser.close();
}
