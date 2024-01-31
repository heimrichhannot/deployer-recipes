<?php

namespace Deployer;

import('recipe/contao.php');
// todo: check if this is still needed
import(__DIR__. '/../extension/base.php');
import(__DIR__ . '/contao/variables.php');
import(__DIR__ . '/contao/composer.php');
import(__DIR__ . '/contao/ddev.php');
import(__DIR__ . '/contao/tasks.php');

desc('Prepares a new release');
task('deploy:prepare', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:project_files',
    'deploy:assets:release',
    'deploy:shared',
    'deploy:themes',
    'deploy:writable',
]);

after('deploy:failed', 'deploy:unlock');
