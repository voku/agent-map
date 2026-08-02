<?php

declare(strict_types=1);

namespace voku\AgentMap\Build;

use Composer\InstalledVersions;
use RuntimeException;

final readonly class PhpStanSemanticAnalyzer implements SemanticAnalyzer
{
    public function analyse(string $root, array $relativeFiles, ?string $configurationFile = null): SemanticAnalysisResult
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

        $exportFile = $temporaryDirectory . '/semantic-export.json';
        $overlayConfiguration = $temporaryDirectory . '/agent-map.neon';
        file_put_contents($overlayConfiguration, $this->overlayConfiguration($configurationFile));

        $command = [PHP_BINARY, $phar, 'analyse'];
        foreach ($relativeFiles as $relativeFile) {
            $command[] = $root . '/' . $relativeFile;
        }
        $command[] = '--configuration=' . $overlayConfiguration;
        $command[] = '--autoload-file=' . $autoload;
        $command[] = '--no-progress';
        $command[] = '--error-format=raw';

        $previousExport = getenv('AGENT_MAP_PHPSTAN_EXPORT');
        putenv('AGENT_MAP_PHPSTAN_EXPORT=' . $exportFile);
        try {
            [$exitCode, $stdout, $stderr] = $this->run($command, $root);
        } finally {
            if ($previousExport === false) {
                putenv('AGENT_MAP_PHPSTAN_EXPORT');
            } else {
                putenv('AGENT_MAP_PHPSTAN_EXPORT=' . $previousExport);
            }
        }

        try {
            if (!is_file($exportFile)) {
                throw new RuntimeException(
                    'PHPStan semantic export was not created.'
                    . ($stderr !== '' ? ' ' . trim($stderr) : '')
                    . ($stdout !== '' ? ' ' . trim($stdout) : ''),
                );
            }
            $json = file_get_contents($exportFile);
            $data = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($data) || !is_array($data['records'] ?? null)) {
                throw new RuntimeException('PHPStan semantic export is malformed.');
            }

            $records = [];
            foreach ($data['records'] as $record) {
                if (is_array($record)) {
                    $records[] = $record;
                }
            }

            $findings = $this->findings($stdout, $stderr, $exitCode);

            return new SemanticAnalysisResult(
                records: $records,
                findings: $findings,
                phpStanVersion: $this->phpStanVersion(),
                configurationSha256: $this->configurationHash($configurationFile),
            );
        } finally {
            @unlink($exportFile);
            @unlink($overlayConfiguration);
            @rmdir($temporaryDirectory);
        }
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string}
     */
    private function run(array $command, string $workingDirectory): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start PHPStan.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
    }

    private function overlayConfiguration(?string $configurationFile): string
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
        if ($configurationFile === null) {
            $content .= "parameters:\n    level: 0\n";
        }

        return $content;
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

        return is_string($hash) ? 'sha256:' . $hash : '';
    }
}
