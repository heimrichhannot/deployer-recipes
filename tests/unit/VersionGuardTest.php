<?php

namespace HeimrichHannot\DeployerRecipes\Tests;

use HeimrichHannot\DeployerRecipes\VersionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionGuardTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function compatible(): iterable
    {
        yield 'same version' => ['8.0.5', '8.0.5'];
        yield 'newer minor at runtime' => ['8.0.5', '8.1.0'];
        yield 'older patch at runtime' => ['8.0.5', '8.0.1'];
        yield 'v prefix' => ['8.0.5', 'v8.0.5'];
    }

    #[DataProvider('compatible')]
    public function testAcceptsSameMajor(string $required, string $running): void
    {
        $this->expectNotToPerformAssertions();
        VersionGuard::assertCompatible($required, $running);
    }

    /** @return iterable<string, array{string, string}> */
    public static function incompatible(): iterable
    {
        yield 'older major' => ['8.0.5', '7.4.0'];
        yield 'newer major' => ['8.0.5', '9.0.0'];
        yield 'unknown running version' => ['8.0.5', 'unknown'];
        yield 'major that only shares a prefix' => ['8.0.5', '80.0.0'];
    }

    #[DataProvider('incompatible')]
    public function testRejectsOtherMajor(string $required, string $running): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("requires Deployer 8, but was loaded by Deployer $running. Run `vendor/bin/dep`.");
        VersionGuard::assertCompatible($required, $running);
    }

    public function testRejectsInvalidRequiredVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid extra.deployer.version ""');
        VersionGuard::assertCompatible('', '8.0.5');
    }
}
