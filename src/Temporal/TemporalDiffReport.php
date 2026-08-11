<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class TemporalDiffReport
{
    /** @param list<TemporalEvent> $events */
    public function __construct(
        public string $beforeDigest,
        public string $afterDigest,
        public array $events,
    ) {
    }

    /** @return array<string, int> */
    public function countsByKind(): array
    {
        $counts = [];
        foreach ($this->events as $event) {
            $counts[$event->kind] = ($counts[$event->kind] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @return array{
     *     before_map_digest: string,
     *     after_map_digest: string,
     *     event_count: int,
     *     counts: array<string, int>,
     *     events: list<array{kind: string, entity_type: string, entity_id: string, before: array<string, string|int|float|bool|null>, after: array<string, string|int|float|bool|null>}>,
     *     truncated: bool
     * }
     */
    public function toArray(int $limit = 100): array
    {
        $limit = max(1, $limit);
        $events = array_slice($this->events, 0, $limit);

        return [
            'before_map_digest' => $this->beforeDigest,
            'after_map_digest' => $this->afterDigest,
            'event_count' => count($this->events),
            'counts' => $this->countsByKind(),
            'events' => array_map(static fn (TemporalEvent $event): array => $event->toArray(), $events),
            'truncated' => count($this->events) > count($events),
        ];
    }
}
