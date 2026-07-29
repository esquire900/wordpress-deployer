<?php

/**
 * Run the pending database migrations after a release goes live.
 *
 * On by default -- required from common.php. Unlike the cache recipes this is
 * not tied to any particular plugin or stack: any WordPress site whose release
 * bumps a version has a schema that may need migrating.
 *
 * The problem
 * -----------
 * WordPress stores a schema version in the database and compares it against the
 * one in the code. When they disagree it does *not* migrate on its own -- it
 * shows an admin notice and waits for a human to click "Update database". A
 * Bedrock deploy that bumps core or a plugin therefore goes live with new code
 * running against an old schema, and stays that way until somebody happens to
 * log into wp-admin. The failure is quiet and can persist for weeks: mostly the
 * site works, until it touches whichever table or column the migration adds.
 *
 * WooCommerce has its own updater on top of core's, with the same behaviour.
 *
 * Ordering
 * --------
 * After deploy:symlink, because wp-cli has to load the *new* code to know which
 * schema version it is migrating towards. Before the OPcache reset, so the
 * pages warmed afterwards are rendered against the migrated database rather
 * than being cached in their pre-migration state.
 *
 * Adding your own
 * ---------------
 * Plugins with their own migration CLI can be appended from deploy.php. Each
 * entry is [label, guard, command]; the guard is a shell test that decides
 * whether the command applies to this site:
 *
 *     add('db_update_commands', [
 *         ['WPML', 'wp plugin is-active sitepress-multilingual-cms', 'wp wpml-mo update'],
 *     ]);
 */

namespace Deployer;

/**
 * Extra updaters, run after core's. WooCommerce ships here rather than in a
 * woocommerce.php recipe because the guard already makes it a no-op on the
 * sites that do not have it -- there is nothing else a Woo recipe would do.
 */
set('db_update_commands', [
    ['WooCommerce', 'wp plugin is-active woocommerce', 'wp wc update'],
]);

/**
 * Deliberately non-fatal, for the same reason rocket:regenerate is: wp-cli is
 * not a composer dependency and may simply not exist on the host. A migration
 * that genuinely fails is reported as an error rather than swallowed, but it
 * does not roll back a release that is already serving traffic -- an
 * un-migrated database is a far smaller problem than an interrupted deploy,
 * and it stays fixable by hand.
 */
desc('Runs pending WordPress and plugin database migrations');
task('wp:update_db', function () {
    if (test('[ ! -x "$(command -v wp)" ]')) {
        writeln('<comment>wp-cli not found on host, skipping database migrations</comment>');

        return;
    }

    cd('{{deploy_path}}/current');

    // --network migrates every site in the network; without it, only the main
    // site's schema moves and subsites break in ways that look unrelated.
    $network = test('wp eval "exit(is_multisite() ? 0 : 1);"') ? ' --network' : '';

    db_update_report('core', db_update_run('wp core update-db' . $network));

    foreach ((array) get('db_update_commands') as [$label, $guard, $command]) {
        if (!test($guard . ' 2>/dev/null')) {
            continue;
        }

        db_update_report($label, db_update_run($command));
    }
});

/**
 * stderr is merged in so a failure reports its actual cause, then trimmed to
 * the tail: a theme or plugin emitting deprecation notices on every wp-cli
 * bootstrap can otherwise bury the one line that matters under a screenful.
 */
function db_update_run(string $command): string
{
    // pipefail so the exit status is wp-cli's, not tail's (which always succeeds).
    return run('set -o pipefail; ' . $command . ' 2>&1 | tail -n 5 || echo "__FAILED__"');
}

/**
 * wp-cli prints "No updates required" on the happy path, which is worth seeing
 * in the deploy log -- it is the difference between "migrated" and "never ran".
 */
function db_update_report(string $label, string $output): void
{
    $output = trim($output);

    if (str_contains($output, '__FAILED__')) {
        writeln("<error>db: $label migration failed: " . str_replace('__FAILED__', '', $output) . '</error>');

        return;
    }

    writeln("<info>db: $label: " . ($output ?: 'nothing to do') . '</info>');
}

before('deploy:opcache_reset', 'wp:update_db');
