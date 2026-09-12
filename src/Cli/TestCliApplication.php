<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Discovery\TestDiscovery;
use voku\AgentMap\Discovery\TestReport;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;

final readonly class TestCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(
        ?MapArtifactPaths $artifacts = null,
        ?string $defaultRoot = null,
    ) {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject($defaultRoot ?? (getcwd() ?: '.'));
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        $command = $argv[1] ?? null;
        if ($command === 'tests' || $command === 'test') {
            return true;
        }

        return $command === 'help' && in_array($argv[2] ?? null, ['tests', 'test'], true);
    }

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
    }

    public function helpOverview(): string
    {
        return <<<'TEXT'

Test discovery:
  tests      Discover tests covering a PHP class, method, or file with direct callers and companions

Run `agent-map help tests` for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            $command = $argv[1] ?? '';
            if ($command === 'help') {
                echo $this->help();
                return 0;
            }

            return $this->test(array_slice($argv, 2));
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    /** @param list<string> $tokens */
    private function test(array $tokens): int
    {
        $parsed = $this->parse($tokens);
        if ($parsed['help']) {
            echo $this->help();
            return 0;
        }

        $query = $parsed['argument'];
        if ($query === null || $query === '') {
            throw new InvalidArgumentException('Test command requires a target (PHP class, method, file, or keyword).');
        }

        $indexPath = $parsed['options']['index'] ?? $this->artifacts->indexJson();
        $map = (new IndexReader())->read($indexPath);

        $report = (new TestDiscovery())->discover($map, $query);

        $format = $parsed['options']['format'] ?? 'text';
        echo $this->render($report, $format);

        return 0;
    }

    private function render(TestReport $report, string $format): string
    {
        return match ($format) {
            'json' => json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'toon' => Toon::encode($report->toArray()) . "\n",
            'markdown' => $this->renderMarkdown($report),
            default => $this->renderText($report),
        };
    }

    private function renderText(TestReport $report): string
    {
        $out = "Test Discovery: {$report->query}\n";
        $out .= "Target kind: {$report->targetKind}\n";
        $tested = $report->testCalls !== [] || $report->companionTestFiles !== [];
        $statusStr = $tested
            ? 'Yes (' . count($report->testCalls) . ' direct test caller(s), ' . count($report->companionTestFiles) . ' companion file(s))'
            : 'No tests found';
        $out .= "Tested: {$statusStr}\n\n";

        if ($report->targets !== []) {
            $out .= "Targets Resolved:\n";
            foreach ($report->targets as $t) {
                $out .= "  {$t['symbol']} [{$t['kind']}] ({$t['file']}:{$t['line']})\n";
            }
            $out .= "\n";
        }

        if ($report->testCalls !== []) {
            $out .= "Direct Test Callers:\n";
            foreach ($report->testCalls as $tc) {
                $out .= "  {$tc['test_symbol']} ({$tc['test_file']}:{$tc['line']}) [{$tc['kind']}] -> {$tc['target_symbol']}\n";
            }
            $out .= "\n";
        }

        if ($report->companionTestFiles !== []) {
            $out .= "Companion Test Files:\n";
            foreach ($report->companionTestFiles as $cf) {
                $out .= "  {$cf['path']} ({$cf['symbols']} symbol(s))\n";
            }
            $out .= "\n";
        }

        if ($report->suggestedTestCommands !== []) {
            $out .= "Suggested Test Commands:\n";
            foreach ($report->suggestedTestCommands as $cmd) {
                $out .= "  {$cmd}\n";
            }
            $out .= "\n";
        }

        return $out;
    }

    private function renderMarkdown(TestReport $report): string
    {
        $out = "# Test Discovery: `{$report->query}`\n\n";
        $out .= "**Target Kind:** `{$report->targetKind}`\n";
        $tested = $report->testCalls !== [] || $report->companionTestFiles !== [];
        $statusStr = $tested
            ? '**Tested:** Yes (' . count($report->testCalls) . ' direct test callers, ' . count($report->companionTestFiles) . ' companion test files)'
            : '**Tested:** No';
        $out .= "{$statusStr}\n\n";

        if ($report->targets !== []) {
            $out .= "## Targets\n\n";
            foreach ($report->targets as $t) {
                $out .= "- **`{$t['symbol']}`** (`{$t['file']}:{$t['line']}`) [{$t['kind']}]\n";
            }
            $out .= "\n";
        }

        if ($report->testCalls !== []) {
            $out .= "## Direct Test Callers\n\n";
            foreach ($report->testCalls as $tc) {
                $out .= "- **`{$tc['test_symbol']}`** (`{$tc['test_file']}:{$tc['line']}`) -> `{$tc['target_symbol']}`\n";
            }
            $out .= "\n";
        }

        if ($report->companionTestFiles !== []) {
            $out .= "## Companion Test Files\n\n";
            foreach ($report->companionTestFiles as $cf) {
                $out .= "- `{$cf['path']}` ({$cf['symbols']} symbols)\n";
            }
            $out .= "\n";
        }

        if ($report->suggestedTestCommands !== []) {
            $out .= "## Suggested Test Commands\n\n";
            $out .= "```bash\n";
            foreach ($report->suggestedTestCommands as $cmd) {
                $out .= "{$cmd}\n";
            }
            $out .= "```\n\n";
        }

        return $out;
    }

    private function help(): string
    {
        return <<<'HELP'
agent-map tests - discover tests covering a PHP class, method, or file

Usage:
  agent-map tests <target> [--index=<path>] [--format=text|json|markdown|toon]

Arguments:
  <target>  PHP class, method, file path, or keyword

Options:
  --index=<path>   Path to php-symbols.json index [default: auto-detected]
  --format=<type>  Output format: text, json, markdown, toon [default: text]

HELP;
    }

    /**
     * @param list<string> $tokens
     * @return array{argument: ?string, options: array<string, string>, help: bool}
     */
    private function parse(array $tokens): array
    {
        $argument = null;
        $options = [];
        $help = false;

        foreach ($tokens as $token) {
            if ($token === '-h' || $token === '--help') {
                $help = true;
                continue;
            }
            if (str_starts_with($token, '--')) {
                $eq = strpos($token, '=');
                if ($eq !== false) {
                    $key = substr($token, 2, $eq - 2);
                    $val = substr($token, $eq + 1);
                    $options[$key] = $val;
                } else {
                    $options[substr($token, 2)] = '1';
                }
                continue;
            }
            if ($argument === null) {
                $argument = $token;
            }
        }

        return [
            'argument' => $argument,
            'options' => $options,
            'help' => $help,
        ];
    }
}
