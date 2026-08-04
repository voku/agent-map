<?php

declare(strict_types=1);

namespace voku\AgentMap\Build;

use Composer\InstalledVersions;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final readonly class PhpStanSemanticAnalyzer implements SemanticAnalyzer
{
    public function analyse(
        string $root,
        array $relativeFiles,
        ?string $configurationFile = null,
        ?string $memoryLimit = null,
        array $analyseDirectories = [],
        array $scanDirectories = [],
    ): SemanticAnalysisResult
    {
        if ($relativeFiles === []) {
            return new SemanticAnalysisResult([], [], $this->phpStanVersion(), $this->configurationHash($configurationFile));
        }

        $phpStanInstallPath = InstalledVersions::getInstallPath('phpstan/phpstan');
        if (!is_string($phpStanInstallPath)) {
            throw new RuntimeException('Unable to locate phpstan/phpstan installation.');
        }
        $phar = $phpStanInstallPath . '/phpstan.phar';
        if (!is_file($phar)) {
            throw new RuntimeException('PHPStan PHAR not found: ' . $phar);
        }

        $autoload = $this->findComposerAutoload();
        $temporaryDirectory = sys_get_temp_dir() . '/agent-map-phpstan-' . bin2hex(random_bytes(8));
        if (!mkdir($temporaryDirectory, 0o775, true) && !is_dir($temporaryDirectory)) {
            throw new RuntimeException('Unable to create PHPStan temporary directory.');
        }

        try {
            $exportFile = $temporaryDirectory . '/semantic-export.json';
            $overlayConfiguration = $temporaryDirectory . '/agent-map.neon';
            $overlay = $this->overlayConfiguration(
                $configurationFile,
                $analyseDirectories !== [] ? $this->absoluteFiles($root, $analyseDirectories) : $this->absoluteFiles($root, $relativeFiles),
                $this->absoluteFiles($root, $scanDirectories),
                $this->resultCacheDirectory($root),
            );
            if (file_put_contents($overlayConfiguration, $overlay) === false) {
                throw new RuntimeException('Unable to write PHPStan overlay configuration: ' . $overlayConfiguration);
            }

            // The analysed paths travel inside the overlay configuration instead of the command
            // line: a long file list blows the operating system argument limit, and PHPStan skips
            // writing its result cache whenever paths are passed as arguments.
            $command = [PHP_BINARY, $phar, 'analyse'];
            $command[] = '--configuration=' . $overlayConfiguration;
            $command[] = '--autoload-file=' . $autoload;
            $command[] = '--no-progress';
            $command[] = '--error-format=raw';
            if ($memoryLimit !== null) {
                $command[] = '--memory-limit=' . $memoryLimit;
            }

            $environment = getenv();
            $environment['AGENT_MAP_PHPSTAN_EXPORT'] = $exportFile;

            [$exitCode, $stdout, $stderr] = $this->run($command, $root, $environment);

            if (!is_file($exportFile)) {
                throw new RuntimeException(
                    'PHPStan semantic export was not created.'
                    . ($stderr !== '' ? ' ' . trim($stderr) : '')
                    . ($stdout !== '' ? ' ' . trim($stdout) : ''),
                );
            }

            $json = file_get_contents($exportFile);
            if (!is_string($json)) {
                throw new RuntimeException('Unable to read PHPStan semantic export.');
            }

            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('PHPStan semantic export is malformed.', 0, $exception);
            }
            if (!is_array($data) || !is_array($data['records'] ?? null)) {
                throw new RuntimeException('PHPStan semantic export is malformed.');
            }

            $records = [];
            foreach ($data['records'] as $record) {
                if (is_array($record)) {
                    $records[] = $record;
                }
            }

            return new SemanticAnalysisResult(
                records: $records,
                findings: $this->findings($stdout, $stderr, $exitCode),
                phpStanVersion: $this->phpStanVersion(),
                configurationSha256: $this->configurationHash($configurationFile),
            );
        } finally {
            $this->removeDirectory($temporaryDirectory);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{0: int, 1: string, 2: string}
     */
    private function run(array $command, string $workingDirectory, array $environment): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start PHPStan.');
        }

        /** @var array<int, resource> $streams */
        $streams = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
        }
        $buffers = [1 => '', 2 => ''];

        try {
            while ($streams !== []) {
                $read = array_values($streams);
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 1);
                if ($selected === false) {
                    throw new RuntimeException('Unable to read PHPStan process output.');
                }

                foreach ($read as $stream) {
                    $key = array_search($stream, $streams, true);
                    if (!is_int($key)) {
                        continue;
                    }
                    $chunk = stream_get_contents($stream);
                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read PHPStan process output.');
                    }
                    $buffers[$key] .= $chunk;
                }

                foreach ($streams as $key => $stream) {
                    if (!feof($stream)) {
                        continue;
                    }
                    fclose($stream);
                    unset($streams[$key]);
                }
            }
        } catch (Throwable $exception) {
            foreach ($streams as $stream) {
                fclose($stream);
            }
            proc_terminate($process);
            proc_close($process);

            throw $exception;
        }

        return [proc_close($process), $buffers[1], $buffers[2]];
    }

    /**
     * @param list<string> $absolutePaths
     * @param list<string> $absoluteScanPaths
     */
    private function overlayConfiguration(?string $configurationFile, array $absolutePaths, array $absoluteScanPaths, string $cacheDirectory): string
    {
        $extension = dirname(__DIR__) . '/PhpStan/resources/extension.neon';
        $includes = [];
        if ($configurationFile !== null) {
            $realConfiguration = realpath($configurationFile);
            if (!is_string($realConfiguration)) {
                throw new RuntimeException('PHPStan configuration not found: ' . $configurationFile);
            }
            $includes[] = $realConfiguration;
        }
        $includes[] = $extension;

        $content = "includes:\n";
        foreach ($includes as $include) {
            $content .= '    - ' . json_encode(str_replace('\\', '/', $include), JSON_THROW_ON_ERROR) . "\n";
        }

        $content .= "parameters:\n";
        if ($configurationFile === null) {
            $content .= "    level: 0\n";
        }
        // An own result cache directory keeps repeated map builds incremental without invalidating
        // the cache of the surrounding project configuration, which analyses different rules.
        $content .= '    tmpDir: ' . json_encode(str_replace('\\', '/', $cacheDirectory), JSON_THROW_ON_ERROR) . "\n";
        $content .= "    paths:\n";
        foreach ($absolutePaths as $absolutePath) {
            $content .= '        - ' . json_encode(str_replace('\\', '/', $absolutePath), JSON_THROW_ON_ERROR) . "\n";
        }
        if ($absoluteScanPaths !== []) {
            $content .= "    scanDirectories:\n";
            foreach ($absoluteScanPaths as $absoluteScanPath) {
                $content .= '        - ' . json_encode(str_replace('\\', '/', $absoluteScanPath), JSON_THROW_ON_ERROR) . "\n";
            }
        }

        return $content;
    }

    /**
     * @param list<string> $relativePaths
     * @return list<string>
     */
    private function absoluteFiles(string $root, array $relativePaths): array
    {
        $absolute = [];
        foreach ($relativePaths as $relativePath) {
            $absolute[] = $relativePath === '.' ? $root : $root . '/' . $relativePath;
        }

        return $absolute;
    }

    private function resultCacheDirectory(string $root): string
    {
        $directory = $root . '/.agent-map/phpstan-cache';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create PHPStan result cache directory: ' . $directory);
        }

        return $directory;
    }

    private function findComposerAutoload(): string
    {
        $directory = dirname(__DIR__, 2);
        for ($i = 0; $i < 6; ++$i) {
            $candidate = $directory . '/vendor/autoload.php';
            if (is_file($candidate)) {
                return $candidate;
            }
            $candidate = $directory . '/autoload.php';
            if (is_file($candidate) && basename($directory) === 'vendor') {
                return $candidate;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        throw new RuntimeException('Unable to locate Composer autoload.php for PHPStan extension loading.');
    }

    /** @return list<string> */
    private function findings(string $stdout, string $stderr, int $exitCode): array
    {
        $lines = [];
        foreach (preg_split('/\R/', trim($stdout . "\n" . $stderr)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
            if (count($lines) >= 100) {
                $lines[] = 'Additional PHPStan findings omitted.';
                break;
            }
        }
        if ($exitCode !== 0 && $lines === []) {
            $lines[] = 'PHPStan exited with code ' . $exitCode . ' while still producing a semantic map.';
        }

        return $lines;
    }

    private function phpStanVersion(): string
    {
        return InstalledVersions::getPrettyVersion('phpstan/phpstan') ?? InstalledVersions::getVersion('phpstan/phpstan') ?? 'unknown';
    }

    private function configurationHash(?string $configurationFile): string
    {
        if ($configurationFile === null) {
            return 'sha256:' . hash('sha256', 'default-level-0');
        }
        $hash = hash_file('sha256', $configurationFile);
        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash PHPStan configuration: ' . $configurationFile);
        }

        return 'sha256:' . $hash;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $removed = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$removed) {
                throw new RuntimeException('Unable to remove temporary PHPStan path: ' . $item->getPathname());
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Unable to remove PHPStan temporary directory: ' . $path);
        }
    }
}
