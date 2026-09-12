<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Evidence\DefinitionCapabilityProbe;
use voku\AgentMap\MapArtifactPaths;

final readonly class DefinitionCapabilitiesCliApplication
{
    public function __construct(
        private ?MapArtifactPaths $artifacts = null,
        private ?string $defaultRoot = null,
    ) {
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'definition-capabilities'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'definition-capabilities');
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            $options = $this->options($argv);
            if ($options['help']) {
                echo <<<'TEXT'
Usage: agent-map definition-capabilities [--root PATH] [--index FILE] [--format text|json]

Report the definition owner route and whether each currently supported definition capability is operational.
This is a projection of the typed PHP owner API; PHP consumers should use DefinitionCapabilityProbe directly.

TEXT;
                return 0;
            }

            $report = (new DefinitionCapabilityProbe())->probe($options['root'], $options['index']);
            if ($options['format'] === 'json') {
                echo json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            echo 'Definition route: ' . $report->route . "\n";
            foreach ($report->toArray()['capabilities'] as $name => $capability) {
                echo sprintf(
                    "  %s: %s [%s]%s\n",
                    $name,
                    $capability['status'],
                    $capability['provider'],
                    $capability['reason'] === null ? '' : ' - ' . $capability['reason'],
                );
            }

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $argv
     * @return array{root: string, index: string, format: 'text'|'json', help: bool}
     */
    private function options(array $argv): array
    {
        $help = ($argv[1] ?? null) === 'help' || in_array('--help', $argv, true) || in_array('-h', $argv, true);
        $tokens = ($argv[1] ?? null) === 'help' ? array_slice($argv, 3) : array_slice($argv, 2);
        $root = $this->defaultRoot ?? (getcwd() ?: '.');
        $rootProvided = false;
        $index = null;
        $format = 'text';

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '--help' || $token === '-h') {
                continue;
            }
            if (!str_starts_with($token, '--')) {
                throw new InvalidArgumentException('Unexpected definition-capabilities argument: ' . $token);
            }

            if (str_contains($token, '=')) {
                [$name, $value] = explode('=', substr($token, 2), 2);
            } else {
                $name = substr($token, 2);
                $value = $tokens[$i + 1] ?? null;
                if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                    throw new InvalidArgumentException('Missing value for option: --' . $name);
                }
                ++$i;
            }

            if ($value === '') {
                throw new InvalidArgumentException('Empty value for option: --' . $name);
            }

            if ($name === 'root') {
                $root = $value;
                $rootProvided = true;
                continue;
            }
            if ($name === 'index') {
                $index = $value;
                continue;
            }
            if ($name === 'format') {
                if (!in_array($value, ['text', 'json'], true)) {
                    throw new InvalidArgumentException('Unknown definition-capabilities format: ' . $value);
                }
                $format = $value;
                continue;
            }

            throw new InvalidArgumentException('Unknown definition-capabilities option: --' . $name);
        }

        $paths = MapArtifactPaths::forProject($root);
        if ($index !== null) {
            $index = $paths->projectPath($index);
        } elseif ($this->artifacts !== null && !$rootProvided) {
            $index = $this->artifacts->indexJson();
        } else {
            $index = $paths->indexJson();
        }

        return [
            'root' => $root,
            'index' => $index,
            'format' => $format,
            'help' => $help,
        ];
    }
}
