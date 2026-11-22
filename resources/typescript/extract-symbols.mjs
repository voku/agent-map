// Syntax-only TypeScript/JavaScript declaration extractor for voku/agent-map.
//
// Protocol: one JSON request on stdin, one JSON response on stdout.
//   request:  {"root": "/abs/project", "files": ["/abs/project/src/a.ts", ...], "typescriptPaths": ["/abs/.../typescript", ...]}
//   response: {"typescriptVersion": "5.9.3", "files": {"/abs/project/src/a.ts": {"ok": true, "symbols": [...]} | {"ok": false, "error": "..."}}}
//
// Only `ts.createSourceFile` is used: no program, no type checker, no module resolution beyond
// relative imports inside the project root. A non-zero exit means the whole batch failed; the
// reason is on stderr.

import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const EXTENSIONS = ['.ts', '.tsx', '.mts', '.cts', '.js', '.jsx', '.mjs', '.cjs'];
const JS_TO_TS = { '.js': ['.ts', '.tsx'], '.jsx': ['.tsx'], '.mjs': ['.mts'], '.cjs': ['.cts'] };

function fail(message, code) {
    process.stderr.write(message + '\n');
    process.exit(code);
}

let request;
try {
    request = JSON.parse(fs.readFileSync(0, 'utf8'));
} catch (error) {
    fail('Invalid extraction request on stdin: ' + error.message, 2);
}

const root = String(request.root ?? '').replace(/\/+$/, '');
const files = Array.isArray(request.files) ? request.files.map(String) : [];
const typescriptPaths = Array.isArray(request.typescriptPaths) ? request.typescriptPaths.map(String) : [];

const require = createRequire(import.meta.url);
let ts = null;
const rejected = [];
for (const candidate of typescriptPaths) {
    try {
        const loaded = require(candidate);
        if (loaded && typeof loaded.createSourceFile === 'function') {
            ts = loaded;
            break;
        }
        rejected.push(candidate + ' (version ' + String(loaded?.version ?? 'unknown') + ' has no createSourceFile compiler API)');
    } catch {
        rejected.push(candidate + ' (not found)');
    }
}
if (ts === null) {
    fail(
        'The "typescript" npm package could not be loaded. Looked in: '
        + (rejected.length > 0 ? rejected.join(', ') : '(no candidates)')
        + '. Install it in the analysed project (npm install --save-dev typescript)'
        + ' or point AGENT_MAP_TYPESCRIPT_PATH at a typescript package directory.',
        3,
    );
}

const SK = ts.SyntaxKind;

function scriptKind(file) {
    switch (path.extname(file).toLowerCase()) {
        case '.tsx': return ts.ScriptKind.TSX;
        case '.jsx': return ts.ScriptKind.JSX;
        case '.js':
        case '.mjs':
        case '.cjs': return ts.ScriptKind.JS;
        default: return ts.ScriptKind.TS;
    }
}

function relative(file) {
    return file.startsWith(root + '/') ? file.slice(root.length + 1) : file;
}

/** @type {Map<string, {source: any, error: string|null}>} */
const parsed = new Map();

function parse(file) {
    let entry = parsed.get(file);
    if (entry !== undefined) {
        return entry;
    }
    try {
        const text = fs.readFileSync(file, 'utf8');
        const source = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, false, scriptKind(file));
        const diagnostics = source.parseDiagnostics ?? [];
        if (diagnostics.length > 0) {
            const first = diagnostics[0];
            const position = source.getLineAndCharacterOfPosition(first.start ?? 0);
            entry = {
                source: null,
                error: 'Syntax error at line ' + (position.line + 1) + ', column ' + (position.character + 1) + ': '
                    + ts.flattenDiagnosticMessageText(first.messageText, ' ')
                    + (diagnostics.length > 1 ? ' (+' + (diagnostics.length - 1) + ' more)' : ''),
            };
        } else {
            entry = { source, error: null };
        }
    } catch (error) {
        entry = { source: null, error: 'Unable to read file: ' + error.message };
    }
    parsed.set(file, entry);

    return entry;
}

function hasModifier(node, kind) {
    return (node.modifiers ?? []).some((modifier) => modifier.kind === kind);
}

function lines(source, node) {
    return {
        line_start: source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1,
        line_end: source.getLineAndCharacterOfPosition(node.end).line + 1,
    };
}

function text(source, node) {
    return node === undefined ? null : node.getText(source).replace(/\s+/g, ' ').trim();
}

function parameters(source, node) {
    return (node.parameters ?? []).map((parameter) => ({
        name: text(source, parameter.name),
        native_type: text(source, parameter.type),
        variadic: parameter.dotDotDotToken !== undefined,
    }));
}

function isFunctionLike(node) {
    return node !== undefined && (node.kind === SK.ArrowFunction || node.kind === SK.FunctionExpression);
}

function memberName(source, name) {
    if (name === undefined) {
        return null;
    }
    if (name.kind === SK.Identifier || name.kind === SK.PrivateIdentifier) {
        return name.getText(source);
    }
    if (name.kind === SK.StringLiteral || name.kind === SK.NoSubstitutionTemplateLiteral || name.kind === SK.NumericLiteral) {
        return name.text;
    }

    return null; // computed names have no static identity
}

function methods(source, declaration) {
    /** @type {Map<string, any>} */
    const byName = new Map();
    for (const member of declaration.members ?? []) {
        let name;
        let signature;
        if (member.kind === SK.Constructor) {
            name = 'constructor';
            signature = member;
        } else if (member.kind === SK.MethodDeclaration || member.kind === SK.MethodSignature) {
            name = memberName(source, member.name);
            signature = member;
        } else if (member.kind === SK.PropertyDeclaration && isFunctionLike(member.initializer)) {
            name = memberName(source, member.name);
            signature = member.initializer;
        } else {
            continue;
        }
        if (name === null) {
            continue;
        }

        const hasBody = signature.body !== undefined;
        const existing = byName.get(name);
        if (existing !== undefined && (existing.hasBody || !hasBody)) {
            continue; // overload signatures: keep the implementation, else the first signature
        }

        let visibility = 'public';
        if (hasModifier(member, SK.PrivateKeyword) || member.name?.kind === SK.PrivateIdentifier) {
            visibility = 'private';
        } else if (hasModifier(member, SK.ProtectedKeyword)) {
            visibility = 'protected';
        }

        byName.set(name, {
            hasBody,
            entry: {
                name,
                visibility,
                ...lines(source, member),
                static: hasModifier(member, SK.StaticKeyword),
                abstract: hasModifier(member, SK.AbstractKeyword),
                parameters: parameters(source, signature),
                native_return_type: text(source, signature.type),
            },
        });
    }

    return [...byName.values()]
        .map((value) => value.entry)
        .sort((left, right) => left.line_start - right.line_start || (left.name < right.name ? -1 : 1));
}

/**
 * Top-level declarations of one parsed file, plus the names its relative imports bind.
 */
function declarations(source) {
    const symbols = [];
    /** @type {Map<string, {specifier: string, name: string|null}>} name === null: namespace import */
    const imports = new Map();
    const exported = new Set();
    let defaultExport = null;

    const push = (kind, name, node, statement, extra = {}) => {
        const attributes = [];
        if (hasModifier(statement, SK.ExportKeyword)) {
            attributes.push(hasModifier(statement, SK.DefaultKeyword) ? 'export default' : 'export');
        }
        symbols.push({ kind, name, ...lines(source, node), attributes, ...extra });
    };

    for (const statement of source.statements) {
        switch (statement.kind) {
            case SK.ImportDeclaration: {
                const specifier = statement.moduleSpecifier?.text;
                const clause = statement.importClause;
                if (typeof specifier !== 'string' || !/^\.\.?\//.test(specifier) || clause === undefined) {
                    break;
                }
                if (clause.name !== undefined) {
                    imports.set(clause.name.text, { specifier, name: 'default' });
                }
                const bindings = clause.namedBindings;
                if (bindings?.kind === SK.NamespaceImport) {
                    imports.set(bindings.name.text, { specifier, name: null });
                } else if (bindings?.kind === SK.NamedImports) {
                    for (const element of bindings.elements) {
                        imports.set(element.name.text, { specifier, name: (element.propertyName ?? element.name).text });
                    }
                }
                break;
            }
            case SK.ClassDeclaration:
            case SK.InterfaceDeclaration: {
                const isClass = statement.kind === SK.ClassDeclaration;
                const heritage = { extends: [], implements: [] };
                for (const clause of statement.heritageClauses ?? []) {
                    const target = clause.token === SK.ImplementsKeyword ? heritage.implements : heritage.extends;
                    for (const type of clause.types) {
                        target.push(text(source, type.expression));
                    }
                }
                push(isClass ? 'class' : 'interface', statement.name?.text ?? 'default', statement, statement, {
                    ...heritage,
                    methods: methods(source, statement),
                });
                break;
            }
            case SK.EnumDeclaration:
                push('enum', statement.name.text, statement, statement);
                break;
            case SK.TypeAliasDeclaration:
                push('type_alias', statement.name.text, statement, statement);
                break;
            case SK.FunctionDeclaration:
                push('function', statement.name?.text ?? 'default', statement, statement, {
                    hasBody: statement.body !== undefined,
                    parameters: parameters(source, statement),
                    native_return_type: text(source, statement.type),
                });
                break;
            case SK.VariableStatement: {
                const list = statement.declarationList;
                if ((list.flags & ts.NodeFlags.Const) === 0) {
                    break;
                }
                for (const declaration of list.declarations) {
                    if (declaration.name.kind !== SK.Identifier) {
                        continue; // destructuring binds several names; out of scope for this slice
                    }
                    const node = list.declarations.length === 1 ? statement : declaration;
                    if (isFunctionLike(declaration.initializer)) {
                        push('function', declaration.name.text, node, statement, {
                            hasBody: true,
                            parameters: parameters(source, declaration.initializer),
                            native_return_type: text(source, declaration.initializer.type),
                        });
                    } else {
                        push('constant', declaration.name.text, node, statement);
                    }
                }
                break;
            }
            case SK.ExportDeclaration:
                if (statement.moduleSpecifier === undefined && statement.exportClause?.kind === SK.NamedExports) {
                    for (const element of statement.exportClause.elements) {
                        const local = (element.propertyName ?? element.name).text;
                        if (element.name.text === 'default') {
                            defaultExport = local;
                        } else {
                            exported.add(local);
                        }
                    }
                }
                break;
            case SK.ExportAssignment:
                if (!statement.isExportEquals && statement.expression.kind === SK.Identifier) {
                    defaultExport = statement.expression.text;
                }
                break;
            default:
                break;
        }
    }

    // One entry per kind and name: function overloads keep the implementation, repeated
    // declarations of one kind (interface merging) keep the first.
    /** @type {Map<string, any>} */
    const unique = new Map();
    for (const symbol of symbols) {
        const key = symbol.kind + ':' + symbol.name;
        const existing = unique.get(key);
        if (existing === undefined || (symbol.kind === 'function' && symbol.hasBody && !existing.hasBody)) {
            unique.set(key, symbol);
        }
    }

    const result = [...unique.values()];
    for (const symbol of result) {
        delete symbol.hasBody;
        if (symbol.attributes.length === 0) {
            if (symbol.name === defaultExport) {
                symbol.attributes.push('export default');
            } else if (exported.has(symbol.name)) {
                symbol.attributes.push('export');
            }
        }
    }
    result.sort((left, right) => left.line_start - right.line_start || (left.kind + left.name < right.kind + right.name ? -1 : 1));

    return { symbols: result, imports };
}

/** @type {Map<string, ReturnType<typeof declarations>|null>} */
const declared = new Map();

function declarationsOf(file) {
    if (!declared.has(file)) {
        const entry = parse(file);
        declared.set(file, entry.source === null ? null : declarations(entry.source));
    }

    return declared.get(file);
}

function isProjectSource(file) {
    if (!file.startsWith(root + '/') || /\.d\.[cm]?ts$/.test(file) || /(^|\/)node_modules\//.test(relative(file))) {
        return false;
    }
    try {
        return fs.statSync(file).isFile();
    } catch {
        return false;
    }
}

function resolveModule(fromFile, specifier) {
    const base = path.resolve(path.dirname(fromFile), specifier);
    const extension = path.extname(base).toLowerCase();
    const candidates = [];
    if (EXTENSIONS.includes(extension)) {
        const stem = base.slice(0, -extension.length);
        for (const replacement of JS_TO_TS[extension] ?? []) {
            candidates.push(stem + replacement);
        }
        candidates.push(base);
    }
    for (const candidate of EXTENSIONS) {
        candidates.push(base + candidate);
    }
    for (const candidate of EXTENSIONS) {
        candidates.push(base + '/index' + candidate);
    }

    return candidates.find(isProjectSource) ?? null;
}

// What a heritage clause can name, in order of preference. A class extends a class; `implements`
// and an interface's `extends` take an interface or a class. A type alias, enum, function or
// constant of the same name is never the target: whether it denotes a class is not provable here.
const CLASS_EXTENDS = ['class'];
const CONTRACTS = ['interface', 'class'];

function pick(symbols, kinds, matches) {
    for (const kind of kinds) {
        const match = symbols.find((symbol) => symbol.kind === kind && matches(symbol));
        if (match !== undefined) {
            return match;
        }
    }

    return undefined;
}

function typeDeclaredIn(file, name, kinds) {
    const found = declarationsOf(file);
    if (found === null || found === undefined) {
        return null;
    }
    const match = name === 'default'
        ? pick(found.symbols, kinds, (symbol) => symbol.attributes.includes('export default'))
        : pick(found.symbols, kinds, (symbol) => symbol.name === name && symbol.attributes.length > 0);

    return match === undefined ? null : relative(file) + '#' + match.name;
}

/**
 * The FQN of a heritage name when its declaration is provable from syntax alone: declared in the
 * same file, or bound by a relative import to a project source file that declares and exports it,
 * with a kind the clause can name (`kinds`).
 * Everything else (packages, re-exports, mixin calls, globals) keeps the name as written.
 */
function resolveHeritage(file, own, written, kinds, self) {
    const direct = /^[A-Za-z_$][\w$]*$/.test(written);
    const qualified = /^([A-Za-z_$][\w$]*)\.([A-Za-z_$][\w$]*)$/.exec(written);
    if (direct) {
        // Any own declaration of the name shadows an import of it, whatever its kind.
        if (own.symbols.some((symbol) => symbol.name === written)) {
            // A declaration naming itself (`class A extends A`) proves nothing: keep the written name.
            return pick(own.symbols, kinds, (symbol) => symbol.name === written && symbol !== self) === undefined
                ? written
                : relative(file) + '#' + written;
        }
        const binding = own.imports.get(written);
        if (binding === undefined || binding.name === null) {
            return written;
        }
        const target = resolveModule(file, binding.specifier);

        return (target === null ? null : typeDeclaredIn(target, binding.name, kinds)) ?? written;
    }
    if (qualified !== null) {
        const binding = own.imports.get(qualified[1]);
        if (binding === undefined || binding.name !== null) {
            return written;
        }
        const target = resolveModule(file, binding.specifier);

        return (target === null ? null : typeDeclaredIn(target, qualified[2], kinds)) ?? written;
    }

    return written;
}

const response = { typescriptVersion: String(ts.version ?? 'unknown'), files: {} };
for (const file of files) {
    const entry = parse(file);
    if (entry.source === null) {
        response.files[file] = { ok: false, error: entry.error };
        continue;
    }

    const own = declarationsOf(file);
    const prefix = relative(file) + '#';
    response.files[file] = {
        ok: true,
        symbols: own.symbols.map((symbol) => ({
            ...symbol,
            fqn: prefix + symbol.name,
            extends: (symbol.extends ?? []).map((written) => resolveHeritage(file, own, written, symbol.kind === 'class' ? CLASS_EXTENDS : CONTRACTS, symbol)),
            implements: (symbol.implements ?? []).map((written) => resolveHeritage(file, own, written, CONTRACTS, symbol)),
        })),
    };
}

process.stdout.write(JSON.stringify(response));
