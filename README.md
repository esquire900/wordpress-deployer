[# wordpress-deployer
[Deployer](https://deployer.org/) recipe for an opinionated wordpress deploy.

Makes use of DDEV, roots bedrock and deployer 7.

Requires:

GITHUB_TOKEN set as env variable
a passwordless ssh connection with the target server 

(for some functionality) a locally working wp installation

Quirks:
A new deploy required 3 "dep deploy"'s, all which of which will fail somewhere except the last one.


example deploy_file:

```

<?php

namespace Deployer;

require 'recipe/wordpress.php';
require './vendor/esquire900/wordpress-deployer/recipe/common.php';

set('github_token', function () {
    return getenv('GITHUB_TOKEN');
});

set('repository', 'https://esquire900:{{github_token}}@github.com/esquire900/wptest.git');

host('plesk')
   ->set('remote_user', 'simonnouwens')
   ->set('deploy_path', '~/wp.simonnouwens.nl');


set('local_root', '/home/script/_tmp/testwp');

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
Based on [florianmoser/bedrock-deployer](https://github.com/florianmoser/bedrock-deployer)

]()
