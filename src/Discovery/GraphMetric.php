<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

enum GraphMetric: string
{
    case DEPENDENTS = 'dependents';
    case CALLERS = 'callers';
    case DEPENDENCIES = 'dependencies';
    case CALLEES = 'callees';
    case MEMBERS = 'members';

    public function incoming(): bool
    {
        return match ($this) {
            self::DEPENDENTS, self::CALLERS => true,
            self::DEPENDENCIES, self::CALLEES, self::MEMBERS => false,
        };
    }

    public function acceptsRelation(string $kind): bool
    {
        return match ($this) {
            self::DEPENDENTS, self::DEPENDENCIES => in_array($kind, [
                'calls',
                'extends',
                'implements',
                'instantiates',
                'overrides',
                'references_type',
                'uses_trait',
            ], true),
            self::CALLERS, self::CALLEES => $kind === 'calls',
            self::MEMBERS => $kind === 'declares_method',
        };
    }
}
