<?php

use function Deployer\run;
use function Deployer\set;
use function Deployer\task;

require(__DIR__ . '/../lib/functions.php');
require(__DIR__ . '/bedrock_db.php');
require(__DIR__ . '/bedrock_env.php');
require(__DIR__ . '/common.php');
require(__DIR__ . '/bedrock_misc.php');
require(__DIR__ . '/filetransfer.php');


task('sync', [
    'pull:files-no-bak',
    'pull:db',
    'run-search-replace-locally'
]);

set('shared_dirs', [
//    'web/wp-content/uploads'
]);

set('sync_dirs', [
    dirname(__FILE__) . '/web/wp-content/uploads/' => '{{deploy_path}}/shared/web/wp-content/uploads/',
]);
set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --optimize-autoloader  --no-dev');

task('bedrock:vendors', function () {
    run('cd {{release_path}} && /usr/bin/composer install {{composer_options}}');
});
