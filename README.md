# wordpress-deployer

[Deployer 7](https://deployer.org/) recipes for Bedrock WordPress sites, aimed at
Plesk-style hosting (nginx in front of Apache + PHP-FPM).

Beyond the usual release/symlink deploy, it fixes the things that quietly break
*because* of a release/symlink deploy: OPcache serving a deleted release's
opcodes, WP Rocket's page cache silently switching itself off, Divi's generated
CSS 404-ing, and Wordfence dropping back into Learning Mode.

Based on [florianmoser/bedrock-deployer](https://github.com/florianmoser/bedrock-deployer).

---

## Requirements

- Bedrock layout, Deployer 7, PHP 8.1+
- `GITHUB_TOKEN` and `GITHUB_USER` in your shell env or the project `.env`
- Passwordless SSH to the target host
- Optional: `wp-cli` on the host (WP Rocket regeneration skips without it)
- Optional: a working local WP install (for `sync`, `pull:db`, `pull:files`)

---

## Minimal deploy.php

```php
<?php
namespace Deployer;

require 'recipe/wordpress.php';
require './vendor/esquire900/wordpress-deployer/recipe/common.php';

set('repository', 'https://{{github_user}}:{{github_token}}@github.com/acme/site.git');

host('example.com')
    ->set('remote_user', 'example.cn10')
    ->set('deploy_path', '~/httpdocs');

task('deploy', [
    'deploy:composer_token',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'bedrock:vendors',
    'bedrock:env',
    'deploy:shared',
    'deploy:writable',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success',
]);
```

`{{github_token}}` / `{{github_user}}` resolve from the environment, falling back
to the project `.env`, and throw a clear error if set in neither.

Composer runs through Deployer's `{{bin/composer}}`, which finds a system
composer or downloads `composer.phar` once. If the deploy user has no `composer`
on PATH — common on Plesk — override it rather than redefining the task:

```php
set('bin/composer', '/usr/bin/composer');
```

`deploy:opcache_reset` adds itself after `deploy:symlink`; you never list it.

---

## Opt-in recipes

One `require` per plugin/theme the site actually uses. Each hooks itself into the
flow, so the task list above never changes.

```php
require './vendor/esquire900/wordpress-deployer/recipe/wp_rocket.php';
require './vendor/esquire900/wordpress-deployer/recipe/divi.php';
require './vendor/esquire900/wordpress-deployer/recipe/wordfence.php';
```

| Recipe | Shares | Adds |
|---|---|---|
| `opcache.php` (automatic) | — | `deploy:opcache_reset` |
| `wp_rocket.php` | `wp-rocket-config/`, `cache/` | `rocket:regenerate` |
| `divi.php` | `et-cache/` | — |
| `wordfence.php` | `wflogs/` | `wordfence:verify` |

Resulting order: `deploy:symlink → rocket:regenerate → deploy:opcache_reset →
wordfence:verify`. Confirm yours with `dep tree deploy`.

Useful overrides, after the requires:

```php
set('opcache_warm_paths', ['/en/']);  // default ['/']
set('public_dir', 'web');             // '' for a classic WP layout
set('content_dir', 'web/app');        // 'wp-content' for a classic layout
```

---

## Per-repo checklist

### Commit these

| File | Why |
|---|---|
| `web/app/advanced-cache.php` | Copy from `stubs/`. WP Rocket's own version hardcodes the `releases/N` path it was generated in; once `deploy:cleanup` removes that release, page caching silently stops. The stub derives its paths from `WP_CONTENT_DIR`. **Check it is not gitignored.** |
| `web/wordfence-waf.php` | The `auto_prepend_file` target. Wordfence writes it to `ABSPATH` — on Bedrock that is `web/wp`, which composer rewrites every deploy. Its contents are `__DIR__`-relative, so one committed copy works from any release. |
| `web/.htaccess` | Ships with each release; a gitignored one simply will not exist in a new one. |

### Gitignore these

`web/app/wflogs/`, `web/app/wp-rocket-config/`, `web/app/cache/`,
`web/app/et-cache/`, `web/app/uploads/` — mutable runtime state that
`deploy:shared` symlinks into each release, so a committed copy would be
shadowed and never read.

### config/application.php

```php
Config::define('WORDFENCE_WAF_PREPEND_DIRECTORY', $webroot_dir);
```

Without it, Wordfence writes `wordfence-waf.php` into `web/wp`, where composer
destroys it on every deploy.

### Before the first deploy, seed shared/ from the live site

`deploy:shared` copies a directory into `shared/` only if it is absent there
*and* present in the release. The dirs above are gitignored, so they are in
neither: you get an empty shared directory and lose the live state permanently.
On the host, **before** deploying:

```bash
cd ~/httpdocs
mkdir -p shared/web/app
cp -a current/web/app/wflogs           shared/web/app/   # else: Learning Mode, forever
cp -a current/web/app/wp-rocket-config shared/web/app/   # else: page caching off
```

Check with `ls shared/web/app/wflogs/config.php` — one level, not `wflogs/wflogs/`.

---

## Host setup (not deployable)

### PHP-FPM and OPcache

Give the domain a **dedicated** FPM pool — on a shared pool a neighbouring site
evicts your opcodes. Plesk: *PHP Settings → Additional directives*:

```ini
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=32531
opcache.revalidate_freq=60
opcache.revalidate_path=0
opcache.huge_code_pages=1
opcache.jit=tracing
opcache.jit_buffer_size=128M
opcache.enable_cli=0
realpath_cache_size=8192K
realpath_cache_ttl=600
```

`memory_consumption` and `max_accelerated_files` are the two that matter.
WordPress with a page builder is tens of thousands of files, and **every release
adds a fresh set of absolute paths on top of those still cached from previous
releases**. Undersized, the shared segment fills — and a full OPcache does not
evict, it simply stops caching anything new, so the site degrades until a
restart. Check with `opcache_get_status(false)`: `cache_full => true`, or a
climbing `oom_restarts`, means these numbers are too small.

`revalidate_freq=60` is safe *because* `deploy:opcache_reset` runs; you do not
need stat-on-every-request to pick up a deploy.

Measured on one site after tuning: bootstrap 1.15s → 0.45s, uncached render
3.25s → 1.10s, Lighthouse `server-response-time` 100.

### Wordfence extended protection

```ini
auto_prepend_file = /var/www/vhosts/<domain>/httpdocs/current/web/wordfence-waf.php
```

Through `current`, **never** a `releases/N` path. Deploy the file first, then set
this, or every PHP request warns in between.

Do **not** use Wordfence's "Optimize the Firewall" wizard. It writes a
`.user.ini` into the docroot, which disappears with its release, and which PHP
caches for `user_ini.cache_ttl` (300s) — that cache is exactly the *"The changes
have not yet taken effect… wait a few minutes"* message people get stuck in. An
ini directive has neither problem. Delete any leftover `web/.user.ini`.

Verify from *Wordfence → Firewall* (it should read Extended Protection), not from
`wp eval` — WP-CLI runs in the CLI SAPI, which never applies the pool's
`auto_prepend_file`, so it always reports false there.

---

## Quirks

A brand-new deploy needs three `dep deploy` runs; the first two fail partway.
