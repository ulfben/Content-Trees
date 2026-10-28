// Alternate baseline and cold-cache requests to reduce time-of-run bias.
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { performance } from 'node:perf_hooks';

const base = new URL(process.argv[2] || 'http://127.0.0.1:8779/');
const scenario = process.argv[3] || 'both-closed';
const php = process.env.CONTENT_TREES_BENCHMARK_PHP;
const phpArgs = JSON.parse(process.env.CONTENT_TREES_BENCHMARK_PHP_ARGS || '[]');
if (base.protocol !== 'http:' || base.hostname !== '127.0.0.1' || !base.port || !php || !process.env.CONTENT_TREES_TEST_WP_ROOT) throw new Error('A guarded loopback fixture and benchmark PHP environment are required.');
async function sample(variant) {
	if (variant === 'cold') {
		const cleared = execFileSync(php, [...phpArgs, 'tools/benchmark/transient-control.php'], { encoding: 'utf8' });
		if (cleared.trim() !== 'cleared') throw new Error('Could not clear benchmark transients.');
	}
	const url = new URL(base);
	url.searchParams.set('scenario', scenario);
	url.searchParams.set('variant', variant === 'cold' ? 'cache' : 'baseline');
	const start = performance.now();
	const response = await fetch(url, { cache: 'no-store', signal: AbortSignal.timeout(120000) });
	const body = await response.arrayBuffer();
	const latency_ms = performance.now() - start;
	if (!response.ok) throw new Error(`${variant}: HTTP ${response.status}`);
	const metrics = JSON.parse(Buffer.from(response.headers.get('x-content-trees-benchmark'), 'base64').toString('utf8'));
	if (metrics.external_object_cache || body.byteLength !== metrics.response_bytes) throw new Error('Unexpected cache state or response size.');
	return { ...metrics, latency_ms };
}
const data = { scenario, warmups: 5, samples_per_variant: 50, baseline: [], cold: [] };
for (let i = 0; i < 55; i++) {
	for (const variant of i % 2 ? ['cold', 'baseline'] : ['baseline', 'cold']) {
		const result = await sample(variant);
		if (i >= 5) data[variant].push(result);
	}
}
if (new Set([...data.baseline, ...data.cold].map((sample) => sample.dataset)).size !== 1) throw new Error('Dataset changed.');
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const file = `tests/results/benchmark/${data.baseline[0].dataset}-paired-cold-${scenario}-${stamp}.json`;
fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n', 'utf8');
console.log(`Saved ${file}`);
