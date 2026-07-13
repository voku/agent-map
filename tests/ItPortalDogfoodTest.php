<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;

/**
 * Opt-in regression coverage for the real IT-Portal discovery workflow.
 *
 * The package must remain independently testable, so CI skips this class unless
 * IT_PORTAL_ROOT points at a checkout. Run it after a related map change before
 * trusting compact output for a production repository.
 */
final class ItPortalDogfoodTest extends TestCase
{
    private const MODULE = 'lib/application/systems/M365/ModuleM365_EntraAppAnpassen.php';

    private const TEST = 'lib/application/systems/M365/ModuleM365_EntraAppAnpassen_UnitCest.php';

    private const DTO = 'modules/berechtigungen/lib/DTO/AntragCreateDataTransferObjectM365EntraAppAnpassen.php';

    private string $root;

    protected function setUp(): void
    {
        $root = getenv('IT_PORTAL_ROOT');
        if (!is_string($root) || !is_file($root . '/' . self::MODULE)) {
            self::markTestSkipped('Set IT_PORTAL_ROOT to an IT-Portal checkout to run dogfood coverage.');
        }

        $this->root = $root;
    }

    public function testIndexesFocusedM365FilesAndFindsTheirRelatedUnitTest(): void
    {
        $index = (new AgentMapBuilder())->build($this->root, [self::MODULE, self::DTO, self::TEST], []);

        self::assertSame([self::MODULE, self::TEST, self::DTO], array_map(static fn (FileEntry $file): string => $file->path, $index->files));

        $classMatch = $index->query('M365EntraAppAnpassen');
        self::assertSame('mixed', $classMatch->matchType);
        self::assertSame([self::DTO, self::MODULE, self::TEST], array_map(static fn (FileEntry $file): string => $file->path, $classMatch->files));

        $methodMatch = $index->query('handleFormCheck');
        self::assertSame('exact', $methodMatch->matchType);
        self::assertCount(1, $methodMatch->files);
        self::assertSame(self::MODULE, $methodMatch->files[0]->path);
        self::assertSame(['handleFormCheck'], array_map(static fn (MethodEntry $method): string => $method->name, $methodMatch->files[0]->symbols[0]->methods));
        self::assertSame(79, $methodMatch->files[0]->symbols[0]->methods[0]->lineStart);
        self::assertSame(90, $methodMatch->files[0]->symbols[0]->methods[0]->lineEnd);
        self::assertSame(['Override'], $methodMatch->files[0]->symbols[0]->methods[0]->attributes);

        $module = $index->file(self::MODULE);
        $dto = $index->file(self::DTO);
        if ($module === null || $dto === null) {
            self::fail('Focused IT-Portal entries were not written to the map.');
        }

        self::assertSame([self::TEST], array_map(static fn (FileEntry $file): string => $file->path, $index->likelyTestFilesFor([$module, $dto])));
    }
}
