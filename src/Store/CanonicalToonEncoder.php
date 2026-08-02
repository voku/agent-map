<?php

declare(strict_types=1);

namespace voku\AgentMap\Store;

use HelgeSverre\Toon\Toon;

final readonly class CanonicalToonEncoder
{
    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        return Toon::encode($this->canonicalize($payload)) . "\n";
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }
}
