<?php

declare(strict_types=1);

namespace voku\AgentMap\Context;

final class ContextRole
{
    public const PRIMARY = 'primary';
    public const CONTRACT = 'contract';
    public const CHANGE_CANDIDATE = 'change_candidate';
    public const DEPENDENCY = 'dependency';
    public const VERIFICATION = 'verification';
    public const TYPE_DEFINITION = 'type_definition';

    private function __construct()
    {
    }
}
