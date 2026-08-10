<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\RegionLabeler;
use voku\AgentMap\Index\FileEntry;

final class RegionLabelerTest extends TestCase
{
    public function testRootLevelNamespaceLessFilesFallBackToMeaningfulFileToken(): void
    {
        $files = ['BillingService.php', 'BillingRepository.php'];
        $entries = [
            'BillingService.php' => new FileEntry('BillingService.php', 'sha256:a', '', []),
            'BillingRepository.php' => new FileEntry('BillingRepository.php', 'sha256:b', '', []),
        ];

        $label = (new RegionLabeler())->label($files, $entries);

        self::assertNotSame('.', $label);
        self::assertSame('Billing', $label);
    }
}
