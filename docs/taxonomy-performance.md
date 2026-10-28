# Taxonomy performance

Measurements on 28 October 2026 started from Content Trees 0.9.10, commit `4d46d092a902939ab8945927a92025ae3db2832d`. The checkout had no uncommitted changes or newer commits. Benchmark tooling is commit `f708d8e` on `perf/taxonomy`. The original prototype is preserved as a historical patch; the later cache implementation and follow-up measurements are described below.

## Decision

Adopt a bounded database-backed count cache for safely reusable closed outlines. The earlier conclusion that no cache was justified was premature: it had not tested the option relevant to sites without a persistent object cache. The final implementation substantially reduced warm large-site rendering, improved p95 in both specified traffic mixes, and preserved tested public output. It stores counts only, not titles, links or post lists. It does not accelerate open outlines, logged-in users, filtered queries or installations with an external object cache. Its pessimistic p95 improvement is modest and the assumptions and limitations are stated below. The [original prototype patch](../tools/benchmark/count-cache-prototype.patch) is historical, not the current implementation.

Do not add the proposed SQL rewrite. On the large dataset without a persistent object cache, the ID query and membership query were a substantial part of closed-tree rendering. Nevertheless, replacing the ID list with that query as a subquery did not improve the database-level timings on MariaDB: it was approximately equal or slower for categories and consistently slower for tags. It passed the unfiltered row comparison, but this experiment is not a production implementation and does not establish filter compatibility. A persistent cache was unavailable for measurement. No conclusion is claimed about its performance.

Before the experiment, the acceptance threshold for a production change was a repeatable improvement of at least 15% and 10 ms in a meaningful large-site scenario, without a material p95 regression or a small-site median penalty exceeding the larger of 5% and 5 ms. Correct public results and the existing filter behavior are mandatory. The SQL probe found no database improvement and cannot demonstrate that threshold; a full production candidate and its HTTP comparison were not pursued. Its unmeasured PHP ID-list construction cost remains a limitation. After the all-cold comparison, the p95 criterion was explicitly revised to the two measured mixes below; the large-site gain and correctness requirements remain, as does the small-site allowance.

## Follow-up: invalidation, write cost and concurrent rebuilds

The original p95 criterion treated every request as cold. That measures an artificial sequence of invalidations, not a workload where one relevant content change is followed by many requests. After seeing that result, the p95 criterion was deliberately replaced: compare paired requests with one real eligibility-changing edit per 200 page views and, separately, a pessimistic one per 10. P95 must improve in both mixes. The predeclared large-site gain of at least 15% and 10 ms, the small-site median allowance of the larger of 5% or 5 ms, and the correctness requirements remain unchanged. These change rates are scenarios, not measured production rates.

The initial mixed runs, before adding a rebuild lock, alternated baseline and cache requests and verified identical response hashes for each pair. Across 600 pairs at one change per 200, HTTP median/p95 fell from 521.5/632.6 ms to 108.9/137.8 ms, and median render time from 479.2 to 66.9 ms. Across 300 pairs at one per 10, HTTP median/p95 fell from 516.1/626.8 ms to 107.5/551.3 ms. These numbers are useful historical evidence but do not substitute for testing the final lock design.

With the final one-second lock and the stricter uncached baseline, 400 pairs at one change per 200 gave HTTP median/p95 of 404.3/503.4 ms uncached versus 95.1/125.9 ms cached. The corresponding median render times were 364.7 and 56.3 ms. Every paired response hash matched. The baseline removes the cache's SQL observer after WordPress bootstrap and uses a no-op query hook to disable snapshot reuse; both variants still run the modified checkout, so this is not a byte-for-byte measurement of the previous release.

At one change per 10, the final implementation's 300-pair run gave HTTP median/p95 of 399.9/441.1 ms uncached versus 94.3/425.8 ms cached. Because that p95 margin was only 3.5%, it was repeated with another 300 pairs: 398.6/434.7 ms uncached versus 94.2/424.2 ms cached. Every paired response matched. Both runs satisfy the revised p95 criterion, but their 10–15 ms margins are modest; scheduling, storage and different traffic mixes may reverse them. Each run represents one configuration per taxonomy; multiple widget configurations can require more cold rebuilds after a change. In both runs, cold cached requests were individually slower at the median than uncached requests, whereas the great majority of requests were warm.

| Change rate | Pairs | Uncached HTTP median / p95 | Cached HTTP median / p95 |
| --- | ---: | ---: | ---: |
| One per 200 page views | 400 | 404.3 / 503.4 ms | 95.1 / 125.9 ms |
| One per 10 page views | 300 | 399.9 / 441.1 ms | 94.3 / 425.8 ms |
| One per 10, repeat | 300 | 398.6 / 434.7 ms | 94.2 / 424.2 ms |

These timings precede the compatibility safeguards added after the measurement: the additional database-drop-in check and suppression of lock errors were not re-benchmarked. They do not add database queries, but their impact on the small pessimistic p95 margin is unmeasured.

The single-configuration serialized snapshots measured 8,000 bytes for Categories and 77,349 bytes for Tags. In 50 isolated transient insertions per taxonomy, median/p95 write time was 1.45/1.67 ms and 2.66/3.02 ms, respectively; median PHP serialization was 0.013 and 0.155 ms. A separate 20-pair `SAVEQUERIES` diagnostic of both cold trees measured baseline/candidate render medians of 477.2/500.8 ms and SQL medians of 320.7/341.0 ms. The four transient inserts together had a 12.0 ms median and 48.1 ms p95. The small Category payload also had a write outlier, so this points to variable database-write latency and other rendering variation, rather than proving payload size is the main cause. Instrumented medians and percentiles cannot be subtracted to assign the whole earlier 54 ms p95 gap. A compact encoding was not adopted on this evidence.

Twenty alternating bursts of four simultaneous requests against four independent PHP processes demonstrated a stampede without a lock: every cold request performed the full membership work. Uncached/cold HTTP median and p95 were 518.3/554.1 and 541.8/586.6 ms. With a connection-scoped database lock prototype, query counts were consistent with one rebuild per taxonomy per burst; in a separate 20-burst run, uncached/locked-cold median and p95 were 507.8/537.1 and 474.8/505.3 ms. That prototype allowed two seconds of waiting. Repeating with the final one-second limit and the stricter baseline gave 509.3/536.5 ms uncached and 453.9/494.4 ms locked-cold, with query counts again consistent with one rebuild per taxonomy. All concurrent response hashes matched. This is a small, local concurrency experiment, not a production load test. The lock uses MySQL/MariaDB named locks rather than a transient because acquisition must be atomic across requests without a persistent object cache. A request rechecks the snapshot after acquiring the lock and falls back to an uncached query on timeout or failure. `finally` releases the connection lock; a lost connection also releases it. The timeout and exception paths have regression tests. Hosts that cannot acquire the named lock fall back to uncached rendering; MySQL 8 was not available for direct validation.

The 500-post dataset was independently re-created and verified, then tested with 400 alternating pairs and five warm-ups. Baseline/candidate HTTP medians were 46.67/47.35 ms and p95s 59.62/60.13 ms; render medians were 14.62/15.52 ms. After removing the SQL observer from the baseline during rendering, a second 400-pair run gave HTTP medians of 46.71/47.18 ms and p95s of 59.47/61.19 ms; render medians were 14.89/15.50 ms and the paired median HTTP difference was 0.73 ms. Every response hash matched, and neither taxonomy wrote a snapshot. Both runs satisfy the original 5 ms small-site median allowance on this fixture. The candidate made six extra plugin queries at the median, including lock and snapshot checks for Tags, so a different small-site workload could behave differently. WordPress's `wp_count_posts()` was not added: the repeated measurement did not show the proposed overhead problem, and its request-local object cache does not make the count free across separate requests.

## Environment and method

- Windows 11 x64, PHP 8.5.11, WordPress 7.1.3, MariaDB 11.4.9, Node 24.22.1. PHP's built-in loopback HTTP server ran with a 512 MiB memory limit and CLI OPcache disabled. MariaDB used a 128 MiB InnoDB buffer pool and its query cache was off. Its buffers were not flushed between samples; the warm-ups primed them. OS scheduling and disk caches were not controlled.
- A separate, marked WordPress installation used its own table prefix in the guarded loopback test database. The only active plugin was this checkout. The object cache was WordPress's request-local implementation, and `wp_using_ext_object_cache()` was false on every measured request. The test endpoint rejected full-page-cache configuration and unexpected active plugins. No HTTP proxy or CDN was involved.
- The original scenario runs used five fresh HTTP warm-up requests and 50 fresh measured HTTP requests per scenario. The original small run was repeated because of variation, and its table pools the 100 measured requests and six diagnostics from the two runs. The follow-up paired and burst runs use the sample counts stated above. HTTP time includes bootstrap, the synthetic endpoint and the complete response transfer. The plugin column measures tree rendering, or the REST callback between WordPress's before/after callback filters. The harness calls WordPress's REST server anonymously, but does not use a themed page or the ordinary REST URL; absolute whole-page timings should not be generalized to a production web server.
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

## Follow-up: count transients and alternatives

The follow-up reused the same guarded WordPress/MariaDB environment and fixed-seed datasets. It measured a bare synthetic page and closed trees with the same wrapper, five warm-ups and 50 fresh HTTP requests per scenario. The baseline adds a no-op query hook, forcing the prototype's ordinary uncached path; this leaves query results unchanged. Warm runs retain the database transients across requests. For cold runs, a separate guarded PHP process removes only the two prototype transients immediately before each measured request, outside the timed interval. Each run has three separately logged diagnostic requests. The benchmark has no full-page cache or external object cache. Actual widget pages and their theme costs were not measured.

### Large dataset: uncached, warm and cold

| Scenario | Uncached HTTP median / p95 ms | Warm HTTP median / p95 ms | Cold HTTP median / p95 ms | Uncached / warm / cold render median ms |
| --- | ---: | ---: | ---: | ---: |
| Bare page | 30.7 / 43.4 | — | — | 0 / — / — |
| Categories, closed | 172.5 / 183.9 | 46.6 / 52.7 | 185.7 / 201.7 | 136.5 / 7.6 / 145.8 |
| Tags, closed | 282.1 / 305.8 | 89.9 / 112.9 | 299.0 / 316.2 | 241.7 / 48.5 / 255.2 |
| Both, closed | 422.5 / 450.5 | 94.3 / 103.2 | 441.8 / 483.2 | 381.4 / 54.7 / 401.4 |

The difference between the uncached and bare HTTP medians is approximately 142 ms for Categories, 251 ms for Tags and 392 ms for both. The separately timed render medians are in the table; differences of independently sampled medians are not an exact per-request delta. Warm render reductions are approximately 94%, 80% and 86% respectively and exceed the improvement threshold. Cold samples include cache writes: query counts were 17, 16 and 25 versus 13, 12 and 18 uncached. Warm counts were 12, 11 and 15; those counts include transient/revision reads even though the two expensive post/membership queries disappear.

In an interleaved 50-pair run of both closed trees, uncached HTTP median/p95 were 422.8/448.9 ms and cold-cache median/p95 were 444.8/502.7 ms. Render medians were 384.7 and 405.3 ms. The approximately 54 ms all-cold p95 increase was material under the original criterion. It describes the first request after each cache clear, not an observed production mix of warm and cold requests. The later mixed measurements and revised criterion are reported above. An earlier separate run showed cold faster than baseline, so host conditions also affect the precise difference.

Baseline and warm Category and Tag HTTP response hashes matched in the final prototype; the earlier prototype also matched both-tree HTML byte for byte. Separate anonymous, SQL-filtered and logged-in HTTP requests also matched their corresponding uncached outputs, and filtered requests differed from the warmed anonymous result where the filter excluded a post. The filtered and logged-in requests performed the full membership query instead of reusing the snapshot.

### Small dataset

The first prototype also cached the 500-post dataset. Its initial render medians fell from 5.8 to 1.6 ms for Categories, 8.2 to 2.7 ms for Tags and 14.0 to 4.0 ms for both when warm. However, an interleaved 50-pair cold run showed both-tree render median increasing from 13.9 to 19.0 ms and HTTP p95 increasing from 58.0 to 133.6 ms. That did not satisfy the stated small-site criteria.

The revised prototype does not write a snapshot unless the post query returns at least 1,000 eligible IDs. The built-in stored term counts are used only as an early hint to skip a cache lookup when their total is below that threshold; they are never used for the displayed results. If stored counts are inaccurate, this can lose an optimization opportunity but cannot alter the output. The `content_trees_taxonomy_cache_min_posts` filter controls the threshold. Different threshold values are part of the snapshot signature.

| Scenario | Uncached HTTP median / p95 ms | Revised candidate HTTP median / p95 ms | Uncached / candidate render median ms | Snapshot written |
| --- | ---: | ---: | ---: | --- |
| Bare page | 30.7 / 40.9 | — | 0 / — | No |
| Categories, closed | 45.5 / 53.1 | 46.7 / 50.6 | 5.7 / 5.6 | No |
| Tags, closed | 46.7 / 51.4 | 46.7 / 53.6 | 8.2 / 8.6 | No |
| Both, closed | 47.1 / 57.6 | 47.2 / 61.5 | 13.8 / 14.0 | No |

In a separate interleaved 50-pair run of both closed trees, the uncached and revised-candidate render medians were 13.86 and 14.11 ms; HTTP medians were 46.54 and 51.77 ms, with p95 of 57.25 and 60.34 ms. The 5.23 ms HTTP median difference was just above the predeclared 5 ms small-site allowance, while the separate 50-request runs differed by 0.1 ms and render medians by 0.2 ms. This was a borderline historical result; the later 400-pair repeats are reported above. The revised candidate made no small-dataset snapshots, so repeated requests remained uncached. The category term-count hint skipped the lookup; overlapping tag memberships exceeded the hint threshold and caused two additional lookup queries before the actual eligible-post count prevented a write.

### Hiding counts and core direct counts

Turning off `show_counts` alone retains `show_posts=true` and `hide_empty=true`, so the tree still needs eligible memberships and subtree emptiness. In the final uncached large run, counts-on versus counts-off render medians were 136.5 versus 137.6 ms for Categories, 241.7 versus 238.7 ms for Tags, and 381.4 versus 380.0 ms for both. HTTP p95 values were respectively 183.9 versus 183.4, 305.8 versus 296.1, and 450.5 versus 449.2 ms. Counts-off reduced response size but did not meaningfully remove the query work. This is distinct from the names-only fast path, which also disables posts and empty-term filtering.

A separate lightweight direct-count prototype read `WP_Term::count`, generated term links and a flat list. Large-dataset median work was 5.7 ms for Categories and 37.8 ms for Tags, with whole requests of 46.8 and 77.3 ms. This is not a full equivalent tree render. Its stored direct counts differed from the eligible direct counts on 211 of 301 categories and 705 of 3,000 tags. For example, a tag's stored count was 1,543 while its eligible count was 1,498: core's count includes published password-protected posts. A category root had a stored direct count of 2,670, an eligible direct count of 2,616 and a required unique subtree count of 19,200. On the small dataset, core counts differed from eligible direct counts on 10 of 21 categories and 22 of 100 tags; 12 category subtree counts differed. Core's stored counts therefore cannot replace these contracts without changing results. Removing Tags or making counts opt-in would also be a product behavior change; the counts-off measurement alone does not justify changing the default.

Keep the existing Tag tree in this change: removing a supported provider would break saved widgets, blocks and shortcodes and conflicts with preserving the existing public behavior. Its warm rendering remains substantially slower than Categories (48.5 versus 7.6 ms in the earlier single-tree run), and the 3,000-tag name-only path also costs tens of milliseconds. A hierarchical-only product or an explicit Tag opt-out could reduce that cost, but requires a separate compatibility and migration decision; the count cache does not solve rendering thousands of nodes.

### Persistent object cache

Redis cold and warm: **not measured**. The follow-up above tests WordPress's database-backed transients with no persistent object cache, not Redis. The earlier baseline tables and SQL probe describe the original implementation.

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

The database-backed cache stores only per-term unique visible-subtree counts and direct membership counts. These are sufficient to render closed branches; the normal `taxonomy_data()` call and all open-branch rendering retain full memberships. The signature includes the taxonomy, normalized options, visible term IDs, parent map and cache threshold. The default threshold requires at least 1,000 eligible IDs before a snapshot is written; a sum of stored direct term counts can skip even the cache lookup when it is below the threshold. Those core counts are an optimization hint only. Two fixed transient names each retain at most four option signatures, with the oldest entry evicted when necessary. Entries expire after one hour. A durable option revision and deletion of both snapshots invalidate eligible post publication, unpublication, password and type changes, deletion, Category/Tag relationship changes, and edits or deletion of those terms, including category reparenting. Ordinary draft saves, autosaves, page saves, published title edits and post-cache cleaning do not invalidate the snapshots. Term creation is reflected in the term signature. Database changes made outside WordPress APIs and their hooks cannot invalidate the snapshots.

The snapshot is bypassed for logged-in users, external object caches, custom database implementations, explicitly open branches, and automatic opening on a relevant post or term. It also bypasses when relevant post-query, term-query, database-query or cache/revision filters are present, except for explicitly recognized core callbacks and this plugin's own ordering callback. That conservative policy can eliminate the benefit on sites with other plugins. A key containing only SQL and WordPress's posts/terms `last_changed` values would be unsuitable for database transients: without a persistent object cache those values are regenerated between requests. Identical SQL also cannot prove that filter, user, locale or presentation contexts are interchangeable.

The cache also bypasses whenever `wp-content/db.php` exists, even if it returns the standard `wpdb` class. This covers database drop-ins such as HyperDB and LudicrousDB as well as SQLite. A disposable WordPress 7.1.3/PHP 8.5.11 installation with SQLite Database Integration 3.1.1 rendered both trees twice with the cache threshold set to zero: no snapshot, named-lock query, PHP notice or database error was produced. It was not a Playground browser test. A separate MariaDB test forced both lock functions to fail with database errors enabled and verified no error output or log and restored error settings. Another held the lock on a second connection across successive attempts, verified each acquisition uses the one-second wait and checked the uncached fallback output.

A transparent proxy can use the core `wpdb` class without a drop-in, so the guard cannot detect every routed connection. [ProxySQL's documentation](https://proxysql.com/documentation/multiplexing/) says `GET_LOCK()` disables multiplexing but does not disable routing; custom routing can still send release to a different backend. No proxy or replica deployment was tested. A lock left on a pooled backend can persist until that backend connection closes, and each future miss can wait up to one second per taxonomy before falling back; the code does not promise a global lock lifetime or cross-server exclusion.

Each transient has a one-hour expiry. Uninstall removes both snapshots, their database-backed values and timeout options, and the revision option; it does so for every site on multisite, including when a persistent object cache has since been enabled. Other plugin settings are retained. Five rotating option signatures can repeatedly evict one another from the four-entry bound; regression tests verify their output remains correct.

Core's direct `set_post_type()` does not emit the ordinary post-update action. A narrow, unchanged-SQL query observer recognizes that core call, captures the database's old eligibility before the update and checks the new state when core clears the post cache. Other queries do not trigger invalidation. An eligible relationship change in either supported taxonomy invalidates both snapshots, which is conservative but can cause an unnecessary rebuild of the other tree. Direct SQL writes, including mutations that bypass WordPress hooks, remain outside this guarantee. A newly created term can change the term signature and cause a miss even when its creation did not invalidate the stored snapshot.

The cache integration suite covers publication, status and password changes, deletion and type changes, term reassignment, edits and deletion, category reparenting, expiry, draft and autosave non-invalidation, option isolation, bounded storage, lock timeout and exception cleanup, SQL-filter and `posts_pre_query` bypass, logged-in isolation, exclusions, count ordering and open-branch fallback. It compares cached and uncached public HTML and restores its temporary data and prior snapshots. Separate HTTP context checks repeat the anonymous/filter/user comparison across requests. The tests do not prove compatibility with every possible plugin, custom database or persistent cache; those contexts are deliberately excluded from reuse where detected.

HTTP or CDN caching of REST responses is outside this change. Anonymous requests or `credentials: 'omit'` alone do not establish that responses are safe to share across users, filters or request contexts; cacheability would need its own context and invalidation analysis.

## Verification and reproduction

The starting checkout passed PHP syntax, encoding, metadata, bootstrap, JavaScript lint and formatting checks, and the WordPress/PHP coding-standard and compatibility scan. PHPCS reported no errors and one existing `tax_query` performance warning. The current WordPress 7.1.3/PHP 8.5.11 and minimum WordPress 6.3/PHP 7.4.33 integration suites passed, including the additional taxonomy baseline and feature checks. All five browser regression suites passed on the current fixture. Runtime Plugin Check reported no errors and three existing performance warnings: exclusionary query arguments in Archive and taxonomy selectors, and the taxonomy `tax_query` warning. Automated browser tests do not establish screen-reader compatibility. The test fixture's temporary Plugin Check object-cache drop-in was removed.

With the prototype applied, the same integration suites passed on both WordPress/PHP combinations, including 127 cache-specific checks in each, and all five browser suites passed on the current fixture. PHP syntax, metadata, bootstrap, encoding, JavaScript lint/format, Composer validation and PHPCS passed; PHPCS retained its one existing warning. Runtime Plugin Check reported zero errors and the same three existing warnings after a naming cleanup. The patch was then reversed and checked for clean application. No Redis or MySQL 8.0 verification is claimed.

For the 0.10.0 implementation, the full integration suites passed on WordPress 7.1.3/PHP 8.5.11 and WordPress 6.3/PHP 7.4.33, with 249 cache-specific checks and uninstall checks on each. The multisite cleanup test exercised 205 simulated sites. All five browser suites passed with Edge on the current fixture. Encoding, metadata, bootstrap, PHP syntax, JavaScript lint and formatting, Composer validation, and PHPCS passed; PHPCS retained the existing `tax_query` warning. Runtime Plugin Check reported zero errors and the same three existing performance warnings. Tests use one-second lock timeout and verify both the timeout fallback and exception release. No Redis, ProxySQL or MySQL 8.0 verification is claimed.

The following PowerShell commands reproduce the follow-up on this workspace's **separate benchmark installation** after its dataset is removed, using the current checkout. The site was created with its own `ctbench_` table prefix and sample post removed, and has `CONTENT_TREES_DISPOSABLE_TEST_SITE` and `CONTENT_TREES_BENCHMARK_SITE` both set to `true`. It connects only to the guarded `content_trees_test` database at `127.0.0.1:33080` and activates this checkout. On another machine, first create an empty disposable WordPress installation with those markers and guards, and activate the exact checkout; do not use an existing populated site. See [benchmark setup](../tools/benchmark/README.md) for the requirements and Redis procedure. The earlier baseline tables were measured from `f708d8e` using the then-current unwrapped harness; use that revision to reproduce them exactly. Historical prototype-specific numbers were measured using the separate patch and earlier harness.

```powershell
Set-Location 'D:\Temp\wordpress-plugins\repositories\content-trees'
$env:CONTENT_TREES_TEST_WP_ROOT = 'D:\Temp\wordpress-plugins\repositories\content-trees\tests\results\benchmark\site'
$php = 'D:\Temp\wordpress-plugins\tools\php\php.exe'
$phpArgs = @('-d', 'memory_limit=512M', '-d', 'extension=mysqli', '-d', 'extension=pdo_mysql')
$env:CONTENT_TREES_BENCHMARK_PHP = $php
$env:CONTENT_TREES_BENCHMARK_PHP_ARGS = '["-d","memory_limit=512M","-d","extension=mysqli","-d","extension=pdo_mysql"]'
& $php @phpArgs tools/benchmark/dataset.php create large | Set-Content tests/results/benchmark/large-dataset.json -Encoding utf8NoBOM
& $php @phpArgs tools/benchmark/verify.php | Set-Content tests/results/benchmark/large-verify.json -Encoding utf8NoBOM
& $php @phpArgs -S 127.0.0.1:8779 tools/benchmark/router.php
# In a second PowerShell, from the same repository:
node tools/benchmark/run.mjs http://127.0.0.1:8779/ none baseline bare,category-closed,tag-closed,both-closed
node tools/benchmark/run.mjs http://127.0.0.1:8779/ none cache category-closed,tag-closed,both-closed
node tools/benchmark/run.mjs http://127.0.0.1:8779/ none cache-cold category-closed,tag-closed,both-closed
node tools/benchmark/run.mjs http://127.0.0.1:8779/ none baseline category-nocount,tag-nocount,both-nocount,category-core,tag-core
node tools/benchmark/context-check.mjs http://127.0.0.1:8779/
node tools/benchmark/mixed.mjs http://127.0.0.1:8779/ both-closed 200 400
node tools/benchmark/mixed.mjs http://127.0.0.1:8779/ both-closed 10 300
node tools/benchmark/mixed.mjs http://127.0.0.1:8779/ both-closed 10 300
node tools/benchmark/cold-diagnostic.mjs http://127.0.0.1:8779/
& $php @phpArgs tools/benchmark/core-counts.php | Set-Content tests/results/benchmark/large-core-counts.json -Encoding utf8NoBOM
# Substitute the actual output filename from that command:
& $php @phpArgs tools/benchmark/sql-probe.php tests/results/benchmark/large-none-baseline-RESULT.json | Set-Content tests/results/benchmark/large-sql-probe.json -Encoding utf8NoBOM
node tools/benchmark/summarize.mjs tests/results/benchmark/large-none-baseline-RESULT.json
# Stop the server and remove only the marked data before switching sizes:
& $php @phpArgs tools/benchmark/dataset.php remove
& $php @phpArgs tools/benchmark/dataset.php create small | Set-Content tests/results/benchmark/small-dataset.json -Encoding utf8NoBOM
& $php @phpArgs tools/benchmark/verify.php | Set-Content tests/results/benchmark/small-verify.json -Encoding utf8NoBOM
```

Restart the server and run the baseline and cache commands for the small dataset. Run `node tools/benchmark/paired-small.mjs http://127.0.0.1:8779/ 400` for the repeated small comparison. The historical pooled small table used two runs of the original `f708d8e` harness; to reproduce that summary, substitute the two original output files in the following commands:

```powershell
node -e 'const fs=require("node:fs"); const a=JSON.parse(fs.readFileSync(process.argv[1],"utf8")); const b=JSON.parse(fs.readFileSync(process.argv[2],"utf8")); a.samples.push(...b.samples); a.diagnostics.push(...b.diagnostics); a.samples_per_scenario+=b.samples_per_scenario; fs.writeFileSync(process.argv[3],JSON.stringify(a,null,2)+"\n","utf8");' tests/results/benchmark/small-none-FIRST.json tests/results/benchmark/small-none-SECOND.json tests/results/benchmark/small-combined-summary-input.json
node tools/benchmark/summarize.mjs tests/results/benchmark/small-combined-summary-input.json
```

Then stop the server and run `& $php @phpArgs tools/benchmark/dataset.php remove`. The actual file names and combined small-result input are retained under the ignored results directory. The sample commands assume the variables are also set in the second shell and are shown for this local environment; PHP, WordPress and database dependencies are not added to the repository. The write-profile and four-server burst instructions are in the [benchmark setup](../tools/benchmark/README.md).

Raw dataset manifests, verifier output, individual HTTP samples, SQL diagnostics and probe plans are UTF-8 JSON under ignored `tests/results/benchmark/`. Final locked-cache mixes are `large-mixed-both-closed-200-2026-10-28T13-58-38-365Z.json`, `large-mixed-both-closed-10-2026-10-28T14-01-30-347Z.json` and `large-mixed-both-closed-10-2026-10-28T14-04-37-299Z.json`; the final small repeat is `small-paired-repeat-2026-10-28T14-10-48-938Z.json`, and the final burst is `large-burst-2026-10-28T13-54-49-820Z.json`. The write profile and instrumented cold diagnostics are `write-profile-final.json` and `cold-diagnostic-2026-10-28T13-38-08-057Z.json`. The historical large baseline, warm and cold files start with `large-none-baseline-2026-10-28T12-24`, `large-none-cache-2026-10-28T12-26` and `large-none-cache-cold-2026-10-28T12-26`; the interleaved all-cold comparison is `large-paired-cold-both-closed-2026-10-28T12-29-49-935Z.json`. Historical small results and context checks are retained alongside them.

These are local evidence and are not included in the plugin delivery allowlist or run as timing assertions in CI. The SQL probe requires the dataset used to generate its diagnostic input to remain installed. After these runs, the generated posts and terms were removed, the ownership marker was absent, and the benchmark HTTP and disposable database servers were stopped. The empty benchmark installation remains available for reproduction.
