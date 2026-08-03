<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;

/**
 * Shared fixture repo for scope tests: one function file, a repository
 * class, a primary `UserService::save` covering every call/table/template
 * kind the ScopeInspector must classify, and a same-named class in another
 * namespace to force selector ambiguity on the bare `UserService` name.
 */
trait ScopeFixture
{
    private string $scopeRoot;

    private function setUpScopeFixture(): void
    {
        $this->scopeRoot = sys_get_temp_dir() . '/agent-map-scope-' . bin2hex(random_bytes(6));
        mkdir($this->scopeRoot . '/src/Service', 0o775, true);
        mkdir($this->scopeRoot . '/src/Other', 0o775, true);

        file_put_contents($this->scopeRoot . '/src/functions.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        function save_user($user): void
        {
        }

        function normalize_email(string $email): string
        {
            return strtolower($email);
        }
        PHP);

        file_put_contents($this->scopeRoot . '/src/Service/UserRepository.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Service;

        final class UserRepository
        {
            public function store($user): void
            {
            }

            public static function flush(): void
            {
            }
        }
        PHP);

        file_put_contents($this->scopeRoot . '/src/Service/UserService.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Service;

        final class UserService
        {
            public function save($user): void
            {
                save_user($user);
                $this->validate($user);
                $repository = new UserRepository();
                $repository->store($user);
                UserRepository::flush();
                $method = 'notify';
                $this->{$method}();
                $callback = 'save_user';
                $callback();

                $sql = <<<'SQL'
                    SELECT u.id
                    FROM users u
                    JOIN user_roles r ON r.user_id = u.id
                    SQL;

                $table = 'audit_log';
                $dynamicSql = 'SELECT * FROM ' . $table;

                $update = 'UPDATE users SET name = ? WHERE id = ?';
                $insert = 'INSERT INTO audit_log (id) VALUES (?)';

                $this->twig->render('user/saved.html.twig');
                $this->smarty->display('user/edit.tpl');
                view('user.edit');
            }

            private function validate($user): void
            {
            }

            public function outsideScope(): void
            {
                outside_call();
            }
        }
        PHP);

        file_put_contents($this->scopeRoot . '/src/Other/UserService.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Other;

        final class UserService
        {
            public function save($user): void
            {
            }
        }
        PHP);
    }

    private function tearDownScopeFixture(): void
    {
        $this->removeScopeDirectory($this->scopeRoot);
    }

    private function buildScopeIndex(): AgentMapIndex
    {
        return (new AgentMapBuilder())->build($this->scopeRoot, ['src'], []);
    }

    private function removeScopeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
