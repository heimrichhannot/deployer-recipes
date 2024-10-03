<?php

namespace Deployer;

import('recipe/symfony.php');

before('deploy', 'ask_production_confirmation');

after('deploy:failed', 'deploy:unlock');
