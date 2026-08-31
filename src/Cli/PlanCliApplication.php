<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use voku\AgentMap\Plan\PlanCapability;

/**
 * The shared public shape of one governed plan CLI boundary.
 *
 * Routing and capability discovery come from the same list, so a contract cannot be reachable on the
 * command line while being invisible to a host that asks what agent-map can prove.
 */
interface PlanCliApplication
{
    /** @param list<string> $argv */
    public function supports(array $argv): bool;

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool;

    public function helpOverview(): string;

    /** @param list<string> $argv */
    public function run(array $argv): int;

    public function capability(): PlanCapability;
}
