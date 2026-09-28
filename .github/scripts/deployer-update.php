<?php

/*
 * CLI for the Deployer update workflow. Run from the repository root.
 *
 *   gh release list ... | php .github/scripts/deployer-update.php plan
 *       Prints key=value lines (for $GITHUB_OUTPUT), see DeployerUpdate::plan(). Reads git tags and history,
 *       never CHANGELOG.md.
 *   php .github/scripts/deployer-update.php release-changelog <recipes-version> <deployer-version>
 *       Adds a release section to CHANGELOG.md, below [Unreleased] if there is one.
 *   php .github/scripts/deployer-update.php unreleased-changelog <deployer-version>
 *       Adds a "Bundle Deployer" entry to the [Unreleased] section of CHANGELOG.md.
 */

use HeimrichHannot\DeployerRecipes\Ci\DeployerUpdate;

require __DIR__ . '/DeployerUpdate.php';

$composer = \json_decode(\file_get_contents('composer.json'), true, flags: \JSON_THROW_ON_ERROR);

/** @return list<string> output lines of a git command; throws if it fails */
function git(string $arguments): array
{
    \exec("git $arguments", $output, $exitCode);
    if ($exitCode !== 0) {
        throw new \RuntimeException(\sprintf('"git %s" failed; run from a git checkout with full history and tags.', $arguments));
    }

    return $output;
}

switch ($argv[1] ?? '') {
    case 'plan':
        $recipesTags = git('tag --list');
        $branchAlias = $composer['extra']['branch-alias']['dev-main'] ?? throw new \RuntimeException('composer.json has no extra.branch-alias.dev-main');
        $recipesMajor = DeployerUpdate::major($branchAlias);
        $latestTag = DeployerUpdate::latestRecipesTag($recipesTags, $recipesMajor);
        $plan = DeployerUpdate::plan(
            $composer['extra']['deployer']['version'] ?? throw new \RuntimeException('composer.json has no extra.deployer.version'),
            $recipesMajor,
            \preg_split('/\R/', \stream_get_contents(\STDIN), -1, \PREG_SPLIT_NO_EMPTY),
            $recipesTags,
            $latestTag === null ? 0 : (int) git('rev-list --count ' . \escapeshellarg("refs/tags/$latestTag..HEAD"))[0],
        );
        foreach ($plan as $key => $value) {
            echo "$key=$value\n";
        }
        break;

    case 'release-changelog':
        \file_put_contents('CHANGELOG.md', DeployerUpdate::insertRelease(\file_get_contents('CHANGELOG.md'), $argv[2], \gmdate('Y-m-d'), $argv[3]));
        break;

    case 'unreleased-changelog':
        \file_put_contents('CHANGELOG.md', DeployerUpdate::addUnreleasedEntry(\file_get_contents('CHANGELOG.md'), $argv[2]));
        break;

    default:
        \fwrite(\STDERR, "Usage: deployer-update.php plan|release-changelog <recipes-version> <deployer-version>|unreleased-changelog <deployer-version>\n");
        exit(2);
}
