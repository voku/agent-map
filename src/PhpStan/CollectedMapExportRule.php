<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use RuntimeException;
use voku\AgentMap\PhpStan\Collector\AssignCollector;
use voku\AgentMap\PhpStan\Collector\AssignOpCollector;
use voku\AgentMap\PhpStan\Collector\AssignRefCollector;
use voku\AgentMap\PhpStan\Collector\CatchCollector;
use voku\AgentMap\PhpStan\Collector\ClassLikeCollector;
use voku\AgentMap\PhpStan\Collector\ClassMethodCollector;
use voku\AgentMap\PhpStan\Collector\ForeachCollector;
use voku\AgentMap\PhpStan\Collector\FunctionCallCollector;
use voku\AgentMap\PhpStan\Collector\FunctionCollector;
use voku\AgentMap\PhpStan\Collector\InstantiationCollector;
use voku\AgentMap\PhpStan\Collector\MethodCallCollector;
use voku\AgentMap\PhpStan\Collector\NullsafeMethodCallCollector;
use voku\AgentMap\PhpStan\Collector\NullsafePropertyFetchCollector;
use voku\AgentMap\PhpStan\Collector\PropertyDeclarationCollector;
use voku\AgentMap\PhpStan\Collector\PropertyFetchCollector;
use voku\AgentMap\PhpStan\Collector\ReturnCollector;
use voku\AgentMap\PhpStan\Collector\StaticCallCollector;
use voku\AgentMap\PhpStan\Collector\StaticPropertyFetchCollector;
use voku\AgentMap\PhpStan\Collector\ThrowCollector;

/** @implements Rule<CollectedDataNode> */
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
            NullsafeMethodCallCollector::class,
            StaticCallCollector::class,
            FunctionCallCollector::class,
            InstantiationCollector::class,
            PropertyDeclarationCollector::class,
            PropertyFetchCollector::class,
            NullsafePropertyFetchCollector::class,
            StaticPropertyFetchCollector::class,
            AssignCollector::class,
            AssignOpCollector::class,
            AssignRefCollector::class,
            ForeachCollector::class,
            CatchCollector::class,
            ReturnCollector::class,
            ThrowCollector::class,
        ] as $collector) {
            foreach ($node->get($collector) as $fileRecords) {
                foreach ($fileRecords as $record) {
                    if (is_array($record)) {
                        if (isset($record['record_type'])) {
                            $records[] = $record;
                        } else {
                            foreach ($record as $subRecord) {
                                if (is_array($subRecord) && isset($subRecord['record_type'])) {
                                    $records[] = $subRecord;
                                }
                            }
                        }
                    }
                }
            }
        }

        usort($records, static function (array $left, array $right): int {
            return ((string) $left['record_type']) <=> ((string) $right['record_type'])
                ?: ((string) ($left['file'] ?? '')) <=> ((string) ($right['file'] ?? ''))
                ?: ((int) ($left['line_start'] ?? 0)) <=> ((int) ($right['line_start'] ?? 0))
                ?: ((string) ($left['name'] ?? $left['kind'] ?? '')) <=> ((string) ($right['name'] ?? $right['kind'] ?? ''))
                ?: ((string) ($left['source_id'] ?? '')) <=> ((string) ($right['source_id'] ?? ''));
        });

        $json = json_encode(['schema_version' => '1.0', 'records' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($exportFile, $json . "\n") === false) {
            throw new RuntimeException('Unable to write PHPStan semantic export: ' . $exportFile);
        }

        return [];
    }
}
