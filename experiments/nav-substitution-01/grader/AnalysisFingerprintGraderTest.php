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
     * @var list<string>
     */
    private const BASE_KEYS = [
        'phpstan_version',
        'phpstan_config_sha256',
        'composer_lock_sha256',
        'source_digest',
    ];

    /**
     * G1: serialized provenance adds a persisted field beyond the historical schema.
     */
    public function testSerializedFingerprintExposesPhpStanReference(): void
    {
        $serialized = self::newPhpStanFingerprint()->toArray();
        $extraKeys = array_values(array_diff(array_keys($serialized), self::BASE_KEYS));

        self::assertNotSame(
            [],
            $extraKeys,
            'The serialized fingerprint must persist PHPStan package provenance beyond the historical schema.',
        );
    }

    /**
     * G2: a PHPStan-backed fingerprint records the exact installed package reference.
     */
    public function testPhpStanBackedFingerprintCapturesInstalledReference(): void
    {
        $serialized = self::newPhpStanFingerprint()->toArray();
        $key = self::referenceKey($serialized);

        self::assertSame(self::installedPhpStanReference(), $serialized[$key]);
    }

    /**
     * G3: THE DISCRIMINATING CASE.
     *
     * The structural-only sentinel is taken from the production code path that
     * actually emits it, not from a literal in this test. A candidate that
     * gates on the analyser's backend() name instead of the phpStanVersion it
     * emits falls through to the installed-package branch and stamps a
     * structural map with a PHPStan reference it never used.
     */
    public function testStructuralOnlyFingerprintNeverClaimsAnInstalledPhpStanPackage(): void
    {
        $key = self::referenceKey(self::newPhpStanFingerprint()->toArray());
        $structuralVersion = (new StructuralOnlySemanticAnalyzer())
            ->analyse(sys_get_temp_dir(), [])
            ->phpStanVersion;

        $fingerprint = new AnalysisFingerprint(
            phpStanVersion: $structuralVersion,
            phpStanConfigSha256: 'sha256:none',
            composerLockSha256: 'sha256:none',
            sourceDigest: 'sha256:sources',
        );

        $serialized = $fingerprint->toArray();
        self::assertArrayHasKey($key, $serialized);
        $recorded = $serialized[$key];

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
        $key = self::referenceKey(self::newPhpStanFingerprint()->toArray());
        $fingerprint = AnalysisFingerprint::fromArray([
            'phpstan_version' => '2.2.8',
            'phpstan_config_sha256' => 'sha256:config',
            'composer_lock_sha256' => 'sha256:lock',
            'source_digest' => 'sha256:sources',
        ]);

        $serialized = $fingerprint->toArray();
        self::assertArrayHasKey($key, $serialized);
        $recorded = $serialized[$key];

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
        $key = self::referenceKey(self::newPhpStanFingerprint()->toArray());
        $fingerprint = AnalysisFingerprint::fromArray([
            'phpstan_version' => '2.2.8',
            $key => self::HISTORICAL_REFERENCE,
            'phpstan_config_sha256' => 'sha256:config',
            'composer_lock_sha256' => 'sha256:lock',
            'source_digest' => 'sha256:sources',
        ]);

        self::assertSame(self::HISTORICAL_REFERENCE, $fingerprint->toArray()[$key]);
    }

    private static function newPhpStanFingerprint(): AnalysisFingerprint
    {
        return new AnalysisFingerprint(
            phpStanVersion: self::installedPhpStanVersion(),
            phpStanConfigSha256: 'sha256:config',
            composerLockSha256: 'sha256:lock',
            sourceDigest: 'sha256:sources',
        );
    }

    /**
     * Find the persisted provenance field by its required semantic value rather
     * than imposing the historical implementation's key name.
     *
     * @param array<string, mixed> $serialized
     */
    private static function referenceKey(array $serialized): string
    {
        $reference = self::installedPhpStanReference();
        $matches = [];

        foreach ($serialized as $key => $value) {
            if (in_array($key, self::BASE_KEYS, true)) {
                continue;
            }

            if ($value === $reference) {
                $matches[] = $key;
            }
        }

        self::assertCount(
            1,
            $matches,
            'Exactly one serialized provenance field must contain the installed PHPStan package reference.',
        );

        return $matches[0];
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
