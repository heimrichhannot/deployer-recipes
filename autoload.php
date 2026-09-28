<?php

namespace Deployer;

\set_include_path(\get_include_path() . PATH_SEPARATOR . __DIR__);

// DEPLOYER is defined by bin/dep after the composer autoloader ran, so this file is
// a no-op when loaded via composer's "files" autoload (e.g. by the application itself)
if (!\defined('DEPLOYER'))
{
    return;
}

// Fail early with a clear message instead of obscure errors when a different Deployer
// major version (e.g. a globally installed dep) loads these recipes
require_once 'extension/VersionGuard.php';

$composer = \json_decode((string) \file_get_contents(__DIR__ . '/composer.json'), true);
\HeimrichHannot\DeployerRecipes\VersionGuard::assertCompatible(
    (string) ($composer['extra']['deployer']['version'] ?? ''),
    \defined('DEPLOYER_VERSION') ? \DEPLOYER_VERSION : 'unknown',
);

require_once 'extension/functions.php';
require_once 'extension/tasks.php';
