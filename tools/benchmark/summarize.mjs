import fs from 'node:fs';

const file = process.argv[2];
if (!file) throw new Error('Usage: node tools/benchmark/summarize.mjs RESULTS.json');
const run = JSON.parse(fs.readFileSync(file, 'utf8'));
function median(values) {
	const sorted = [...values].sort((a, b) => a - b);
	const middle = Math.floor(sorted.length / 2);
	return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}
function p95(values) {
	return [...values].sort((a, b) => a - b)[Math.ceil(values.length * 0.95) - 1];
}
console.log('| Scenario | HTTP median / p95 ms | Plugin median ms | Queries | Diagnostic query ms | Max SQL bytes | Peak MiB | Body bytes |');
console.log('| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |');
for (const scenario of [...new Set(run.diagnostics.map((diagnostic) => diagnostic.metrics.scenario))]) {
	const diagnostics = run.diagnostics.filter((diagnostic) => diagnostic.metrics.scenario === scenario);
	const samples = run.samples.filter((sample) => sample.scenario === scenario);
	if (samples.length !== run.samples_per_scenario) throw new Error('Missing samples.');
	const times = samples.map((sample) => sample.latency_ms);
	const plugin = samples.map((sample) => sample.plugin_seconds * 1000);
	const queryMs = median(diagnostics.map((diagnostic) => diagnostic.queries.reduce((sum, query) => sum + query.seconds * 1000, 0)));
	const maxSql = Math.max(0, ...diagnostics.flatMap((diagnostic) => diagnostic.queries.map((query) => Buffer.byteLength(query.sql, 'utf8'))));
	console.log(`| ${scenario} | ${median(times).toFixed(1)} / ${p95(times).toFixed(1)} | ${median(plugin).toFixed(1)} | ${median(samples.map((sample) => sample.queries))} | ${queryMs.toFixed(1)} | ${maxSql} | ${(median(samples.map((sample) => sample.peak_bytes)) / 1048576).toFixed(1)} | ${median(samples.map((sample) => sample.response_bytes))} |`);
}
