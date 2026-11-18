# Template engine evidence

Status: design note plus a first, shipped slice (pluggable dialects for residue scanning). Nothing here changes plan
status, edits, blockers or blind spots: template evidence stays residue until a stronger tier is measured.

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

## Tier 2: parser-backed template facts (proposed)

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

## Tier 3: typed render context (proposed)

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

## Mutation side (`agent-edit`, proposed)

- Validate edited templates with the engine (Smarty compile without execution, Twig `Environment::parse()`), using the
  project's configured delimiters and registered plugins. Without a configured engine the result is `unvalidated`,
  never `passed`.
- Map-backed template plans only once measured demand exists: template move (file + includes + render sites), and
  template-variable rename (the `assign()` key plus every use in the template and its includes).

## Measurement before promotion

Add template tasks to the polyglot evidence corpus with pinned real repositories (PrestaShop for Smarty, a Symfony
application for Twig, a Laravel application for Blade) and reviewed expectations kept away from providers. Compare the
tier 1 dialects with a tier 2 adapter on precision, recall and false absences. Promote template facts into the
`AgentMapIndex` schema only when the adapter clearly beats tier 1.
