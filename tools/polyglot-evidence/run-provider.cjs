'use strict';

const fs = require('fs');
const path = require('path');
const { performance } = require('perf_hooks');
const { spawnSync } = require('child_process');

function parseOptions(argv) {
  const out = {};
  for (const arg of argv.slice(2)) {
    if (!arg.startsWith('--') || !arg.includes('=')) throw new Error(`Expected --name=value, got: ${arg}`);
    const eq = arg.indexOf('=');
    const key = arg.slice(2, eq);
    const value = arg.slice(eq + 1);
    if (!key || !value) throw new Error(`Expected non-empty --name=value, got: ${arg}`);
    out[key] = value;
  }
  return out;
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function writeJson(file, value) {
  ensureDir(path.dirname(file));
  fs.writeFileSync(file, JSON.stringify(value, null, 2) + '\n');
}

function normalizePath(value) {
  return value.split(path.sep).join('/').replace(/^\.\//, '');
}

function appendLog(file, line) {
  ensureDir(path.dirname(file));
  fs.appendFileSync(file, line.endsWith('\n') ? line : line + '\n');
}

function run(command, args, options = {}) {
  const started = performance.now();
  appendLog(options.log, `$ ${[command, ...args].join(' ')}`);
  const proc = spawnSync(command, args, {
    cwd: options.cwd,
    encoding: 'utf8',
    maxBuffer: 128 * 1024 * 1024,
    env: { ...process.env, ...(options.env || {}) },
    timeout: options.timeout || 10 * 60 * 1000,
  });
  const elapsedMs = performance.now() - started;
  appendLog(options.log, proc.stdout || '');
  appendLog(options.log, proc.stderr || '');
  appendLog(options.log, `[exit=${proc.status} elapsed_ms=${elapsedMs.toFixed(1)}]`);
  if (proc.error) throw proc.error;
  if (proc.status !== 0) {
    throw new Error(`${command} failed with exit ${proc.status}: ${(proc.stderr || proc.stdout || '').trim()}`);
  }
  return { stdout: proc.stdout || '', stderr: proc.stderr || '', elapsedMs };
}

function commandVersion(command, args, log) {
  const result = run(command, args, { log, timeout: 60 * 1000 });
  return (result.stdout || result.stderr).trim().split(/\r?\n/)[0] || 'unknown';
}

function repoInfo(manifest, id) {
  const row = manifest.repositories && manifest.repositories[id];
  if (!row || typeof row.path !== 'string' || typeof row.language !== 'string') {
    throw new Error(`Repository ${id} is missing from manifest`);
  }
  return row;
}

function walkFiles(root, language) {
  const extensions = language === 'python'
    ? new Set(['.py'])
    : new Set(['.js', '.cjs', '.mjs', '.jsx', '.ts', '.tsx']);
  const excluded = new Set(['.git', 'node_modules', 'vendor', 'dist', 'build', 'coverage', '.context', '.agent-map-polyglot']);
  const out = [];
  const stack = [root];
  while (stack.length) {
    const dir = stack.pop();
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (excluded.has(entry.name)) continue;
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) stack.push(full);
      else if (entry.isFile() && extensions.has(path.extname(entry.name))) out.push(full);
    }
  }
  out.sort();
  return out;
}

function directoryBytes(root) {
  if (!fs.existsSync(root)) return 0;
  let total = 0;
  const stack = [root];
  while (stack.length) {
    const current = stack.pop();
    const stat = fs.statSync(current);
    if (stat.isFile()) total += stat.size;
    else if (stat.isDirectory()) {
      for (const entry of fs.readdirSync(current)) stack.push(path.join(current, entry));
    }
  }
  return total;
}

function rankByTermHits(repoRoot, terms, log) {
  const scores = new Map();
  for (const term of terms) {
    const result = run('rg', ['--files-with-matches', '--fixed-strings', '--glob', '!.git/**', '--glob', '!node_modules/**', term, '.'], {
      cwd: repoRoot,
      log,
      timeout: 2 * 60 * 1000,
    });
    for (const line of result.stdout.split(/\r?\n/).filter(Boolean)) {
      const rel = normalizePath(line);
      scores.set(rel, (scores.get(rel) || 0) + 1);
    }
  }
  return [...scores.entries()]
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
    .map(([file]) => file);
}

function parseJsonOutput(text) {
  const trimmed = text.trim();
  try { return JSON.parse(trimmed); } catch (_) {}
  const start = trimmed.indexOf('{');
  const end = trimmed.lastIndexOf('}');
  if (start >= 0 && end > start) return JSON.parse(trimmed.slice(start, end + 1));
  throw new Error('Command did not emit a JSON object');
}

function sigmapRankedFiles(repoRoot, payload) {
  const rows = Array.isArray(payload.rankedFiles) ? payload.rankedFiles : [];
  const files = [];
  for (const row of rows) {
    if (typeof row === 'string') files.push(normalizePath(row));
    else if (row && typeof row === 'object') {
      const value = row.path || row.file || row.filename || row.relativePath;
      if (typeof value === 'string' && value) files.push(normalizePath(value));
    }
  }
  if (files.length) return [...new Set(files)];

  if (typeof payload.contextPath !== 'string' || !payload.contextPath) return [];
  const contextPath = path.resolve(repoRoot, payload.contextPath);
  if (!fs.existsSync(contextPath)) return [];
  for (const line of fs.readFileSync(contextPath, 'utf8').split(/\r?\n/)) {
    const match = line.match(/^###\s+(\S+)\s*$/);
    if (match) files.push(normalizePath(match[1]));
  }
  return [...new Set(files)];
}

function declarationName(node) {
  if (!node || typeof node.childForFieldName !== 'function') return null;
  const named = node.childForFieldName('name');
  return named && typeof named.text === 'string' ? named.text : null;
}

function collectTreeDeclarations(repoRoot, language, log) {
  const Parser = require('tree-sitter');
  const grammar = language === 'python' ? require('tree-sitter-python') : require('tree-sitter-javascript');
  const parser = new Parser();
  parser.setLanguage(grammar);
  const wantedTypes = new Set(language === 'python'
    ? ['class_definition', 'function_definition']
    : ['class_declaration', 'function_declaration', 'method_definition']);
  const byName = new Map();

  for (const file of walkFiles(repoRoot, language)) {
    const source = fs.readFileSync(file, 'utf8');
    const tree = parser.parse(source);
    const stack = [tree.rootNode];
    while (stack.length) {
      const node = stack.pop();
      if (wantedTypes.has(node.type)) {
        const name = declarationName(node);
        if (name) {
          const rel = normalizePath(path.relative(repoRoot, file));
          const set = byName.get(name) || new Set();
          set.add(rel);
          byName.set(name, set);
        }
      }
      for (let i = node.namedChildCount - 1; i >= 0; --i) stack.push(node.namedChild(i));
    }
  }
  appendLog(log, `tree-sitter declarations=${[...byName.values()].reduce((n, set) => n + set.size, 0)}`);
  return byName;
}

function matchesScipName(symbol, name) {
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`(?:^|[/ .])${escaped}(?:#|\\(\\)\\.|\\.|$)`).test(symbol);
}

function scipDefinitionIndex(payload) {
  const byName = new Map();
  const definitionPathBySymbol = new Map();
  const documents = Array.isArray(payload.documents) ? payload.documents : [];
  for (const doc of documents) {
    const rel = normalizePath(doc.relativePath || '');
    for (const occ of Array.isArray(doc.occurrences) ? doc.occurrences : []) {
      if ((Number(occ.symbolRoles || 0) & 1) !== 1 || typeof occ.symbol !== 'string') continue;
      definitionPathBySymbol.set(occ.symbol, rel);
    }
  }
  for (const [symbol, rel] of definitionPathBySymbol.entries()) {
    const descriptors = symbol.split(/[/ .]/).filter(Boolean);
    for (const descriptor of descriptors) {
      const name = descriptor.replace(/[#`()\[\]]/g, '').replace(/\(\)$/, '');
      if (!name) continue;
      const set = byName.get(name) || new Set();
      set.add(rel);
      byName.set(name, set);
    }
  }
  return { byName, definitionPathBySymbol, documents };
}

function symbolsForName(definitionPathBySymbol, name) {
  return [...definitionPathBySymbol.keys()].filter((symbol) => matchesScipName(symbol, name));
}

function scipRelation(index, sourceName, targetName) {
  const sourceSymbols = symbolsForName(index.definitionPathBySymbol, sourceName);
  const targetSymbols = new Set(symbolsForName(index.definitionPathBySymbol, targetName));
  const relations = [];
  const paths = new Set();
  for (const sourceSymbol of sourceSymbols) {
    const sourcePath = index.definitionPathBySymbol.get(sourceSymbol);
    if (!sourcePath) continue;
    paths.add(sourcePath);
    const doc = index.documents.find((candidate) => normalizePath(candidate.relativePath || '') === sourcePath);
    if (!doc) continue;

    for (const occ of Array.isArray(doc.occurrences) ? doc.occurrences : []) {
      if (typeof occ.symbol === 'string' && targetSymbols.has(occ.symbol) && (Number(occ.symbolRoles || 0) & 1) !== 1) {
        const targetPath = index.definitionPathBySymbol.get(occ.symbol);
        if (targetPath) {
          paths.add(targetPath);
          relations.push(`${sourcePath}->${targetPath}`);
        }
      }
    }

    const infos = [
      ...(Array.isArray(doc.symbols) ? doc.symbols : []),
      ...(Array.isArray(index.externalSymbols) ? index.externalSymbols : []),
    ];
    for (const info of infos) {
      if (info.symbol !== sourceSymbol) continue;
      for (const relation of Array.isArray(info.relationships) ? info.relationships : []) {
        if (typeof relation.symbol !== 'string' || !targetSymbols.has(relation.symbol)) continue;
        if (!(relation.isReference || relation.isImplementation || relation.isTypeDefinition)) continue;
        const targetPath = index.definitionPathBySymbol.get(relation.symbol);
        if (targetPath) {
          paths.add(targetPath);
          relations.push(`${sourcePath}->${targetPath}`);
        }
      }
    }
  }
  return { paths: [...paths].sort(), relations: [...new Set(relations)].sort() };
}

function scipIndexerArgs(repo, outputRel) {
  if (repo.language === 'python') {
    return {
      command: 'scip-python',
      args: ['index', '--cwd', repo.path, '--project-name', path.basename(repo.path), '--output', outputRel, '--quiet'],
      cwd: repo.path,
    };
  }
  return {
    command: 'scip-typescript',
    args: ['index', '--infer-tsconfig', '--output', outputRel],
    cwd: repo.path,
  };
}

function buildScipIndexes(manifest, log) {
  const indexes = new Map();
  let cold = 0;
  let warm = 0;
  let bytes = 0;
  let materialize = 0;
  for (const [id, repo] of Object.entries(manifest.repositories || {})) {
    const derivedDir = path.join(repo.path, '.agent-map-polyglot');
    fs.rmSync(derivedDir, { recursive: true, force: true });
    fs.mkdirSync(derivedDir, { recursive: true });
    const outputRel = '.agent-map-polyglot/index.scip';
    const output = path.join(repo.path, outputRel);
    const spec = scipIndexerArgs(repo, outputRel);
    cold += run(spec.command, spec.args, { cwd: spec.cwd, log, timeout: 10 * 60 * 1000 }).elapsedMs;
    warm += run(spec.command, spec.args, { cwd: spec.cwd, log, timeout: 10 * 60 * 1000 }).elapsedMs;
    bytes += fs.statSync(output).size;
    const printed = run('scip', ['print', '--json', output], { cwd: repo.path, log, timeout: 5 * 60 * 1000 });
    materialize += printed.elapsedMs;
    indexes.set(id, parseJsonOutput(printed.stdout));
  }
  return { indexes, metadata: { cold_index_ms: cold, warm_index_ms: warm, index_bytes: bytes, materialize_ms: materialize } };
}

function buildTreeIndexes(manifest, log) {
  const indexes = new Map();
  let cold = 0;
  let warm = 0;
  for (const [id, repo] of Object.entries(manifest.repositories || {})) {
    let started = performance.now();
    collectTreeDeclarations(repo.path, repo.language, log);
    cold += performance.now() - started;
    started = performance.now();
    const index = collectTreeDeclarations(repo.path, repo.language, log);
    warm += performance.now() - started;
    indexes.set(id, index);
  }
  return { indexes, metadata: { cold_index_ms: cold, warm_index_ms: warm, index_bytes: 0 } };
}

function prepareSigmap(manifest, log) {
  let cold = 0;
  let warm = 0;
  let bytes = 0;
  for (const repo of Object.values(manifest.repositories || {})) {
    fs.rmSync(path.join(repo.path, '.context'), { recursive: true, force: true });
    cold += run('sigmap', [], { cwd: repo.path, log, timeout: 5 * 60 * 1000 }).elapsedMs;
    warm += run('sigmap', [], { cwd: repo.path, log, timeout: 5 * 60 * 1000 }).elapsedMs;
    bytes += directoryBytes(path.join(repo.path, '.context'));
  }
  return { cold_index_ms: cold, warm_index_ms: warm, index_bytes: bytes };
}

function taskResult(task, status, paths, relations, elapsedMs) {
  return { id: task.id, status, paths, relations, elapsed_ms: elapsedMs };
}

function main() {
  const options = parseOptions(process.argv);
  const provider = options.provider;
  const corpusPath = options.corpus;
  const repositoriesPath = options.repositories;
  const outPath = options.out;
  const logPath = options.log;
  if (!provider || !corpusPath || !repositoriesPath || !outPath || !logPath) {
    throw new Error('Usage: --provider=<name> --corpus=<corpus.json> --repositories=<repositories.json> --out=<result.json> --log=<log>');
  }
  fs.writeFileSync(logPath, '');
  const corpus = readJson(corpusPath);
  const manifest = readJson(repositoriesPath);
  if (corpus.schema !== 'agent-map-polyglot-corpus@1') throw new Error('Unsupported corpus schema');
  if (manifest.schema !== 'agent-map-polyglot-repositories@1') throw new Error('Unsupported repository manifest schema');

  let version = 'unknown';
  let metadata = { cold_index_ms: 0, warm_index_ms: 0, index_bytes: 0 };
  let treeIndexes = null;
  let scipIndexes = null;

  if (provider === 'rg') {
    version = commandVersion('rg', ['--version'], logPath);
  } else if (provider === 'sigmap') {
    version = commandVersion('sigmap', ['--version'], logPath);
    metadata = prepareSigmap(manifest, logPath);
  } else if (provider === 'tree-sitter') {
    version = `tree-sitter@${require('tree-sitter/package.json').version}`;
    const built = buildTreeIndexes(manifest, logPath);
    treeIndexes = built.indexes;
    metadata = built.metadata;
  } else if (provider === 'scip') {
    version = [
      commandVersion('scip', ['--version'], logPath),
      commandVersion('scip-typescript', ['--version'], logPath),
      commandVersion('scip-python', ['--version'], logPath),
    ].join(' | ');
    const built = buildScipIndexes(manifest, logPath);
    scipIndexes = built.indexes;
    metadata = built.metadata;
  } else {
    throw new Error(`Unsupported provider: ${provider}`);
  }

  const results = [];
  for (const task of corpus.tasks || []) {
    const repo = repoInfo(manifest, task.repository);
    const probe = task.probe || {};
    const started = performance.now();
    try {
      if (task.kind === 'relation' && provider !== 'scip') {
        results.push(taskResult(task, 'unavailable', [], [], performance.now() - started));
        continue;
      }

      if (provider === 'rg') {
        const terms = Array.isArray(probe.text_terms) ? probe.text_terms : [];
        if (!terms.length) {
          results.push(taskResult(task, 'unavailable', [], [], performance.now() - started));
          continue;
        }
        const paths = rankByTermHits(repo.path, terms, logPath);
        results.push(taskResult(task, paths.length ? 'answered' : 'not_found', paths, [], performance.now() - started));
      } else if (provider === 'sigmap') {
        if (typeof task.question !== 'string' || !task.question) {
          results.push(taskResult(task, 'unavailable', [], [], performance.now() - started));
          continue;
        }
        const asked = run('sigmap', ['ask', task.question, '--json', '--no-squeeze'], { cwd: repo.path, log: logPath, timeout: 2 * 60 * 1000 });
        const paths = sigmapRankedFiles(repo.path, parseJsonOutput(asked.stdout));
        results.push(taskResult(task, paths.length ? 'answered' : 'not_found', paths, [], performance.now() - started));
      } else if (provider === 'tree-sitter') {
        const symbol = typeof probe.symbol === 'string' ? probe.symbol : null;
        if (!symbol) {
          results.push(taskResult(task, 'unavailable', [], [], performance.now() - started));
          continue;
        }
        const paths = [...(treeIndexes.get(task.repository).get(symbol) || new Set())].sort();
        results.push(taskResult(task, paths.length ? 'answered' : 'not_found', paths, [], performance.now() - started));
      } else if (provider === 'scip') {
        const index = scipDefinitionIndex(scipIndexes.get(task.repository));
        if (task.kind === 'relation') {
          const source = probe.source_symbol;
          const target = probe.target_symbol;
          if (typeof source !== 'string' || typeof target !== 'string') {
            results.push(taskResult(task, 'unavailable', [], [], performance.now() - started));
            continue;
          }
          const relation = scipRelation(index, source, target);
          results.push(taskResult(task, relation.relations.length ? 'answered' : 'not_found', relation.paths, relation.relations, performance.now() - started));
        } else {
          const symbol = probe.symbol;
          if (typeof symbol !== 'string' || !symbol) {
            results.push(taskResult(task, 'unavailable', [], [], performance.now() - started));
            continue;
          }
          const paths = [...(index.byName.get(symbol) || new Set())].sort();
          results.push(taskResult(task, paths.length ? 'answered' : 'not_found', paths, [], performance.now() - started));
        }
      }
    } catch (error) {
      appendLog(logPath, `[task ${task.id}] ${error.stack || error.message}`);
      results.push(taskResult(task, 'error', [], [], performance.now() - started));
    }
  }

  writeJson(outPath, {
    schema: 'agent-map-polyglot-provider-result@1',
    provider,
    version,
    metadata,
    tasks: results,
  });
}

try {
  main();
} catch (error) {
  process.stderr.write((error.stack || error.message || String(error)) + '\n');
  process.exit(1);
}
