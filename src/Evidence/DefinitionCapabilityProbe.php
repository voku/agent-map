<?php

declare(strict_types=1);

namespace voku\AgentMap\Evidence;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class DefinitionCapabilityProbe
{
    public function probe(string $root, string $phpIndex): DefinitionCapabilityReport
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $php = is_file($phpIndex)
            ? new DefinitionCapability('php', 'php-map', 'operational')
            : new DefinitionCapability('php', 'php-map', 'unavailable', 'Canonical PHP map artifact is absent.');

        if ($php->status === 'operational') {
            $reason = 'Canonical PHP map artifact is authoritative for public definition query routing.';

            return new DefinitionCapabilityReport(
                'php',
                $php,
                new DefinitionCapability('javascript_typescript', 'scip', 'unavailable', $reason),
                new DefinitionCapability('python', 'scip', 'unavailable', $reason),
            );
        }

        if ($this->containsPhpOwnerSource($root)) {
            $reason = 'PHP owner source is present without a canonical map; polyglot fallback is not allowed.';

            return new DefinitionCapabilityReport(
                'php',
                $php,
                new DefinitionCapability('javascript_typescript', 'scip', 'unavailable', $reason),
                new DefinitionCapability('python', 'scip', 'unavailable', $reason),
            );
        }

        $javascript = is_file($root . '/package.json')
            || is_file($root . '/tsconfig.json')
            || is_file($root . '/jsconfig.json');
        $python = is_file($root . '/pyproject.toml')
            || is_file($root . '/setup.py')
            || is_file($root . '/setup.cfg');

        if ($javascript && $python) {
            $reason = 'Both JavaScript/TypeScript and Python project markers are present; the first SCIP slice does not guess which semantic index should answer.';

            return new DefinitionCapabilityReport(
                'mixed',
                $php,
                new DefinitionCapability('javascript_typescript', 'scip', 'unavailable', $reason),
                new DefinitionCapability('python', 'scip', 'unavailable', $reason),
            );
        }

        if ($javascript) {
            $missing = $this->missingExecutables(['scip', 'scip-typescript']);
            $javascriptCapability = $missing === []
                ? new DefinitionCapability('javascript_typescript', 'scip', 'operational')
                : new DefinitionCapability(
                    'javascript_typescript',
                    'scip',
                    'unavailable',
                    'Missing executable(s): ' . implode(', ', $missing) . '.',
                    $missing,
                    'manual_setup_required',
                );

            return new DefinitionCapabilityReport(
                'javascript_typescript',
                $php,
                $javascriptCapability,
                new DefinitionCapability('python', 'scip', 'unavailable', 'No Python project marker is present.'),
            );
        }

        if ($python) {
            $missing = $this->missingExecutables(['scip', 'scip-python']);
            $pythonCapability = $missing === []
                ? new DefinitionCapability('python', 'scip', 'operational')
                : new DefinitionCapability(
                    'python',
                    'scip',
                    'unavailable',
                    'Missing executable(s): ' . implode(', ', $missing) . '.',
                    $missing,
                    'manual_setup_required',
                );

            return new DefinitionCapabilityReport(
                'python',
                $php,
                new DefinitionCapability('javascript_typescript', 'scip', 'unavailable', 'No JavaScript/TypeScript project marker is present.'),
                $pythonCapability,
            );
        }

        $reason = 'No supported JavaScript/TypeScript or Python project marker is present.';

        return new DefinitionCapabilityReport(
            'unsupported',
            $php,
            new DefinitionCapability('javascript_typescript', 'scip', 'unavailable', $reason),
            new DefinitionCapability('python', 'scip', 'unavailable', $reason),
        );
    }

    /** @param list<string> $names
     *  @return list<string>
     */
    private function missingExecutables(array $names): array
    {
        $missing = [];
        foreach ($names as $name) {
            if ($this->executable($name) === null) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    private function executable(string $name): ?string
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function containsPhpOwnerSource(string $root): bool
    {
        if (is_file($root . '/composer.json') || (glob($root . '/*.php') ?: []) !== []) {
            return true;
        }

        $skipDirectories = [
            '.agent-map' => true,
            '.git' => true,
            'build' => true,
            'coverage' => true,
            'dist' => true,
            'node_modules' => true,
            'vendor' => true,
        ];

        foreach (['src', 'app', 'lib', 'packages', 'modules'] as $sourceDirectory) {
            $path = $root . '/' . $sourceDirectory;
            if (!is_dir($path)) {
                continue;
            }

            $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                static fn (SplFileInfo $item): bool => !$item->isDir() || !isset($skipDirectories[$item->getFilename()]),
            );
            $iterator = new RecursiveIteratorIterator(
                $filter,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($iterator as $item) {
                if ($item->isFile() && str_ends_with($item->getFilename(), '.php')) {
                    return true;
                }
            }
        }

        return false;
    }
}
