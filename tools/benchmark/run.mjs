// Separate HTTP requests, with query logging only in a distinct diagnostic request.
import fs from 'node:fs';
import path from 'node:path';
import { performance } from 'node:perf_hooks';
import { execFileSync } from 'node:child_process';

const base = process.argv[2];
const cache = process.argv[3];
if (!base || !['none', 'redis-cold', 'redis-warm'].includes(cache)) {
	throw new Error('Usage: node tools/benchmark/run.mjs http://127.0.0.1:PORT none|redis-cold|redis-warm');
}
const url = new URL(base);
if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1' || !url.port) {
	throw new Error('The benchmark server must be on an explicit IPv4 loopback port.');
}
const scenarios = ['category-closed', 'tag-closed', 'both-closed', 'category-names', 'tag-names', 'both-names', 'category-open', 'tag-open', 'both-open', 'category-rest', 'tag-rest'];
const root = path.resolve('tests/results/benchmark');
fs.mkdirSync(root, { recursive: true });
const samples = [];
const diagnostics = [];
let redisStatus = null;
const php = process.env.CONTENT_TREES_BENCHMARK_PHP;
const phpArgs = JSON.parse(process.env.CONTENT_TREES_BENCHMARK_PHP_ARGS || '[]');
const wpCli = process.env.CONTENT_TREES_BENCHMARK_WP_CLI;
function cacheControl(action) {
	const result = execFileSync(php, [...phpArgs, 'tools/benchmark/cache-control.php', action], { encoding: 'utf8' });
	return JSON.parse(result);
}
if (cache !== 'none') {
	if (!php || !wpCli || !process.env.CONTENT_TREES_TEST_WP_ROOT || !Array.isArray(phpArgs)) {
		throw new Error('Set CONTENT_TREES_BENCHMARK_PHP, CONTENT_TREES_BENCHMARK_WP_CLI, CONTENT_TREES_TEST_WP_ROOT and optional JSON CONTENT_TREES_BENCHMARK_PHP_ARGS.');
	}
	const guard = cacheControl('status');
	const status = execFileSync(php, [...phpArgs, wpCli, 'redis', 'status', `--path=${process.env.CONTENT_TREES_TEST_WP_ROOT}`], { encoding: 'utf8' });
	if (!/Status:\s*Connected\b/i.test(status)) throw new Error('Redis Object Cache did not report Connected.');
	redisStatus = { guard, status };
}
async function request(scenario, diagnostic = false) {
	const target = new URL(base);
	target.searchParams.set('scenario', scenario);
	if (diagnostic) target.searchParams.set('diagnostic', '1');
	const start = performance.now();
	const response = await fetch(target, { cache: 'no-store', redirect: 'error', signal: AbortSignal.timeout(120000) });
	const body = await response.arrayBuffer();
	const latencyMs = performance.now() - start;
	if (!response.ok) throw new Error(`${scenario}: HTTP ${response.status}: ${new TextDecoder().decode(body).slice(0, 500)}`);
	if (diagnostic) return JSON.parse(new TextDecoder().decode(body));
	const encoded = response.headers.get('x-content-trees-benchmark');
	if (!encoded) throw new Error(`${scenario}: missing measurement header`);
	const metrics = JSON.parse(Buffer.from(encoded, 'base64').toString('utf8'));
	if (metrics.external_object_cache !== (cache !== 'none')) throw new Error('Unexpected persistent object cache state; aborting.');
	if (body.byteLength !== metrics.response_bytes) throw new Error('Response size mismatch.');
	return { ...metrics, latency_ms: latencyMs };
}
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
let output;
for (const scenario of scenarios) {
	for (let i = 0; i < 5; i++) await request(scenario);
	for (let i = 0; i < 50; i++) {
		if (cache === 'redis-cold') cacheControl('flush');
		samples.push(await request(scenario));
	}
	for (let i = 0; i < 3; i++) {
		if (cache === 'redis-cold') cacheControl('flush');
		diagnostics.push(await request(scenario, true));
	}
	const dataset = samples[0].dataset;
	if (samples.some((sample) => sample.dataset !== dataset)) throw new Error('Dataset changed during measurement.');
	output ||= path.join(root, `${dataset}-${cache}-${stamp}.json`);
	fs.writeFileSync(output, JSON.stringify({ format: 1, cache, redis_status: redisStatus, warmups: 5, samples_per_scenario: 50, generated_at: new Date().toISOString(), samples, diagnostics }, null, 2) + '\n', 'utf8');
	console.log(`Measured ${scenario}`);
}
console.log(`Saved ${output}`);
