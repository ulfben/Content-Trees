# Taxonomy performance

Measurements on 28 October 2026 started from Content Trees 0.9.10, commit `4d46d092a902939ab8945927a92025ae3db2832d`. The checkout had no uncommitted changes or newer commits. Benchmark tooling is commit `f708d8e` on `perf/taxonomy`; the plugin implementation is unchanged.

## Decision

Do not add the proposed SQL rewrite or a Content Trees cache based on these measurements. On the large dataset without a persistent object cache, the ID query and membership query were a substantial part of closed-tree rendering. Nevertheless, replacing the ID list with that query as a subquery did not improve the database-level timings on MariaDB: it was approximately equal or slower for categories and consistently slower for tags. It passed the unfiltered row comparison, but this experiment is not a production implementation and does not establish filter compatibility. A persistent cache was unavailable for measurement. No conclusion is claimed about its performance.

Before the experiment, the acceptance threshold for a production change was a repeatable improvement of at least 15% and 10 ms in a meaningful large-site scenario, without a material p95 regression or a small-site median penalty exceeding the larger of 5% and 5 ms. Correct public results and the existing filter behavior are mandatory. The SQL probe found no database improvement and cannot demonstrate that threshold; a full production candidate and its HTTP comparison were not pursued. Its unmeasured PHP ID-list construction cost remains a limitation. No caching candidate was implemented or benchmarked; the available evidence does not justify its complexity. Revisit these decisions with a representative workload and real cache measurements if site requirements warrant it.

## Environment and method

- Windows 11 x64, PHP 8.5.11, WordPress 7.1.3, MariaDB 11.4.9, Node 24.22.1. PHP's built-in loopback HTTP server ran with a 512 MiB memory limit and CLI OPcache disabled. MariaDB used a 128 MiB InnoDB buffer pool and its query cache was off. Its buffers were not flushed between samples; the warm-ups primed them. OS scheduling and disk caches were not controlled.
- A separate, marked WordPress installation used its own table prefix in the guarded loopback test database. The only active plugin was this checkout. The object cache was WordPress's request-local implementation, and `wp_using_ext_object_cache()` was false on every measured request. The test endpoint rejected full-page-cache configuration and unexpected active plugins. No HTTP proxy or CDN was involved.
- Each run used five fresh HTTP warm-up requests and 50 fresh measured HTTP requests per scenario. The small run was repeated because of variation, and its table pools the 100 measured requests and six diagnostics from the two runs. The HTTP time includes bootstrap, the synthetic endpoint and the complete response transfer. The plugin column measures tree rendering, or the REST callback between WordPress's before/after callback filters. The harness calls WordPress's REST server anonymously, but does not use a themed page or the ordinary REST URL; absolute whole-page timings should not be generalized to a production web server.
- Query counts come from the primary requests. SQL time and maximum SQL length come from three subsequent `SAVEQUERIES` diagnostic requests per run, not the timed samples. Query time is the median of those diagnostic request totals; instrumentation affects it. Peak memory is the median allocated PHP peak; response bytes are the uncompressed body size. P95 is the nearest-rank 95th percentile.
- Redis Object Cache and a Redis server were not available. Docker, WSL and MySQL 8.0 were also unavailable. No persistent cache was simulated. The benchmark's Redis modes are included for a separately provisioned, dedicated loopback Redis instance, but were not exercised here.

The fixed seed was `1729`. The large fixture contained 20,000 standard posts: 19,200 published and password-free, 400 drafts, and 400 password-protected. It generated 300 categories and 3,000 tags, with 39,907 category and 59,982 tag assignments including ineligible posts. The fresh installation's empty default category makes 301 categories visible with empty terms enabled. Categories reached at least six levels. Independent database checks found 38,310 eligible direct category memberships and 57,583 eligible tag memberships. There were 31 categories and 300 tags with no eligible direct memberships; category direct membership p50/p95 was 85/103, with six hot terms at 2,548–2,679, and tag p50/p95 was 15/22, with ten hot terms at 1,497–1,612. The main category root had 19,200 unique eligible posts in its subtree. The selected category and tag REST branches contained 2,616 and 1,498 complete eligible items respectively. The verifier compared every direct membership and visible subtree count with independently read database rows and checked both selected REST result sets.

The small fixture had 500 posts: 480 eligible, 10 drafts and 10 password-protected. It generated 20 categories and 100 tags, plus the installation's empty default category, with 965 category and 1,480 tag assignments including ineligible posts. It had 926 eligible direct category memberships and 1,422 tag memberships; 3 categories and 10 tags had no eligible direct memberships. Category direct membership p50/p95 was 34/97, and tag p50/p95 was 12/47. The main category root had 480 unique eligible posts, and the selected category and tag REST branches contained 97 and 51 items. Both generators reserve the final 10% of each taxonomy's generated terms from assignments and create overlapping assignments, ineligible posts and uneven branches.

## Options and results

For closed trees, `auto_open=false`, `show_posts=true`, `show_counts=true`, and `hide_empty=true`. Name-only uses `auto_open=false`, `show_posts=false`, `show_counts=false`, and `hide_empty=false`, so it exercises the fast path. Open uses the closed settings plus `open_to` set to the first generated category or tag. Both-tree scenarios render both providers in one request. Other normalized options use the plugin defaults: no root or exclusions, terms sorted by name ascending, posts by date descending, title shown, and controls and sibling closing off. REST uses the first generated category or tag, date descending and no exclusions. Each diagnostic records the complete normalized options and term ID.

### Large, no persistent object cache

| Scenario | HTTP median / p95 ms | Plugin median ms | Queries | Diagnostic query ms | Max SQL bytes | Peak MiB | Body bytes |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| category-closed | 253.7 / 281.9 | 212.0 | 13 | 157.4 | 117099 | 40.0 | 103054 |
| tag-closed | 368.6 / 409.0 | 330.3 | 12 | 222.3 | 130597 | 56.0 | 1058205 |
| both-closed | 521.8 / 625.1 | 478.5 | 18 | 334.7 | 130597 | 58.0 | 1161259 |
| category-names | 46.1 / 54.5 | 7.7 | 10 | 5.8 | 1628 | 6.0 | 33920 |
| tag-names | 108.9 / 130.1 | 64.8 | 9 | 12.9 | 15126 | 14.0 | 356908 |
| both-names | 108.0 / 126.9 | 65.5 | 12 | 15.8 | 15126 | 14.0 | 390828 |
| category-open | 400.0 / 441.6 | 353.2 | 17 | 155.9 | 117099 | 52.0 | 350196 |
| tag-open | 441.8 / 531.1 | 396.4 | 16 | 193.1 | 130597 | 60.0 | 1199730 |
| both-open | 795.7 / 972.9 | 743.8 | 25 | 581.9 | 130597 | 78.0 | 1549926 |
| category-rest | 274.4 / 322.7 | 226.1 | 15 | 60.3 | 18649 | 44.0 | 223558 |
| tag-rest | 163.4 / 204.8 | 118.2 | 15 | 39.2 | 10823 | 30.0 | 128003 |

The closed category diagnostics attributed 61–73 ms to the ID query and 69–87 ms to the membership query; tag diagnostics attributed 78–102 ms and 111–124 ms respectively. These ranges are from instrumented requests, not components measured inside the 50 primary requests. Large tag name-only rendering also spent substantial time outside SQL and emitted 357 KB. Initially open and REST scenarios pay additional costs to prepare and send post links; response size matters independently of membership SQL.

### Small, no persistent object cache

| Scenario | HTTP median / p95 ms | Plugin median ms | Queries | Diagnostic query ms | Max SQL bytes | Peak MiB | Body bytes |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| category-closed | 46.6 / 56.4 | 7.5 | 13 | 6.8 | 3379 | 6.0 | 8012 |
| tag-closed | 48.2 / 61.2 | 10.1 | 12 | 7.7 | 3777 | 6.0 | 36086 |
| both-closed | 61.1 / 68.7 | 17.1 | 18 | 12.0 | 3777 | 6.0 | 44098 |
| category-names | 46.1 / 51.8 | 1.7 | 10 | 2.3 | 234 | 4.0 | 4800 |
| tag-names | 46.0 / 51.3 | 2.7 | 9 | 2.5 | 626 | 4.0 | 12708 |
| both-names | 45.6 / 56.2 | 3.9 | 12 | 3.2 | 626 | 4.0 | 17508 |
| category-open | 61.9 / 73.0 | 20.2 | 17 | 14.6 | 3379 | 6.0 | 17063 |
| tag-open | 62.4 / 75.1 | 20.6 | 16 | 14.4 | 3777 | 6.0 | 40871 |
| both-open | 96.6 / 236.2 | 54.0 | 25 | 28.9 | 3777 | 6.0 | 57934 |
| category-rest | 61.1 / 75.2 | 13.4 | 15 | 15.0 | 1016 | 8.0 | 8138 |
| tag-rest | 68.6 / 166.8 | 24.4 | 15 | 19.3 | 694 | 6.0 | 4286 |

The individual run medians for both-open were 126.1 and 89.2 ms; tag REST medians were 75.0 and 64.6 ms. The cause of that variation was not isolated, so small differences in those scenarios should not be treated as reliable improvements. The small SQL probe below compares query execution only; no candidate HTTP overhead was measured.

### Persistent object cache

Redis cold and warm: **not measured**. There is no before/after HTTP table for an SQL or cache implementation because neither was retained. The tables above and below describe the unchanged baseline, not an inferred speedup.

## SQL experiment and compatibility

The probe used the actual unfiltered ID and membership SQL recorded for each large closed-tree scenario. It replaced only the `tr.object_id IN (...)` list with the ID SELECT, retained the membership query's independent public-post checks, and compared all resulting `(post, term)` pairs. Five warm-ups and 20 alternating database-level samples per variant were followed by `EXPLAIN`; the full comparison was repeated. These are not 50 fresh HTTP requests and omit PHP's ID-list construction, so they do not quantify a production implementation's whole-request effect.

| Dataset and scenario | Baseline median ms | Subquery median ms | Repeated baseline / subquery ms | Equal membership rows |
| --- | ---: | ---: | ---: | ---: |
| Large categories | 146.6 | 148.7 | 166.3 / 180.0 | 38,310 |
| Large tags | 170.8 | 208.0 | 174.8 / 214.4 | 57,583 |
| Small categories | 3.96 | 2.89 | Not repeated | 926 |
| Small tags | 4.68 | 7.48 | Not repeated | 1,422 |

The combined SQL text fell from 119,022 to 3,823 bytes for categories and from 146,018 to 30,819 bytes for tags. MariaDB's candidate plans joined the posts and relationships again; the tag plan also included derived tables for the large term lists. Shorter SQL alone did not translate into a faster query. The captured plans and all samples are in the raw results. MySQL 8.0 plans were not tested because that server was unavailable.

Inspection of the installed WordPress 6.3 and 7.1.3 `WP_Query::get_posts()` implementations shows that the existing ID-only query applies `pre_get_posts`, SQL clause filters and `posts_request`, followed by `posts_pre_query`. A non-null `posts_pre_query` result short-circuits the database query. When `fields` remains `ids`, both versions return from the ID path before ordinary `posts_results` and `the_posts` filters; `posts_request_ids` belongs to a later split-object-query path. A filter can change query variables or SQL, so a future rewrite must verify the exact query, preserve externally supplied results and fall back whenever equivalence is uncertain. Existing exclusion handling and the independent eligibility checks must also remain. The SQL probe did not test these filter cases and is not evidence that a rewrite preserves them.

## Cache assessment

Request-local reuse can avoid repeated identical work within one request, but provides no cross-request benefit and must account for mutable filters and context. WordPress's object-cache API can provide cross-request reuse only with a persistent backend; WordPress already caches eligible post-query results when its query is cacheable. That does not eliminate this plugin's membership processing, and no Redis comparison was available to quantify what remains.

Database-backed transients can persist on sites without an external cache, but require durable invalidation on post publication, status/password changes, deletion, reassignment, term edits/deletion and reparenting, as well as an expiry and obsolete-entry cleanup strategy. A key containing SQL and the posts/terms `last_changed` values alone is unsuitable there: without a persistent object cache those values are regenerated between requests. Identical SQL also cannot prove that filter, user, locale or presentation contexts are interchangeable. A safely reusable data layer and its context would have to be established before adding any cache; no such invalidation or cross-context tests are claimed here.

HTTP or CDN caching of REST responses is outside this change. Anonymous requests or `credentials: 'omit'` alone do not establish that responses are safe to share across users, filters or request contexts; cacheability would need its own context and invalidation analysis.

## Verification and reproduction

The starting checkout passed PHP syntax, encoding, metadata, bootstrap, JavaScript lint and formatting checks, and the WordPress/PHP coding-standard and compatibility scan. PHPCS reported no errors and one existing `tax_query` performance warning. The current WordPress 7.1.3/PHP 8.5.11 and minimum WordPress 6.3/PHP 7.4.33 integration suites passed, including the additional taxonomy baseline and feature checks. All five browser regression suites passed on the current fixture. Runtime Plugin Check reported no errors and three existing performance warnings: exclusionary query arguments in Archive and taxonomy selectors, and the taxonomy `tax_query` warning. Automated browser tests do not establish screen-reader compatibility. The test fixture's temporary Plugin Check object-cache drop-in was removed.

The following PowerShell commands reproduce the measured run on this workspace's **separate benchmark installation** after its dataset is removed. The site was created with its own `ctbench_` table prefix and sample post removed, and has `CONTENT_TREES_DISPOSABLE_TEST_SITE` and `CONTENT_TREES_BENCHMARK_SITE` both set to `true`. It connects only to the guarded `content_trees_test` database at `127.0.0.1:33080` and activates this checkout. On another machine, first create an empty disposable WordPress installation with those markers and guards, and activate the exact checkout; do not use an existing populated site. See [benchmark setup](../tools/benchmark/README.md) for the requirements and Redis procedure.

```powershell
Set-Location 'D:\Temp\wordpress-plugins\repositories\content-trees'
$env:CONTENT_TREES_TEST_WP_ROOT = 'D:\Temp\wordpress-plugins\repositories\content-trees\tests\results\benchmark\site'
$php = 'D:\Temp\wordpress-plugins\tools\php\php.exe'
$phpArgs = @('-d', 'memory_limit=512M', '-d', 'extension=mysqli', '-d', 'extension=pdo_mysql')
& $php @phpArgs tools/benchmark/dataset.php create large | Set-Content tests/results/benchmark/large-dataset.json -Encoding utf8NoBOM
& $php @phpArgs tools/benchmark/verify.php | Set-Content tests/results/benchmark/large-verify.json -Encoding utf8NoBOM
& $php @phpArgs -S 127.0.0.1:8779 tools/benchmark/router.php
# In a second PowerShell, from the same repository:
node tools/benchmark/run.mjs http://127.0.0.1:8779/ none
# Substitute the actual output filename from that command:
& $php @phpArgs tools/benchmark/sql-probe.php tests/results/benchmark/large-none-RESULT.json | Set-Content tests/results/benchmark/large-sql-probe.json -Encoding utf8NoBOM
node tools/benchmark/summarize.mjs tests/results/benchmark/large-none-RESULT.json
# Stop the server and remove only the marked data before switching sizes:
& $php @phpArgs tools/benchmark/dataset.php remove
& $php @phpArgs tools/benchmark/dataset.php create small | Set-Content tests/results/benchmark/small-dataset.json -Encoding utf8NoBOM
& $php @phpArgs tools/benchmark/verify.php | Set-Content tests/results/benchmark/small-verify.json -Encoding utf8NoBOM
```

Restart the server, run the Node command twice for the small dataset and run the SQL probe with one of those result filenames. To reproduce the pooled small table, substitute the two output files in the following commands:

```powershell
node -e 'const fs=require("node:fs"); const a=JSON.parse(fs.readFileSync(process.argv[1],"utf8")); const b=JSON.parse(fs.readFileSync(process.argv[2],"utf8")); a.samples.push(...b.samples); a.diagnostics.push(...b.diagnostics); a.samples_per_scenario+=b.samples_per_scenario; fs.writeFileSync(process.argv[3],JSON.stringify(a,null,2)+"\n","utf8");' tests/results/benchmark/small-none-FIRST.json tests/results/benchmark/small-none-SECOND.json tests/results/benchmark/small-combined-summary-input.json
node tools/benchmark/summarize.mjs tests/results/benchmark/small-combined-summary-input.json
```

Then stop the server and run `& $php @phpArgs tools/benchmark/dataset.php remove`. The actual file names and combined small-result input are retained under the ignored results directory. The sample commands assume the variables are also set in the second shell and are shown for this local environment; PHP, WordPress and database dependencies are not added to the repository.

Raw dataset manifests, verifier output, individual HTTP samples, SQL diagnostics and probe plans are UTF-8 JSON under ignored `tests/results/benchmark/`. They are local evidence and are not included in the plugin delivery allowlist or run as timing assertions in CI. The SQL probe requires the dataset used to generate its diagnostic input to remain installed. After these runs, the generated posts and terms were removed, the ownership marker was absent, and the benchmark HTTP and disposable database servers were stopped. The empty benchmark installation remains available for reproduction.
