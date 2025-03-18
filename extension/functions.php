<?php

namespace Deployer;

use Deployer\Exception\Exception;
use Deployer\Host\Host;
use Deployer\Host\HostCollection;

/**
 * @param string $recipe
 * @return void
 * @throws Exception
 */
function recipe(string $recipe): void
{
    import("recipe-huh/$recipe.php");
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

/**
 * Shorthand for {@see Deployer::$hosts Deployer::get()->hosts}.
 *
 * @return Host[]|HostCollection
 */
function getHosts()
{
    return Deployer::get()->hosts;
}

/**
 * Returns an object that forwards any method call to all selected hosts,
 *   allowing you to call the same method on multiple hosts at once.
 * If no selector is provided, all hosts from getHosts() are selected.
 *
 * @example
 *   broadcast()->set('deploy_path', '/var/www/htdocs/{{alias}}');
 *   broadcast('env=stage')->setHostname('stage.example.com');
 */
function broadcast(?string $selector = null): object
{
    return new class($selector) {

        public ?string $selector;

        public function __construct(?string $selector) {}

        public function __call($name, $arguments): self
        {
            $selected = $this->selector ? select($this->selector) : getHosts();

            foreach ($selected as $host) {
                $host->$name(...$arguments);
            }

            return $this;
        }
    };
}

/**
 * Returns an object that forwards any method call to all hosts from getHosts(),
 *   allowing you to call the same method on multiple hosts at once.
 *
 * @deprecated Use broadcast() instead.
 */
function onAllHosts(): object
{
    return broadcast();
}
