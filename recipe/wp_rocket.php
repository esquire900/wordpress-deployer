<?php

/**
 * Keep WP Rocket working across Deployer release swaps.
 *
 * Opt-in -- require this from your deploy.php when the site runs WP Rocket:
 *
 *     require './vendor/esquire900/wordpress-deployer/recipe/wp_rocket.php';
 *
 * The problem
 * -----------
 * WP Rocket keeps three things inside wp-content, none of which are in git:
 *
 *   wp-rocket-config/   per-domain PHP config, written on save
 *   cache/wp-rocket/    the generated page cache
 *   advanced-cache.php  the drop-in that serves that cache
 *
 * Without sharing the first two, every release starts with wp-rocket-config/
 * missing entirely. advanced-cache.php guards on file_exists() for it, so the
 * guard trips and page caching silently turns *off* -- the site keeps working,
 * just several times slower, until someone happens to open the WP Rocket
 * settings screen and save. Sharing them also keeps the warm cache across a
 * release swap instead of starting cold on every deploy.
 *
 * advanced-cache.php itself needs a fix this recipe cannot make for you,
 * because it is a file in your repo: as shipped, WP Rocket writes *absolute*
 * paths into it, pointing at the releases/N directory that happened to be
 * current when it was generated. deploy:cleanup eventually deletes that
 * directory and caching dies. Track the drop-in in git and derive its paths
 * from WP_CONTENT_DIR -- see this package's README.
 *
 * Configuration (optional):
 *   set('content_dir', 'web/app');  // Bedrock default; 'wp-content' for classic
 */

namespace Deployer;

add('shared_dirs', ['{{content_dir}}/cache', '{{content_dir}}/wp-rocket-config']);
add('writable_dirs', ['{{content_dir}}/cache', '{{content_dir}}/wp-rocket-config']);

/**
 * Rewrite the drop-in and the per-domain config against the release that is
 * now live.
 *
 * Deliberately non-fatal: wp-cli is not a composer dependency, so a host
 * without it must not break the deploy.
 */
desc('Regenerates the WP Rocket config and advanced-cache drop-in');
task('rocket:regenerate', function () {
    if (test('[ ! -x "$(command -v wp)" ]')) {
        writeln('<comment>wp-cli not found on host, skipping WP Rocket regeneration</comment>');

        return;
    }

    cd('{{deploy_path}}/current');
    run('wp rocket regenerate --file=config || true');
    run('wp rocket regenerate --file=advanced-cache || true');
});

// Ordering: this must run after deploy:symlink (it operates on `current`) and
// before the OPcache reset, so the freshly written drop-in is what gets
// compiled and warmed. Hooking onto deploy:opcache_reset rather than adding a
// second after('deploy:symlink', ...) makes that order independent of the
// order in which these recipes happen to be required.
before('deploy:opcache_reset', 'rocket:regenerate');
