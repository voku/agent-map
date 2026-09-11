<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\PolyglotQueryCliApplication;

final class PolyglotNestedPhpRoutingTest extends TestCase
{
    public function testNestedPhpSourceKeepsMixedRepositoryOutOfTheScipPath(): void
    {
        $root = sys_get_temp_dir() . '/agent-map-polyglot-nested-php-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o775, true);
        file_put_contents($root . '/package.json', "{}\n");
        file_put_contents($root . '/src/PhpService.php', "<?php\n");

        try {
            $router = new PolyglotQueryCliApplication(defaultRoot: $root);

            self::assertFalse($router->supports(['agent-map', 'query', 'PhpService']));
        } finally {
            unlink($root . '/src/PhpService.php');
            unlink($root . '/package.json');
            rmdir($root . '/src');
            rmdir($root);
        }
    }

    public function testPhpFixtureDoesNotClaimOwnershipOfJavaScriptProject(): void
    {
        $root = sys_get_temp_dir() . '/agent-map-polyglot-php-fixture-' . bin2hex(random_bytes(6));
        mkdir($root . '/test/fixtures', 0o775, true);
        file_put_contents($root . '/package.json', "{}\n");
        file_put_contents($root . '/test/fixtures/php.php', "<?php\n");

        try {
            $router = new PolyglotQueryCliApplication(defaultRoot: $root);

            self::assertTrue($router->supports(['agent-map', 'query', 'buildCallGraph']));
        } finally {
            unlink($root . '/test/fixtures/php.php');
            unlink($root . '/package.json');
            rmdir($root . '/test/fixtures');
            rmdir($root . '/test');
            rmdir($root);
        }
    }
}
