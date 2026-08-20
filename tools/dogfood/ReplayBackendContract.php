<?php

declare(strict_types=1);

namespace voku\AgentMap\Dogfood;

use RuntimeException;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;

/**
 * The rule that keeps replay evidence honest: a report may only claim the backend it actually got.
 *
 * A structural-only map and a PHPStan-backed map answer different questions - the structural one
 * carries no `calls` relations at all - so a measurement is only comparable if the backend it was
 * asked for is the backend that produced it. Neither a `--backend=phpstan` flag nor a
 * `*-phpstan.json` filename is evidence of that; the only authority is the effective backend the
 * built map publishes as `AgentMapIndex::backend`.
 *
 * This class adds no backend knowledge of its own. The identities it compares against come from
 * agent-map's own semantic analyzers, so there is exactly one source of truth for what "phpstan" and
 * "structural-only" mean.
 */
final class ReplayBackendContract
{
    public const REQUEST_STRUCTURAL = 'structural';

    public const REQUEST_PHPSTAN = 'phpstan';

    /** @var non-empty-list<string> */
    public const REQUESTS = [self::REQUEST_STRUCTURAL, self::REQUEST_PHPSTAN];

    /**
     * The semantic backend identity agent-map publishes when a request is honoured.
     *
     * @throws RuntimeException when the request is not one this harness can express
     */
    public static function expectedSemanticBackend(string $requested): string
    {
        return match ($requested) {
            self::REQUEST_PHPSTAN => (new PhpStanSemanticAnalyzer())->backend(),
            self::REQUEST_STRUCTURAL => (new StructuralOnlySemanticAnalyzer())->backend(),
            default => throw new RuntimeException('Unknown replay backend request: ' . $requested),
        };
    }

    /**
     * The semantic half of a map backend identity.
     *
     * `AgentMapBuilder` composes it as `<structural extractor>+<semantic backend>`, for example
     * `simple-php-code-parser+phpstan`. An identity without a separator is returned unchanged rather
     * than being guessed at, so an unexpected shape fails the comparison instead of passing it.
     */
    public static function semanticBackendOf(string $effectiveBackend): string
    {
        $separator = strrpos($effectiveBackend, '+');

        return $separator === false ? $effectiveBackend : substr($effectiveBackend, $separator + 1);
    }

    /**
     * Does a built map prove the backend that was asked for?
     *
     * @param string $effectiveBackend the identity the map itself publishes, e.g. `AgentMapIndex::backend`
     */
    public static function isSatisfiedBy(string $requested, string $effectiveBackend): bool
    {
        return self::semanticBackendOf($effectiveBackend) === self::expectedSemanticBackend($requested);
    }

    /**
     * Fail closed when the produced map does not prove the requested backend.
     *
     * @throws RuntimeException with an actionable message; callers must not publish evidence after it
     */
    public static function assertSatisfiedBy(string $requested, string $effectiveBackend, string $subject): void
    {
        if (self::isSatisfiedBy($requested, $effectiveBackend)) {
            return;
        }

        $remedy = $requested === self::REQUEST_PHPSTAN
            ? ' Install phpstan/phpstan (composer install) and rerun the replay.'
            : ' Rerun the replay with a matching --backend.';

        throw new RuntimeException(sprintf(
            '%s requested the "%s" backend, but the map reports "%s" (semantic backend "%s", expected "%s").%s',
            $subject,
            $requested,
            $effectiveBackend,
            self::semanticBackendOf($effectiveBackend),
            self::expectedSemanticBackend($requested),
            $remedy,
        ));
    }

    /**
     * Reject a report whose observation envelope contradicts itself.
     *
     * Used twice on purpose: once before a replay publishes its own report, and once when the
     * summary boundary reads reports it did not produce. A report that cannot state both backends is
     * rejected too - unreadable provenance is not the same as agreeing provenance.
     *
     * @param array<string, mixed> $report
     *
     * @throws RuntimeException
     */
    public static function assertReportIsConsistent(array $report, string $subject): void
    {
        $envelope = $report['observation_envelope'] ?? null;
        if (!is_array($envelope)) {
            throw new RuntimeException($subject . ' has no observation envelope; it cannot be used as backend evidence.');
        }

        $requested = $envelope['requested_backend'] ?? null;
        $effective = $envelope['backend'] ?? null;
        if (!is_string($requested) || !is_string($effective) || $requested === '' || $effective === '') {
            throw new RuntimeException($subject . ' does not record both the requested and the effective backend.');
        }

        self::assertSatisfiedBy($requested, $effective, $subject);
    }
}
