<?php

/**
 * Reset (and warm) the PHP OPcache of the web-facing FPM pool after a release
 * swap.
 *
 * Why this is needed at all
 * -------------------------
 * Deployer publishes by repointing `current` at a new releases/N directory. The
 * docroot therefore resolves to a *different absolute path* after every deploy,
 * but the FPM pool that serves the site is a long-lived process: it still holds
 * compiled opcodes and realpath-cache entries keyed to the previous release.
 * Until those age out (opcache.revalidate_freq / realpath_cache_ttl) the pool
 * mixes stale and fresh paths, which is what makes a site misbehave in odd,
 * hard-to-reproduce ways for the first minutes after a deploy. The dead entries
 * also accumulate in the shared segment; once it is full OPcache stops caching
 * new files entirely and the whole site gets slow.
 *
 * Why over HTTP and not via CLI
 * -----------------------------
 * opcache_reset() only affects the process that executes it. `php -r` runs in
 * the CLI SAPI, which has its own (usually disabled) cache, so it does nothing
 * for the FPM pool. The reset has to arrive as a real web request.
 *
 * A single-use, randomly-named script is written into the docroot, curled once,
 * and removed again in a `finally`, so there is no permanent unauthenticated
 * endpoint that can be used to flush the cache from outside.
 *
 * Non-fatal by design: a failed cache reset must never fail an otherwise good
 * deploy.
 *
 * Configuration (all optional):
 *   set('public_dir', 'web');                  // docroot inside the release
 *   set('site_url', 'https://{{hostname}}');   // URL that reaches this host
 *   set('opcache_warm_paths', ['/']);          // requested after the reset
 */

namespace Deployer;

// Bedrock's docroot. Set to '' for a classic WordPress layout where the
// release root is the docroot.
set('public_dir', 'web');

// Must reach *this* host's FPM pool. Override when the deploy target is not
// reachable under its hostname (staging behind basic auth, split DNS, ...).
set('site_url', 'https://{{hostname}}');

// Requested once after the reset so the first real visitor does not pay for
// recompiling the framework. Multi-language sites usually want their default
// language prefix here, e.g. ['/en/'].
set('opcache_warm_paths', ['/']);

desc('Resets the OPcache of the web-facing FPM pool and warms it');
task('deploy:opcache_reset', function () {
    $script = <<<'PHP'
<?php
header('Content-Type: text/plain');
$reset = function_exists('opcache_reset') ? opcache_reset() : null;
clearstatcache(true);
$status = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
// The restart is deferred to the next request, so this count is the number of
// scripts that were resident at reset time, not what remains afterwards.
printf(
    'reset=%s scripts_evicted=%s',
    var_export($reset, true),
    $status ? $status['opcache_statistics']['num_cached_scripts'] : 'n/a'
);
PHP;

    $docroot = rtrim('{{release_path}}/' . get('public_dir'), '/');
    $name    = 'opcache-reset-' . bin2hex(random_bytes(16)) . '.php';

    // base64 avoids any shell quoting issues with the script body.
    run('echo ' . base64_encode($script) . " | base64 -d > $docroot/$name");

    try {
        $out = run("curl -fsSL --max-time 30 {{site_url}}/$name || echo 'FAILED'");
        writeln('<info>opcache: ' . trim($out) . '</info>');
    } finally {
        run("rm -f $docroot/$name");
    }

    foreach ((array) get('opcache_warm_paths') as $path) {
        run('curl -fsS --max-time 30 -o /dev/null {{site_url}}' . $path . ' || true');
    }
});

// Has to run after the symlink swap: before it, {{site_url}} still serves the
// previous release, so the reset would flush a cache that is about to be
// replaced anyway and warm the wrong code.
after('deploy:symlink', 'deploy:opcache_reset');
