<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

/** Shared public shape for concrete governed rename-plan CLI boundaries. */
interface RenamePlanCliApplication
{
    /** @param list<string> $argv */
    public function supports(array $argv): bool;

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool;

    public function helpOverview(): string;

    /** @param list<string> $argv */
    public function run(array $argv): int;

    public function capability(): RenamePlanCapability;
}
