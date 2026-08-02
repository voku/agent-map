<?php

declare(strict_types=1);

namespace voku\AgentMap\Context;

use RuntimeException;
use voku\AgentMap\Index\FileEntry;

final readonly class SourceMaterializer
{
    /** @return array{start: int, end: int, content: string} */
    public function materialize(string $root, FileEntry $file, int $lineStart, int $lineEnd, bool $includeDeclarationContext = true): array
    {
        $absolute = realpath($root . '/' . $file->path);
        $realRoot = realpath($root);
        if (!is_string($absolute) || !is_string($realRoot) || !str_starts_with(str_replace('\\', '/', $absolute), rtrim(str_replace('\\', '/', $realRoot), '/') . '/')) {
            throw new RuntimeException('Source path escapes repository root: ' . $file->path);
        }
        $hash = hash_file('sha256', $absolute);
        if (!is_string($hash) || !hash_equals($file->sha256, 'sha256:' . $hash)) {
            throw new RuntimeException('Source file is stale; rebuild agent-map: ' . $file->path);
        }
        $lines = file($absolute);
        if (!is_array($lines)) {
            throw new RuntimeException('Unable to read source file: ' . $file->path);
        }
        $lineStart = max(1, $lineStart);
        $lineEnd = max($lineStart, min($lineEnd, count($lines)));
        if ($includeDeclarationContext) {
            $lineStart = $this->expandStart($lines, $lineStart);
        }
        $content = implode('', array_slice($lines, $lineStart - 1, $lineEnd - $lineStart + 1));

        return ['start' => $lineStart, 'end' => $lineEnd, 'content' => $content];
    }

    /** @param list<string> $lines */
    private function expandStart(array $lines, int $lineStart): int
    {
        $start = $lineStart;
        $insideDocBlock = false;
        for ($line = $lineStart - 1; $line >= 1 && $lineStart - $line <= 30; --$line) {
            $text = trim($lines[$line - 1]);
            if ($insideDocBlock) {
                $start = $line;
                if (str_starts_with($text, '/**')) {
                    $insideDocBlock = false;
                }
                continue;
            }
            if ($text === '' || str_starts_with($text, '#[') || str_starts_with($text, '//')) {
                $start = $line;
                continue;
            }
            if (str_ends_with($text, '*/') || str_starts_with($text, '*')) {
                $insideDocBlock = true;
                $start = $line;
                continue;
            }
            break;
        }

        return $start;
    }
}
