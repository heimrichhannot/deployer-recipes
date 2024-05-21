<?php

namespace Deployer;

import('recipe/contao.php');
import(__DIR__ . '/contao/variables.php');
import(__DIR__ . '/contao/composer.php');
import(__DIR__ . '/contao/ddev.php');
import(__DIR__ . '/contao/tasks.php');
import(__DIR__ . '/contao/database.php');

desc('Prepares a new release');
task('deploy:prepare', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:project_files',
    'deploy:assets:release',
    'deploy:shared',
    'deploy:writable',
]);

after('deploy', 'cache:opcache:clear');
after('deploy:failed', 'deploy:unlock');
