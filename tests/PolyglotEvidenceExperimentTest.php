<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PolyglotEvidenceExperimentTest extends TestCase
{
    public function testScoresCoverageHitRatesFalseAbsenceAndRelations(): void
    {
        $root = sys_get_temp_dir() . '/agent-map-polyglot-experiment-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root, 0o775, true));

        $corpus = $root . '/corpus.json';
        $result = $root . '/provider.json';

        file_put_contents($corpus, <<<'JSON'
{
  "schema": "agent-map-polyglot-corpus@1",
  "tasks": [
    {
      "id": "locate.good",
      "kind": "locate",
      "expected_paths": ["src/Good.php"],
      "expected_relations": []
    },
    {
      "id": "relation.good",
      "kind": "relation",
      "expected_paths": ["src/Caller.php", "src/Callee.php"],
      "expected_relations": ["src/Caller.php->src/Callee.php"]
    },
    {
      "id": "locate.false-absence",
      "kind": "locate",
      "expected_paths": ["src/Exists.php"],
      "expected_relations": []
    },
    {
      "id": "relation.unavailable",
      "kind": "relation",
      "expected_paths": ["src/Other.php"],
      "expected_relations": ["src/Other.php->src/Dependency.php"]
    }
  ]
}
JSON);

        file_put_contents($result, <<<'JSON'
{
  "schema": "agent-map-polyglot-provider-result@1",
  "provider": "fixture",
  "version": "1",
  "metadata": {
    "cold_index_ms": 12,
    "warm_index_ms": 2,
    "index_bytes": 2048
  },
  "tasks": [
    {
      "id": "locate.good",
      "status": "answered",
      "paths": ["src/Good.php"],
      "relations": [],
      "elapsed_ms": 1
    },
    {
      "id": "relation.good",
      "status": "answered",
      "paths": ["src/Caller.php", "src/Callee.php"],
      "relations": ["src/Caller.php->src/Callee.php", "src/Caller.php->src/Noise.php"],
      "elapsed_ms": 2
    },
    {
      "id": "locate.false-absence",
      "status": "not_found",
      "paths": [],
      "relations": [],
      "elapsed_ms": 3
    },
    {
      "id": "relation.unavailable",
      "status": "unavailable",
      "paths": [],
      "relations": [],
      "elapsed_ms": 4
    }
  ]
}
JSON);

        try {
            [$exit, $stdout, $stderr] = $this->executeExperiment($corpus, $result);

            self::assertSame(0, $exit, $stderr);
            self::assertStringContainsString(
                "provider\tcoverage\tunavailable\terrors\thit@1\thit@5\tfalse-absence",
                $stdout,
            );
            self::assertStringContainsString('fixture@1', $stdout);
            self::assertStringContainsString("75.0%\t1\t0\t50.0%\t50.0%\t25.0%\t50.0%\t100.0%", $stdout);
            self::assertStringContainsString("2.5\t12.0\t2.0\t2048.0", $stdout);
        } finally {
            $this->remove($corpus);
            $this->remove($result);
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testRejectsUnknownProviderStatus(): void
    {
        $root = sys_get_temp_dir() . '/agent-map-polyglot-experiment-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root, 0o775, true));

        $corpus = $root . '/corpus.json';
        $result = $root . '/provider.json';
        file_put_contents($corpus, <<<'JSON'
{
  "schema": "agent-map-polyglot-corpus@1",
  "tasks": [
    {
      "id": "locate.good",
      "kind": "locate",
      "expected_paths": ["src/Good.php"],
      "expected_relations": []
    }
  ]
}
JSON);
        file_put_contents($result, <<<'JSON'
{
  "schema": "agent-map-polyglot-provider-result@1",
  "provider": "fixture",
  "version": "1",
  "tasks": [
    {
      "id": "locate.good",
      "status": "maybe",
      "paths": [],
      "relations": []
    }
  ]
}
JSON);

        try {
            [$exit, , $stderr] = $this->executeExperiment($corpus, $result);

            self::assertSame(1, $exit);
            self::assertStringContainsString('unsupported status "maybe"', $stderr);
        } finally {
            $this->remove($corpus);
            $this->remove($result);
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    /** @return array{int, string, string} */
    private function executeExperiment(string $corpus, string $result): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__) . '/tools/polyglot-evidence-experiment.php',
                '--corpus=' . $corpus,
                '--results=' . $result,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start polyglot evidence experiment.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Unable to read polyglot evidence experiment output.');
        }

        return [$exit, $stdout, $stderr];
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
