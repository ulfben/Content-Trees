// Interleave paired requests and real eligible-post changes on the disposable fixture.
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { performance } from 'node:perf_hooks';

const base = new URL(process.argv[2] || 'http://127.0.0.1:8779/');
const scenario = process.argv[3] || 'both-closed';
const interval = Number(process.argv[4] || 200);
const count = Number(process.argv[5] || 600);
const php = process.env.CONTENT_TREES_BENCHMARK_PHP;
const phpArgs = JSON.parse(process.env.CONTENT_TREES_BENCHMARK_PHP_ARGS || '[]');
if (base.protocol !== 'http:' || base.hostname !== '127.0.0.1' || !base.port || !php || !process.env.CONTENT_TREES_TEST_WP_ROOT || !Number.isInteger(interval) || interval < 1 || !Number.isInteger(count) || count < interval) throw new Error('Guarded fixture and valid sample counts required.');
function control(command) {
	return execFileSync(php, [...phpArgs, 'tools/benchmark/mix-control.php', command], { encoding: 'utf8' }).trim();
}
async function sample(variant) {
	const url = new URL(base);
	url.searchParams.set('scenario', scenario);
	url.searchParams.set('variant', variant);
	const start = performance.now();
	const response = await fetch(url, { cache: 'no-store', signal: AbortSignal.timeout(120000) });
	const body = Buffer.from(await response.arrayBuffer());
	const latency_ms = performance.now() - start;
	if (!response.ok) throw new Error(`${variant}: HTTP ${response.status}: ${body.toString('utf8').slice(0, 300)}`);
	const metrics = JSON.parse(Buffer.from(response.headers.get('x-content-trees-benchmark'), 'base64').toString('utf8'));
	if (metrics.external_object_cache || body.length !== metrics.response_bytes) throw new Error('Unexpected cache state or response size.');
	return { ...metrics, latency_ms, hash: createHash('sha256').update(body).digest('hex') };
}
const data = { scenario, interval, warmups: 5, samples_per_variant: count, baseline: [], cache: [] };
let started = false;
try {
	if (control('start') !== 'started') throw new Error('Could not reserve post.');
	started = true;
	for (let i = -5; i < count; i++) {
		const invalidated = i >= 0 && i % interval === 0;
		if (invalidated && control('toggle') !== 'toggled') throw new Error('Mutation failed.');
		const pair = {};
		for (const variant of i % 2 ? ['cache', 'baseline'] : ['baseline', 'cache']) pair[variant] = await sample(variant);
		if (pair.baseline.hash !== pair.cache.hash) throw new Error(`Output differs at pair ${i}.`);
		if (i >= 0) for (const variant of ['baseline', 'cache']) data[variant].push({ ...pair[variant], invalidated });
		if (i >= 0 && (i + 1) % 100 === 0) console.log(`${scenario} interval ${interval}: ${i + 1}/${count}`);
	}
	if (new Set([...data.baseline, ...data.cache].map((sample) => sample.dataset)).size !== 1) throw new Error('Dataset changed.');
	const stamp = new Date().toISOString().replace(/[:.]/g, '-');
	const file = `tests/results/benchmark/${data.baseline[0].dataset}-mixed-${scenario}-${interval}-${stamp}.json`;
	fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n', 'utf8');
	console.log(`Saved ${file}`);
} finally {
	if (started && control('restore') !== 'restored') {
		console.error('Restore failed; marker retained for recovery.');
		process.exitCode = 1;
	}
}
