<?php

declare(strict_types=1);

namespace voku\AgentMap\IO;

use InvalidArgumentException;

/**
 * The source languages a map build can be asked to index, and which file belongs to which.
 */
final class SourceLanguage
{
    public const PHP = 'php';

    /** TypeScript and JavaScript: one syntax-only extractor covers both. */
    public const TYPESCRIPT = 'typescript';

    /**
     * File name suffixes per language, in canonical language order.
     *
     * @var array<string, list<string>>
     */
    public const EXTENSIONS = [
        self::PHP => PhpFileFinder::EXTENSIONS,
        self::TYPESCRIPT => ['.ts', '.tsx', '.mts', '.cts', '.js', '.jsx', '.mjs', '.cjs'],
    ];

    /**
     * @param list<string> $languages
     *
     * @return list<string> the known languages among `$languages`, deduplicated, in canonical order
     */
    public static function normalize(array $languages): array
    {
        $unknown = array_diff($languages, array_keys(self::EXTENSIONS));
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown language: ' . implode(', ', $unknown) . '. Supported: ' . implode(', ', array_keys(self::EXTENSIONS)) . '.',
            );
        }
        if ($languages === []) {
            throw new InvalidArgumentException('At least one language is required. Supported: ' . implode(', ', array_keys(self::EXTENSIONS)) . '.');
        }

        return array_values(array_filter(
            array_keys(self::EXTENSIONS),
            static fn (string $language): bool => in_array($language, $languages, true),
        ));
    }

    /**
     * @param list<string> $languages
     *
     * @return list<string>
     */
    public static function extensions(array $languages): array
    {
        $extensions = [];
        foreach (self::normalize($languages) as $language) {
            $extensions = [...$extensions, ...self::EXTENSIONS[$language]];
        }

        return $extensions;
    }

    /**
     * The language a path is indexed as, or null when no language claims it.
     *
     * TypeScript declaration files (`.d.ts`, `.d.mts`, `.d.cts`) describe code that lives elsewhere
     * and are never indexed.
     */
    public static function ofPath(string $path): ?string
    {
        if (self::isTypeScriptDeclarationFile($path)) {
            return null;
        }
        foreach (self::EXTENSIONS as $language => $extensions) {
            foreach ($extensions as $extension) {
                if (str_ends_with($path, $extension)) {
                    return $language;
                }
            }
        }

        return null;
    }

    public static function isTypeScriptDeclarationFile(string $path): bool
    {
        return preg_match('~\.d\.[cm]?ts$~', $path) === 1;
    }
}
