import assert from 'node:assert/strict';
import fs from 'node:fs';
import { chromium } from 'playwright';

const browser = await chromium.launch({
	executablePath: process.env.CONTENT_TREES_BROWSER,
	headless: true,
});
const html = fs.readFileSync('tests/results/archive-render.html', 'utf8');
const responses = JSON.parse(fs.readFileSync('tests/results/archive-posts.json', 'utf8'));
const css = fs.readFileSync('shared/archive.css', 'utf8');
const errors = [];
try {
	const page = await browser.newPage();
	page.on('pageerror', (error) => errors.push(error.message));
	await page.setContent(`<style>${css}</style>${html}`);
	await page.evaluate((responses) => {
		const months = new Set(Object.keys(responses));
		document.querySelectorAll('.ctrees-month').forEach((month) => {
			if (!months.has(month.dataset.month)) month.remove();
		});
		document.querySelectorAll('.ctrees-year').forEach((year) => {
			if (!['1987', '1988'].includes(year.dataset.year)) year.remove();
		});
		window.archiveRequests = [];
		window.archiveActive = 0;
		window.archivePeak = 0;
		const attempts = {};
		window.fetch = async (url) => {
			const parsed = new URL(url);
			const route = parsed.searchParams.get('rest_route') || parsed.pathname;
			const match = route.match(/\/months\/(\d{4})\/(\d+)$/);
			if (!match) throw new Error('Unexpected Archive route');
			const key = `${match[1]}-${match[2].padStart(2, '0')}`;
			window.archiveRequests.push(key);
			window.archivePeak = Math.max(window.archivePeak, ++window.archiveActive);
			await new Promise((resolve) => setTimeout(resolve, 30));
			window.archiveActive--;
			attempts[key] = (attempts[key] || 0) + 1;
			if (key === '1987-01' && attempts[key] === 1) return { ok: false };
			return { ok: true, json: async () => responses[key] };
		};
	}, responses);
	for (const file of ['shared/post-loader.js', 'shared/tree.js', 'archive/archive.js'])
		await page.addScriptTag({ content: fs.readFileSync(file, 'utf8') });
	const grouped = page.locator('nav[aria-label="Grouped archive"]');
	const flat = page.locator('nav[aria-label="Flat archive"]');
	const compact = page.locator('nav[aria-label="Compact archive"]');
	assert.equal(await page.evaluate(() => window.archiveRequests.length), 0);
	const year = (value) => grouped.locator(`.ctrees-year[data-year="${value}"]`);
	const month = (tree, value) => tree.locator(`.ctrees-month[data-month="${value}"]`);
	await year(1987).locator(':scope > summary > .ctrees-toggle').click();
	await month(grouped, '1987-01').locator(':scope > summary > .ctrees-toggle').click();
	const retry = month(grouped, '1987-01').getByRole('button', { name: 'Try again' });
	await retry.waitFor({ state: 'visible' });
	await retry.click();
	await month(grouped, '1987-01').locator('.ctrees-posts a').first().waitFor();
	assert.equal(
		await month(grouped, '1987-01').locator('.ctrees-posts a').count(),
		responses['1987-01'].items.length,
	);
	assert.equal(
		await page.evaluate(() => document.activeElement?.dataset.postId),
		String(responses['1987-01'].items[0].id),
	);
	await month(grouped, '1987-02').locator(':scope > summary > .ctrees-toggle').click();
	assert.equal(await month(grouped, '1987-01').getAttribute('open'), null);
	await year(1988).locator(':scope > summary > .ctrees-toggle').click();
	assert.equal(await year(1987).getAttribute('open'), null);
	assert.equal(await year(1987).locator('details[open]').count(), 0);
	assert.equal(await flat.locator('details[open]').count(), 0);
	await grouped.getByRole('button', { name: 'Open All', exact: true }).click();
	await page.waitForFunction(
		() =>
			document.querySelector('nav[aria-label="Grouped archive"] .ctrees-announcement')
				.textContent === 'Archive loading finished.',
	);
	assert.equal(await grouped.locator('details[open]').count(), 5);
	for (const [key, response] of Object.entries(responses)) {
		assert.equal(
			await month(grouped, key).locator('.ctrees-posts a').count(),
			response.items.length,
		);
	}
	await grouped.getByRole('button', { name: 'Close All', exact: true }).click();
	assert.equal(await grouped.locator('details[open]').count(), 0);
	await flat.getByRole('button', { name: 'Open All', exact: true }).click();
	await page.waitForFunction(
		() =>
			document.querySelector('nav[aria-label="Flat archive"] .ctrees-announcement')
				.textContent === 'Archive loading finished.',
	);
	assert.equal(await flat.locator('details[open]').count(), 3);
	await flat.getByRole('button', { name: 'Close All', exact: true }).click();
	await month(flat, '1987-01').locator(':scope > summary > .ctrees-toggle').click();
	await month(flat, '1987-02').locator(':scope > summary > .ctrees-toggle').click();
	assert.equal(await month(flat, '1987-01').getAttribute('open'), null);
	assert.equal(await month(flat, '1987-02').getAttribute('open'), '');
	assert.ok(await page.evaluate(() => window.archivePeak <= 3));
	await compact.locator('.ctrees-year[data-year="1987"] > summary > .ctrees-toggle').click();
	const spacing = await compact
		.locator('.ctrees-year[data-year="1987"] .ctrees-month-link')
		.first()
		.evaluate((paragraph) => {
			const link = paragraph.querySelector('a').getBoundingClientRect();
			const count = paragraph.querySelector('.ctrees-count');
			return {
				gap: count.getBoundingClientRect().left - link.right,
				margin: Number.parseFloat(getComputedStyle(count).marginInlineStart),
			};
		});
	assert.ok(
		Math.abs(spacing.gap - spacing.margin) < 1,
		'Compact counts use the CSS margin without an extra template whitespace gap.',
	);
	assert.deepEqual(errors, []);
	console.log(
		'PASS: grouped/flat Archive controls, direct sibling closing, descendant closing, complete loads, retry/focus, shared queue and compact count spacing.',
	);
} finally {
	await browser.close();
}
