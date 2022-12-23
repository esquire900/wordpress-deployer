<?php

/**
 * Collection of common deployment tasks.
 */

namespace Deployer;

require(__DIR__ . '/../lib/functions.php');
require(__DIR__ . '/bedrock_db.php');
require(__DIR__ . '/bedrock_env.php');
require(__DIR__ . '/bedrock_misc.php');
require(__DIR__ . '/filetransfer.php');

use function Deployer\add;
use function Deployer\after;
use function Deployer\get;
use function Deployer\run;
use function Deployer\set;
use function Deployer\task;


task('sync', [
   'pull:files',
   'pull:db',
]);

set('project_name', function () {
    $repo = get('repository');
    $name = explode('/', $repo)[-1];
    return str_replace('.git', '', $name);
});


add('shared_files', ['.env']);
add('shared_dirs', ['web/app/uploads']);
add('writable_dirs', ['web/app/uploads']);
add('sync_dirs', [
   '{{local_root}}/web/app/uploads/' => '{{deploy_path}}/shared/web/app/uploads/',
]);


set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --optimize-autoloader --no-dev');

task('bedrock:vendors', function () {
    run('cd {{release_path}} && /usr/bin/composer install {{composer_options}}');
});


task('deploy:composer_token', function () {
    run('{{bin/composer}} config -g github-oauth.github.com {{github_token}}');
});

after('deploy:failed', 'deploy:unlock');

