<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class QueryMatch
{
    /**
     * @param list<FileEntry> $files
     * @param 'exact'|'normalized'|'mixed'|'none' $matchType 'exact' means every result was
     *   found verbatim (case-insensitive substring). 'normalized' means matching required
     *   stripping case and separators (`is_dev_user` vs `isDevUser` vs `->is_dev_user`), and
     *   'mixed' includes both. Normalized matches are useful discovery hints, not confirmation
     *   that a symbol exists exactly as spelled.
     */
    public function __construct(
        public array $files,
        public string $matchType,
    ) {
    }
}
