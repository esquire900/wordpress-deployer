<?php

/**
 * Keep the Wordfence firewall configured across Deployer release swaps.
 *
 * Opt-in -- require this from your deploy.php when the site runs Wordfence:
 *
 *     require './vendor/esquire900/wordpress-deployer/recipe/wordfence.php';
 *
 * The problem
 * -----------
 * Everything the firewall knows lives in {{content_dir}}/wflogs, and none of it
 * is in git: the rule set, the IP block list, the accumulated attack data, the
 * GeoIP databases, and -- the one that stings -- the learned configuration that
 * takes Learning Mode a week to build. A new release gets an empty wflogs, so
 * Wordfence finds no configuration, recreates it from defaults, and drops
 * straight back into Learning Mode. Every single deploy.
 *
 * Sharing the directory is the whole fix. Nothing else about the firewall is
 * release-specific.
 *
 * The second half: extended protection
 * ------------------------------------
 * "Extended protection" means the firewall runs via `auto_prepend_file`, before
 * WordPress loads, so it can block a request aimed at a plugin file directly.
 * Two things have to line up, and neither is deployable from here:
 *
 * 1. A `wordfence-waf.php` that exists in every release. Wordfence writes it to
 *    ABSPATH by default -- on Bedrock that is web/wp, which composer rewrites
 *    on every deploy. Set WORDFENCE_WAF_PREPEND_DIRECTORY to your webroot in
 *    config/application.php and commit the resulting web/wordfence-waf.php. Its
 *    contents are already __DIR__-relative, so it needs no per-release fixing.
 *
 * 2. An `auto_prepend_file` ini setting pointing at it. Set it in your control
 *    panel's PHP directives, via the `current` symlink, NOT via a release path:
 *
 *      auto_prepend_file = /path/to/deploy_path/current/web/wordfence-waf.php
 *
 *    Do not let Wordfence's "Optimize the Firewall" wizard do this for you. It
 *    writes a .user.ini into the docroot, which (a) disappears with the release
 *    that contained it and (b) PHP caches for user_ini.cache_ttl seconds --
 *    which is exactly the "changes have not yet taken effect, wait a few
 *    minutes" message people get stuck on. An ini setting has neither problem.
 *
 * This task verifies #1 after each deploy so a broken firewall is loud rather
 * than silent.
 */

namespace Deployer;

add('shared_dirs', ['{{content_dir}}/wflogs']);
add('writable_dirs', ['{{content_dir}}/wflogs']);

/**
 * Warn when the auto_prepend target is missing from the new release.
 *
 * Only a warning: a missing prepend file means reduced protection, not a broken
 * site, and failing the deploy here would be worse than shipping.
 */
desc('Checks that the Wordfence auto_prepend file shipped with this release');
task('wordfence:verify', function () {
    $file = '{{release_path}}/' . trim(get('public_dir') . '/wordfence-waf.php', '/');

    if (test("[ -f $file ]")) {
        writeln('<info>wordfence: auto_prepend file present</info>');

        return;
    }

    warning(
        'wordfence: ' . parse($file) . ' is missing, so extended protection is off for this '
        . 'release. Commit the file (see this recipe\'s header) or the firewall runs in '
        . 'basic mode only.'
    );
});

after('deploy:symlink', 'wordfence:verify');
