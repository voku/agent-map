<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Temporal\CoChangeAnalyzer;
use voku\AgentMap\Temporal\CoChangePair;

final class CoChangeAnalyzerTest extends TestCase
{
    public function testExposesTemporalCouplingAlongsideStaticEvidenceWithoutCollapsingThemIntoOneScore(): void
    {
        $map = new AgentMapIndex(
            '2.0',
            '/tmp/agent-map-cochange',
            'test',
            [
                $this->file('src/A.php', 'App\\A'),
                $this->file('src/B.php', 'App\\B'),
                $this->file('other/C.php', 'App\\C'),
            ],
            [RelationEntry::create(
                'method:App\\A::run',
                'calls',
                ['method:App\\B::run'],
                'src/A.php',
                10,
                10,
                'phpstan_resolved',
            )],
        );
        $commits = [
            ['src/A.php', 'src/B.php'],
            ['src/A.php', 'src/B.php'],
            ['src/A.php', 'other/C.php'],
            ['src/A.php', 'other/C.php'],
            ['src/A.php', 'other/C.php'],
        ];

        $report = (new CoChangeAnalyzer())->analyze($map, $commits, 2, 10);
        $hidden = $this->pair($report->pairs, 'other/C.php', 'src/A.php');
        $static = $this->pair($report->pairs, 'src/A.php', 'src/B.php');

        self::assertSame(3, $hidden->coChanges);
        self::assertSame(1.0, $hidden->smallerSideRatio);
        self::assertSame(0.0, $hidden->semanticStaticWeight);
        self::assertSame([], $hidden->staticSignals);

        self::assertSame(2, $static->coChanges);
        self::assertGreaterThan(0.0, $static->semanticStaticWeight);
        self::assertArrayHasKey('calls', $static->staticSignals);
    }

    public function testSkipsBulkCommitsThatWouldTurnRepositoryWideFormattingIntoFalseCoupling(): void
    {
        $map = new AgentMapIndex(
            '2.0',
            '/tmp/agent-map-cochange-bulk',
            'test',
            [
                $this->file('src/A.php', 'App\\A'),
                $this->file('src/B.php', 'App\\B'),
                $this->file('src/C.php', 'App\\C'),
            ],
        );

        $report = (new CoChangeAnalyzer())->analyze(
            $map,
            [
                ['src/A.php', 'src/B.php', 'src/C.php'],
                ['src/A.php', 'src/B.php'],
                ['src/A.php', 'src/B.php'],
            ],
            minimumCoChanges: 2,
            top: 10,
            maximumFilesPerCommit: 2,
        );

        self::assertSame(2, $report->commitsAnalyzed);
        self::assertSame(1, $report->bulkCommitsSkipped);
        self::assertCount(1, $report->pairs);
        self::assertSame(2, $report->pairs[0]->coChanges);
    }

    /** @param list<CoChangePair> $pairs */
    private function pair(array $pairs, string $left, string $right): CoChangePair
    {
        foreach ($pairs as $pair) {
            if ($pair->left === $left && $pair->right === $right) {
                return $pair;
            }
        }

        self::fail('Pair not found: ' . $left . ' <> ' . $right);
    }

    private function file(string $path, string $fqn): FileEntry
    {
        $parts = explode('\\', $fqn);
        $name = (string) end($parts);
        $namespace = implode('\\', array_slice($parts, 0, -1));

        return new FileEntry(
            $path,
            'sha256:' . hash('sha256', $path),
            $namespace,
            [new SymbolEntry('class', $name, $fqn, 1, 30, [new MethodEntry('run', 'public', 5, 20)])],
        );
    }
}
