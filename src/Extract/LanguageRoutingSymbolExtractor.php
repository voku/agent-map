<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

use voku\AgentMap\IO\SourceLanguage;

/**
 * Dispatches each file to the extractor of its language, by file extension.
 */
final readonly class LanguageRoutingSymbolExtractor implements SymbolExtractor, ProjectRootAware
{
    public function __construct(
        private SymbolExtractor $php = new SimplePhpParserSymbolExtractor(),
        private SymbolExtractor $typeScript = new TypeScriptSymbolExtractor(),
    ) {
    }

    public function withProjectRoot(string $root): SymbolExtractor
    {
        return new self(
            $this->php instanceof ProjectRootAware ? $this->php->withProjectRoot($root) : $this->php,
            $this->typeScript instanceof ProjectRootAware ? $this->typeScript->withProjectRoot($root) : $this->typeScript,
        );
    }

    public function extract(string $file): ExtractResult
    {
        return $this->extractorFor($file)->extract($file);
    }

    public function extractMany(array $files): array
    {
        $typeScriptFiles = [];
        $phpFiles = [];
        foreach ($files as $file) {
            if (SourceLanguage::ofPath($file) === SourceLanguage::TYPESCRIPT) {
                $typeScriptFiles[] = $file;
            } else {
                $phpFiles[] = $file;
            }
        }

        $extracted = [
            ...($phpFiles === [] ? [] : $this->php->extractMany($phpFiles)),
            ...($typeScriptFiles === [] ? [] : $this->typeScript->extractMany($typeScriptFiles)),
        ];

        $results = [];
        foreach ($files as $file) {
            if (isset($extracted[$file])) {
                $results[$file] = $extracted[$file];
            }
        }

        return $results;
    }

    private function extractorFor(string $file): SymbolExtractor
    {
        return SourceLanguage::ofPath($file) === SourceLanguage::TYPESCRIPT ? $this->typeScript : $this->php;
    }
}
