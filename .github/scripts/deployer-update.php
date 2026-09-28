<?php

/*
 * CLI for the Deployer update workflow. Run from the repository root.
 *
 *   gh release list ... | php .github/scripts/deployer-update.php plan
 *       Prints key=value lines (for $GITHUB_OUTPUT), see DeployerUpdate::plan().
 *   php .github/scripts/deployer-update.php release-changelog <recipes-version> <deployer-version>
 *       Adds a release section below the empty [Unreleased] section of CHANGELOG.md.
 *   php .github/scripts/deployer-update.php unreleased-changelog <deployer-version>
 *       Adds a "Bundle Deployer" entry to the [Unreleased] section of CHANGELOG.md.
 */

use HeimrichHannot\DeployerRecipes\Ci\DeployerUpdate;

require __DIR__ . '/DeployerUpdate.php';

$composer = \json_decode(\file_get_contents('composer.json'), true, flags: \JSON_THROW_ON_ERROR);
$changelog = \file_get_contents('CHANGELOG.md');

switch ($argv[1] ?? '') {
    case 'plan':
        \exec('git tag --list', $recipesTags, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('"git tag --list" failed; run from a git checkout with tags fetched.');
        }
        $branchAlias = $composer['extra']['branch-alias']['dev-main'] ?? throw new \RuntimeException('composer.json has no extra.branch-alias.dev-main');
        $plan = DeployerUpdate::plan(
            $composer['extra']['deployer']['version'] ?? throw new \RuntimeException('composer.json has no extra.deployer.version'),
            DeployerUpdate::major($branchAlias),
            \preg_split('/\R/', \stream_get_contents(\STDIN), -1, \PREG_SPLIT_NO_EMPTY),
            $recipesTags,
            $changelog,
        );
        foreach ($plan as $key => $value) {
            echo "$key=$value\n";
        }
        break;

    case 'release-changelog':
        \file_put_contents('CHANGELOG.md', DeployerUpdate::insertRelease($changelog, $argv[2], \gmdate('Y-m-d'), $argv[3]));
        break;

    case 'unreleased-changelog':
        \file_put_contents('CHANGELOG.md', DeployerUpdate::addUnreleasedEntry($changelog, $argv[2]));
        break;

    default:
        \fwrite(\STDERR, "Usage: deployer-update.php plan|release-changelog <recipes-version> <deployer-version>|unreleased-changelog <deployer-version>\n");
        exit(2);
}
