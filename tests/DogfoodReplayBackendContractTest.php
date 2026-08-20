<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Dogfood\ReplayBackendContract;

/**
 * The failure contract that keeps dogfood evidence from claiming a backend it did not have.
 */
final class DogfoodReplayBackendContractTest extends TestCase
{
    public function testExpectedIdentitiesComeFromAgentMapsOwnAnalyzers(): void
    {
        self::assertSame(
            (new PhpStanSemanticAnalyzer())->backend(),
            ReplayBackendContract::expectedSemanticBackend(ReplayBackendContract::REQUEST_PHPSTAN),
        );
        self::assertSame(
            (new StructuralOnlySemanticAnalyzer())->backend(),
            ReplayBackendContract::expectedSemanticBackend(ReplayBackendContract::REQUEST_STRUCTURAL),
        );
    }

    public function testUnknownRequestIsRejectedInsteadOfDefaulted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown replay backend request: semantic');

        ReplayBackendContract::expectedSemanticBackend('semantic');
    }

    public function testSemanticHalfIsReadFromTheComposedIdentity(): void
    {
        self::assertSame('phpstan', ReplayBackendContract::semanticBackendOf('simple-php-code-parser+phpstan'));
        self::assertSame('structural-only', ReplayBackendContract::semanticBackendOf('simple-php-code-parser+structural-only'));
    }

    public function testIdentityWithoutSeparatorIsNotGuessedIntoAMatch(): void
    {
        self::assertSame('phpstan-ish', ReplayBackendContract::semanticBackendOf('phpstan-ish'));
        self::assertFalse(ReplayBackendContract::isSatisfiedBy(ReplayBackendContract::REQUEST_PHPSTAN, 'phpstan-ish'));
    }

    public function testMatchingBackendsAreAccepted(): void
    {
        self::assertTrue(ReplayBackendContract::isSatisfiedBy(
            ReplayBackendContract::REQUEST_PHPSTAN,
            'simple-php-code-parser+phpstan',
        ));
        self::assertTrue(ReplayBackendContract::isSatisfiedBy(
            ReplayBackendContract::REQUEST_STRUCTURAL,
            'simple-php-code-parser+structural-only',
        ));
    }

    public function testSatisfiedContractPublishesWithoutComplaint(): void
    {
        $this->expectNotToPerformAssertions();

        ReplayBackendContract::assertSatisfiedBy(
            ReplayBackendContract::REQUEST_STRUCTURAL,
            'simple-php-code-parser+structural-only',
            'Replay demo',
        );
        ReplayBackendContract::assertReportIsConsistent([
            'observation_envelope' => [
                'requested_backend' => 'phpstan',
                'backend' => 'simple-php-code-parser+phpstan',
            ],
        ], 'Replay report demo.json');
    }

    public function testRequestedPhpStanWithStructuralResultFailsWithAnActionableMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replay demo requested the "phpstan" backend, but the map reports "simple-php-code-parser+structural-only"');

        ReplayBackendContract::assertSatisfiedBy(
            ReplayBackendContract::REQUEST_PHPSTAN,
            'simple-php-code-parser+structural-only',
            'Replay demo',
        );
    }

    public function testStructuralRequestIsNotSatisfiedByAPhpStanMap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected "structural-only"');

        ReplayBackendContract::assertSatisfiedBy(
            ReplayBackendContract::REQUEST_STRUCTURAL,
            'simple-php-code-parser+phpstan',
            'Replay demo',
        );
    }

    public function testContradictoryReportIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requested the "phpstan" backend');

        ReplayBackendContract::assertReportIsConsistent([
            'observation_envelope' => [
                'requested_backend' => 'phpstan',
                'backend' => 'simple-php-code-parser+structural-only',
            ],
        ], 'Replay report demo.json');
    }

    public function testReportWithoutBackendProvenanceIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not record both the requested and the effective backend');

        ReplayBackendContract::assertReportIsConsistent([
            'observation_envelope' => ['backend' => 'simple-php-code-parser+phpstan'],
        ], 'Replay report demo.json');
    }

    public function testReportWithoutEnvelopeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no observation envelope');

        ReplayBackendContract::assertReportIsConsistent(['strategies' => []], 'Replay report demo.json');
    }
}
