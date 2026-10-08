<?php

declare(strict_types=1);

namespace voku\AgentMap\Prepare;

use Throwable;
use voku\AgentMap\IO\PhpFileFinder;

/**
 * Keeps a map current in a long-running process.
 *
 * Each cycle costs one directory walk plus one stat per PHP file; the real
 * refresh (which hashes files and re-parses what moved) only runs when that
 * cheap signature changed and then stayed stable for one more interval, so a
 * save burst or a branch switch triggers a single refresh. The process stays
 * warm between edits: no PHP start-up, no autoload, and the parser's
 * memoized docblocks survive.
 *
 * Polling instead of inotify on purpose: it behaves the same on native Linux
 * paths, WSL2 `/mnt/c` mounts and containers, and for a few hundred files it
 * is cheaper than the refresh it guards.
 */
final readonly class MapWatcher
{
    /** @var \Closure(int): void */
    private \Closure $sleepMilliseconds;

    /**
     * @param (callable(int): void)|null $sleepMilliseconds replaceable for tests
     */
    public function __construct(
        private MapPreparationService $service = new MapPreparationService(),
        private PhpFileFinder $finder = new PhpFileFinder(),
        ?callable $sleepMilliseconds = null,
    ) {
        $this->sleepMilliseconds = $sleepMilliseconds !== null
            ? \Closure::fromCallable($sleepMilliseconds)
            : static function (int $milliseconds): void {
                usleep($milliseconds * 1000);
            };
    }

    /**
     * @param callable(string): void $log
     * @param callable(): bool       $shouldStop polled once per cycle
     * @param int|null               $maxCycles  stop after this many polling cycles (tests)
     *
     * @return int number of refreshes that ran, including the initial one
     */
    public function watch(
        MapPreparationRequest $request,
        int $intervalMilliseconds,
        callable $log,
        callable $shouldStop,
        ?int $maxCycles = null,
    ): int {
        $refreshes = 0;
        $seen = $this->signature($request);

        $refreshes += $this->refreshOnce($request, $log, true) ? 1 : 0;

        for ($cycle = 0; $maxCycles === null || $cycle < $maxCycles; ++$cycle) {
            if ($shouldStop()) {
                break;
            }

            ($this->sleepMilliseconds)($intervalMilliseconds);
            $current = $this->signature($request);
            if ($current === $seen) {
                continue;
            }

            // Debounce: wait until the file set stops moving before paying for a refresh.
            for ($settle = 0; $settle < 20; ++$settle) {
                if ($shouldStop()) {
                    break;
                }

                ($this->sleepMilliseconds)($intervalMilliseconds);
                $next = $this->signature($request);
                if ($next === $current) {
                    break;
                }
                $current = $next;
            }

            $seen = $current;
            $refreshes += $this->refreshOnce($request, $log, false) ? 1 : 0;
        }

        return $refreshes;
    }

    /**
     * A failing refresh (for example a file saved mid-edit with a syntax error)
     * is reported and retried on the next change, never fatal for the watcher.
     *
     * @param callable(string): void $log
     */
    private function refreshOnce(MapPreparationRequest $request, callable $log, bool $initial): bool
    {
        $started = hrtime(true);
        try {
            $result = $initial ? $this->service->prepare($request) : $this->service->refresh($request);
        } catch (Throwable $throwable) {
            $log('refresh failed: ' . $throwable->getMessage());

            return false;
        }

        $log(sprintf('%s (%d ms)', $result->message, (int) ((hrtime(true) - $started) / 1_000_000)));

        return true;
    }

    /**
     * @return array<string, string> relative path => "mtime-size"
     */
    private function signature(MapPreparationRequest $request): array
    {
        clearstatcache();
        $signature = [];
        try {
            $files = $this->finder->find($request->root, $request->paths, $request->excludes);
        } catch (Throwable) {
            return $signature;
        }

        $root = rtrim(str_replace('\\', '/', (string) realpath($request->root)), '/');
        foreach ($files as $relative) {
            $path = $root . '/' . $relative;
            $mtime = @filemtime($path);
            $size = @filesize($path);
            $signature[$relative] = $mtime . '-' . $size;
        }

        return $signature;
    }
}
