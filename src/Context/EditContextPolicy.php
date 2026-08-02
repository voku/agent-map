<?php

declare(strict_types=1);

namespace voku\AgentMap\Context;

use InvalidArgumentException;

final readonly class EditContextPolicy
{
    public function __construct(
        public int $maximumSourceBytes = 60_000,
        public int $maximumFiles = 20,
        public int $maximumCallers = 10,
        public int $maximumCallees = 10,
        public int $maximumTests = 10,
        public int $maximumTypeDefinitions = 10,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value < 0 || ($name === 'maximumSourceBytes' && $value < 1)) {
                throw new InvalidArgumentException('Invalid edit-context policy value: ' . $name);
            }
        }
    }
}
