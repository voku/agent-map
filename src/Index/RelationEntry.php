<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class RelationEntry
{
    /**
     * @param non-empty-list<string> $targetIds
     */
    public function __construct(
        public string $id,
        public string $sourceId,
        public string $kind,
        public array $targetIds,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
        public string $resolution,
        public ?string $receiverType = null,
        public ?string $resultType = null,
    ) {
    }

    /**
     * @param non-empty-list<string> $targetIds
     */
    public static function create(
        string $sourceId,
        string $kind,
        array $targetIds,
        string $file,
        int $lineStart,
        int $lineEnd,
        string $resolution,
        ?string $receiverType = null,
        ?string $resultType = null,
    ): self {
        sort($targetIds, SORT_STRING);
        $identity = implode("\0", [
            $sourceId,
            $kind,
            implode(',', $targetIds),
            $file,
            (string) $lineStart,
            (string) $lineEnd,
            $resolution,
        ]);

        return new self(
            id: 'relation:' . hash('sha256', $identity),
            sourceId: $sourceId,
            kind: $kind,
            targetIds: $targetIds,
            file: $file,
            lineStart: $lineStart,
            lineEnd: $lineEnd,
            resolution: $resolution,
            receiverType: $receiverType,
            resultType: $resultType,
        );
    }

    /**
     * @return array{id: string, source_id: string, kind: string, target_ids: non-empty-list<string>, file: string, line_start: int, line_end: int, resolution: string, receiver_type: ?string, result_type: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_id' => $this->sourceId,
            'kind' => $this->kind,
            'target_ids' => $this->targetIds,
            'file' => $this->file,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'resolution' => $this->resolution,
            'receiver_type' => $this->receiverType,
            'result_type' => $this->resultType,
        ];
    }

    /**
     * @param array{id?: mixed, source_id?: mixed, kind?: mixed, target_ids?: mixed, file?: mixed, line_start?: mixed, line_end?: mixed, resolution?: mixed, receiver_type?: mixed, result_type?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        $targetIds = [];
        foreach (is_array($data['target_ids'] ?? null) ? $data['target_ids'] : [] as $targetId) {
            if (is_string($targetId) && $targetId !== '') {
                $targetIds[] = $targetId;
            }
        }
        if ($targetIds === []) {
            $targetIds[] = 'unresolved:unknown';
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            sourceId: (string) ($data['source_id'] ?? ''),
            kind: (string) ($data['kind'] ?? ''),
            targetIds: $targetIds,
            file: (string) ($data['file'] ?? ''),
            lineStart: (int) ($data['line_start'] ?? 0),
            lineEnd: (int) ($data['line_end'] ?? 0),
            resolution: (string) ($data['resolution'] ?? 'dynamic'),
            receiverType: is_string($data['receiver_type'] ?? null) ? $data['receiver_type'] : null,
            resultType: is_string($data['result_type'] ?? null) ? $data['result_type'] : null,
        );
    }
}
