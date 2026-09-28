<?php

namespace Deployer;

\set_include_path(\get_include_path() . PATH_SEPARATOR . __DIR__);

// DEPLOYER is defined by bin/dep after the composer autoloader ran, so this file is
// a no-op when loaded via composer's "files" autoload (e.g. by the application itself)
if (!\defined('DEPLOYER'))
{
    return;
}

require_once 'extension/functions.php';
require_once 'extension/tasks.php';
