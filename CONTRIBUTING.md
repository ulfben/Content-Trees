# Development checks

The plugin runs directly from source. Node and Composer are only needed for development checks; there is no JavaScript build step.

Install PHP 7.4 or later with `mysqli`, `pdo_mysql`, DOM and mbstring, Composer 2, and Node 22.19 or later. Then run:

```sh
composer install
npm ci
composer lint
composer phpcs
npm run lint
npm run format:check
```

`composer phpcs` checks the full WordPress standard and PHP 7.4–8.5 compatibility. Errors fail the check. Warnings remain visible without failing it; the existing taxonomy-query performance warning is deferred. Use `composer phpcbf` and `npm run format` for automatic formatting.

The mechanical PHP formatting commit is listed in `.git-blame-ignore-revs`. To skip it in local blame output, run `git config blame.ignoreRevsFile .git-blame-ignore-revs`.

## WordPress integration tests

Use a disposable WordPress installation with this checkout activated as `content-trees`. The suite deliberately refuses other database names or hosts:

- Database: `content_trees_test` (or `content_trees_minimum` for the existing local minimum fixture).
- Database host: `127.0.0.1:33080`.
- Add `define( 'CONTENT_TREES_DISPOSABLE_TEST_SITE', true );` to its `wp-config.php`.

Set `CONTENT_TREES_TEST_WP_ROOT` to its absolute directory, then run `composer test:integration`. Tests create and remove their own content and restore saved widget settings. They must run sequentially against a shared database. The existing parent-workspace fixtures remain supported without the environment variable.

The integration suite also writes browser fixtures to ignored `tests/results/`. After it passes:

```sh
npx playwright install chromium
npm run test:browser
```

Set `CONTENT_TREES_BROWSER` to an existing Chromium or Edge executable to use that browser instead. These tests cover native navigation, multiple tree instances, branch controls, complete lists, shared request limits, retries, timeouts, focus, announcements and safe links. Automated checks do not establish screen-reader compatibility.

## GitHub Actions

Checks run on pushes, pull requests and manual dispatch. They include coding standards, syntax, minimum WordPress 6.3/PHP 7.4 and current WordPress/PHP 8.5 integration tests, browser regressions, and Plugin Check. The workflow needs no repository secrets and does not publish releases.

## Installable files

`tools/plugin-files.json` is the explicit delivery allowlist. `php tools/build-plugin.php` copies those files into a new `dist/content-trees/` folder for checks; it refuses an existing destination. Tests, dependency files and CI configuration are excluded. Existing local delivery scripts consume the same list. Versioned packages remain immutable.
