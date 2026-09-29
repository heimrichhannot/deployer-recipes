<?php

namespace HeimrichHannot\DeployerRecipes\Tests;

use HeimrichHannot\DeployerRecipes\Ci\DeployerUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeployerUpdateTest extends TestCase
{
    private const CHANGELOG_HEAD = "# Changelog\n\nAll notable changes to this project will be documented in this file.\n\n";

    public function testStableVersionsKeepsOnlyStrictVersionsSortedAscending(): void
    {
        self::assertSame(
            ['7.5.12', '8.0.4', '8.0.10', '9.0.0'],
            DeployerUpdate::stableVersions(['v8.0.10', 'v9.0.0', 'v8.1.0-rc.1', '8.0.4', 'v7.5.12', 'latest', 'v8.0.10', " v8.0.4\n"]),
        );
    }

    public function testLatestInMajor(): void
    {
        $versions = ['7.5.12', '8.0.4', '8.0.10', '9.0.0'];

        self::assertSame('8.0.10', DeployerUpdate::latestInMajor($versions, 8));
        self::assertNull(DeployerUpdate::latestInMajor($versions, 10));
    }

    public function testLatestAboveMajor(): void
    {
        self::assertSame('10.1.0', DeployerUpdate::latestAboveMajor(['8.0.5', '9.0.0', '10.1.0'], 8));
        self::assertNull(DeployerUpdate::latestAboveMajor(['7.5.12', '8.0.5'], 8));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function bumps(): iterable
    {
        yield 'Deployer patch → recipes patch' => ['2.3.1', '8.0.5', '8.0.6', '2.3.2'];
        yield 'skipped Deployer patches → one recipes patch' => ['2.3.1', '8.0.5', '8.0.9', '2.3.2'];
        yield 'Deployer minor → recipes minor, patch reset' => ['2.3.1', '8.0.5', '8.1.0', '2.4.0'];
        yield 'Deployer minor and patch → recipes minor' => ['2.3.1', '8.0.5', '8.2.3', '2.4.0'];
        yield 'two-digit patch' => ['2.0.9', '8.0.9', '8.0.10', '2.0.10'];
        yield 'keeps a "v" prefix' => ['v2.0.9', '8.0.9', '8.0.10', 'v2.0.10'];
    }

    #[DataProvider('bumps')]
    public function testNextRecipesVersion(string $latestTag, string $current, string $new, string $expected): void
    {
        self::assertSame($expected, DeployerUpdate::nextRecipesVersion($latestTag, $current, $new));
    }

    public function testNextRecipesVersionRejectsMajorChange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeployerUpdate::nextRecipesVersion('2.3.1', '8.0.5', '9.0.0');
    }

    public function testNextRecipesVersionRejectsDowngrade(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeployerUpdate::nextRecipesVersion('2.3.1', '8.0.5', '8.0.5');
    }

    public function testLatestRecipesTagPicksNewestTagInRecipesMajor(): void
    {
        self::assertSame('2.1.0', DeployerUpdate::latestRecipesTag(['1.15.0', '2.0.0', '2.1.0', '3.0.0-rc.1', 'v2.0.9'], 2));
        self::assertNull(DeployerUpdate::latestRecipesTag(['1.15.0'], 2));
    }

    public function testLatestRecipesTagReturnsTheTagAsWritten(): void
    {
        self::assertSame('v2.0.9', DeployerUpdate::latestRecipesTag(['1.15.0', 'v2.0.8', 'v2.0.9'], 2));
    }

    public function testInsertReleaseBelowEmptyUnreleased(): void
    {
        $changelog = self::CHANGELOG_HEAD . "## [Unreleased]\n\n## [2.3.1] - 2026-10-01\n\n### Fixed\n\n- y\n";

        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n"
            . "## [2.3.2] - 2026-10-05\n\n### Changed\n\n"
            . "- Bundle Deployer 8.0.6 ([release notes](https://github.com/deployphp/deployer/releases/tag/v8.0.6)).\n\n"
            . "## [2.3.1] - 2026-10-01\n\n### Fixed\n\n- y\n",
            DeployerUpdate::insertRelease($changelog, '2.3.2', '2026-10-05', '8.0.6'),
        );
    }

    public function testInsertReleaseWhenUnreleasedIsTheLastSection(): void
    {
        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n## [2.0.1] - 2026-10-05\n\n### Changed\n\n"
            . "- Bundle Deployer 8.0.6 ([release notes](https://github.com/deployphp/deployer/releases/tag/v8.0.6)).\n",
            DeployerUpdate::insertRelease(self::CHANGELOG_HEAD . "## [Unreleased]\n", '2.0.1', '2026-10-05', '8.0.6'),
        );
    }

    public function testInsertReleaseLeavesUnreleasedContentAlone(): void
    {
        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Fixed\n\n- y\n\n"
            . "## [2.3.2] - 2026-10-05\n\n### Changed\n\n"
            . "- Bundle Deployer 8.0.6 ([release notes](https://github.com/deployphp/deployer/releases/tag/v8.0.6)).\n\n"
            . "## [2.3.1] - 2026-10-01\n",
            DeployerUpdate::insertRelease(self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Fixed\n\n- y\n\n## [2.3.1] - 2026-10-01\n", '2.3.2', '2026-10-05', '8.0.6'),
        );
    }

    public function testInsertReleaseWithoutUnreleasedHeadingGoesAboveNewestRelease(): void
    {
        self::assertSame(
            self::CHANGELOG_HEAD . "## [2.3.2] - 2026-10-05\n\n### Changed\n\n"
            . "- Bundle Deployer 8.0.6 ([release notes](https://github.com/deployphp/deployer/releases/tag/v8.0.6)).\n\n"
            . "## [2.3.1] - 2026-10-01\n\n- y\n",
            DeployerUpdate::insertRelease(self::CHANGELOG_HEAD . "## [2.3.1] - 2026-10-01\n\n- y\n", '2.3.2', '2026-10-05', '8.0.6'),
        );
    }

    public function testInsertReleaseIntoChangelogWithoutSections(): void
    {
        self::assertSame(
            self::CHANGELOG_HEAD . "## [2.0.1] - 2026-10-05\n\n### Changed\n\n"
            . "- Bundle Deployer 8.0.6 ([release notes](https://github.com/deployphp/deployer/releases/tag/v8.0.6)).\n",
            DeployerUpdate::insertRelease(self::CHANGELOG_HEAD, '2.0.1', '2026-10-05', '8.0.6'),
        );
    }

    public function testAddUnreleasedEntryPrependsToExistingChangedList(): void
    {
        $changelog = self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n- Something\n\n### Fixed\n\n- y\n\n## [2.3.1] - 2026-10-01\n\n- z\n";

        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n"
            . "- Bundle Deployer 9.0.0 ([release notes](https://github.com/deployphp/deployer/releases/tag/v9.0.0)).\n"
            . "- Something\n\n### Fixed\n\n- y\n\n## [2.3.1] - 2026-10-01\n\n- z\n",
            DeployerUpdate::addUnreleasedEntry($changelog, '9.0.0'),
        );
    }

    public function testAddUnreleasedEntryCreatesChangedList(): void
    {
        $changelog = self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Fixed\n\n- y\n\n## [2.3.1] - 2026-10-01\n\n- z\n";

        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n"
            . "- Bundle Deployer 9.0.0 ([release notes](https://github.com/deployphp/deployer/releases/tag/v9.0.0)).\n\n"
            . "### Fixed\n\n- y\n\n## [2.3.1] - 2026-10-01\n\n- z\n",
            DeployerUpdate::addUnreleasedEntry($changelog, '9.0.0'),
        );
    }

    public function testAddUnreleasedEntryCreatesMissingUnreleasedHeading(): void
    {
        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n"
            . "- Bundle Deployer 9.0.0 ([release notes](https://github.com/deployphp/deployer/releases/tag/v9.0.0)).\n\n"
            . "## [2.3.1] - 2026-10-01\n",
            DeployerUpdate::addUnreleasedEntry(self::CHANGELOG_HEAD . "## [2.3.1] - 2026-10-01\n", '9.0.0'),
        );
    }

    public function testAddUnreleasedEntryToEmptyUnreleased(): void
    {
        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n"
            . "- Bundle Deployer 9.0.0 ([release notes](https://github.com/deployphp/deployer/releases/tag/v9.0.0)).\n\n"
            . "## [2.3.1] - 2026-10-01\n",
            DeployerUpdate::addUnreleasedEntry(self::CHANGELOG_HEAD . "## [Unreleased]\n\n## [2.3.1] - 2026-10-01\n", '9.0.0'),
        );
    }

    public function testPlanReleasesSameMajorUpdate(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v8.0.6', 'v8.0.5', 'v7.5.12'], ['1.15.0', '2.0.0'], 0);

        self::assertSame(
            ['mode' => 'release', 'current' => '8.0.5', 'same_major' => '8.0.6', 'next_major' => '', 'latest_tag' => '2.0.0', 'recipes_version' => '2.0.1', 'next_recipes_major' => '3'],
            $plan,
        );
    }

    public function testPlanIgnoresOlderAndEqualReleases(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v8.0.5', 'v8.0.4'], ['2.0.0'], 0);

        self::assertSame('none', $plan['mode']);
        self::assertSame('', $plan['same_major']);
    }

    public function testPlanOpensPullRequestWhenMainHasCommitsSinceLatestTag(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v8.0.6'], ['2.0.0'], 3);

        self::assertSame('pr', $plan['mode']);
        self::assertSame('8.0.6', $plan['same_major']);
        self::assertSame('2.0.0', $plan['latest_tag']);
        self::assertSame('', $plan['recipes_version']);
    }

    public function testPlanKeepsTheTagPrefix(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v8.0.6'], ['v2.0.9'], 0);

        self::assertSame('v2.0.9', $plan['latest_tag']);
        self::assertSame('v2.0.10', $plan['recipes_version']);
    }

    public function testPlanWaitsForFirstTagInRecipesMajor(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v8.0.6'], ['1.15.0'], 0);

        self::assertSame('untagged', $plan['mode']);
    }

    public function testPlanReportsNextMajorAlongsideSameMajorUpdate(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v9.0.1', 'v9.0.0', 'v8.0.6'], ['2.0.0'], 0);

        self::assertSame('release', $plan['mode']);
        self::assertSame('8.0.6', $plan['same_major']);
        self::assertSame('9.0.1', $plan['next_major']);
        self::assertSame('3', $plan['next_recipes_major']);
    }
}
