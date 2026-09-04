# Third-party notices

## Rector

Selected refactoring behavior and regression scenarios in this repository may be adapted from Rector.

Upstream: `rectorphp/rector` and `rectorphp/rector-src`

Pinned distribution commit used for the method-rename hardening work: `29ac8eb5d206c9d62486c9e8ff018b27f94f34ce`

Pinned source commit referenced by that distribution: `83496b5d65035792e7b8eea3bb083e8f3b16bec0`

Class-constant rename behavior was additionally adapted from
`rules/Renaming/Rector/ClassConstFetch/RenameClassConstFetchRector.php` at upstream commit
`f92750937f4744876ad0cfd5a1ef9d7010b50020`.

Unused-private-method removal planning and its trait, magic-method, magic-dispatch, dynamic-call,
and class-string static-call safety boundaries were adapted from
`rules/DeadCode/Rector/ClassMethod/RemoveUnusedPrivateMethodRector.php` at upstream commit
`2ac92445a4bbef7c21bce2d48dae756a64516c02`. Agent-map adapts that mutating behavior into a
read-only, PHPStan-backed exact-edit contract.

Unused-private-class-constant removal behavior, especially its private visibility and
single-declaration safety rules, was adapted from
`rules/DeadCode/Rector/ClassConst/RemoveUnusedPrivateClassConstantRector.php` at upstream commit
`cd3ec48e1209436d03d9c67d47c51ac4972a20cc`. Agent-map adds hash guarding, whole-project AST fetch
evidence, explicit uncertainty, and a read-only plan boundary.

### MIT License

The MIT License
---------------

Copyright (c) 2017-present Tomáš Votruba (https://tomasvotruba.cz)

Permission is hereby granted, free of charge, to any person
obtaining a copy of this software and associated documentation
files (the "Software"), to deal in the Software without
restriction, including without limitation the rights to use,
copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the
Software is furnished to do so, subject to the following
conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
OTHER DEALINGS IN THE SOFTWARE.
