<?php

declare(strict_types=1);

namespace voku\AgentMap\Plan;

use InvalidArgumentException;

/**
 * The status vocabulary every governed plan shares, and the one rule that makes it worth anything.
 *
 * `blocked` is not a severity label. It is a promise that the plan carries nothing a mutation host
 * could apply, so a host that branches on the status cannot half-apply an unproven change. Ten
 * planners deciding that correctly is a convention; refusing to construct the object is a contract.
 */
final class PlanStatus
{
    public const SAFE = 'safe';
    public const REVIEW_REQUIRED = 'review_required';
    public const BLOCKED = 'blocked';

    /** @var list<string> */
    public const ALL = [self::SAFE, self::REVIEW_REQUIRED, self::BLOCKED];

    /**
     * @param list<PlanEdit> $edits
     * @param list<PlanMove> $moves
     */
    public static function assertPublishable(string $planType, string $status, array $edits, array $moves = []): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s has unknown plan status "%s"; expected one of %s.',
                $planType,
                $status,
                implode(', ', self::ALL),
            ));
        }

        if ($status !== self::BLOCKED || ($edits === [] && $moves === [])) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s is blocked but carries %d edit(s) and %d move(s); a blocked plan must publish no applicable mutation.',
            $planType,
            count($edits),
            count($moves),
        ));
    }
}
