// Instrument SQL separately from render time; not a primary latency benchmark.
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const base = new URL(process.argv[2] || 'http://127.0.0.1:8779/');
const php = process.env.CONTENT_TREES_BENCHMARK_PHP;
const phpArgs = JSON.parse(process.env.CONTENT_TREES_BENCHMARK_PHP_ARGS || '[]');
if (base.protocol !== 'http:' || base.hostname !== '127.0.0.1' || !base.port || !php || !process.env.CONTENT_TREES_TEST_WP_ROOT) throw new Error('Guarded loopback fixture required.');
async function sample(variant) {
	if (variant === 'cache') {
		if (execFileSync(php, [...phpArgs, 'tools/benchmark/transient-control.php'], { encoding: 'utf8' }).trim() !== 'cleared') throw new Error('Clear failed.');
	}
	const url = new URL(base);
	url.searchParams.set('scenario', 'both-closed');
	url.searchParams.set('variant', variant);
	url.searchParams.set('diagnostic', '1');
	const response = await fetch(url, { cache: 'no-store', signal: AbortSignal.timeout(120000) });
	if (!response.ok) throw new Error(`HTTP ${response.status}`);
	const data = await response.json();
	if (data.metrics.external_object_cache) throw new Error('Unexpected object cache.');
	const end = data.metrics.queries;
	const queries = data.queries.slice(end - data.metrics.plugin_queries, end);
	return { metrics: data.metrics, query_seconds: queries.reduce((sum, query) => sum + query.seconds, 0), queries };
}
const data = { warmups: 3, samples_per_variant: 20, baseline: [], cache: [] };
for (let i = 0; i < 23; i++) {
	for (const variant of i % 2 ? ['cache', 'baseline'] : ['baseline', 'cache']) {
		const result = await sample(variant);
		if (i >= 3) data[variant].push(result);
	}
}
const file = `tests/results/benchmark/cold-diagnostic-${new Date().toISOString().replace(/[:.]/g, '-')}.json`;
fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n', 'utf8');
console.log(`Saved ${file}`);
