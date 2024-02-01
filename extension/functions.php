<?php

namespace Deployer;

use Deployer\Exception\Exception;

/**
 * @param string $recipe
 * @return void
 * @throws Exception
 */
function recipe(string $recipe): void
{
    $recipePath = __HUH_DEPLOYER_DIR__ . '/recipe/' . $recipe . '.php';
    if (file_exists($recipePath)) {
        import($recipePath);
    } else {
        throw new Exception('Invalid recipe name.');
    }
}

/**
 * @param string $variableName
 * @param array|string $needle
 * @return void
 * @throws Exception
 */
function remove(string $variableName, $needle): void
{
    if (!is_array($needle)) {
        $needle = [$needle];
    }
    $var = get($variableName);
    foreach ($needle as $n) {
        unset($var[array_search($n, $var)]);
    }
    set($variableName, $var);
}
