<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use WeakMap;

/**
 * Lazily built lookup tables for one {@see AgentMapIndex} instance.
 *
 * An index is treated as a deep-immutable value: the class and every entry it
 * holds are `readonly`, so a lookup table built once stays correct for the
 * instance's lifetime. `readonly` does not freeze an array slot that is still
 * a PHP reference (`$r = &$files[0]` before construction keeps `$r` writable),
 * so a caller that builds an index from referenced arrays and then mutates
 * them gets stale answers. Indexes decoded by IndexReader or built by
 * AgentMapBuilder never contain references. Planners ask the same index for incoming/outgoing relations,
 * methods and symbols dozens of times per request; answering each with a
 * full scan of every relation or every file made a single edit-context plan
 * quadratic in practice and spent most of its time re-serialising the whole
 * map to compute an unchanged digest.
 *
 * Tables are kept in a WeakMap keyed by the index object, so they add nothing
 * to the index's own state, equality or serialisation and disappear with it.
 * Every table preserves the iteration order of the scans it replaces: the
 * first match wins where a scan returned the first match, and lists keep the
 * original relation/file order.
 *
 * @internal
 */
final class AgentMapIndexLookup
{
    /** @var WeakMap<AgentMapIndex, self>|null */
    private static ?WeakMap $instances = null;

    /** @var array<string, list<RelationEntry>>|null */
    private ?array $outgoingBySource = null;

    /** @var array<string, list<RelationEntry>>|null */
    private ?array $incomingByTarget = null;

    /** @var array<string, array{FileEntry, SymbolEntry, MethodEntry}>|null */
    private ?array $methodsById = null;

    /** @var array<string, array{file: FileEntry, symbol: SymbolEntry}>|null */
    private ?array $symbolsById = null;

    /** @var array<string, array{file: FileEntry, symbol: SymbolEntry}>|null */
    private ?array $symbolsByFqn = null;

    /** @var array<string, FileEntry>|null */
    private ?array $filesByPath = null;

    /** @var array<string, list<LocalBindingEntry>>|null */
    private ?array $bindingsByOwner = null;

    /** @var array<string, list<LocalExitEntry>>|null */
    private ?array $exitsByOwner = null;

    private ?string $mapDigest = null;

    private function __construct()
    {
    }

    public static function for(AgentMapIndex $index): self
    {
        self::$instances ??= new WeakMap();
        $lookup = self::$instances[$index] ?? null;
        if ($lookup === null) {
            // The lookup must never reference its index: a WeakMap value that
            // points back at its key would keep the index alive forever.
            $lookup = new self();
            self::$instances[$index] = $lookup;
        }

        return $lookup;
    }

    /** @return list<RelationEntry> */
    public function outgoing(AgentMapIndex $index, string $symbolId): array
    {
        if ($this->outgoingBySource === null) {
            $this->outgoingBySource = [];
            foreach ($index->relations as $relation) {
                $this->outgoingBySource[$relation->sourceId][] = $relation;
            }
        }

        return $this->outgoingBySource[$symbolId] ?? [];
    }

    /** @return list<RelationEntry> */
    public function incoming(AgentMapIndex $index, string $symbolId): array
    {
        if ($this->incomingByTarget === null) {
            $this->incomingByTarget = [];
            foreach ($index->relations as $relation) {
                $seen = [];
                foreach ($relation->targetIds as $targetId) {
                    // A relation listing the same target twice is still one incoming relation.
                    if (isset($seen[$targetId])) {
                        continue;
                    }
                    $seen[$targetId] = true;
                    $this->incomingByTarget[$targetId][] = $relation;
                }
            }
        }

        return $this->incomingByTarget[$symbolId] ?? [];
    }

    /** @return array{FileEntry, SymbolEntry, MethodEntry}|null */
    public function method(AgentMapIndex $index, string $id): ?array
    {
        if ($this->methodsById === null) {
            $this->methodsById = [];
            foreach ($index->files as $file) {
                foreach ($file->symbols as $symbol) {
                    foreach ($symbol->methods as $method) {
                        $this->methodsById[$symbol->methodId($method)] ??= [$file, $symbol, $method];
                    }
                }
            }
        }

        return $this->methodsById[$id] ?? null;
    }

    /** @return array{file: FileEntry, symbol: SymbolEntry}|null */
    public function symbol(AgentMapIndex $index, string $id): ?array
    {
        if ($this->symbolsById === null) {
            $this->symbolsById = [];
            foreach ($index->files as $file) {
                foreach ($file->symbols as $symbol) {
                    $this->symbolsById[$symbol->id()] ??= ['file' => $file, 'symbol' => $symbol];
                }
            }
        }

        return $this->symbolsById[$id] ?? null;
    }

    /** @return array{file: FileEntry, symbol: SymbolEntry}|null */
    public function symbolByFqn(AgentMapIndex $index, string $fqn): ?array
    {
        if ($this->symbolsByFqn === null) {
            $this->symbolsByFqn = [];
            foreach ($index->files as $file) {
                foreach ($file->symbols as $symbol) {
                    $this->symbolsByFqn[ltrim($symbol->fqn, '\\')] ??= ['file' => $file, 'symbol' => $symbol];
                }
            }
        }

        return $this->symbolsByFqn[ltrim($fqn, '\\')] ?? null;
    }

    public function file(AgentMapIndex $index, string $path): ?FileEntry
    {
        if ($this->filesByPath === null) {
            $this->filesByPath = [];
            foreach ($index->files as $file) {
                $this->filesByPath[$file->path] ??= $file;
            }
        }

        return $this->filesByPath[$path] ?? null;
    }

    /** @return list<LocalBindingEntry> */
    public function bindings(AgentMapIndex $index, string $ownerId): array
    {
        if ($this->bindingsByOwner === null) {
            $this->bindingsByOwner = [];
            foreach ($index->localBindings as $binding) {
                $this->bindingsByOwner[$binding->ownerId][] = $binding;
            }
        }

        return $this->bindingsByOwner[$ownerId] ?? [];
    }

    /** @return list<LocalExitEntry> */
    public function exits(AgentMapIndex $index, string $ownerId): array
    {
        if ($this->exitsByOwner === null) {
            $this->exitsByOwner = [];
            foreach ($index->localExits as $exit) {
                $this->exitsByOwner[$exit->ownerId][] = $exit;
            }
        }

        return $this->exitsByOwner[$ownerId] ?? [];
    }

    /** @param callable(): string $compute */
    public function mapDigest(callable $compute): string
    {
        return $this->mapDigest ??= $compute();
    }
}
