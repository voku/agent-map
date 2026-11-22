<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Build\SemanticAnalysisResult;
use voku\AgentMap\Build\SemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Cli\CliOptions;
use voku\AgentMap\Extract\ExtractResult;
use voku\AgentMap\Extract\LanguageRoutingSymbolExtractor;
use voku\AgentMap\Extract\SymbolExtractor;
use voku\AgentMap\Extract\TypeScriptSymbolExtractor;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\ParameterEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\IO\PhpFileFinder;
use voku\AgentMap\IO\SourceLanguage;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Prepare\MapPreparationException;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapPreparationService;
use voku\AgentMap\Prepare\MapWatcher;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Rename\FunctionRenamePlanner;

/**
 * First slice of TypeScript/JavaScript support: declarations only, through the TypeScript
 * compiler in syntax-only mode.
 *
 * The tests that run the compiler need Node.js and `<agent-map>/node_modules/typescript`
 * (`npm ci`). Without them they skip locally and fail when `AGENT_MAP_REQUIRE_TYPESCRIPT=1`.
 */
final class TypeScriptSupportTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/typescript-project';
    private const BROKEN_FIXTURE = __DIR__ . '/fixtures/typescript-broken';

    private string $root;

    protected function setUp(): void
    {
        // A copy: builds write a structural cache below the analysed root, and the mixed-language
        // tests add a PHP file that must not sit in a directory PHPStan analyses.
        $this->root = (string) realpath(sys_get_temp_dir()) . '/agent-map-typescript-' . bin2hex(random_bytes(6));
        $this->copyDirectory(self::FIXTURE, $this->root);
        mkdir($this->root . '/src/Php', 0o775, true);
        file_put_contents(
            $this->root . '/src/Php/Greeter.php',
            "<?php\n\nnamespace App\\Php;\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return 'Hello ' . \$name;\n    }\n}\n\nfunction scale(int \$value): int\n{\n    return \$value * 2;\n}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV);
    }

    public function testExtractsEveryDeclarationKindWithExactLinesAndSignatures(): void
    {
        $symbols = $this->extract('src/kinds.ts');

        self::assertSame(
            [
                'enum:src/kinds.ts#Color' => [1, 4],
                'type_alias:src/kinds.ts#Point' => [6, 9],
                'constant:src/kinds.ts#ORIGIN' => [11, 11],
                'constant:src/kinds.ts#internalLimit' => [13, 13],
                'function:src/kinds.ts#distance' => [15, 16],
                'function:src/kinds.ts#clamp' => [18, 20],
                // Overload signatures on lines 22-23 collapse into the implementation.
                'function:src/kinds.ts#scale' => [24, 26],
                'function:src/kinds.ts#hidden' => [28, 28],
                // A value and a type may share one name; `let` is not a constant and is not indexed.
                'constant:src/kinds.ts#Mode' => [31, 31],
                'type_alias:src/kinds.ts#Mode' => [32, 32],
                'function:src/kinds.ts#main' => [38, 40],
            ],
            array_map(static fn (SymbolEntry $symbol): array => [$symbol->lineStart, $symbol->lineEnd], $symbols),
        );

        $distance = $symbols['function:src/kinds.ts#distance'];
        self::assertSame('distance', $distance->name);
        self::assertSame(['from', 'to'], array_map(static fn (ParameterEntry $parameter): string => $parameter->name, $distance->parameters));
        self::assertSame(['Point', 'Point'], array_map(static fn (ParameterEntry $parameter): ?string => $parameter->nativeType, $distance->parameters));
        self::assertSame('number', $distance->nativeReturnType);
        self::assertSame('Point', $symbols['function:src/kinds.ts#scale']->nativeReturnType);
        self::assertSame('{ min, max }', $symbols['function:src/kinds.ts#clamp']->parameters[1]->name);

        // Exported or not is recorded in `attributes`, including `export { a as b }` lists.
        self::assertSame(['export'], $symbols['enum:src/kinds.ts#Color']->attributes);
        self::assertSame(['export'], $symbols['constant:src/kinds.ts#internalLimit']->attributes);
        self::assertSame(['export default'], $symbols['function:src/kinds.ts#main']->attributes);
        self::assertSame([], $this->extract('src/shapes/circle.ts')['interface:src/shapes/circle.ts#Serializable']->attributes);
    }

    public function testExtractsClassAndInterfaceMethodsLikePhpMethods(): void
    {
        $symbols = $this->extract('src/shapes/base.ts');

        $shape = $symbols['interface:src/shapes/base.ts#Shape'];
        self::assertSame([2, 5], [$shape->lineStart, $shape->lineEnd]);
        self::assertSame(['area', 'describe'], array_map(static fn (MethodEntry $method): string => $method->name, $shape->methods));
        self::assertSame('method:src/shapes/base.ts#Shape::describe', $shape->methodId($shape->methods[1]));

        $base = $symbols['class:src/shapes/base.ts#BaseShape'];
        self::assertSame([11, 29], [$base->lineStart, $base->lineEnd]);
        $methods = [];
        foreach ($base->methods as $method) {
            $methods[$method->name] = [$method->visibility, $method->static, $method->abstract, $method->lineStart, $method->lineEnd, $method->nativeReturnType];
        }
        self::assertSame(
            [
                'constructor' => ['public', false, false, 14, 16, null],
                'area' => ['public', false, true, 18, 18, 'number'],
                'describe' => ['public', false, false, 20, 22, 'string'],
                'create' => ['protected', true, false, 24, 24, 'void'],
                '#secret' => ['private', false, false, 26, 26, 'void'],
                // An arrow-function property is callable like a method.
                'onResize' => ['private', false, false, 28, 28, 'void'],
            ],
            $methods,
        );

        $describe = $base->methods[2];
        self::assertSame(['prefix', 'rest'], array_map(static fn (ParameterEntry $parameter): string => $parameter->name, $describe->parameters));
        self::assertSame(['string', 'string[]'], array_map(static fn (ParameterEntry $parameter): ?string => $parameter->nativeType, $describe->parameters));
        self::assertSame([false, true], array_map(static fn (ParameterEntry $parameter): bool => $parameter->variadic, $describe->parameters));
    }

    public function testResolvesExtendsAndImplementsOnlyWhenTheDeclarationIsProvable(): void
    {
        $base = $this->extract('src/shapes/base.ts');
        self::assertSame(['src/shapes/base.ts#Shape'], $base['interface:src/shapes/base.ts#Named']->extends, 'same file');
        self::assertSame(['src/shapes/base.ts#Shape'], $base['class:src/shapes/base.ts#BaseShape']->implements, 'same file');

        $circle = $this->extract('src/shapes/circle.ts');
        // `./base.js` resolves to base.ts; `Shape as ShapeContract` resolves through the alias.
        self::assertSame(['src/shapes/base.ts#BaseShape'], $circle['class:src/shapes/circle.ts#Circle']->extends);
        self::assertSame(
            ['src/shapes/base.ts#Shape', 'src/shapes/circle.ts#Serializable', 'Disposable'],
            $circle['class:src/shapes/circle.ts#Circle']->implements,
            'A global stays as written.',
        );
        self::assertSame(['EventEmitter'], $circle['class:src/shapes/circle.ts#Bus']->extends, 'A package import stays as written.');

        $ring = $this->extract('src/ring.ts');
        self::assertSame(['Circle'], $ring['class:src/ring.ts#Ring']->extends, 'A re-exporting barrel does not declare the name.');
        self::assertSame(['src/shapes/base.ts#Named'], $ring['class:src/ring.ts#Ring']->implements, 'namespace import');
        self::assertSame(['src/view/Widget.tsx#Widget'], $ring['class:src/ring.ts#FancyWidget']->extends, 'default import');
    }

    public function testExtractsTsxAndPlainJavaScriptModules(): void
    {
        $widget = $this->extract('src/view/Widget.tsx');
        self::assertSame(
            ['interface:src/view/Widget.tsx#WidgetProps', 'function:src/view/Widget.tsx#Label', 'class:src/view/Widget.tsx#Widget'],
            array_keys($widget),
        );
        self::assertSame(['export default'], $widget['class:src/view/Widget.tsx#Widget']->attributes);
        self::assertSame('render', $widget['class:src/view/Widget.tsx#Widget']->methods[0]->name);

        $legacy = $this->extract('src/tools/legacy.mjs');
        self::assertSame(
            ['function:src/tools/legacy.mjs#legacyHelper', 'class:src/tools/legacy.mjs#LegacyBox', 'constant:src/tools/legacy.mjs#LEGACY_VERSION'],
            array_keys($legacy),
        );
        self::assertNull($legacy['function:src/tools/legacy.mjs#legacyHelper']->parameters[0]->nativeType);
        self::assertTrue($legacy['class:src/tools/legacy.mjs#LegacyBox']->methods[1]->static);
    }

    /**
     * Decision: a file with syntax errors fails as a whole (like a PHP parse error) instead of
     * yielding what the parser recovered; the rest of the batch is unaffected.
     */
    public function testSyntacticallyBrokenFileFailsPerFileAndFailsTheBuild(): void
    {
        $root = (string) realpath(self::BROKEN_FIXTURE);
        $results = $this->extractor($root)->extractMany([$root . '/src/broken.ts', $root . '/src/ok.ts']);

        self::assertFalse($results[$root . '/src/broken.ts']->ok);
        self::assertSame([], $results[$root . '/src/broken.ts']->symbols);
        self::assertStringContainsString('Syntax error at line 4', (string) $results[$root . '/src/broken.ts']->error);
        self::assertTrue($results[$root . '/src/ok.ts']->ok);
        self::assertSame('src/ok.ts#fine', $results[$root . '/src/ok.ts']->symbols[0]->fqn);

        copy($root . '/src/broken.ts', $this->root . '/src/broken.ts');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parsing failed for src/broken.ts. Syntax error at line 4');
        $this->builder([SourceLanguage::TYPESCRIPT])->build($this->root, ['.'], []);
    }

    public function testFinderSkipsDeclarationFilesAndCompiledOutputAndKeepsPhpDefaults(): void
    {
        self::assertSame(['src/Php/Greeter.php'], (new PhpFileFinder())->find($this->root, ['.']));

        self::assertSame(
            [
                'src/Php/Greeter.php',
                'src/kinds.ts',
                'src/ring.ts',
                'src/shapes/base.ts',
                'src/shapes/circle.ts',
                'src/shapes/index.ts',
                'src/tools/legacy.mjs',
                // src/types.d.ts and dist/kinds.js are not source.
                'src/view/Widget.tsx',
            ],
            (new PhpFileFinder(SourceLanguage::extensions([SourceLanguage::PHP, SourceLanguage::TYPESCRIPT])))->find($this->root, ['.']),
        );
        self::assertNull(SourceLanguage::ofPath('src/types.d.ts'));
        self::assertSame(SourceLanguage::TYPESCRIPT, SourceLanguage::ofPath('src/a.cjs'));
        self::assertSame(SourceLanguage::PHP, SourceLanguage::ofPath('views/page.phtml'));
    }

    public function testDefaultBuildOfMixedProjectIsThePhpOnlyMap(): void
    {
        $phpOnlyRoot = $this->root . '-php-only';
        mkdir($phpOnlyRoot . '/src/Php', 0o775, true);
        copy($this->root . '/src/Php/Greeter.php', $phpOnlyRoot . '/src/Php/Greeter.php');

        try {
            $mixed = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['.'], []);
            $phpOnly = AgentMapBuilder::forLanguages([SourceLanguage::PHP], new StructuralOnlySemanticAnalyzer())->build($phpOnlyRoot, ['.'], []);
        } finally {
            $this->removeDirectory($phpOnlyRoot);
        }

        self::assertNotNull($mixed->fingerprint);
        self::assertArrayNotHasKey('typescript_version', $mixed->fingerprint->toArray());
        self::assertSame(AgentMapIndex::SCHEMA_VERSION, $mixed->schemaVersion);
        self::assertSame('2.0', $mixed->schemaVersion);
        self::assertSame('simple-php-code-parser+structural-only', $mixed->backend);
        self::assertSame(['src/Php/Greeter.php'], array_map(static fn (FileEntry $file): string => $file->path, $mixed->files));
        self::assertArrayNotHasKey('language', $mixed->files[0]->toArray());
        // The digest ignores the root: identical bytes for identical PHP sources, TypeScript or not.
        self::assertSame($phpOnly->mapDigest(), $mixed->mapDigest());
    }

    public function testEnablingTypeScriptChangesBackendIdentityAndRefusesCrossBackendMerge(): void
    {
        $structural = new StructuralOnlySemanticAnalyzer();
        $php = AgentMapBuilder::forLanguages([SourceLanguage::PHP], $structural);
        $both = $this->builder(['typescript', 'php']);
        $typeScript = $this->builder([SourceLanguage::TYPESCRIPT]);

        self::assertSame('simple-php-code-parser+structural-only', $php->backend());
        self::assertSame('simple-php-code-parser+typescript-compiler+structural-only', $both->backend());
        self::assertSame('typescript-compiler+structural-only', $typeScript->backend());
        self::assertSame(' --languages=php,typescript', AgentMapBuilder::languagesOptionForBackend($both->backend()));
        self::assertSame(' --languages=typescript', AgentMapBuilder::languagesOptionForBackend($typeScript->backend()));
        self::assertSame('', AgentMapBuilder::languagesOptionForBackend('simple-php-code-parser+phpstan'));

        $phpMap = $php->build($this->root, ['.'], []);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot incrementally merge map backend "simple-php-code-parser+structural-only" with "simple-php-code-parser+typescript-compiler+structural-only"');
        $both->build($this->root, ['src/kinds.ts'], [], null, null, $phpMap);
    }

    public function testMixedBuildRecordsLanguageNamespaceStatusAndStructuralRelations(): void
    {
        $semantic = new TypeScriptTestSemanticAnalyzer();
        $index = AgentMapBuilder::forLanguages(
            [SourceLanguage::PHP, SourceLanguage::TYPESCRIPT],
            $semantic,
            null,
            $this->typeScriptPath(),
        )->build($this->root, ['.'], []);

        self::assertSame('2.1', $index->schemaVersion);
        self::assertSame(['src/Php/Greeter.php'], $semantic->files, 'The PHP semantic backend must never be handed a TypeScript file.');

        $files = [];
        foreach ($index->files as $file) {
            $files[$file->path] = [$file->language, $file->namespace, $file->semanticStatus];
        }
        self::assertSame(
            [
                'src/Php/Greeter.php' => ['php', 'App\\Php', 'no_semantic_records'],
                'src/kinds.ts' => ['typescript', 'src', 'structural_only'],
                'src/ring.ts' => ['typescript', 'src', 'structural_only'],
                'src/shapes/base.ts' => ['typescript', 'src/shapes', 'structural_only'],
                'src/shapes/circle.ts' => ['typescript', 'src/shapes', 'structural_only'],
                'src/shapes/index.ts' => ['typescript', 'src/shapes', 'structural_only'],
                'src/tools/legacy.mjs' => ['typescript', 'src/tools', 'structural_only'],
                'src/view/Widget.tsx' => ['typescript', 'src/view', 'structural_only'],
            ],
            $files,
        );

        // No "PHPStan produced no records" claims about files PHPStan was never shown.
        foreach ($index->diagnostics as $diagnostic) {
            self::assertSame('src/Php/Greeter.php', $diagnostic->file);
        }

        $relations = [];
        foreach ($index->relations as $relation) {
            if ($relation->sourceId === 'class:src/shapes/circle.ts#Circle') {
                $relations[$relation->kind][] = implode(',', $relation->targetIds);
            }
        }
        sort($relations['implements']);
        self::assertSame(['class:src/shapes/base.ts#BaseShape'], $relations['extends']);
        self::assertSame(
            ['interface:src/shapes/base.ts#Shape', 'interface:src/shapes/circle.ts#Serializable', 'unresolved:implements'],
            $relations['implements'],
        );
        self::assertNotNull($index->fingerprint);
        self::assertSame(AgentMapBuilderTestTypeScriptVersion::current(), $index->fingerprint->typeScriptVersion);
        self::assertSame(AgentMapBuilderTestTypeScriptVersion::current(), $index->fingerprint->toArray()['typescript_version'] ?? null);
        self::assertContains('method:src/shapes/circle.ts#Circle::area', $relations['declares_method']);
    }

    public function testLanguageSurvivesJsonAndToonRoundTripAndMissingLanguageReadsAsPhp(): void
    {
        $index = $this->builder([SourceLanguage::PHP, SourceLanguage::TYPESCRIPT])->build($this->root, ['.'], []);

        foreach (['json', 'toon'] as $format) {
            $path = $this->root . '/out/map.' . $format;
            (new IndexWriter())->write($index, $path, $format);
            IndexReader::clearCache();
            $read = (new IndexReader())->read($path);

            self::assertSame('2.1', $read->schemaVersion, $format);
            self::assertSame($index->backend, $read->backend, $format);
            self::assertSame('typescript', $read->file('src/kinds.ts')?->language, $format);
            self::assertSame('php', $read->file('src/Php/Greeter.php')?->language, $format);
            self::assertSame($index->mapDigest(), $read->mapDigest(), $format);
        }

        $raw = json_decode((string) file_get_contents($this->root . '/out/map.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($raw);
        $languages = [];
        foreach ($raw['files'] as $file) {
            $languages[$file['path']] = $file['language'] ?? null;
        }
        self::assertSame('typescript', $languages['src/kinds.ts']);
        self::assertNull($languages['src/Php/Greeter.php'], 'PHP files keep their pre-language shape.');

        self::assertSame('php', FileEntry::fromArray(['path' => 'src/A.php', 'sha256' => 'sha256:x'])->language);
        self::assertSame('typescript', FileEntry::fromArray(['path' => 'src/a.ts', 'language' => 'typescript'])->language);
    }

    public function testQueryFindsTypeScriptSymbolsByShortNameFqnAndMethod(): void
    {
        $index = $this->builder([SourceLanguage::PHP, SourceLanguage::TYPESCRIPT])->build($this->root, ['.'], []);

        $byName = $index->query('BaseShape');
        self::assertSame('src/shapes/base.ts', $byName->files[0]->path);
        self::assertSame('typescript', $byName->files[0]->language);
        self::assertSame(['class:src/shapes/base.ts#BaseShape'], $this->ids($byName->files[0]));

        $byFqn = $index->query('src/kinds.ts#distance');
        self::assertSame('exact', $byFqn->matchType);
        self::assertSame(['function:src/kinds.ts#distance'], $this->ids($byFqn->files[0]));

        // A value and a type sharing one name are both reported, not collapsed by FQN.
        $shared = $index->query('src/kinds.ts#Mode');
        self::assertSame(['constant:src/kinds.ts#Mode', 'type_alias:src/kinds.ts#Mode'], $this->ids($shared->files[0]));

        $byMethod = $index->query('serialize');
        self::assertSame('src/shapes/circle.ts', $byMethod->files[0]->path);

        // One short name, two languages: both are found and stay distinguishable.
        $scale = [];
        foreach ($index->query('scale')->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->name === 'scale') {
                    $scale[$symbol->id()] = $file->language;
                }
            }
        }
        ksort($scale);
        self::assertSame(['function:App\\Php\\scale' => 'php', 'function:src/kinds.ts#scale' => 'typescript'], $scale);
    }

    public function testCliBuildsWithLanguagesOptionAndQueriesTheMap(): void
    {
        $this->typeScriptPath();
        putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV . '=' . $this->typeScriptPath());
        $out = $this->root . '/out/cli-map.json';

        [$exit, $stdout] = $this->runCli(['build', '--root=' . $this->root, '--languages=typescript', '--out=' . $out]);
        self::assertSame(0, $exit, $stdout);
        self::assertStringContainsString('Wrote 7 file(s)', $stdout);

        $map = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        self::assertSame('typescript-compiler+structural-only', $map['backend']);
        self::assertSame('2.1', $map['schema_version']);

        [$exit, $stdout] = $this->runCli(['query', 'distance', '--root=' . $this->root, '--index=' . $out]);
        self::assertSame(0, $exit, $stdout);
        self::assertStringContainsString('src/kinds.ts', $stdout);
        self::assertStringContainsString('distance', $stdout);

        [$exit, $stdout] = $this->runCli(['query', 'src/shapes/base.ts#BaseShape', '--root=' . $this->root, '--index=' . $out, '--format=json']);
        self::assertSame(0, $exit, $stdout);
        self::assertStringContainsString('src/shapes/base.ts#BaseShape', $stdout);
    }

    public function testCliOptionsParseLanguagesAndKeepPhpStanOutOfTypeScriptOnlyMaps(): void
    {
        self::assertSame(['php'], CliOptions::parse(['build', '--root=' . $this->root])->languages);
        self::assertSame(['php', 'typescript'], CliOptions::parse(['build', '--root=' . $this->root, '--languages=typescript,php'])->languages);

        $typeScriptOnly = CliOptions::parse(['build', '--root=' . $this->root, '--languages=typescript']);
        self::assertSame(['typescript'], $typeScriptOnly->languages);
        self::assertSame('structural', $typeScriptOnly->backend);

        try {
            CliOptions::parse(['build', '--root=' . $this->root, '--languages=typescript', '--backend=phpstan']);
            self::fail('A TypeScript-only map cannot carry the PHPStan backend.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('--backend=phpstan requires php in --languages.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown language: rust. Supported: php, typescript.');
        CliOptions::parse(['build', '--root=' . $this->root, '--languages=php,rust']);
    }

    public function testMissingTypeScriptPackageFailsEveryFileWithAnActionableMessage(): void
    {
        $this->requireNode();
        $extractor = new TypeScriptSymbolExtractor($this->root, $this->root . '/no-such-typescript');
        $files = [$this->root . '/src/kinds.ts', $this->root . '/src/ring.ts'];

        $results = $extractor->extractMany($files);

        self::assertSame($files, array_keys($results));
        foreach ($results as $result) {
            self::assertFalse($result->ok);
            self::assertSame([], $result->symbols);
            self::assertStringContainsString('The "typescript" npm package could not be loaded.', (string) $result->error);
            self::assertStringContainsString($this->root . '/no-such-typescript (not found)', (string) $result->error);
            self::assertStringContainsString('The configured compiler path "' . $this->root . '/no-such-typescript" was rejected', (string) $result->error);
            self::assertStringNotContainsString($this->root . '/node_modules/typescript', (string) $result->error);
            self::assertStringContainsString('npm install --save-dev typescript', (string) $result->error);
            self::assertStringContainsString('AGENT_MAP_TYPESCRIPT_PATH', (string) $result->error);
        }

        $builder = AgentMapBuilder::forLanguages([SourceLanguage::TYPESCRIPT], new StructuralOnlySemanticAnalyzer(), null, $this->root . '/no-such-typescript');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parsing failed for src/kinds.ts. TypeScript symbol extraction failed (exit 3): The "typescript" npm package could not be loaded.');
        $builder->build($this->root, ['.'], []);
    }

    public function testMissingNodeOrProjectRootFailsEveryFileInsteadOfReturningAnEmptyResult(): void
    {
        $file = $this->root . '/src/kinds.ts';

        $withoutNode = (new TypeScriptSymbolExtractor($this->root, null, $this->root . '/no-such-node'))->extract($file);
        self::assertFalse($withoutNode->ok);
        self::assertStringContainsString('Unable to run Node.js', (string) $withoutNode->error);

        $withoutRoot = (new TypeScriptSymbolExtractor())->extract($file);
        self::assertFalse($withoutRoot->ok);
        self::assertStringContainsString('needs the analysed project root', (string) $withoutRoot->error);
    }

    public function testRoutingExtractorDispatchesByExtensionAndKeepsRequestOrder(): void
    {
        $php = new TypeScriptTestNamedExtractor('php');
        $typeScript = new TypeScriptTestNamedExtractor('typescript');
        $files = ['/p/a.ts', '/p/B.php', '/p/c.mjs', '/p/d.phtml', '/p/e.tsx'];

        $results = (new LanguageRoutingSymbolExtractor($php, $typeScript))->extractMany($files);

        self::assertSame($files, array_keys($results));
        self::assertSame(['/p/B.php', '/p/d.phtml'], $php->files);
        self::assertSame(['/p/a.ts', '/p/c.mjs', '/p/e.tsx'], $typeScript->files);
        self::assertSame('typescript', $results['/p/e.tsx']->error);
    }

    /**
     * A name the TypeScript extractor could not prove must never become the id of a PHP symbol
     * that happens to share it, nor of a TypeScript symbol the clause cannot name.
     */
    public function testUnprovableHeritageNeverResolvesToAnotherLanguageOrAnIncompatibleKind(): void
    {
        file_put_contents($this->root . '/src/Php/Globals.php', "<?php\n\nclass Base {}\n\ninterface Shape {}\n\nclass Disposable {}\n");
        file_put_contents(
            $this->root . '/src/a.ts',
            "import { Base, Shape } from 'some-package';\n\nexport class A extends Base implements Shape {}\n\n"
            . "export const X = 1;\nexport type X = number;\nclass U extends X {}\n\n"
            . "export type Alias = { a: number };\nexport interface FromAlias extends Alias {}\n"
            . "export class Impl implements FromAlias, A {}\n"
            . "class Self extends Self {}\n",
        );
        file_put_contents(
            $this->root . '/src/b.ts',
            "import { X, A } from './a';\n\nexport class V extends X {}\nexport class W extends A {}\n",
        );

        $a = $this->extract('src/a.ts');
        self::assertSame(['Base'], $a['class:src/a.ts#A']->extends);
        self::assertSame(['Shape'], $a['class:src/a.ts#A']->implements);
        self::assertSame(['X'], $a['class:src/a.ts#U']->extends, 'A constant and a type alias named X are not a class.');
        self::assertSame(['Alias'], $a['interface:src/a.ts#FromAlias']->extends);
        self::assertSame(['src/a.ts#FromAlias', 'src/a.ts#A'], $a['class:src/a.ts#Impl']->implements);
        $b = $this->extract('src/b.ts');
        self::assertSame(['X'], $b['class:src/b.ts#V']->extends);
        self::assertSame(['src/a.ts#A'], $b['class:src/b.ts#W']->extends);

        $index = $this->builder([SourceLanguage::PHP, SourceLanguage::TYPESCRIPT])->build($this->root, ['.'], []);
        $heritage = [];
        foreach ($index->relations as $relation) {
            if (in_array($relation->kind, ['extends', 'implements'], true) && preg_match('~^(class|interface):src/[ab]\.ts#~', $relation->sourceId) === 1) {
                $heritage[] = $relation->sourceId . ' ' . $relation->kind . ' ' . implode(',', $relation->targetIds) . ' (' . ($relation->receiverType ?? '-') . ')';
            }
        }
        sort($heritage);
        self::assertSame(
            [
                'class:src/a.ts#A extends unresolved:extends (Base)',
                'class:src/a.ts#A implements unresolved:implements (Shape)',
                'class:src/a.ts#Impl implements class:src/a.ts#A (-)',
                'class:src/a.ts#Impl implements interface:src/a.ts#FromAlias (-)',
                'class:src/a.ts#Self extends unresolved:extends (Self)',
                'class:src/a.ts#U extends unresolved:extends (X)',
                'class:src/b.ts#V extends unresolved:extends (X)',
                'class:src/b.ts#W extends class:src/a.ts#A (-)',
                'interface:src/a.ts#FromAlias extends unresolved:extends (Alias)',
            ],
            $heritage,
        );
        // The existing fixture: a global TypeScript name next to a PHP class of that name.
        foreach ($index->relations as $relation) {
            if ($relation->file !== 'src/Php/Globals.php' && $relation->kind !== 'defines') {
                self::assertNotContains('class:Disposable', $relation->targetIds);
                self::assertNotContains('class:Base', $relation->targetIds);
                self::assertNotContains('interface:Shape', $relation->targetIds);
            }
        }
    }

    public function testCompiledOutputIsSkippedOnlyAtTheTopLevelAndNeverWhenRequested(): void
    {
        mkdir($this->root . '/src/build', 0o775, true);
        mkdir($this->root . '/build', 0o775, true);
        file_put_contents($this->root . '/src/build/pipeline.ts', "export function runPipeline(): void {}\n");
        file_put_contents($this->root . '/build/out.js', "export function emitted() {}\n");
        $finder = new PhpFileFinder(SourceLanguage::extensions([SourceLanguage::TYPESCRIPT]));

        $default = $finder->find($this->root, ['.']);
        self::assertContains('src/build/pipeline.ts', $default, 'Only top-level dist/ and build/ are compiled output.');
        self::assertNotContains('build/out.js', $default);
        self::assertNotContains('dist/kinds.js', $default);

        self::assertSame(['src/build/pipeline.ts'], $finder->find($this->root, ['src/build']));
        self::assertSame(['build/out.js', 'dist/kinds.js'], $finder->find($this->root, ['dist', 'build']), 'An explicit scope is indexed.');
        self::assertSame(['dist/kinds.js'], $finder->find($this->root, ['./dist/kinds.js']));

        putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV . '=' . $this->typeScriptPath());
        [$exit, $stdout] = $this->runCli(['build', '--root=' . $this->root, '--paths=src/build', '--languages=typescript', '--out=' . $this->root . '/out/nested.json']);
        self::assertSame(0, $exit, $stdout);
        self::assertStringContainsString('Wrote 1 file(s)', $stdout);
    }

    public function testExplicitCompilerPathWinsOverTheProjectCopyWhichStaysTheFallback(): void
    {
        $real = $this->typeScriptPath();
        $file = $this->root . '/src/kinds.ts';

        // A project copy that would be picked up, and executed, if it were tried first.
        mkdir($this->root . '/node_modules/typescript', 0o775, true);
        file_put_contents($this->root . '/node_modules/typescript/package.json', '{"name":"typescript","version":"0.0.0-project","main":"index.js"}');
        file_put_contents(
            $this->root . '/node_modules/typescript/index.js',
            "require('fs').writeFileSync(__dirname + '/executed', '1');\nmodule.exports = {};\n",
        );

        $explicit = (new TypeScriptSymbolExtractor($this->root, $real))->extract($file);
        self::assertTrue($explicit->ok, (string) $explicit->error);
        self::assertSame(AgentMapBuilderTestTypeScriptVersion::current(), $explicit->toolVersion);
        self::assertFileDoesNotExist($this->root . '/node_modules/typescript/executed', 'The project copy must not be loaded when an explicit path works.');

        putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV . '=' . $real);
        self::assertTrue((new TypeScriptSymbolExtractor($this->root))->extract($file)->ok);
        self::assertFileDoesNotExist($this->root . '/node_modules/typescript/executed');
        putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV);

        // A configured path that does not load fails closed: the project copy is not the fallback.
        mkdir($this->root . '/not-typescript', 0o775, true);
        file_put_contents($this->root . '/not-typescript/package.json', '{"name":"other","version":"9.9.9","main":"index.js"}');
        file_put_contents($this->root . '/not-typescript/index.js', "module.exports = { version: '9.9.9' };\n");
        foreach ([$this->root . '/no-such-directory' => '(not found)', $this->root . '/not-typescript' => '(version 9.9.9 has no createSourceFile compiler API)'] as $path => $reason) {
            $viaArgument = (new TypeScriptSymbolExtractor($this->root, $path))->extractMany([$file, $this->root . '/src/ring.ts']);
            putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV . '=' . $path);
            $viaEnvironment = (new TypeScriptSymbolExtractor($this->root))->extractMany([$file, $this->root . '/src/ring.ts']);
            putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV);
            foreach ([...array_values($viaArgument), ...array_values($viaEnvironment)] as $result) {
                self::assertFalse($result->ok);
                self::assertStringContainsString($path . ' ' . $reason, (string) $result->error);
                self::assertStringContainsString('The configured compiler path "' . $path . '" was rejected', (string) $result->error);
            }
        }
        self::assertFileDoesNotExist($this->root . '/node_modules/typescript/executed', 'A rejected explicit path must not fall back to the project copy.');

        // Without any configured path the project copy is used (and here, executed).
        self::assertFalse((new TypeScriptSymbolExtractor($this->root))->extract($file)->ok);
        self::assertFileExists($this->root . '/node_modules/typescript/executed');
        unlink($this->root . '/node_modules/typescript/executed');
        unlink($this->root . '/node_modules/typescript/index.js');
        unlink($this->root . '/node_modules/typescript/package.json');
        rmdir($this->root . '/node_modules/typescript');
        self::assertTrue(symlink($real, $this->root . '/node_modules/typescript'));
        $fallback = (new TypeScriptSymbolExtractor($this->root))->extract($file);
        self::assertTrue($fallback->ok, (string) $fallback->error);
        unlink($this->root . '/node_modules/typescript');
    }

    public function testChildThatNeverReadsOrExitsEarlyIsACleanBatchFailure(): void
    {
        // Far more than a pipe buffer holds, so a blocking write would hang or hit a closed pipe.
        $files = [];
        for ($i = 0; $i < 6000; ++$i) {
            $files[] = $this->root . '/src/generated/file-number-' . $i . '-with-a-long-enough-name.ts';
        }
        $deaf = $this->root . '/deaf-node';
        file_put_contents($deaf, "#!/bin/sh\nexec sleep 30\n");
        chmod($deaf, 0o755);
        $gone = $this->root . '/gone-node';
        file_put_contents($gone, "#!/bin/sh\necho 'boom' >&2\nexit 7\n");
        chmod($gone, 0o755);

        $notices = [];
        set_error_handler(static function (int $severity, string $message) use (&$notices): bool {
            // Like PHPUnit's own handler: a diagnostic silenced at its call site is not raised.
            if ((error_reporting() & $severity) !== 0) {
                $notices[] = $message;
            }

            return true;
        });
        try {
            $started = microtime(true);
            $timedOut = (new TypeScriptSymbolExtractor($this->root, null, $deaf, 1))->extractMany($files);
            $elapsed = microtime(true) - $started;
            $exited = (new TypeScriptSymbolExtractor($this->root, null, $gone, 30))->extractMany($files);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $notices);
        self::assertLessThan(10.0, $elapsed, 'The timeout covers the stdin write.');
        self::assertCount(6000, $timedOut);
        self::assertFalse($timedOut[$files[0]]->ok);
        self::assertSame('TypeScript symbol extraction timed out after 1 second(s).', $timedOut[$files[5999]]->error);
        self::assertCount(6000, $exited);
        self::assertFalse($exited[$files[0]]->ok);
        self::assertSame('TypeScript symbol extraction failed (exit 7): boom', $exited[$files[5999]]->error);
    }

    public function testRefreshPrepareAndStaleReadRefuseATypeScriptMapAndNameTheRebuild(): void
    {
        putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV . '=' . $this->typeScriptPath());
        $out = $this->root . '/out/map.json';
        [$exit] = $this->runCli(['build', '--root=' . $this->root, '--paths=src', '--languages=php,typescript', '--backend=structural', '--out=' . $out]);
        self::assertSame(0, $exit);

        $request = new MapPreparationRequest(
            root: $this->root,
            indexPath: $out,
            outputPath: $out,
            format: 'json',
            paths: ['.'],
            pathsProvided: false,
            scanPaths: [],
            scanPathsProvided: false,
            excludes: [],
            excludesProvided: false,
            backend: 'structural',
            phpStanConfig: null,
            phpStanMemoryLimit: null,
            artifacts: MapArtifactPaths::forProject($this->root, dirname($out)),
        );
        $before = (string) file_get_contents($out);

        // Refresh only discovers PHP files: an unchanged map must not be declared "up to date".
        try {
            (new MapPreparationService())->refresh($request);
            self::fail('Refresh must refuse a map that indexes TypeScript.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('refresh, watch and prepare cover PHP-only maps', $exception->getMessage());
            // The recorded build scope (`src`), not this request's default `.`.
            self::assertStringContainsString('agent-map build --root=' . $this->root . ' --paths=src --out=' . $out . ' --languages=php,typescript', $exception->getMessage());
        }

        // A watcher refuses before its first cycle instead of logging one failure and idling.
        $log = [];
        try {
            (new MapWatcher(sleepMilliseconds: static function (int $milliseconds): void {}))->watch(
                $request,
                50,
                static function (string $line) use (&$log): void {
                    $log[] = $line;
                },
                static fn (): bool => false,
                3,
            );
            self::fail('Watch must refuse a map that indexes TypeScript.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('refresh, watch and prepare cover PHP-only maps', $exception->getMessage());
            self::assertStringContainsString(' --paths=src ', $exception->getMessage());
            self::assertSame([], $log);
        }
        [$exit] = $this->runProcess(['watch', '--root=' . $this->root, '--index=' . $out, '--languages=typescript']);
        self::assertSame(1, $exit, 'watch does not accept --languages.');
        [$exit, , $stderr] = $this->runProcess(['watch', '--root=' . $this->root, '--index=' . $out, '--backend=structural']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('refresh, watch and prepare cover PHP-only maps', $stderr);
        self::assertStringNotContainsString('Watching ', $stderr);
        try {
            (new MapPreparationService())->prepare($request);
            self::fail('Prepare must refuse a map that indexes TypeScript.');
        } catch (MapPreparationException $exception) {
            self::assertSame('backend_mismatch', $exception->reason);
            self::assertStringContainsString(' --paths=src ', $exception->recoveryCommand);
            self::assertStringContainsString(' --languages=php,typescript', $exception->recoveryCommand);
        }

        // A read of a stale map fails closed and names the rebuild, including the languages.
        file_put_contents($this->root . '/src/kinds.ts', "\nexport const ADDED = 1;\n", FILE_APPEND);
        IndexReader::clearCache();
        [$exit, , $stderr] = $this->runProcess(['scope', 'src/kinds.ts#distance', '--root=' . $this->root, '--index=' . $out, '--backend=structural']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('An incremental repair cannot merge two semantic backends.', $stderr);
        self::assertStringContainsString(' --paths=src ', $stderr);
        self::assertStringContainsString(' --languages=php,typescript', $stderr);
        // The printed command reproduces the recorded backend, so no "will carry another" claim.
        self::assertStringNotContainsString('The rebuilt index will carry', $stderr);

        // Without --backend the same command would produce another semantic backend; the message
        // must name the identity that command yields, languages included.
        if (PhpStanSemanticAnalyzer::isAvailable()) {
            [, , $stderr] = $this->runProcess(['scope', 'src/kinds.ts#distance', '--root=' . $this->root, '--index=' . $out]);
            self::assertStringContainsString(' --languages=php,typescript --backend=structural', $stderr);
            self::assertStringNotContainsString('The rebuilt index will carry', $stderr);
        }
        self::assertSame($before, (string) file_get_contents($out), 'A refusal leaves the map untouched.');
    }

    /**
     * Representative planner: the others are untouched in this slice.
     */
    public function testFunctionRenameRefusesTypeScriptDeclarationsExplicitly(): void
    {
        $index = $this->builder([SourceLanguage::PHP, SourceLanguage::TYPESCRIPT])->build($this->root, ['.'], []);

        foreach (['distance', 'src/kinds.ts#distance'] as $target) {
            try {
                (new FunctionRenamePlanner())->plan($index, $target, 'span');
                self::fail('A TypeScript function must not get a rename plan.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Function rename supports PHP declarations only: src/kinds.ts#distance is a typescript declaration (src/kinds.ts). No rename plan is published for it.',
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * @param list<string> $languages
     */
    private function builder(array $languages): AgentMapBuilder
    {
        return AgentMapBuilder::forLanguages($languages, new StructuralOnlySemanticAnalyzer(), null, $this->typeScriptPath());
    }

    private function extractor(string $root): TypeScriptSymbolExtractor
    {
        return new TypeScriptSymbolExtractor($root, $this->typeScriptPath());
    }

    /**
     * @return array<string, SymbolEntry> symbols of one fixture file by id, in extraction order
     */
    private function extract(string $relative): array
    {
        $result = $this->extractor($this->root)->extract($this->root . '/' . $relative);
        self::assertTrue($result->ok, (string) $result->error);

        $symbols = [];
        foreach ($result->symbols as $symbol) {
            $symbols[$symbol->id()] = $symbol;
        }

        return $symbols;
    }

    /**
     * @return list<string>
     */
    private function ids(FileEntry $file): array
    {
        return array_map(static fn (SymbolEntry $symbol): string => $symbol->id(), $file->symbols);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function runCli(array $arguments): array
    {
        IndexReader::clearCache();
        ob_start();
        try {
            $exit = (new AgentMapApplication())->run(['agent-map', ...$arguments]);
        } finally {
            $stdout = (string) ob_get_clean();
        }

        return [$exit, $stdout];
    }

    /**
     * Run the CLI as a host does, so STDERR is observable.
     *
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runProcess(array $arguments): array
    {
        $process = proc_open([\PHP_BINARY, dirname(__DIR__) . '/bin/agent-map', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * The compiler the tests run against: `<agent-map>/node_modules/typescript`, installed by `npm ci`.
     */
    private function typeScriptPath(): string
    {
        $this->requireNode();
        $path = dirname(__DIR__) . '/node_modules/typescript';
        if (!is_file($path . '/package.json')) {
            $this->unavailable('The typescript npm package is missing at ' . $path . '; run `npm ci` in the agent-map checkout.');
        }

        return $path;
    }

    private function requireNode(): void
    {
        $output = [];
        $exit = 1;
        @exec('node --version 2>&1', $output, $exit);
        if ($exit !== 0) {
            $this->unavailable('Node.js is not available on PATH.');
        }
    }

    /**
     * Skips locally; fails where the toolchain is promised (CI sets AGENT_MAP_REQUIRE_TYPESCRIPT=1).
     */
    private function unavailable(string $reason): never
    {
        if (getenv('AGENT_MAP_REQUIRE_TYPESCRIPT') === '1') {
            self::fail($reason . ' AGENT_MAP_REQUIRE_TYPESCRIPT=1 forbids skipping the TypeScript tests.');
        }

        self::markTestSkipped($reason);
    }

    private function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0o775, true);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $destination = $target . '/' . substr($item->getPathname(), strlen($source) + 1);
            $item->isDir() ? mkdir($destination, 0o775, true) : copy($item->getPathname(), $destination);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}

final class AgentMapBuilderTestTypeScriptVersion
{
    /** The version of the compiler `npm ci` installed, read from its own package.json. */
    public static function current(): string
    {
        $package = json_decode((string) file_get_contents(dirname(__DIR__) . '/node_modules/typescript/package.json'), true);

        return is_array($package) && is_string($package['version'] ?? null) ? $package['version'] : '';
    }
}

final class TypeScriptTestSemanticAnalyzer implements SemanticAnalyzer
{
    /** @var list<string> */
    public array $files = [];

    public function backend(): string
    {
        return 'structural-only';
    }

    public function analyse(
        string $root,
        array $relativeFiles,
        ?string $configurationFile = null,
        ?string $memoryLimit = null,
        array $analyseDirectories = [],
        array $scanDirectories = [],
    ): SemanticAnalysisResult {
        $this->files = $relativeFiles;

        return new SemanticAnalysisResult([], [], 'none', 'sha256:none');
    }
}

final class TypeScriptTestNamedExtractor implements SymbolExtractor
{
    /** @var list<string> */
    public array $files = [];

    public function __construct(private readonly string $name)
    {
    }

    public function extract(string $file): ExtractResult
    {
        return $this->extractMany([$file])[$file];
    }

    public function extractMany(array $files): array
    {
        $this->files = [...$this->files, ...$files];
        $results = [];
        foreach ($files as $file) {
            $results[$file] = new ExtractResult($file, true, [], $this->name);
        }

        return $results;
    }
}
