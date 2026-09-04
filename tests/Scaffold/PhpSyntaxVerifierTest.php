<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Scaffold\PhpSyntaxVerifier;

final class PhpSyntaxVerifierTest extends TestCase
{
    private PhpSyntaxVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PhpSyntaxVerifier();
    }

    public function testVerifiesValidSource(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Good
{
    public function hello(): string
    {
        return 'world';
    }
}
PHP;

        self::assertNull($this->verifier->verifySource($code));
    }

    public function testCatchesBrokenSource(): void
    {
        $broken = "<?php\n\nclass Broken {\n    public function missingBrace()\n";
        $error = $this->verifier->verifySource($broken);
        self::assertNotNull($error);
        self::assertStringContainsString('PHP syntax error', $error);
    }

    public function testVerifiesEdits(): void
    {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

class Greeter
{
}
PHP;

        $pos = strrpos($source, '}');
        self::assertNotFalse($pos);

        $edits = [
            new PlanEdit(
                path: 'Greeter.php',
                sourceSha256: 'hash',
                startFilePos: $pos,
                endFilePos: $pos,
                lineStart: 6,
                lineEnd: 6,
                expected: '}',
                replacement: "    public function greet(): string\n    {\n        return 'hi';\n    }\n}",
                role: 'method_insertion',
                symbolId: 'Greeter::greet',
                resolution: 'scaffold',
            ),
        ];

        self::assertNull($this->verifier->verifyEdits($source, $edits));
    }

    public function testRejectsEditThatBreaksSyntax(): void
    {
        $source = <<<'PHP'
<?php

class BadEdit
{
}
PHP;

        $pos = strrpos($source, '}');
        self::assertNotFalse($pos);

        $edits = [
            new PlanEdit(
                path: 'BadEdit.php',
                sourceSha256: 'hash',
                startFilePos: $pos,
                endFilePos: $pos,
                lineStart: 5,
                lineEnd: 5,
                expected: '}',
                replacement: "    syntax error here!!! }",
                role: 'method_insertion',
                symbolId: 'BadEdit::broken',
                resolution: 'scaffold',
            ),
        ];

        $error = $this->verifier->verifyEdits($source, $edits);
        self::assertNotNull($error);
        self::assertStringContainsString('PHP syntax error', $error);
    }
}
