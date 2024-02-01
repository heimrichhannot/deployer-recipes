<?php

set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

const __HUH_DEPLOYER_DIR__ = __DIR__;

if (class_exists(Deployer\Deployer::class)) {
    require_once __DIR__ . '/extension/loader.php';
}
