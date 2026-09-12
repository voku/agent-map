<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TemplateScanner
{
    private const EXCLUDED_DIRS = [
        '.git',
        'vendor',
        'node_modules',
        'smarty/templates_c',
        'templates_c',
        'cache',
        'tmp',
        '.agent-map',
        '.agent-loop',
    ];

    private const TEMPLATE_EXTENSIONS = [
        'tpl',
        'twig',
        'html.twig',
        'blade.php',
    ];

    public function __construct(
        private readonly TemplateParser $parser = new TemplateParser(),
    ) {
    }

    /**
     * @return array<string, TemplateInfo> keyed by normalized relative path
     */
    public function scan(string $root): array
    {
        $realRoot = realpath($root);
        if (!is_string($realRoot) || !is_dir($realRoot)) {
            return [];
        }

        $templates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                continue;
            }

            $filePath = str_replace('\\', '/', $fileInfo->getPathname());
            $relPath = ltrim(substr($filePath, strlen($realRoot)), '/');

            if ($this->isExcluded($relPath)) {
                continue;
            }

            if (!$this->isTemplateFile($relPath)) {
                continue;
            }

            $content = @file_get_contents($filePath);
            if (!is_string($content)) {
                continue;
            }

            $info = $this->parser->parse($relPath, $content);
            $templates[$relPath] = $info;
        }

        return $templates;
    }

    private function isExcluded(string $relPath): bool
    {
        $parts = explode('/', $relPath);
        // Exclude hidden directories (e.g. .git, .agent-loop-runner, .agent-map), but not the filename if it starts with dot
        $dirCount = count($parts) - 1;
        for ($i = 0; $i < $dirCount; ++$i) {
            if ($parts[$i] !== '' && $parts[$i] !== '.' && str_starts_with($parts[$i], '.')) {
                return true;
            }
        }

        foreach (self::EXCLUDED_DIRS as $excluded) {
            if ($relPath === $excluded || str_starts_with($relPath, $excluded . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isTemplateFile(string $relPath): bool
    {
        foreach (self::TEMPLATE_EXTENSIONS as $ext) {
            if (str_ends_with($relPath, '.' . $ext)) {
                return true;
            }
        }

        return false;
    }
}
