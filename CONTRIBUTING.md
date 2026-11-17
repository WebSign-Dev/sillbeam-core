# Contributing to Sillbeam

Bug reports, documentation improvements and focused pull requests are welcome. Sillbeam is a modular architecture layer for Symfony; contributions should preserve its integration with native Symfony services, routing and runtime behavior.

## Report a bug or propose a change

Search [existing issues](https://github.com/WebSign-Dev/sillbeam-core/issues) before opening a new report. Include:

- The Sillbeam commit or version, PHP version and Symfony version.
- A minimal reproduction, relevant configuration and module or Project layout.
- Expected behavior, actual behavior and the complete relevant error message.
- Architecture diagnostic output when the container can boot.

Remove credentials and private application data from shared configuration and logs. For larger features or changes to the public API, open an issue to discuss the intended behavior before implementation. The [release notes](docs/releases/0.4.0.md) describe the current scope and limitations. Report security vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Set up a development checkout

Use PHP 8.4 or newer, Composer and the PHP extensions required by the locked dependencies. Enable `pdo_sqlite` for the persistence integration tests. PHP 8.4 is the minimum supported version; check compatibility when developing on a newer runtime.

```bash
git clone https://github.com/WebSign-Dev/sillbeam-core.git
cd sillbeam-core
git switch dev-v0.4.x
composer install
```

If contributing through a fork, clone your fork instead. The current development
line is `dev-v0.4.x`; base focused work on that line and follow the integration flow
below. There is no automatic CI push run on this development branch.

The six example application variants and the main fixtures use the root Composer autoloader. You do not need to start an external runtime to run the root test suite. The separate consumer under `apps/test-app` has its own dependencies and runtime; see the [test-app guide](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/test-apps.md).

## Make a focused change

- Keep changes tied to one problem or feature and explain the resulting behavior.
- Follow the existing PSR-4 layout, strict typing and code style. Production classes use the `Sillbeam\Core\` namespace.
- Add regression coverage for bug fixes and meaningful tests for changed behavior. Use unit tests for discovery and dependency rules, and integration tests for container, routing or persistence behavior.
- Preserve conventional Symfony applications, module-only applications, optional Projects and operation without Doctrine where relevant.
- Update the guides, configuration reference and public API documentation when behavior changes. Explain breaking changes in the [migration guide](docs/migration/modular-monolith.md).
- Avoid unrelated dependency upgrades and generated cache or vendor files. Update Composer lockfiles when dependency changes require it.

The [architecture overview](docs/architecture/overview.md) explains ownership and compilation. The [public API reference](docs/reference/public-api.md) describes the supported attributes and runtime interfaces.

## Distribution contents

The root `.gitattributes` is the single export policy for Composer and Git archives;
there is no separate `composer.json` archive filter. It permits runtime `src/`,
Composer metadata, license/support/contribution files and the handbook's user-facing
architecture, asset, example, guide, migration, module, operations, reference and
release-note directories. Unlisted paths and hidden placeholders are excluded. Logs, raw measurement streams,
temporary files, backups, caches and generated `reference.php` helpers are also
excluded inside public directories.

`docs/development/` (acceptance logs, raw measurements, environment/results JSON,
verification reports and helper scripts), `docs/superpowers/`, empty documentation
scaffolding, tests, tools, example applications, skeleton/recipe sources, vendor,
local caches and generated output stay in Git/development checkouts and are not
part of the distributable package. Source archives do not replace a development
checkout. Public links to excluded evidence/recipe sources use immutable repository
commit URLs; links to packaged guides, schemas and images remain relative.

Run `composer test:release-package` to build and inspect the real ZIP, check all
packaged relative Markdown/image links and byte-identical runtime source, install
that ZIP without development dependencies, and execute the packaged Quickstart.
It also verifies that current default SVG export and module inspection work from
the installed package. `composer test:consumer` checks the supported layouts with
and without Doctrine. No release or tag is created by these manual test commands.

## Run the checks

From the repository root:

```bash
composer validate --strict
composer test
composer analyse
composer architecture
composer cs:check
composer test:consumer
composer test:release-package
composer test:flex-recipe
SILLBEAM_TEST_DOCTRINE=1 composer test:flex-recipe
```

| Check | What it verifies |
| :--- | :--- |
| `validate --strict` | Composer metadata and lockfile consistency. |
| `test` | Unit and integration behavior, including application layouts and persistence. |
| `analyse` | PHPStan static analysis. |
| `architecture` | Deptrac rules for the library's own dependency layers. |
| `cs:check` | The repository's PHP CS Fixer rules. |
| `test:consumer` | Independent installations of all six layouts, both kernels, with/without Doctrine. |
| `test:release-package` | Actual Composer ZIP installed in a separate manual consumer, including the documented Hello example. |
| `test:flex-recipe` | Recipe installation/removal and both consumer kernels; the environment flag also checks Doctrine. |

The Flex check needs Composer network access and permission to open a local HTTP port. Report an environment limitation explicitly if it prevents a check from running.

To apply the configured formatting rules:

```bash
php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-unsupported-php-version=yes --sequential
```

Review the resulting diff and rerun the affected checks. The [historical testing notes](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/testing.md) record earlier checks; the branch policy below and the [CI workflow](https://github.com/WebSign-Dev/sillbeam-core/blob/main/.github/workflows/ci.yml) define current triggering and the PHP/Symfony compatibility matrix. Local success on one combination does not establish that every matrix job passes.

## Code documentation and API reference

Write PHPDoc in English. Describe the contract and its reason, rather than repeating
native types or trivial method bodies. Document non-obvious parameters, return
semantics, expected exceptions, filesystem/container/output side effects and important
architecture constraints. Keep PHPStan-compatible collection types, array shapes and
`class-string` annotations; do not weaken them for the renderer.

Mark supported entry points, contracts and their input/output data classes with `@api`.
The [public API reference](docs/reference/public-api.md) defines this boundary.
Consumers should inject registry/context interfaces; their concrete services, discovery,
compiler, AST index, loaders, diagnostic presentation and Symfony integration helpers
are `@internal`. Public PHP visibility alone does not make a service supported API.
Inherited interface contracts need not be repeated on trivial implementations. Document
meaningful overrides and keep new internal declarations explicitly marked.

Build the navigable HTML reference from the repository root:

```bash
composer install
composer docs:build
```

Open `build/docs/api/index.html` in a browser. `phpdoc.dist.xml` scans `src/`.
The build renders public
and protected members of non-internal types; `@internal` classes/members are omitted.
Check that changed API contracts appear, their parameter/return types and exceptions
are readable, navigation links work, and internal service pages are absent.

phpDocumentor 3 is a development-only PHAR installed through `phpdocumentor/shim`.
Composer explicitly allows only `phpdocumentor/shim`; do not use a wildcard plugin
allowance. Its `phar-io/composer-distributor` dependency is a library and needs no
plugin allowance. The installer downloads
and verifies the upstream PHAR signature. The PHAR isolates documentation dependencies
from the application's Symfony constraints. Composer platform checks cover the installer,
not everything bundled inside the PHAR: run `vendor/bin/phpdoc --version` and the real
build as well. The installed 3.10 PHAR requires PHP `^8.1` (covered by this project's
PHP `^8.4` floor), DOM/libxml, SimpleXML/XML/XMLWriter, fileinfo, iconv, tokenizer,
Phar, zlib and the core date/filter/hash/JSON extensions. Keep mbstring enabled as
recommended upstream. The bundled Symfony components conflict with `ext-psr`; the
PHAR dependency isolation does not isolate PHP extensions. GnuPG must be available
for the installer's signature verification. Diagrams are optional and not required
for this HTML build. See the upstream
[installation requirements](https://docs.phpdoc.org/guide/getting-started/installing.html) and
[configuration reference](https://docs.phpdoc.org/guide/references/configuration.html).
No documentation tool belongs in `require` or application boot code.

Generated HTML (`build/docs/`), parser caches (`.phpdoc/`) and local overrides
(`phpdoc.xml`) are ignored. The Composer script explicitly uses the distribution
configuration for reproducibility. When changing visibility or removing API elements,
delete the generated `build/docs/api/` directory before rebuilding: phpDocumentor does
not remove obsolete HTML files. Run `composer test`, `composer analyse` and
`composer cs:check` after PHPDoc changes; annotations are inputs to static analysis.

### Export English API JSON

Run `composer docs:export-json` to write `build/docs/api/sillbeam-core.en.json`.
The [versioned schema and field guide](docs/reference/phpdoc-json.md) explain
plain descriptions, original method signatures, code examples, stable identities,
public/internal filtering and phpDocumentor 3.10 limitations. The JSON-only Twig
transformation uses existing descriptors and a separate ignored cache; it does not
consume HTML or add another AST parser. Export twice after changes and compare
bytes. Keep the HTML build working and test both paths. This is a manual development
command, with no translation, website integration, release hook or new CI trigger.

## Submit a pull request

Target **`dev`** when integrating the `dev-v0.4.x` development line. Describe:

- The concrete problem and the behavior after the change.
- Relevant reproduction steps or examples.
- Checks you ran, their results and any checks you could not run.
- Compatibility implications, configuration changes or migration steps.

Keep commits in the existing Conventional Commit style (`feat:`, `fix:`, `docs:`,
`chore:`, `ci:`), with a focused diff and user documentation alongside changes.
Maintainers may request revisions before integration.

The required promotion sequence is:

1. Work on `dev-v0.4.x` and open the integration PR to `dev`.
2. An authorized integration/push to `dev` runs automatic CI; verify its results.
3. Obtain maintainer approval before promoting the checked integration to `main`.
4. An authorized integration/push to `main` runs automatic CI again.
5. A release requires separate, explicit maintainer authorization after that check.

The existing workflow has push triggers **only for `main` and `dev`**, plus manual
`workflow_dispatch`. It has no PR trigger and no automatic development-branch run;
a PR alone does not supply a pre-merge CI result. Branch protection/review settings
are managed separately. Neither green CI nor this guide authorizes a push, merge,
tag, publication or release automatically.

## License

Sillbeam is licensed under the [MIT License](LICENSE). By submitting a contribution, you agree that your contribution may be distributed under that license. Only contribute material you have the right to license this way.
