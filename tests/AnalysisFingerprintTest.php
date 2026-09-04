<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\SemanticScope;

final class AnalysisFingerprintTest extends TestCase
{
    public function testNewFingerprintCapturesInstalledPhpStanReference(): void
    {
        self::assertTrue(InstalledVersions::isInstalled('phpstan/phpstan'));
        $reference = InstalledVersions::getReference('phpstan/phpstan');
        self::assertIsString($reference);
        self::assertNotSame('', $reference);

        $fingerprint = new AnalysisFingerprint(
            phpStanVersion: InstalledVersions::getPrettyVersion('phpstan/phpstan') ?? 'unknown',
            phpStanConfigSha256: 'sha256:config',
            composerLockSha256: 'sha256:lock',
            sourceDigest: 'sha256:sources',
        );

        self::assertSame($reference, $fingerprint->phpStanReference);
        self::assertSame($reference, $fingerprint->toArray()['phpstan_reference']);
    }

    public function testStructuralFingerprintRecordsNoPhpStanReference(): void
    {
        $fingerprint = new AnalysisFingerprint(
            phpStanVersion: 'none',
            phpStanConfigSha256: 'sha256:none',
            composerLockSha256: 'sha256:none',
            sourceDigest: 'sha256:sources',
        );

        self::assertSame('none', $fingerprint->phpStanReference);
    }

    public function testHistoricalFingerprintWithoutReferenceStaysExplicitlyUnknown(): void
    {
        $fingerprint = AnalysisFingerprint::fromArray([
            'phpstan_version' => '2.2.8',
            'phpstan_config_sha256' => 'sha256:config',
            'composer_lock_sha256' => 'sha256:lock',
            'source_digest' => 'sha256:sources',
        ]);

        self::assertSame('unknown', $fingerprint->phpStanReference);
        self::assertSame('unknown', $fingerprint->toArray()['phpstan_reference']);
    }

    public function testSemanticScopeRoundTripsWithItsDeterministicIdentity(): void
    {
        $scope = new SemanticScope(['src', 'modules'], ['~(^|/)generated(/|$)~'], ['stubs']);
        $fingerprint = new AnalysisFingerprint(
            phpStanVersion: '2.2.8',
            phpStanConfigSha256: 'sha256:config',
            composerLockSha256: 'sha256:lock',
            sourceDigest: 'sha256:sources',
            semanticScope: $scope,
        );

        $roundTripped = AnalysisFingerprint::fromArray($fingerprint->toArray());

        self::assertNotNull($roundTripped->semanticScope);
        self::assertSame($scope->toArray(), $roundTripped->semanticScope->toArray());
    }

    public function testRecordedReferenceRoundTripsWithoutConsultingCurrentRuntime(): void
    {
        $fingerprint = AnalysisFingerprint::fromArray([
            'phpstan_version' => '2.2.8',
            'phpstan_reference' => '0123456789abcdef0123456789abcdef01234567',
            'phpstan_config_sha256' => 'sha256:config',
            'composer_lock_sha256' => 'sha256:lock',
            'source_digest' => 'sha256:sources',
        ]);

        self::assertSame('0123456789abcdef0123456789abcdef01234567', $fingerprint->phpStanReference);
    }
}
