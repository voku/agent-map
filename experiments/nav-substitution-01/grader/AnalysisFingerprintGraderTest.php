<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AnalysisFingerprint;

/**
 * Hidden grader for experiment nav-substitution-01.
 *
 * Sealed BEFORE the MAP_FIRST arm runs. Do not edit after the seal is recorded.
 *
 * Grades semantic behaviour, not patch identity. A candidate may name things
 * however it likes, may or may not add a public property, and may pick any
 * internal sentinel - as long as the observable provenance contract holds.
 *
 * The contract under test: an AnalysisFingerprint must record which exact
 * PHPStan package produced the map, and must never claim a PHPStan package
 * for a map that no PHPStan produced.
 */
final class AnalysisFingerprintGraderTest extends TestCase
{
    private const HISTORICAL_REFERENCE = '0123456789abcdef0123456789abcdef01234567';

    /**
     * G1: the serialized fingerprint exposes a phpstan reference field at all.
     */
    public function testSerializedFingerprintExposesPhpStanReference(): void
    {
        $fingerprint = new AnalysisFingerprint(
            phpStanVersion: self::installedPhpStanVersion(),
            phpStanConfigSha256: 'sha256:config',
            composerLockSha256: 'sha256:lock',
            sourceDigest: 'sha256:sources',
        );

        self::assertArrayHasKey('phpstan_reference', $fingerprint->toArray());
    }

    /**
     * G2: a PHPStan-backed fingerprint records the exact installed package reference.
     */
    public function testPhpStanBackedFingerprintCapturesInstalledReference(): void
    {
        $reference = self::installedPhpStanReference();

        $fingerprint = new AnalysisFingerprint(
            phpStanVersion: self::installedPhpStanVersion(),
            phpStanConfigSha256: 'sha256:config',
            composerLockSha256: 'sha256:lock',
            sourceDigest: 'sha256:sources',
        );

        self::assertSame($reference, $fingerprint->toArray()['phpstan_reference']);
    }

    /**
     * G3: THE DISCRIMINATING CASE.
     *
     * The structural-only sentinel is taken from the production code path that
     * actually emits it, not from a literal in this test. A candidate that
     * gated on the analyser's backend() name ('structural-only') instead of the
     * phpStanVersion it emits ('none') falls through to the installed-package
     * branch and stamps a structural map with a PHPStan reference it never used.
     *
     * That is provenance falsification and it is the failure this task exists
     * to prevent, so it is graded as a hard failure.
     */
    public function testStructuralOnlyFingerprintNeverClaimsAnInstalledPhpStanPackage(): void
    {
        $structuralVersion = (new StructuralOnlySemanticAnalyzer())
            ->analyse(sys_get_temp_dir(), [])
            ->phpStanVersion;

        $fingerprint = new AnalysisFingerprint(
            phpStanVersion: $structuralVersion,
            phpStanConfigSha256: 'sha256:none',
            composerLockSha256: 'sha256:none',
            sourceDigest: 'sha256:sources',
        );

        $recorded = $fingerprint->toArray()['phpstan_reference'];

        self::assertIsString($recorded);
        self::assertNotSame(
            '',
            $recorded,
            'A structural-only fingerprint must still record an explicit provenance marker.',
        );
        self::assertNotSame(
            self::installedPhpStanReference(),
            $recorded,
            'A structural-only map must never be stamped with the installed PHPStan package reference.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/^[0-9a-f]{40}$/',
            $recorded,
            'A structural-only map must not record anything shaped like a package commit reference.',
        );
    }

    /**
     * G4: a historical fingerprint written before this field existed stays
     * explicitly unknown and does not silently absorb the current runtime.
     */
    public function testHistoricalFingerprintWithoutReferenceStaysExplicitlyUnknown(): void
    {
        $fingerprint = AnalysisFingerprint::fromArray([
            'phpstan_version' => '2.2.8',
            'phpstan_config_sha256' => 'sha256:config',
            'composer_lock_sha256' => 'sha256:lock',
            'source_digest' => 'sha256:sources',
        ]);

        $recorded = $fingerprint->toArray()['phpstan_reference'];

        self::assertNotSame(
            self::installedPhpStanReference(),
            $recorded,
            'A fingerprint recorded before the field existed must not be back-filled from the current runtime.',
        );
        self::assertSame('unknown', $recorded);
    }

    /**
     * G5: a recorded reference round-trips verbatim without consulting the runtime.
     */
    public function testRecordedReferenceRoundTripsWithoutConsultingCurrentRuntime(): void
    {
        $fingerprint = AnalysisFingerprint::fromArray([
            'phpstan_version' => '2.2.8',
            'phpstan_reference' => self::HISTORICAL_REFERENCE,
            'phpstan_config_sha256' => 'sha256:config',
            'composer_lock_sha256' => 'sha256:lock',
            'source_digest' => 'sha256:sources',
        ]);

        self::assertSame(self::HISTORICAL_REFERENCE, $fingerprint->toArray()['phpstan_reference']);
    }

    private static function installedPhpStanVersion(): string
    {
        self::assertTrue(
            InstalledVersions::isInstalled('phpstan/phpstan'),
            'The grading environment must have phpstan/phpstan installed.',
        );

        return InstalledVersions::getPrettyVersion('phpstan/phpstan') ?? 'unknown';
    }

    private static function installedPhpStanReference(): string
    {
        $reference = InstalledVersions::getReference('phpstan/phpstan');

        self::assertIsString($reference);
        self::assertNotSame('', $reference);

        return $reference;
    }
}
