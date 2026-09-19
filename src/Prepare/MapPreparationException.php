<?php

declare(strict_types=1);

namespace voku\AgentMap\Prepare;

use RuntimeException;
use Throwable;

/**
 * A preparation refusal that leaves the existing map untouched and names the
 * owner recovery operation a caller may present to its host.
 */
final class MapPreparationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $recoveryCommand,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
