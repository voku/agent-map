<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs the real tag script from .github/workflows/release-tag.yml against a real Git
 * repository whose `origin` is a local bare repository, so tagging and pushing happen
 * for real and nothing is faked.
 */
final class ReleaseTagWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        foreach (['git', 'jq', 'bash'] as $binary) {
            if (trim((string) shell_exec('command -v ' . escapeshellarg($binary))) === '') {
                self::markTestSkipped($binary . ' is required to run the release workflow script.');
            }
        }
        $this->root = sys_get_temp_dir() . '/agent-map-release-tag-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/origin.git', 0777, true));
        self::assertTrue(mkdir($this->root . '/work'));
        $this->git($this->root . '/origin.git', 'init', '--bare', '--quiet');
        $this->git($this->root . '/work', 'init', '--quiet');
        $this->git($this->root . '/work', 'remote', 'add', 'origin', $this->root . '/origin.git');
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /**
     * The newest section sits at the top of a CHANGELOG larger than the pipe buffer. A
     * `git show | grep -q` check under pipefail sees grep exit early, git die with SIGPIPE
     * and the whole pipeline fail, so a present section was reported missing.
     */
    public function testTagsATargetWhoseLargeChangelogStartsWithTheReleaseSection(): void
    {
        $changelog = "# Changelog\n\n## [1.2.3] - 2026-11-19\n\n- Release entry.\n\n"
            . str_repeat("- Historical entry that keeps the file larger than a pipe buffer.\n", 4000);
        self::assertGreaterThan(65536 * 3, strlen($changelog));
        $target = $this->commitRelease('1.2.3', $changelog);

        $result = $this->runReleaseScript();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame($target, $this->git($this->root . '/origin.git', 'rev-list', '-n', '1', '1.2.3'));
        self::assertSame('tag', $this->git($this->root . '/origin.git', 'cat-file', '-t', '1.2.3'));
    }

    public function testRefusesATargetWithoutTheReleaseSection(): void
    {
        $changelog = "# Changelog\n\n## [1.2.2] - 2026-11-01\n\n"
            . str_repeat("- Historical entry that keeps the file larger than a pipe buffer.\n", 4000);
        // agent-map additionally requires the target to equal main outside release markers, so a
        // section missing at the target is a section missing on main as well.
        $this->commitRelease('1.2.3', $changelog);

        $result = $this->runReleaseScript();

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('CHANGELOG.md has no release section for 1.2.3', $result['output']);
        self::assertSame('', $this->git($this->root . '/origin.git', 'tag', '--list', '1.2.3'));
    }

    /**
     * Commit the release target, then a later commit carrying the marker, like the
     * repository's two-step release flow. Returns the target SHA.
     */
    private function commitRelease(string $version, string $targetChangelog, bool $markerAddsSection = false): string
    {
        $work = $this->root . '/work';
        file_put_contents($work . '/CHANGELOG.md', $targetChangelog);
        $this->git($work, 'add', 'CHANGELOG.md');
        $this->git($work, '-c', 'user.name=test', '-c', 'user.email=test@example.invalid', 'commit', '--quiet', '-m', 'release notes');
        $target = $this->git($work, 'rev-parse', 'HEAD');

        if ($markerAddsSection) {
            // main's current CHANGELOG has the section, but the marker's target does not.
            file_put_contents($work . '/CHANGELOG.md', "## [$version] - 2026-11-19\n\n" . $targetChangelog);
        }
        mkdir($work . '/.release');
        file_put_contents($work . '/.release/' . $version . '.json', json_encode(['version' => $version, 'target_sha' => $target], JSON_THROW_ON_ERROR) . "\n");
        $this->git($work, 'add', '.');
        $this->git($work, '-c', 'user.name=test', '-c', 'user.email=test@example.invalid', 'commit', '--quiet', '-m', 'release marker');

        return $target;
    }

    /** @return array{exit: int, output: string} */
    private function runReleaseScript(): array
    {
        $workflow = file_get_contents(__DIR__ . '/../.github/workflows/release-tag.yml');
        self::assertIsString($workflow);
        self::assertSame(1, preg_match('~^        run: \|\R(?<script>(?:^ {10}.*\R|^\R)*)~m', $workflow, $matches));
        if (!isset($matches['script'])) {
            self::fail('Release workflow has no executable tag script.');
        }
        $script = preg_replace('~^ {10}~m', '', $matches['script']);
        self::assertIsString($script);
        file_put_contents($this->root . '/release-tag.sh', $script);

        $output = [];
        $exit = 0;
        exec('cd ' . escapeshellarg($this->root . '/work') . ' && bash ' . escapeshellarg($this->root . '/release-tag.sh') . ' 2>&1', $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    private function git(string $directory, string ...$arguments): string
    {
        $output = [];
        $exit = 0;
        exec('git -C ' . escapeshellarg($directory) . ' ' . implode(' ', array_map(escapeshellarg(...), $arguments)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
