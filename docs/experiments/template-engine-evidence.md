# Template engine evidence

Status: design note plus two shipped slices: pluggable dialects for residue scanning (tier 1), and an experimental
Smarty 5 evidence API for tiers 2 and 3. Nothing here changes plan status, edits, blockers or blind spots, the Map
schema or the CLI: template evidence stays residue until a stronger tier is measured.

## Question

PHP projects keep a large part of their behaviour in templates (Smarty, Twig, Blade, Latte, plain `.phtml`). A rename or
removal that is `safe` for PHP can still break a template. How can `agent-map` report template evidence for more than
one engine, without pretending a text match is proof?

## Principles

- **Engine-neutral contract, engine-specific adapters.** The scanner knows nothing about a particular engine; each engine
  is a `TemplateDialect` (today) and later a template adapter (below). Projects plug in their own.
- **Explicit project settings beat guessing.** Delimiters, template directories, plugin registration and resources are
  usually configured in PHP bootstrap code and cannot be recovered reliably by reading it. They are constructor
  arguments today and should become project configuration, not heuristics.
- **Every fact says how it was found.** Regex boundary, engine parser, or typed render context. Only the last one can
  ever be more than residue.
- **Unavailable is not absent.** A dynamic include (`{include file=$tpl}`), an unknown variable type, or an engine with
  no adapter is reported as a coverage gap, never as "no references".

## Tier 1: dialects (shipped)

`voku\AgentMap\Reference\Template\TemplateDialect` answers two questions per engine: where is template code in this
file, and does attribute access (`x.name`) reach PHP getters?

| Dialect | Files | Code regions | `.name` reaches `getName()` |
| --- | --- | --- | --- |
| `RegexTemplateDialect::twig()` | `.twig` | `{{ }}`, `{% %}`, `{# #}` (kept: `@var` type hints) | yes |
| `RegexTemplateDialect::blade()` | `.blade.php` | `{{ }}`, `{!! !!}`, directive arguments | no (`->name` is a property) |
| `SmartyDialect` | `.tpl` (configurable) | tags per the Smarty 5 lexer, configurable delimiters | no (`.name` is an array key) |

`SmartyDialect` follows Smarty 5's own tag rules without loading Smarty: `{* *}` comments and `{literal}` blocks are
not code, auto-literal treats `{` followed by whitespace as text, and quotes and nested tags (`{$a[{$b}]}`,
`{"Hello {$name}"}`) do not end a tag early. A project with `setLeftDelimiter('<{')` passes
`new SmartyDialect('<{', '}>')`.

Other engines plug in without changing the scanner:

```php
$scanner = new NonPhpReferenceScanner(200, [
    new SmartyDialect('<{', '}>', ['.tpl', '.smarty']),
    new RegexTemplateDialect('latte', ['.latte'], ['/\{(?![\s*])[^{}]*\}/'], false),
    ...NonPhpReferenceScanner::defaultDialects(),
]);
```

## Tier 2: parser-backed template facts (Smarty: experimental API; other engines: proposed)

A template adapter turns one engine's files into one normalized fact set:

| Fact | Smarty 5 | Twig | Blade |
| --- | --- | --- | --- |
| template → template | `{include file=}`, `{extends}`, `{block}`, `{function}`/`{call}` | `extends`, `include`, `embed`, `import`, `use` | `@extends`, `@include`, `@component`, `<x-…>` |
| variable read with access chain | `{$user->getName()}` → `user`, `->getName()` | `user.name` → `user`, `.name` | `$user->name` |
| call of a registered PHP callable | modifiers / functions / blocks | functions / filters / tests | directives, components |
| static PHP reference | `{App\Foo::bar()}` (registered classes) | `constant('App\\Foo::BAR')` | any PHP expression |
| dynamic target | `{include file=$tpl}` → `unavailable` | `include(var)` → `unavailable` | `@include($view)` → `unavailable` |

Each fact carries the template path, line and byte range.

Preferred source per engine, strongest first:

1. **The engine's own parser.** Smarty 5 ships `Smarty\Lexer\TemplateLexer` (tracks `taglineno` per tag) and
   `Smarty\Parser\TemplateParser`; Twig has `Twig\Lexer` / `Twig\Parser` with a node tree; Blade has the standalone
   `stillat/blade-parser`. These stay optional (`suggest`), never runtime requirements of `agent-map`.
2. **Compile to PHP and reuse the PHP analysis.** Works for nearly every engine and yields the same facts the PHP
   pipeline already understands. The open problem is mapping back to template lines: Twig and Latte emit line
   comments, **Smarty 5's compiled output has no per-line map**, so for Smarty the lexer is the better source of
   positions.
3. **Plain PHP templates** (`.phtml`, Plates, WordPress themes) need no adapter at all: they are PHP and only have to be
   included in the PHP index.
4. **Tier 1 dialects** remain the fallback.

## Tier 3: typed render context (Smarty: experimental API, static types only; other engines: proposed)

The step that turns residue into evidence is the PHP side that renders a template:

```php
$smarty->assign('user', $user);   // PHPStan: App\Model\User
$smarty->display('user.tpl');
```

A render-site extractor per engine (`assign`/`display`/`fetch` for Smarty, `render()` for Twig, `view()` / `View::make()`
for Blade, `#[Template]` for Symfony) records template name → variable → PHP type. Every
`{$user->getName()}` in `user.tpl`, and in templates it includes with that variable, then refers to
`App\Model\User::getName()` with a confidence grade that is stronger than `member_name_only`. A variable assigned
different types at different render sites stays ambiguous and lists every type.

### Smarty 5 makes the template → PHP set closed

Since Smarty 5.0, templates can no longer call native or userland PHP functions unless they are registered
(`$smarty->php_functions` / `php_modifiers` were removed). Every callable a Smarty 5 template can reach is therefore
declared in PHP through one of:

- `registerPlugin('modifier'|'modifiercompiler'|'function'|'block'|'compiler', $name, $callback)`;
- `addExtension()` / `setExtensions()` with `Smarty\Extension\Base` (`getModifierCallback()`, `getFunctionHandler()`,
  `getBlockHandler()`, `getTagCompiler()`);
- `registerClass()` for static access, and `addPluginsDir()` with the `modifier.<name>.php` naming convention.

Static extraction of those registrations gives `|price` → `Fmt::price()` edges, so renaming or removing `Fmt::price()`
can list every template use. A registration whose callback is not statically resolvable is reported as
`unavailable`.

## Smarty 5 experimental API

```php
use voku\AgentMap\Reference\Template\Smarty\SmartyEvidenceBuilder;
use voku\AgentMap\Reference\Template\SmartyDialect;

$report = (new SmartyEvidenceBuilder(new SmartyDialect('{', '}'), templateDirs: ['templates']))->build($projectRoot);
$report->referencesTo('App\\Model\\User', 'getName'); // list<SmartyTypedReference>
$report->gaps;                                         // what could not be resolved
$report->toArray();                                    // schema `agent-map-smarty-evidence@experimental`
```

It reads files only and never loads Smarty. Pieces, each usable on its own:

- `SmartyTemplateAnalyzer`: per template, `TemplateFact`s for tag names, `{include}` / `{extends}` (static target or
  dynamic, `u=$user` arguments), `$var->member` accesses, modifiers and static references, with exact byte ranges.
- `SmartyRenderSiteExtractor`: per PHP file, `display()` / `fetch()` sites with the variables `assign()`ed to the same
  receiver in the same function (including `createTemplate()` children), and `registerPlugin()` / `registerClass()` /
  `addExtension()` registrations with resolved callbacks. A site is kept only when its receiver is statically a
  Smarty object or its template name is a Smarty template, because `display()` / `fetch()` are common method names.
- `SmartyEvidenceBuilder`: joins both, flows variables through includes and extends, and reports typed references
  (`render_context_typed`, `registered_plugin`, `static_reference`) plus gaps (`dynamic_render`, `dynamic_include`,
  `dynamic_extends`, `unknown_template`, `untyped_variable`, `unresolved_registration`).

Not covered yet, by design of this slice:

- types that need inference: method return values, `{foreach $users as $user}` element types, `@var` annotations.
  These become gaps (`untyped_variable`), not guesses; a PHPStan-backed pass is the natural next step;
- members after the first one in a chain (`$user->getAddress()->getCity()` reports only `getAddress`);
- `assign()` and `display()` on the same receiver in different functions (a base controller that assigns and a
  child that displays);
- Smarty 3/4 differences (unregistered PHP functions used as modifiers are not modelled).

## Real-world validation (Smarty)

The experimental Smarty API was run over two pinned public code bases, with no project-specific configuration:

| Corpus | Commit | Templates | Facts | Registrations | References | Gaps | Time |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Piwigo (gallery application, PHP + Smarty) | `2224114` (2026-11-09) | 146 | 5,687 | 44 | 3,207 | 61 | 4 s |
| PrestaShop `classic-theme` (templates only) | `a04ea34` (2026-10-26) | 186 | 2,608 | 0 | 0 | 22 | <1 s |

What was checked:

- **Exact positions.** Each of the 3,207 Piwigo references was re-read from its file: the byte range holds exactly
  the reported text, and the reported line is the line of its first byte. There were 0 mismatches.
- **Recall.** The references per registered plugin equal a grep over the same templates once `{* ... *}`
  comments and `{literal}` blocks are removed. That gives 2,695 `translate` modifiers and 192 `combine_script`
  tags in both counts.
  A plain grep reports 42 more `translate` calls and 1 more `combine_script`. All of them sit inside comments, often
  inside an HTML attribute (`{*title="{'Edit'|@translate}"*}`): text search would report them as live call sites.
- **Resolution.** All 44 `registerPlugin()` calls in Piwigo were found. They are made on a `$this->smarty`
  property and use the legacy `array('Template', 'method')` / `array(&$this, 'method')` callbacks. Every
  reference resolves to the registered PHP symbol, and none is ambiguous.
  `SmartyLegacyIdiomsTest` locks these shapes in with a fixture written for the test: `|@modifier`, modifiers in
  attribute values, block plugins, commented-out calls.

Gaps the run made visible:

- **Wrapper classes hide the render context.** Piwigo renders through its own `Template` class
  (`$template->assign()`, 565 calls), not through a `Smarty` receiver, so the API finds no render site. All 52
  `$var->member` accesses therefore stay `untyped_variable` gaps instead of typed references. That is correct
  fail-closed behavior, but it means typed member references need either a typed wrapper or a configuration that
  names the wrapper's assign/display methods. This is not built: one project's wrapper is not evidence for a generic
  contract.
- **Plugins registered outside the analysed tree stay unresolved.** PrestaShop registers `{l s=...}` (568 uses)
  and its other plugins in the core repository, not in the theme. A theme-only checkout yields tag facts but no
  references, as it should. Analysing the theme together with the core resolves them.
- **Dynamic layouts.** `{extends file=$layout}` and `{include file="module:...$var.tpl"}` are reported as
  `dynamic_extends` / `dynamic_include` gaps (PrestaShop: 20 of 22 gaps).

## Mutation side (`agent-edit`, proposed)

Today agent-edit needs nothing new to *apply* a template change: a host can turn template references into a
`source_edit_plan` with `find` anchors (or exact text from the byte ranges) and `expected_source_sha256`. What agent-edit
cannot do yet is *validate* a template or *prove* that every template use was changed. Those need:


- validation of edited templates with the engine (Smarty compile without execution, Twig `Environment::parse()`), using the
  project's configured delimiters and registered plugins. Without a configured engine the result is `unvalidated`,
  never `passed`.
- Map-backed template plans, only once measured demand exists: template move (file + includes + render sites), and
  template-variable rename (the `assign()` key plus every use in the template and its includes).

## Measurement before promotion

Add template tasks to the polyglot evidence corpus with pinned real repositories (PrestaShop for Smarty, a Symfony
application for Twig, a Laravel application for Blade) and reviewed expectations kept away from providers. Compare the
tier 1 dialects with a tier 2 adapter on precision, recall and false absences. Promote template facts into the
`AgentMapIndex` schema only when the adapter clearly beats tier 1.
