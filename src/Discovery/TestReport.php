<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class TestReport
{
    /**
     * @param string $query
     * @param string $targetKind
     * @param list<array{symbol: string, file: string, line: int, kind: string}> $targets
     * @param list<array{test_symbol: string, test_file: string, line: int, target_symbol: string, kind: string}> $testCalls
     * @param list<array{path: string, symbols: int}> $companionTestFiles
     * @param list<string> $suggestedTestCommands
     */
    public function __construct(
        public string $query,
        public string $targetKind,
        public array $targets = [],
        public array $testCalls = [],
        public array $companionTestFiles = [],
        public array $suggestedTestCommands = [],
    ) {
    }

    /**
     * @return array{
     *     query: string,
     *     target_kind: string,
     *     targets: list<array{symbol: string, file: string, line: int, kind: string}>,
     *     test_calls: list<array{test_symbol: string, test_file: string, line: int, target_symbol: string, kind: string}>,
     *     companion_test_files: list<array{path: string, symbols: int}>,
     *     suggested_test_commands: list<string>,
     *     tested: bool,
     *     direct_test_count: int,
     *     companion_test_count: int
     * }
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'target_kind' => $this->targetKind,
            'targets' => $this->targets,
            'test_calls' => $this->testCalls,
            'companion_test_files' => $this->companionTestFiles,
            'suggested_test_commands' => $this->suggestedTestCommands,
            'tested' => $this->testCalls !== [] || $this->companionTestFiles !== [],
            'direct_test_count' => count($this->testCalls),
            'companion_test_count' => count($this->companionTestFiles),
        ];
    }
}
