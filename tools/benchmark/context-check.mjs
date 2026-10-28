import crypto from 'node:crypto';
import fs from 'node:fs';

const base = new URL(process.argv[2] || 'http://127.0.0.1:8779/');
if (base.protocol !== 'http:' || base.hostname !== '127.0.0.1' || !base.port) throw new Error('Expected a loopback benchmark server.');
const result = {};
for (const scenario of ['category-closed', 'tag-closed']) {
	const runs = {};
	for (const variant of ['baseline', 'cache', 'cache', 'filtered', 'filtered-baseline', 'logged', 'logged-baseline', 'cache']) {
		const url = new URL(base);
		url.searchParams.set('scenario', scenario);
		url.searchParams.set('variant', variant);
		const response = await fetch(url, { cache: 'no-store' });
		if (!response.ok) throw new Error(`${scenario} ${variant}: HTTP ${response.status}`);
		const body = Buffer.from(await response.arrayBuffer());
		const metrics = JSON.parse(Buffer.from(response.headers.get('x-content-trees-benchmark'), 'base64').toString('utf8'));
		(runs[variant] ||= []).push({ sha256: crypto.createHash('sha256').update(body).digest('hex'), plugin_queries: metrics.plugin_queries });
	}
	if (runs.baseline[0].sha256 !== runs.cache[1].sha256 || runs.cache[1].plugin_queries >= runs.baseline[0].plugin_queries || runs.filtered[0].sha256 !== runs['filtered-baseline'][0].sha256 || runs.logged[0].sha256 !== runs['logged-baseline'][0].sha256 || runs.filtered[0].sha256 === runs.cache[1].sha256 || runs.cache[1].sha256 !== runs.cache.at(-1).sha256) {
		throw new Error(`${scenario}: context results differ from their uncached counterparts`);
	}
	result[scenario] = runs;
}
const file = 'tests/results/benchmark/context-check.json';
fs.writeFileSync(file, JSON.stringify(result, null, 2) + '\n', 'utf8');
console.log(`PASS: Separate anonymous, filtered and logged-in requests preserve their own output. Saved ${file}`);
