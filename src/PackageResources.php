<?php

declare(strict_types=1);

namespace voku\AgentMap;

/**
 * The single owner of package-shipped resource locations.
 */
final class PackageResources
{
    public const string MAKE_INCLUDE = 'resources/make/agent-map.mk';

    public static function makeInclude(): string
    {
        return dirname(__DIR__) . '/' . self::MAKE_INCLUDE;
    }
}
