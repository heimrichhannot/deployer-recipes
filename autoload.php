<?php

namespace Deployer;

\set_include_path(\get_include_path() . PATH_SEPARATOR . __DIR__);

if (!\class_exists(Deployer::class))
{
    return;
}

require_once 'extension/functions.php';
require_once 'extension/tasks.php';
