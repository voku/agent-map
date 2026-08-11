<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class CoChangePair
{
    /**
     * @param list<string> $revisions
     * @param array<string, float> $staticSignals
     */
    public function __construct(
        public string $left,
        public string $right,
        public int $coChanges,
        public int $leftChanges,
        public int $rightChanges,
        public float $jaccard,
        public float $smallerSideRatio,
        public array $revisions,
        public float $semanticStaticWeight,
        public float $pathStaticWeight,
        public array $staticSignals,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'left' => $this->left,
            'right' => $this->right,
            'cochanges' => $this->coChanges,
            'left_changes' => $this->leftChanges,
            'right_changes' => $this->rightChanges,
            'jaccard' => $this->jaccard,
            'smaller_side_ratio' => $this->smallerSideRatio,
            'revisions' => $this->revisions,
            'semantic_static_weight' => $this->semanticStaticWeight,
            'path_static_weight' => $this->pathStaticWeight,
            'static_signals' => $this->staticSignals,
        ];
    }
}
