<?php

namespace Deployer;

import('recipe/typo3.php');

before('deploy', 'ask_production_confirmation');
