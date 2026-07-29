<?php

/**
 * Collection of common deployment tasks.
 */

namespace Deployer;

require_once(__DIR__ . '/../lib/functions.php');
require_once(__DIR__ . '/bedrock_db.php');
require_once(__DIR__ . '/bedrock_env.php');
require_once(__DIR__ . '/bedrock_misc.php');
require_once(__DIR__ . '/filetransfer.php');
// Every release swap moves the docroot to a new absolute path, so the FPM
// pool's OPcache always needs flushing afterwards -- this is not specific to
// any plugin or theme and is therefore on by default. Cache recipes that *are*
// stack-specific (wp_rocket.php, divi.php) are opt-in from your deploy.php.
require_once(__DIR__ . '/opcache.php');

task('sync', [
   'pull:files',
   'pull:db',
]);

set('project_name', function () {
    $repo = get('repository');
    $name = explode('/', $repo)[-1];
    return str_replace('.git', '', $name);
});


// Bedrock's wp-content equivalent. Set to 'wp-content' for a classic layout;
// the cache recipes build their shared dirs from it.
set('content_dir', 'web/app');

add('shared_files', ['.env']);
add('shared_dirs', ['web/app/uploads']);
add('writable_dirs', ['web/app/uploads']);
add('sync_dirs', [
   '{{local_root}}/web/app/uploads/' => '{{deploy_path}}/shared/web/app/uploads/',
]);


// Credentials for cloning a private repository. Lazy, so a public-repo site
// that never references {{github_token}} is not forced to define one. Override
// either in your deploy.php if they come from somewhere else.
set('github_token', fn() => get_env('GITHUB_TOKEN'));
set('github_user', fn() => get_env('GITHUB_USER'));

set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --optimize-autoloader --no-dev');

/**
 * Installing composer dependencies.
 *
 * `{{bin/composer}}` is Deployer's own resolver: it uses a system composer if
 * one is on PATH, otherwise it downloads composer.phar into {{deploy_path}}/.dep
 * once and reuses it. Sites that need a specific binary -- a Plesk host where
 * `composer` is not on the deploy user's PATH is the usual case -- override it
 * rather than redefining this task:
 *
 *     set('bin/composer', '/usr/bin/composer');
 *     set('bin/composer', '{{bin/php}} /path/to/composer.phar');
 */
desc('Installing Bedrock vendors');
task('bedrock:vendors', function () {
    run('cd {{release_path}} && {{bin/composer}} install {{composer_options}}');
});

/**
 * Authorises composer against GitHub for private repositories.
 *
 * Runs against the *global* composer config on the host, so it only needs to
 * happen once, but it is cheap and idempotent and running it every deploy means
 * a rebuilt server needs no manual setup step.
 */
desc('Storing the GitHub token in the global composer config');
task('deploy:composer_token', function () {
    run('{{bin/composer}} config -g github-oauth.github.com {{github_token}}');
});

after('deploy:failed', 'deploy:unlock');

