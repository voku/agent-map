<?php

declare(strict_types=1);

namespace voku\AgentMap\Backend;

final readonly class TokenAstBackend implements AstBackend
{
    public function parse(string $file): AstResult
    {
        return new AstResult($file, true);
    }
}
