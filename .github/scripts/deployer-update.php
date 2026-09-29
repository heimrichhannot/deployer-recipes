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
 *   php .github/scripts/deployer-update.php bundled-version < composer.json
 *       Prints extra.deployer.version of the composer.json on stdin, or nothing if it has none.
 *       Works from any directory.
 */

use HeimrichHannot\DeployerRecipes\Ci\DeployerUpdate;

require __DIR__ . '/DeployerUpdate.php';

/** @return array<string, mixed> */
function composer(string $json): array
{
    return \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $composer */
function bundledVersion(array $composer): ?string
{
    return $composer['extra']['deployer']['version'] ?? null;
}

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
        $composer = composer(\file_get_contents('composer.json'));
        $recipesTags = git('tag --list');
        // The major version this branch releases, independent of the branch name
        $recipesMajor = $composer['extra']['deployer']['recipes-major'] ?? null;
        if (!\is_int($recipesMajor) || $recipesMajor < 1) {
            throw new \RuntimeException('composer.json needs extra.deployer.recipes-major as a positive integer, e.g. 2');
        }
        $latestTag = DeployerUpdate::latestRecipesTag($recipesTags, $recipesMajor);
        $plan = DeployerUpdate::plan(
            bundledVersion($composer) ?? throw new \RuntimeException('composer.json has no extra.deployer.version'),
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

    case 'bundled-version':
        echo bundledVersion(composer(\stream_get_contents(\STDIN))) ?? '';
        break;

    default:
        \fwrite(\STDERR, "Usage: deployer-update.php plan|release-changelog <recipes-version> <deployer-version>|unreleased-changelog <deployer-version>|bundled-version\n");
        exit(2);
}
