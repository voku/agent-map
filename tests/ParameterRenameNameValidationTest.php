<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Rename\ParameterRenamePlanner;

final class ParameterRenameNameValidationTest extends TestCase
{
    public function testReservedThisCannotBecomeAParameterName(): void
    {
        $map = new AgentMapIndex(
            schemaVersion: '2.0',
            root: sys_get_temp_dir(),
            backend: 'simple-php-code-parser+phpstan',
            files: [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PHP parameter name: $this');

        (new ParameterRenamePlanner())->plan($map, 'Demo\\Service::find', '$old', '$this');
    }
}
