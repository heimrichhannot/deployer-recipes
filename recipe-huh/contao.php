<?php

namespace Deployer;

import('recipe/contao.php');
import('recipe-huh/contao/variables.php');
import('recipe-huh/contao/composer.php');
import('recipe-huh/contao/contao-manager.php');
import('recipe-huh/contao/ddev.php');
import('recipe-huh/contao/tasks.php');
import('recipe-huh/contao/database.php');

desc('Prepares a new release');
task('deploy:prepare', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:project_files',
    'deploy:assets:release',
    'deploy:shared',
    'deploy:contao-manager',
    'deploy:writable',
    'deploy:symlinks'
]);

before('deploy', 'ask_production_confirmation');

after('deploy', 'cache:opcache:clear');
after('deploy:failed', 'deploy:unlock');
