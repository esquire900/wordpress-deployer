<?php

/**
 * Keep the Redis object cache drop-in installed across Deployer release swaps.
 *
 * Opt-in -- require this from your deploy.php when the site runs the Redis
 * Object Cache plugin:
 *
 *     require './vendor/esquire900/wordpress-deployer/recipe/redis.php';
 *
 * The problem
 * -----------
 * This is advanced-cache.php again, with a quieter failure. Enabling the plugin
 * copies its object-cache.php into WP_CONTENT_DIR -- {{content_dir}} on Bedrock
 * -- and that copy is not in git. Every release therefore ships without it, the
 * persistent object cache silently switches off, and WordPress falls back to
 * the per-request cache. Nothing breaks. The site just serves every logged-in
 * page, admin screen, REST call and cron run straight from the database again,
 * and because WP Rocket still handles anonymous traffic the graphs barely move.
 *
 * Why regenerate rather than commit the drop-in
 * --------------------------------------------
 * A committed stub that requires the plugin's file would survive deploys, but
 * the plugin version-checks the installed drop-in against its own and nags to
 * update whenever the two drift -- which is every plugin update. Copying the
 * real file at deploy time keeps the two in lockstep by construction.
 *
 * `wp redis enable` is the right tool for it even though the admin has a button
 * for the same thing: the button honours DISALLOW_FILE_MODS, which a hardened
 * Bedrock production config sets to true, so it is unavailable exactly where it
 * is needed. The CLI writes the file regardless.
 *
 * Configuration
 * -------------
 * Set WP_REDIS_PREFIX per site in config/application.php. Sites sharing a host
 * share a Redis instance, and without a prefix they read each other's keys --
 * which presents as one site's content appearing on another rather than as
 * anything cache-shaped. Deriving it from WP_HOME makes it automatic.
 */

namespace Deployer;

/**
 * Non-fatal throughout, matching the other cache recipes: no wp-cli, or an
 * unreachable Redis, means the site runs without a persistent object cache --
 * slower, but entirely correct. Neither is worth aborting a release for.
 */
desc('Installs the Redis object-cache drop-in and clears stale objects');
task('redis:enable', function () {
    if (test('[ ! -x "$(command -v wp)" ]')) {
        writeln('<comment>wp-cli not found on host, skipping Redis object cache setup</comment>');

        return;
    }

    cd('{{deploy_path}}/current');

    // Idempotent: a no-op when the drop-in is already present and valid, and it
    // reports its own error (without failing) when Redis cannot be reached.
    writeln('<info>redis: ' . trim(run('wp redis enable 2>&1 | tail -n 3 || true')) . '</info>');

    // The cache key prefix is per-site, not per-release, so objects cached by
    // the previous release survive the swap. Usually harmless, occasionally
    // not: a plugin update that changes the shape of something it caches will
    // read back the old shape and fatal. Flushing is much cheaper than that.
    //
    // `wp cache flush`, not `wp redis flush` -- the plugin has no flush
    // subcommand of its own and routes this through the installed drop-in,
    // which is why it has to run after redis:enable rather than before.
    $flush = trim(run('set -o pipefail; wp cache flush 2>&1 | tail -n 3 || echo "__FAILED__"'));

    // Reported rather than ignored: a flush that quietly stops working leaves
    // the previous release's objects in place indefinitely, and the resulting
    // bug looks like anything except a stale cache.
    writeln(str_contains($flush, '__FAILED__')
        ? '<error>redis: cache flush failed: ' . str_replace('__FAILED__', '', $flush) . '</error>'
        : '<info>redis: ' . $flush . '</info>');
});

/**
 * Before the migrations, so those run against a clean cache rather than
 * repopulating one that is about to be thrown away -- and therefore before the
 * OPcache reset and page warming that wp:update_db itself precedes.
 *
 * Depends on db_update.php, which common.php always loads.
 */
before('wp:update_db', 'redis:enable');
