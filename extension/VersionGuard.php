<?php

namespace HeimrichHannot\DeployerRecipes;

/**
 * Stops the recipes from running under a Deployer major version they were not written for.
 */
final class VersionGuard
{
    /**
     * @param string $required the bundled Deployer version from composer.json extra.deployer.version
     * @param string $running  the running Deployer version (DEPLOYER_VERSION)
     *
     * @throws \RuntimeException if $required is not a version or $running has a different major version
     */
    public static function assertCompatible(string $required, string $running): void
    {
        $requiredMajor = self::major($required);
        if ($requiredMajor === null) {
            throw new \RuntimeException(\sprintf('heimrichhannot/deployer-recipes: invalid extra.deployer.version "%s" in composer.json.', $required));
        }

        if (self::major($running) !== $requiredMajor) {
            throw new \RuntimeException(\sprintf('heimrichhannot/deployer-recipes requires Deployer %d, but was loaded by Deployer %s. Run `vendor/bin/dep`.', $requiredMajor, $running));
        }
    }

    private static function major(string $version): ?int
    {
        return \preg_match('/^v?(\d+)\.\d+/', $version, $match) ? (int) $match[1] : null;
    }
}
