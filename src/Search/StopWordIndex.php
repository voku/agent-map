<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

use Throwable;
use voku\helper\StopWords;

/**
 * Stop-word membership for query planning and FTS tokenization.
 *
 * The word lists live in `voku/stop-words` and are not copied here. That package already keeps its
 * per-language data in a static cache behind a plain `require` of a PHP array, so loading English
 * and German costs ~2 ms once per process and ~0.002 ms on every later call - there is nothing left
 * for a disk cache to save, and a snapshot in source would only be a copy that silently ages.
 *
 * What did cost real time is the lookup: `in_array()` over ~1300 words is a linear scan on every
 * token of every indexed chunk. Measured on this package: 1.4M lookups took 4227 ms that way
 * against 17.8 ms through a hash map, so the list is flipped once and probed with `isset()`.
 */
final class StopWordIndex
{
    /**
     * Words that are stop words for code search but are not in any natural-language list: an agent
     * asking "which class handles this" means none of them as an identifier.
     *
     * The German half matters more than it looks: German capitalizes every noun, and the structural
     * channel treats a capitalized word with lowercase letters as identifier-shaped. Without these,
     * "Welche Methode schreibt X" claims `Methode` is a symbol the user named.
     *
     * The trade is deliberate and one-directional: a project that really does own a class named
     * `File` or `Datei` loses it from the *structural* channel only - the lexical and semantic
     * channels still match it, and a query naming it as `Namespace\File` or `File::method` is
     * identifier-shaped regardless.
     *
     * @var list<string>
     */
    private const EXTRA_STOP_WORDS = [
        'class',
        'code',
        'file',
        'function',
        'handle',
        'handled',
        'method',
        'use',
        'used',
        'work',
        'works',
        'datei',
        'funktion',
        'klasse',
        'methode',
    ];

    /** @var array<string, array<string, true>> */
    private static array $indexes = [];

    /** @param list<string> $languages ISO language codes */
    public static function contains(string $word, array $languages = ['en', 'de']): bool
    {
        return isset(self::index($languages)[mb_strtolower($word)]);
    }

    /**
     * @param list<string> $languages ISO language codes
     *
     * @return list<string> sorted, lowercased
     */
    public static function words(array $languages = ['en', 'de']): array
    {
        $words = array_keys(self::index($languages));
        sort($words);

        return $words;
    }

    /**
     * @param list<string> $languages
     *
     * @return array<string, true>
     */
    private static function index(array $languages): array
    {
        $key = implode(',', $languages);
        if (isset(self::$indexes[$key])) {
            return self::$indexes[$key];
        }

        $helper = new StopWords();
        $words = self::EXTRA_STOP_WORDS;
        foreach ($languages as $language) {
            try {
                $words = array_merge($words, $helper->getStopWordsFromLanguage($language));
            } catch (Throwable) {
                // An unsupported language code narrows the list; it is not a reason to fail a search.
            }
        }

        return self::$indexes[$key] = array_fill_keys(array_map('mb_strtolower', $words), true);
    }
}
