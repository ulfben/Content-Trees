// Four independently served simultaneous requests; clear only the owned snapshots.
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { performance } from 'node:perf_hooks';

const ports = process.argv.slice(2).map(Number);
const php = process.env.CONTENT_TREES_BENCHMARK_PHP;
const phpArgs = JSON.parse(process.env.CONTENT_TREES_BENCHMARK_PHP_ARGS || '[]');
if (ports.length < 2 || ports.some(p => !Number.isInteger(p) || p < 1 || p > 65535) || !php || !process.env.CONTENT_TREES_TEST_WP_ROOT) throw new Error('Independent loopback servers and guarded fixture required.');
async function burst(variant) {
	if (variant === 'cache') {
		const result = execFileSync(php, [...phpArgs, 'tools/benchmark/transient-control.php'], { encoding: 'utf8' });
		if (result.trim() !== 'cleared') throw new Error('Snapshot clear failed.');
	}
	return Promise.all(ports.map(async port => {
		const start = performance.now();
		const response = await fetch(`http://127.0.0.1:${port}/?scenario=both-closed&variant=${variant}`, { cache: 'no-store', signal: AbortSignal.timeout(120000) });
		const body = Buffer.from(await response.arrayBuffer());
		const latency_ms = performance.now() - start;
		if (!response.ok) throw new Error(`HTTP ${response.status}`);
		const metrics = JSON.parse(Buffer.from(response.headers.get('x-content-trees-benchmark'), 'base64').toString('utf8'));
		if (metrics.external_object_cache || body.byteLength !== metrics.response_bytes) throw new Error('Unexpected cache state.');
		return { ...metrics, latency_ms, hash: createHash('sha256').update(body).digest('hex') };
	}));
}
const data = { ports, warmups: 3, bursts: 20, baseline: [], cache: [] };
for (let i = 0; i < 23; i++) {
	const pair = {};
	for (const variant of i % 2 ? ['cache', 'baseline'] : ['baseline', 'cache']) {
		const result = await burst(variant);
		pair[variant] = result;
		if (i >= 3) data[variant].push(result);
	}
	if (new Set([...pair.baseline, ...pair.cache].map(sample => sample.hash)).size !== 1) throw new Error(`Concurrent output differs at burst ${i}.`);
}
const file = `tests/results/benchmark/${data.baseline[0][0].dataset}-burst-${new Date().toISOString().replace(/[:.]/g, '-')}.json`;
fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n', 'utf8');
console.log(`Saved ${file}`);
