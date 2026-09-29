<?php

namespace Deployer;

import(__DIR__ . '/../../../autoload.php');
recipe('typo3');

host('smoke.example.org')
    ->setRemoteUser('smoke')
    ->set('deploy_path', '/var/www/smoke')
    ->set('public_url', 'https://smoke.example.org');
