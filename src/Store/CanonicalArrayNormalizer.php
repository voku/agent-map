<?php

declare(strict_types=1);

namespace voku\AgentMap\Store;

final readonly class CanonicalArrayNormalizer
{
    public function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->normalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $nested) {
            $value[$key] = $this->normalize($nested);
        }

        return $value;
    }
}
