<?php

namespace HeimrichHannot\DeployerRecipes\Ci;

/**
 * Pure decision logic for the Deployer update workflow. No I/O; see deployer-update.php for the CLI.
 */
final class DeployerUpdate
{
    private const UNRELEASED = '## [Unreleased]';

    /**
     * @param list<string> $tags e.g. ["v8.0.5", "v8.1.0-rc.1", "8.0.4"]
     *
     * @return list<string> strict X.Y.Z versions without "v" prefix, ascending
     */
    public static function stableVersions(array $tags): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            if (\preg_match('/^v?(\d+\.\d+\.\d+)$/', \trim($tag), $match)) {
                $versions[$match[1]] = true;
            }
        }
        $versions = \array_keys($versions);
        \usort($versions, 'version_compare');

        return $versions;
    }

    public static function major(string $version): int
    {
        if (!\preg_match('/^v?(\d+)\.\d+/', $version, $match)) {
            throw new \InvalidArgumentException(\sprintf('Not a version: "%s"', $version));
        }

        return (int) $match[1];
    }

    /** @param list<string> $versions ascending, as returned by stableVersions() */
    public static function latestInMajor(array $versions, int $major): ?string
    {
        $inMajor = \array_filter($versions, static fn (string $v): bool => self::major($v) === $major);

        return $inMajor === [] ? null : \end($inMajor);
    }

    /** @param list<string> $versions ascending, as returned by stableVersions() */
    public static function latestAboveMajor(array $versions, int $major): ?string
    {
        $above = \array_filter($versions, static fn (string $v): bool => self::major($v) > $major);

        return $above === [] ? null : \end($above);
    }

    /**
     * Deployer patch release → recipes patch bump; Deployer minor release → recipes minor bump.
     * Keeps a "v" prefix of $latestRecipesTag, so new tags match the existing ones.
     */
    public static function nextRecipesVersion(string $latestRecipesTag, string $currentDeployer, string $newDeployer): string
    {
        if (self::major($currentDeployer) !== self::major($newDeployer)) {
            throw new \InvalidArgumentException('Deployer major changes need a pull request, not an automatic release.');
        }
        if (!\version_compare($newDeployer, $currentDeployer, '>')) {
            throw new \InvalidArgumentException(\sprintf('Deployer %s is not newer than %s.', $newDeployer, $currentDeployer));
        }

        [$major, $minor, $patch] = \array_map('intval', \explode('.', self::stableVersions([$latestRecipesTag])[0] ?? throw new \InvalidArgumentException(\sprintf('Not a version: "%s"', $latestRecipesTag))));
        $minorChanged = \explode('.', $currentDeployer)[1] !== \explode('.', $newDeployer)[1];

        $prefix = \str_starts_with(\trim($latestRecipesTag), 'v') ? 'v' : '';

        return $prefix . ($minorChanged ? "$major." . ($minor + 1) . '.0' : "$major.$minor." . ($patch + 1));
    }

    /**
     * @param list<string> $recipesTags tags of this repository
     *
     * @return string|null the tag as written, e.g. "v2.0.9", so it can be used as a git ref
     */
    public static function latestRecipesTag(array $recipesTags, int $recipesMajor): ?string
    {
        $tagsByVersion = [];
        foreach ($recipesTags as $tag) {
            $version = self::stableVersions([$tag])[0] ?? null;
            if ($version !== null) {
                $tagsByVersion[$version] = \trim($tag);
            }
        }
        $latest = self::latestInMajor(self::stableVersions(\array_keys($tagsByVersion)), $recipesMajor);

        return $latest === null ? null : $tagsByVersion[$latest];
    }

    public static function bundleLine(string $deployerVersion): string
    {
        return \sprintf('- Bundle Deployer %1$s ([release notes](https://github.com/deployphp/deployer/releases/tag/v%1$s)).', $deployerVersion);
    }

    /**
     * Adds a release section below the [Unreleased] section, whatever it holds,
     * or above the newest release if there is no [Unreleased] heading.
     */
    public static function insertRelease(string $changelog, string $version, string $date, string $deployerVersion): string
    {
        return self::withLf($changelog, static function (string $changelog) use ($version, $date, $deployerVersion): string {
            $unreleased = \strpos($changelog, self::UNRELEASED);
            $offset = self::nextSection($changelog, $unreleased === false ? 0 : $unreleased + \strlen(self::UNRELEASED));

            return self::insertSection($changelog, $offset, "## [$version] - $date\n\n### Changed\n\n" . self::bundleLine($deployerVersion) . "\n");
        });
    }

    /** Adds the bundle line to the "### Changed" list of [Unreleased], creating the heading and list if needed. */
    public static function addUnreleasedEntry(string $changelog, string $deployerVersion): string
    {
        return self::withLf($changelog, static function (string $changelog) use ($deployerVersion): string {
            if (!\str_contains($changelog, self::UNRELEASED)) {
                $changelog = self::insertSection($changelog, self::nextSection($changelog, 0), self::UNRELEASED . "\n");
            }

            [$before, $body, $after] = self::splitUnreleased($changelog);
            $line = self::bundleLine($deployerVersion);

            // The heading and any blank lines after it, whether or not the list follows directly
            $changedHeading = '/^### Changed[ \t]*\n(?:[ \t]*\n)*/m';
            if (\preg_match($changedHeading, $body)) {
                $body = \preg_replace($changedHeading, "### Changed\n\n$line\n", $body, 1);
            } else {
                $body = "\n\n### Changed\n\n$line\n" . (\trim($body) === '' ? '' : "\n" . \ltrim($body, "\n"));
            }

            $body = \rtrim($body, "\n") . "\n";

            return $before . $body . ($after === '' ? '' : "\n" . $after);
        });
    }

    /**
     * @param list<string> $upstreamTags deployphp/deployer release tags
     * @param list<string> $recipesTags  tags of this repository
     *
     * @param int          $unreleasedCommits commits on the default branch since the latest recipes tag (latest_tag)
     *
     * @return array{mode: string, current: string, same_major: string, next_major: string, latest_tag: string, recipes_version: string, next_recipes_major: string}
     *   mode: "none" (no same-major update), "untagged" (no release in this recipes major yet),
     *   "pr" (the default branch has commits that are not released yet) or "release"
     */
    public static function plan(string $currentDeployer, int $recipesMajor, array $upstreamTags, array $recipesTags, int $unreleasedCommits): array
    {
        $upstream = self::stableVersions($upstreamTags);
        $deployerMajor = self::major($currentDeployer);

        $sameMajor = self::latestInMajor($upstream, $deployerMajor);
        if ($sameMajor !== null && !\version_compare($sameMajor, $currentDeployer, '>')) {
            $sameMajor = null;
        }
        $latestRecipesTag = self::latestRecipesTag($recipesTags, $recipesMajor);

        $mode = match (true) {
            $sameMajor === null => 'none',
            $latestRecipesTag === null => 'untagged',
            $unreleasedCommits > 0 => 'pr',
            default => 'release',
        };

        return [
            'mode' => $mode,
            'current' => $currentDeployer,
            'same_major' => $sameMajor ?? '',
            'next_major' => self::latestAboveMajor($upstream, $deployerMajor) ?? '',
            'latest_tag' => $latestRecipesTag ?? '',
            'recipes_version' => $mode === 'release' ? self::nextRecipesVersion($latestRecipesTag, $currentDeployer, $sameMajor) : '',
            'next_recipes_major' => (string) ($recipesMajor + 1),
        ];
    }

    /**
     * Runs $edit on $changelog with LF line endings and restores CRLF endings afterwards if the changelog used them.
     *
     * @param callable(string): string $edit
     */
    private static function withLf(string $changelog, callable $edit): string
    {
        if (!\str_contains($changelog, "\r\n")) {
            return $edit($changelog);
        }

        return \str_replace("\n", "\r\n", $edit(\str_replace("\r\n", "\n", $changelog)));
    }

    /** @return array{string, string, string} text up to and including the heading, the section body, the rest starting at the next "## " */
    private static function splitUnreleased(string $changelog): array
    {
        $start = \strpos($changelog, self::UNRELEASED);
        if ($start === false) {
            throw new \InvalidArgumentException('CHANGELOG has no "## [Unreleased]" heading.');
        }
        $headingEnd = $start + \strlen(self::UNRELEASED);
        $next = \strpos($changelog, "\n## ", $headingEnd);
        $bodyEnd = $next === false ? \strlen($changelog) : $next + 1;

        return [\substr($changelog, 0, $headingEnd), \substr($changelog, $headingEnd, $bodyEnd - $headingEnd), \substr($changelog, $bodyEnd)];
    }

    /** Offset of the next "## " heading line after $from, or the end of the changelog. */
    private static function nextSection(string $changelog, int $from): int
    {
        $next = \strpos($changelog, "\n## ", $from);

        return $next === false ? \strlen($changelog) : $next + 1;
    }

    /** Inserts $section at $offset, separated from its neighbours by one blank line. */
    private static function insertSection(string $changelog, int $offset, string $section): string
    {
        $after = \substr($changelog, $offset);

        return \rtrim(\substr($changelog, 0, $offset), "\n") . "\n\n" . $section . ($after === '' ? '' : "\n" . $after);
    }
}
