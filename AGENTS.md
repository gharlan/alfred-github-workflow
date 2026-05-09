# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this is

Workflow for [Alfred](https://www.alfredapp.com) to search GitHub and GitHub Enterprise. Pure PHP (>= 8.2), no framework. Distributed as `github.alfredworkflow` — a zip with the `.alfredworkflow` extension (the format Alfred expects); built via `PharData` in zip mode, downloaded from GitHub Releases.

## Common commands

```bash
composer install          # install php-cs-fixer (only dev dep)
vendor/bin/php-cs-fixer fix   # apply code style (Symfony + risky, see .php-cs-fixer.dist.php)
npm install               # pulls @primer/octicons (only needed when regenerating icons)
bin/create_icons.php      # regenerate icons/*.png from octicons SVGs (needs Imagick + rsvg-convert)
bin/build                 # bundles release into github.alfredworkflow, writes VERSION + CHANGELOG into info.plist
```

There are no tests.

## Entry points and request flow

Alfred calls one of two PHP scripts via `info.plist` script filter / action bindings:

- `search.php` — invoked on every keystroke; returns Alfred XML items. The first query token dispatches: `>` → system commands, `@user` → user subcommands, `user/repo …` → repo subcommands, `s …` → API search, `my …` → "my" pages, otherwise default (orgs/starred/subscribed/repos/following).
- `action.php` — invoked when the user actions an item. If the arg is a URL, opens it (with a `.git` → `x-github-client://openRepo/…` rewrite). Otherwise the arg starts with `>` (or `e >` for enterprise) and matches a system command (`login`, `logout`, `delete-cache`, `update`, `refresh-cache`, etc.).

`server.php` is a tiny built-in PHP web server started on `localhost:2233` during OAuth login (`gh > login`). GitHub's OAuth callback hits it with `?access_token=…`, which it persists and triggers a cache warmup. `Workflow::startServer()` spawns it; `stopServer()` kills it.

## Core architecture

**`Workflow` (workflow.php)** — static singleton. Owns the SQLite DB at `$alfred_workflow_data/db.sqlite` (tables: `config`, `request_cache`). Holds the access token, base URLs, and the items list. The enterprise flag flips `$baseUrl` / `$apiUrl` / `$gistUrl` and which token key is used (`access_token` vs `enterprise_access_token`).

**`Curl` (curl.php)** — wrapper around `curl_multi_*`. All API requests are batched: `Search` queues multiple `Workflow::requestApi(...)` calls into one `Curl` instance, then `$curl->execute()` runs them in parallel. Responses come back via per-request callbacks. The `X-Url` request header is used to map response → request inside the multi-handle loop.

**`Workflow::requestCache()`** — the heart of the caching layer. For each URL:
1. Look up `request_cache` row. If fresh (`timestamp > now - maxAge*60`), serve cached content + walk the `parent` chain to merge paginated pages.
2. If stale but content exists and `refresh < now - 3min`, mark for background refresh and **still serve cached** (stale-while-revalidate). The refresh URL is queued in `self::$refreshUrls`; on shutdown, `action.php "> refresh-cache <urls>"` is spawned in background.
3. If no cached content, do a live request with `If-None-Match: <etag>`. On `200`, store; on `304`, reuse stored content; follow `Link: rel="next"` for pagination, storing each page with `parent = previous_url`.

The pagination model means every "list" response is actually a chain of cache rows linked by `parent`. `cleanCache()` drops rows older than 100 days; `deleteCache()` truncates the table.

**`Item` (item.php)** — fluent builder for one Alfred result row. Two non-obvious bits:
- `match($query)` is a custom subsequence matcher that ranks by *consecutive same-position chars* (`sameChars`) first, then by `prio`, then by length distance. This is what makes `gh user/r` rank `user/repo` above `user/something-with-r-later`.
- `toXml()` serializes items, prepending the enterprise prefix (`e `) to non-URL args so `action.php` can detect scope.

## Conventions to keep in mind

- No internal BC concerns: classes/methods are only consumed by other files in this workflow. Refactor signatures freely, no deprecation shims, no `@deprecated` annotations.
- Don't break PHP < 8.2 syntactically in shipped files unless `composer.json` is bumped — the version constraint is the contract.
- `bin/build` has an explicit file allowlist; new top-level PHP/asset files must be added there or they won't be in the release.
- Code style is enforced by php-cs-fixer with `@Symfony` + `@Symfony:risky` and a few overrides; run it before committing.
- API URLs go through `Workflow::getApiUrl()` which appends `per_page=100` — don't construct API URLs by hand.
- New cached endpoints should use `Workflow::requestApi()` (cached) or `Workflow::request()` (uncached, e.g. for the update download), not raw curl.
- Icons are PNGs under `icons/`; the file basename is the string passed to `Item::icon('foo')`. Add new ones to `bin/create_icons.php` and regenerate, don't hand-edit PNGs.
