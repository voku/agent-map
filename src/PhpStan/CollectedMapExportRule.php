<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use RuntimeException;
use voku\AgentMap\PhpStan\Collector\ClassLikeCollector;
use voku\AgentMap\PhpStan\Collector\ClassMethodCollector;
use voku\AgentMap\PhpStan\Collector\FunctionCallCollector;
use voku\AgentMap\PhpStan\Collector\FunctionCollector;
use voku\AgentMap\PhpStan\Collector\InstantiationCollector;
use voku\AgentMap\PhpStan\Collector\MethodCallCollector;
use voku\AgentMap\PhpStan\Collector\StaticCallCollector;

/**
 * @implements Rule<CollectedDataNode>
 */
final readonly class CollectedMapExportRule implements Rule
{
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $exportFile = getenv('AGENT_MAP_PHPSTAN_EXPORT');
        if (!is_string($exportFile) || $exportFile === '') {
            return [];
        }

        $records = [];
        foreach ([
            ClassLikeCollector::class,
            ClassMethodCollector::class,
            FunctionCollector::class,
            MethodCallCollector::class,
            StaticCallCollector::class,
            FunctionCallCollector::class,
            InstantiationCollector::class,
        ] as $collector) {
            foreach ($node->get($collector) as $fileRecords) {
                foreach ($fileRecords as $record) {
                    $records[] = $record;
                }
            }
        }

        usort($records, static function (array $left, array $right): int {
            $leftKey = implode('|', [
                (string) ($left['record_type'] ?? ''),
                (string) ($left['file'] ?? ''),
                (string) ($left['line_start'] ?? 0),
                (string) ($left['name'] ?? $left['kind'] ?? ''),
                (string) ($left['source_id'] ?? ''),
            ]);
            $rightKey = implode('|', [
                (string) ($right['record_type'] ?? ''),
                (string) ($right['file'] ?? ''),
                (string) ($right['line_start'] ?? 0),
                (string) ($right['name'] ?? $right['kind'] ?? ''),
                (string) ($right['source_id'] ?? ''),
            ]);

            return $leftKey <=> $rightKey;
        });

        $json = json_encode(['schema_version' => '1.0', 'records' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($exportFile, $json . "\n") === false) {
            throw new RuntimeException('Unable to write PHPStan semantic export: ' . $exportFile);
        }

        return [];
    }
}
