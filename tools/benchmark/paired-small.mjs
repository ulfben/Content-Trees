// Interleave requests on the small fixture without clearing between requests.
import fs from 'node:fs';
import { createHash } from 'node:crypto';
import { performance } from 'node:perf_hooks';

const base = new URL(process.argv[2] || 'http://127.0.0.1:8779/');
const count = Number(process.argv[3] || 400);
if (base.protocol !== 'http:' || base.hostname !== '127.0.0.1' || !base.port || !Number.isInteger(count) || count < 50) throw new Error('Loopback endpoint and valid sample count required.');
async function sample(variant) {
	const url = new URL(base);
	url.searchParams.set('scenario', 'both-closed');
	url.searchParams.set('variant', variant);
	const start = performance.now();
	const response = await fetch(url, { cache: 'no-store', signal: AbortSignal.timeout(120000) });
	const body = Buffer.from(await response.arrayBuffer());
	const latency_ms = performance.now() - start;
	if (!response.ok) throw new Error(`HTTP ${response.status}`);
	const metrics = JSON.parse(Buffer.from(response.headers.get('x-content-trees-benchmark'), 'base64').toString('utf8'));
	if (metrics.external_object_cache || metrics.dataset !== 'small' || body.length !== metrics.response_bytes) throw new Error('Unexpected fixture.');
	return { ...metrics, latency_ms, hash: createHash('sha256').update(body).digest('hex') };
}
const data = { scenario: 'both-closed', warmups: 5, samples_per_variant: count, baseline: [], cache: [] };
for (let i = -5; i < count; i++) {
	const pair = {};
	for (const variant of i % 2 ? ['cache', 'baseline'] : ['baseline', 'cache']) pair[variant] = await sample(variant);
	if (pair.baseline.hash !== pair.cache.hash) throw new Error(`Output differs at pair ${i}.`);
	if (i >= 0) for (const variant of ['baseline', 'cache']) data[variant].push(pair[variant]);
	if (i >= 0 && (i + 1) % 100 === 0) console.log(`small: ${i + 1}/${count}`);
}
const file = `tests/results/benchmark/small-paired-repeat-${new Date().toISOString().replace(/[:.]/g, '-')}.json`;
fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n', 'utf8');
console.log(`Saved ${file}`);
