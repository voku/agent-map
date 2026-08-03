<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;

final class ScopeCommandTest extends TestCase
{
    use ScopeFixture;

    private string $indexPath;

    protected function setUp(): void
    {
        $this->setUpScopeFixture();
        $this->indexPath = $this->scopeRoot . '/map.json';
        (new IndexWriter())->write($this->buildScopeIndex(), $this->indexPath);
    }

    protected function tearDown(): void
    {
        $this->tearDownScopeFixture();
    }

    public function testScopeTextOutputListsCallsTablesAndTemplates(): void
    {
        $result = $this->runApp(['agent-map', 'scope', 'Demo\Service\UserService::save', '--index=' . $this->indexPath]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Demo\Service\UserService::save', $result['output']);
        self::assertStringContainsString('Calls:', $result['output']);
        self::assertStringContainsString('save_user', $result['output']);
        self::assertStringContainsString('Tables:', $result['output']);
        self::assertStringContainsString('users', $result['output']);
        self::assertStringContainsString('Templates:', $result['output']);
        self::assertStringContainsString('user/saved.html.twig', $result['output']);
    }

    public function testScopeJsonOutputIsValidAndCarriesHints(): void
    {
        $result = $this->runApp(['agent-map', 'scope', 'Demo\Service\UserService::save', '--index=' . $this->indexPath, '--format=json']);

        self::assertSame(0, $result['exit']);
        self::assertJson($result['output']);
        $decoded = json_decode($result['output'], true);
        self::assertIsArray($decoded);
        self::assertSame('scope', $decoded['type']);
        self::assertSame('Demo\Service\UserService::save', $decoded['target']['label']);
        self::assertNotEmpty($decoded['calls']);
        self::assertNotEmpty($decoded['tables']);
        self::assertNotEmpty($decoded['templates']);
    }

    public function testScopeAmbiguousSelectionExitsNonZeroWithCandidates(): void
    {
        $result = $this->runApp(['agent-map', 'scope', 'UserService::save', '--index=' . $this->indexPath]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Demo\Service\UserService', $result['output']);
        self::assertStringContainsString('Demo\Other\UserService', $result['output']);
    }

    public function testScopeNotFoundExitsNonZero(): void
    {
        $result = $this->runApp(['agent-map', 'scope', 'NoSuchClass::noSuchMethod', '--index=' . $this->indexPath]);

        self::assertSame(1, $result['exit']);
    }

    public function testScopeRejectsAStaleMap(): void
    {
        file_put_contents($this->scopeRoot . '/src/Service/UserService.php', "\n// changed\n", FILE_APPEND);

        $result = $this->runApp(['agent-map', 'scope', 'Demo\\Service\\UserService::save', '--index=' . $this->indexPath]);

        self::assertSame(1, $result['exit']);
    }

    public function testExistingCommandsAndCurrentSchemaAreUnaffectedByScope(): void
    {
        $index = (new IndexReader())->read($this->indexPath);
        self::assertSame('2.0', $index->schemaVersion);

        $query = $this->runApp(['agent-map', 'query', 'UserRepository', '--index=' . $this->indexPath]);
        self::assertSame(0, $query['exit']);
        self::assertStringContainsString('src/Service/UserRepository.php', $query['output']);
    }

    /**
     * @param list<string> $argv
     * @return array{exit: int, output: string}
     */
    private function runApp(array $argv): array
    {
        ob_start();
        $exit = (new AgentMapApplication())->run($argv);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }
}
