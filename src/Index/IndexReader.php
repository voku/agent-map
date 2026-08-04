<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use HelgeSverre\Toon\Exceptions\DecodeException;
use HelgeSverre\Toon\Toon;
use JsonException;
use RuntimeException;

final readonly class IndexReader
{
    /** Read size for the section scan; the largest section is read in pieces of this size. */
    private const CHUNK_BYTES = 65536;

    public function read(string $file): AgentMapIndex
    {
        if (!is_file($file)) {
            throw new RuntimeException('Index file not found: ' . $file);
        }
        $content = file_get_contents($file);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read index: ' . $file);
        }

        $toonFirst = str_ends_with(strtolower($file), '.toon');
        $data = $toonFirst ? $this->decodeToon($content) : $this->decodeJson($content);
        if ($data === null) {
            // File extensions are useful hints, not a second map schema. Try
            // the other serializer so renamed or extension-free indexes still
            // load without making callers choose a different reader.
            $data = $toonFirst ? $this->decodeJson($content) : $this->decodeToon($content);
        }
        if ($data === null) {
            throw new RuntimeException('Invalid agent-map index: ' . $file);
        }

        return AgentMapIndex::fromArray($data);
    }

    /**
     * Reads an index but decodes only the sections a command actually uses.
     *
     * `relations` is by far the largest section of a real map - tens of megabytes against a few for
     * `files` - so decoding it for a "show me this file" lookup dominates the runtime of every quick
     * query. IndexWriter puts each top-level section on its own line, which lets this skip the
     * unwanted ones with a plain line scan. Anything that does not match that layout (an older index,
     * a TOON index, a hand-edited file) falls back to the regular full read.
     *
     * @param list<string> $sections
     */
    public function readSections(string $file, array $sections): AgentMapIndex
    {
        if (!is_file($file)) {
            throw new RuntimeException('Index file not found: ' . $file);
        }
        if (str_ends_with(strtolower($file), '.toon')) {
            return $this->read($file);
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return $this->read($file);
        }

        try {
            // An index written before the section layout is one single line, and the layout always
            // opens with a short scalar section - so no newline in the first chunk means this is not
            // a sectioned index, and scanning on would only decode the whole map twice.
            $probe = fread($handle, self::CHUNK_BYTES);
            if (!is_string($probe) || !str_contains($probe, "\n")) {
                return $this->read($file);
            }
            rewind($handle);

            $data = [];
            while (($head = fgets($handle, self::CHUNK_BYTES)) !== false) {
                $complete = $this->endsLine($head, $handle);
                $trimmed = rtrim($head, "\r\n");
                if ($complete && ($trimmed === '}' || $trimmed === '')) {
                    continue;
                }

                $entry = $this->sectionLine($trimmed, $complete);
                if ($entry === null) {
                    return $this->read($file);
                }

                [$key, $raw] = $entry;
                // Skipping a section means never holding it in memory: the relation list of a real
                // map is tens of megabytes, and building that string is most of what this avoids.
                $keep = in_array($key, $sections, true) || !str_starts_with($raw, '[');
                $rest = $complete ? '' : $this->consumeLine($handle, $keep);
                if (!$keep) {
                    $data[$key] = [];
                    continue;
                }

                $decoded = $this->decodeJson('{' . json_encode($key, JSON_THROW_ON_ERROR) . ':' . rtrim($raw . $rest, ",\r\n") . '}');
                if ($decoded === null) {
                    return $this->read($file);
                }

                $data[$key] = $decoded[$key];
            }
        } finally {
            fclose($handle);
        }

        if ($data === []) {
            return $this->read($file);
        }

        return AgentMapIndex::fromArray($data);
    }

    /** Whether the chunk just read reaches the end of its line. */
    private function endsLine(string $chunk, mixed $handle): bool
    {
        return str_ends_with($chunk, "\n") || feof($handle);
    }

    /**
     * Reads the remainder of the current line, returning it only when the caller wants to keep it.
     *
     * @param resource $handle
     */
    private function consumeLine(mixed $handle, bool $keep): string
    {
        $rest = '';
        while (($chunk = fgets($handle, self::CHUNK_BYTES)) !== false) {
            if ($keep) {
                $rest .= $chunk;
            }
            if ($this->endsLine($chunk, $handle)) {
                break;
            }
        }

        return $rest;
    }

    /**
     * Splits one `"key":<value>` section line, tolerating the leading `{` or trailing `,`.
     *
     * @param bool $complete whether `$line` is the whole line; a trailing comma may only be dropped
     *                       then, because mid-value it is part of the JSON
     *
     * @return array{0: string, 1: string}|null
     */
    private function sectionLine(string $line, bool $complete): ?array
    {
        // The writer opens the document with `{` and ends every section line but the last with `,`.
        $line = ltrim($line, '{');
        if ($complete) {
            $line = rtrim($line, ',');
        }
        if (!str_starts_with($line, '"')) {
            return null;
        }

        $closing = strpos($line, '":', 1);
        if ($closing === false) {
            return null;
        }

        $key = substr($line, 1, $closing - 1);
        $raw = substr($line, $closing + 2);
        if ($key === '' || $raw === '' || str_contains($key, '\\')) {
            return null;
        }

        return [$key, $raw];
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(string $content): ?array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string, mixed>|null */
    private function decodeToon(string $content): ?array
    {
        try {
            $decoded = Toon::decode($content);
        } catch (DecodeException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
