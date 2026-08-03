<?php

declare(strict_types=1);

namespace voku\AgentMap\Context;

use InvalidArgumentException;

final readonly class EditContextPolicy
{
    /** @param list<string> $focusTerms */
    public function __construct(
        public int $maximumSourceBytes = 60_000,
        public int $maximumFiles = 20,
        public int $maximumCallers = 10,
        public int $maximumCallees = 10,
        public int $maximumTests = 10,
        public int $maximumTypeDefinitions = 10,
        public array $focusTerms = [],
        public int $focusContextLines = 12,
        public int $maximumFocusMatches = 3,
        public bool $includeRelatedContext = true,
    ) {
        foreach ([
            'maximumSourceBytes' => $maximumSourceBytes,
            'maximumFiles' => $maximumFiles,
            'maximumCallers' => $maximumCallers,
            'maximumCallees' => $maximumCallees,
            'maximumTests' => $maximumTests,
            'maximumTypeDefinitions' => $maximumTypeDefinitions,
            'focusContextLines' => $focusContextLines,
            'maximumFocusMatches' => $maximumFocusMatches,
        ] as $name => $value) {
            if ($value < 0 || (in_array($name, ['maximumSourceBytes', 'maximumFiles'], true) && $value < 1)) {
                throw new InvalidArgumentException('Invalid edit-context policy value: ' . $name);
            }
        }
        if ($maximumFocusMatches < 1) {
            throw new InvalidArgumentException('Invalid edit-context policy value: maximumFocusMatches');
        }
        foreach ($focusTerms as $focusTerm) {
            if (trim($focusTerm) === '') {
                throw new InvalidArgumentException('Edit-context focus terms must be non-empty strings.');
            }
        }
    }
}
