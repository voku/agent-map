<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

final class DogfoodTokenEfficiencyContractTest extends \PHPUnit\Framework\TestCase
{
    public function testPortableAscii135HasExecutableValidationContract(): void
    {
        $fixturePath = __DIR__ . '/../tools/dogfood/replays/portable-ascii-135.json';
        $fixture = \json_decode((string) \file_get_contents($fixturePath), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('1.1', $fixture['schema_version']);
        self::assertSame('portable-ascii-135', $fixture['id']);
        self::assertSame('88f94f89fe03bed03eb8fbcfb84178a8a5eb1d5b', $fixture['base_commit']);
        self::assertSame('acad28ab1b1b50480276edb89310ff2ecad3dee4', $fixture['fix_commit']);
        self::assertStringContainsString('voku\\helper\\ASCII::to_ascii', $fixture['experiment_task']['text']);
        self::assertStringContainsString('n34', $fixture['experiment_task']['text']);

        $validation = $fixture['validation'];
        self::assertNotEmpty($validation['setup_commands']);
        self::assertNotSame('', $validation['regression_command']);
        self::assertNotSame('', $validation['test_command']);
        self::assertNotSame('', $validation['static_analysis_command']);
        self::assertNotEmpty($validation['acceptance_assertions']);
        self::assertTrue($validation['baseline_after_setup']);

        $constraints = $validation['diff_constraints'];
        self::assertSame(['src/voku/helper/ASCII.php'], $fixture['verified']['edit_files']);
        self::assertSame(['src/voku/helper/ASCII.php', 'tests/AsciiTest.php'], $constraints['required_files']);
        self::assertSame($constraints['required_files'], $constraints['allowed_files']);
        self::assertCount(2, $constraints['required_ranges']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $constraints['historical_patch_sha256']);
        self::assertTrue($constraints['historical_patch_is_ground_truth_not_exact_diff_requirement']);
    }
}
