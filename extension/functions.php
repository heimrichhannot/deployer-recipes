<?php

namespace Deployer;

/**
 * @param string $variableName
 * @param array|string $needle
 * @return void
 * @throws Exception\Exception
 */
function yank(string $variableName, $needle)
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
