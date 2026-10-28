# Taxonomy benchmark

These tools run manually, outside CI and the plugin delivery allowlist. They require a **separate, empty, disposable WordPress installation**: existing posts or taxonomy terms would change the workload. Use a dedicated database server and an installation with database name `content_trees_test` or `content_trees_minimum`; set `CONTENT_TREES_TEST_DB_HOST` when its loopback port is not `33080`. Configure the site with both `CONTENT_TREES_DISPOSABLE_TEST_SITE` and `CONTENT_TREES_BENCHMARK_SITE` defined as `true`, and activate this exact checkout as `content-trees`. The same database, host, marker and checkout guards as the integration tests apply. The initial WordPress sample post must be removed from the new installation; its default category remains and is included in reported totals.

The count-cache experiment is preserved as `count-cache-prototype.patch`, not applied to the plugin. It substantially improves warm large-site renders but missed the predeclared all-cold p95 criterion. To reproduce or review it in a clean checkout, first run `git apply --check tools/benchmark/count-cache-prototype.patch` and then `git apply tools/benchmark/count-cache-prototype.patch`. The patch includes its invalidation regression suite. After testing, `git apply -R tools/benchmark/count-cache-prototype.patch` removes it if its files have not been edited. `cache`, `cache-cold` and context-check scenarios require the prototype; `baseline` can run without it.

For a new **empty** local database and a new site directory, WP-CLI can create the fixture as follows. Substitute the absolute paths and disposable database credentials; do not use a populated database or site. This example uses an isolated server on port 33080:

```sh
export REPO=/absolute/path/to/content-trees
export CONTENT_TREES_TEST_WP_ROOT=/absolute/path/to/new/wordpress
export CONTENT_TREES_TEST_DB_HOST=127.0.0.1:33080
export BENCH_DB_USER=disposable_user BENCH_DB_PASSWORD=disposable_password
wp core download --path="$CONTENT_TREES_TEST_WP_ROOT" --version=7.1.3
wp config create --path="$CONTENT_TREES_TEST_WP_ROOT" --dbname=content_trees_test --dbuser="$BENCH_DB_USER" --dbpass="$BENCH_DB_PASSWORD" --dbhost="$CONTENT_TREES_TEST_DB_HOST" --dbprefix=ctbench_
wp config set CONTENT_TREES_DISPOSABLE_TEST_SITE true --raw --path="$CONTENT_TREES_TEST_WP_ROOT"
wp config set CONTENT_TREES_BENCHMARK_SITE true --raw --path="$CONTENT_TREES_TEST_WP_ROOT"
wp config set DISABLE_WP_CRON true --raw --path="$CONTENT_TREES_TEST_WP_ROOT"
wp core install --path="$CONTENT_TREES_TEST_WP_ROOT" --url=http://127.0.0.1:8779 --title=Benchmark --admin_user=benchmark --admin_password=disposable-only --admin_email=benchmark@example.invalid --skip-email
ln -s "$REPO" "$CONTENT_TREES_TEST_WP_ROOT/wp-content/plugins/content-trees"
wp plugin activate content-trees --path="$CONTENT_TREES_TEST_WP_ROOT"
wp post delete 1 --force --path="$CONTENT_TREES_TEST_WP_ROOT"
cd "$REPO"
```

For example, after installing a fresh guarded site and activating the checkout, in a POSIX shell with PHP, Node and WP-CLI available:

```sh
export CONTENT_TREES_TEST_WP_ROOT=/absolute/path/to/disposable/wordpress
export CONTENT_TREES_TEST_DB_HOST=127.0.0.1:33080
mkdir -p tests/results/benchmark
php -d memory_limit=512M tools/benchmark/dataset.php create small > tests/results/benchmark/small-dataset.json
php -d memory_limit=512M tools/benchmark/verify.php > tests/results/benchmark/small-verify.json
php -d memory_limit=512M -S 127.0.0.1:8779 tools/benchmark/router.php
# In a second shell, from the checkout:
node tools/benchmark/run.mjs http://127.0.0.1:8779/ none baseline bare,category-closed,tag-closed,both-closed
node tools/benchmark/run.mjs http://127.0.0.1:8779/ none cache category-closed,tag-closed,both-closed
# Stop the server, then replace the dataset only after the run completes:
php -d memory_limit=512M tools/benchmark/dataset.php remove
php -d memory_limit=512M tools/benchmark/dataset.php create large > tests/results/benchmark/large-dataset.json
php -d memory_limit=512M tools/benchmark/verify.php > tests/results/benchmark/large-verify.json
```

Start the server again and run the same Node commands for the large dataset. `baseline` installs a no-op post-query hook to force the existing uncached path; `cache` permits the count snapshot. `cache-cold` removes only the two snapshot transients before every sample and requires `CONTENT_TREES_BENCHMARK_PHP`, the test-site path and optional JSON `CONTENT_TREES_BENCHMARK_PHP_ARGS`. Pass a comma-separated scenario list, or omit it to run every scenario. `paired-cold.mjs` alternates baseline and cold requests for one scenario; `context-check.mjs` compares warmed anonymous output with filtered and logged-in output and requires a dataset large enough to produce a snapshot.

The endpoint uses the real WordPress bootstrap and invokes WordPress's REST server anonymously, but is a synthetic HTTP harness rather than a themed page or WordPress's ordinary REST URL. REST plugin time is measured between the REST server's before/after callback filters; whole-request time includes dispatch and serialization. Results include response and render metrics, plus separately instrumented SQL statements. `SAVEQUERIES` is enabled only for three diagnostic requests after each set of timed samples. The runner sends five warm-ups followed by 50 measured requests per scenario, in sequence. It refuses an unexpected persistent-cache state. The site must have no full-page cache; the router rejects `WP_CACHE` or an `advanced-cache.php` drop-in and unexpected active plugins. Core-count scenarios produce a flat list from stored direct counts and are not equivalent tree renderings.

Before changing the dataset, `php -d memory_limit=512M tools/benchmark/sql-probe.php tests/results/benchmark/RESULT.json` compares the recorded closed-tree ID and membership queries against a subquery using five warm-ups and 20 alternating samples per taxonomy. Redirect its UTF-8 JSON output to the ignored results directory. It checks the result rows and captures `EXPLAIN` plans. This is a database-level diagnostic on the recorded unfiltered fixture, not an HTTP benchmark or proof of WordPress filter compatibility; run it only with results from the current dataset and checkout.

The generator owns only items with its reserved slug prefix and refuses existing matching items or a previous dataset marker. It leaves the marker in place after an interrupted creation so `remove` can recover only those items. Do not mix other content into the benchmark site. `verify.php` compares all eligible direct memberships and category subtree counts with independently read database rows. Metadata and raw JSON results are saved in ignored `tests/results/benchmark/`.

For Redis, install Redis Object Cache and a Redis server only on the disposable fixture. Use a dedicated loopback Redis instance and database, explicitly define `WP_REDIS_HOST` as `127.0.0.1`, `WP_REDIS_DATABASE` as a positive integer, and `CONTENT_TREES_BENCHMARK_DEDICATED_REDIS` as `true`. Do not use a database containing any other data: cold samples flush it. Preserve and restore any pre-existing configuration or drop-in. Set `CONTENT_TREES_BENCHMARK_PHP` to the PHP executable, `CONTENT_TREES_BENCHMARK_WP_CLI` to the WP-CLI executable or phar, and optionally `CONTENT_TREES_BENCHMARK_PHP_ARGS` to a JSON array of PHP arguments. Run the Node command with `redis-cold` or `redis-warm` in place of `none`. The runner requires the WordPress external-cache flag and a connected status from `wp redis status`; cold runs flush outside each timed request after warm-up. Redis support must be validated on the actual target installation before trusting its results.
