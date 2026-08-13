<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

/**
 * One searchable unit of code, derived from a canonical map symbol.
 *
 * The identity is the canonical symbol id plus a chunk kind, never a storage row id: an index that
 * is rebuilt from the same map must produce the same chunk ids, or a result cannot be traced back
 * to the map it came from.
 *
 * `contentSha256` covers the chunk policy, the symbol id and the normalized content, so an unchanged
 * method keeps its fingerprint - and its cached embedding - when a neighbouring method changes.
 */
final readonly class CodeChunk
{
    public const KIND_SYMBOL_OVERVIEW = 'symbol_overview';

    public const KIND_METHOD_BODY = 'method_body';

    public const KIND_FUNCTION_BODY = 'function_body';

    public const KIND_FILE_BODY = 'file_body';

    public function __construct(
        public string $chunkId,
        public string $symbolId,
        public string $filePath,
        public string $symbolName,
        public string $kind,
        public int $startLine,
        public int $endLine,
        public string $sourceSha256,
        public string $contentSha256,
        public string $signature,
        public string $content,
    ) {
    }

    public static function create(
        string $symbolId,
        string $kind,
        string $filePath,
        string $symbolName,
        int $startLine,
        int $endLine,
        string $sourceSha256,
        string $signature,
        string $content,
        int $chunkPolicyVersion = ChunkPolicy::VERSION,
    ): self {
        return new self(
            chunkId: self::identity($symbolId, $kind, $chunkPolicyVersion),
            symbolId: $symbolId,
            filePath: $filePath,
            symbolName: $symbolName,
            kind: $kind,
            startLine: $startLine,
            endLine: $endLine,
            sourceSha256: $sourceSha256,
            contentSha256: self::fingerprint($symbolId, $content, $chunkPolicyVersion),
            signature: $signature,
            content: $content,
        );
    }

    public static function identity(string $symbolId, string $kind, int $chunkPolicyVersion = ChunkPolicy::VERSION): string
    {
        $suffix = match ($kind) {
            self::KIND_SYMBOL_OVERVIEW => 'overview',
            self::KIND_FILE_BODY       => 'file',
            default                    => 'body',
        };

        return $symbolId . '#' . $suffix . ':v' . $chunkPolicyVersion;
    }

    /**
     * Line endings and trailing whitespace are normalized away: a file re-saved with different line
     * endings must not invalidate every embedding in it.
     */
    public static function fingerprint(string $symbolId, string $content, int $chunkPolicyVersion = ChunkPolicy::VERSION): string
    {
        $normalized = rtrim(str_replace(["\r\n", "\r"], "\n", $content));

        return 'sha256:' . hash('sha256', $chunkPolicyVersion . "\0" . $symbolId . "\0" . $normalized);
    }
}
