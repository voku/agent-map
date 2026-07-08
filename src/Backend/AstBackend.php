<?php

declare(strict_types=1);

namespace voku\AgentMap\Backend;

interface AstBackend
{
    public function parse(string $file): AstResult;
}
