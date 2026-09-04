<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\PackageResources;

final class PackageResourcesTest extends TestCase
{
    public function testPackageResourcesResolvesShippedAssets(): void
    {
        self::assertSame('resources/make/agent-map.mk', PackageResources::MAKE_INCLUDE);
        self::assertFileExists(PackageResources::makeInclude());
    }
}
