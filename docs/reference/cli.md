# CLI and architecture reporting (0.3.0)

```bash
php bin/console sillbeam:architecture
php bin/console sillbeam:architecture --format=json
php bin/console sillbeam:architecture -v
php bin/console sillbeam:graph
php bin/console sillbeam:graph --module=Billing --output=billing.svg
php bin/console sillbeam:graph --format=mermaid > architecture.mmd
php bin/console sillbeam:report
php bin/console sillbeam:report --output=docs/architecture-report.md
php bin/console sillbeam:architecture --env=prod --no-debug --format=json
php bin/console sillbeam:about
```

## Enable reporting before container compilation

In your application's existing `bin/console`, replace the Console Application import:

```php
use Sillbeam\Core\Console\Application;
// replaces: use Symfony\Bundle\FrameworkBundle\Console\Application;
```

Keep your existing Composer/Symfony Runtime bootstrap and kernel construction. For example, the existing runtime callback remains:

```php
return function (array $context) {
    $kernel = new App\Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);

    return new Application($kernel);
};
```

This adapter runs the exact `sillbeam:architecture`, `sillbeam:report`, `sillbeam:inspect:module`, `sillbeam:graph` and `sillbeam:doctor` commands before kernel boot; it also supports retrieving these exact commands through `find()`/`get()`. Other commands, including `list`, delegate to Symfony and retain their normal boot behavior. Use the full command name when reporting a broken application. The package's test-app entry point demonstrates this import.

Without this import, the command remains available through the normal Symfony application when its container compiles. Symfony's normal console cannot discover container services when boot fails; the adapter is needed for that case. No additional executable, configuration file or dependency is required.

## Effective configuration

Architecture reporting, module inspection and graph export prepare an uncompiled builder through the actual kernel's configuration hooks. Symfony's own loaders read its YAML/PHP/XML imports, environment-specific configuration and parameters. Composer PSR-4 mappings determine discovery namespaces through the same resolver used by validation. Bundle `prepend()` configuration is included, and Sillbeam's existing Doctrine/late-prepend consistency requirement is enforced.

Doctor instead uses the static file inspection described below.

The analyzer and configuration processor are exactly those used by container validation. Reporting does not boot bundles, run service autowiring/optimization or require compiled registries. It can therefore report a valid architecture even when unrelated service definitions cannot compile. An unreadable/invalid configuration, missing bundle, unsupported kernel preparation, unexpected execution error or fatal analysis blocker returns code 2. An already broken configuration-loading hook cannot be bypassed by guessing its effective configuration.

The existing bootstrap selects the kernel, environment and debug mode as usual, including any Symfony Runtime/Dotenv setup. Reporting does not scan for a second configuration file, load another `.env` interpretation or assume an `App\Kernel` class. Custom kernels must support Symfony's uncompiled `initializeBundles`/`buildContainer` preparation hooks; this integration is tested on Symfony 7.4 and 8.1.

## Locale configuration

Core uses English (`en`) by default and also supports German (`de`). The
effective language for human-facing commands is resolved in this order.

1. An explicit `--locale=en` or `--locale=de`.
2. The current user's saved preference.
3. The project's `sillbeam.locale` setting.
4. English.

Set the project default in your existing Symfony configuration.

```yaml
sillbeam:
    locale: de
```

Use the Core console adapter to manage your personal preference before container
boot, or to override the language for one command or its help.

```bash
php bin/console sillbeam:locale
php bin/console sillbeam:locale --set=de
php bin/console sillbeam:locale --reset
php bin/console sillbeam:architecture --locale=de
php bin/console sillbeam:make:module --help --locale=de
```

The personal preference lives in `sillbeam/locale` under the user configuration
directory and applies across projects.

| Platform | User configuration directory |
| --- | --- |
| Linux | `$XDG_CONFIG_HOME` or `$HOME/.config` |
| macOS | `$HOME/Library/Application Support` |
| Windows | `%APPDATA%` or `%LOCALAPPDATA%` |

An unsupported explicit locale is a usage error. A corrupt or unreadable
preference reports its file path when read. `--set` and `--reset` can repair
the preference. The resolver uses English for preboot diagnostics when project
configuration cannot be read and no higher-priority locale is available.
Before boot, Doctor reads literal locale declarations from conventional Symfony
YAML files. This preserves its static inspection of projects with dynamic
configuration.

Architecture and Doctor JSON v1 keep their existing status values, fields and
English messages. Human-facing text, Markdown and visible graph labels use the
effective CLI locale. The graph syntax and descriptor identities stay stable.

`sillbeam:api --locale` selects the Live API document language and keeps its
existing contract. Live defaults to English independently of CLI preferences.
Analysis includes both languages and rejects a locale selection. The
capabilities and source-inventory JSON are locale-independent. The CLI preference
can still localize the help and human-readable errors of the JSON commands.

## Output and exit codes

| Exit | Meaning |
| --- | --- |
| 0 | Architecture is valid; this is not a service-container health check |
| 1 | Analysis completed and found architecture violations |
| 2 | Analysis incomplete/unexecutable, configuration or command usage invalid, or report file could not be written |

Text output follows the Core CLI prototype 1.2. A valid architecture gets a short
success status. One diagnosis is explained fully; multiple diagnoses show the
complete compact index and the first diagnosis in full. Findings and diagnoses
are counted separately. Group numbers identify only this check run.

```bash
php bin/console sillbeam:architecture --all
php bin/console sillbeam:architecture --issue=2
php bin/console sillbeam:architecture --issue=2 -v
php bin/console sillbeam:architecture --couplings
```

`--all` explains every group. `--issue=N` selects one group and includes all its
original findings. `-v` adds rule codes, original messages, context and full source
paths. Default locations are readable relative paths. No module table or
Application couplings appear by default; `--couplings` explicitly includes the
informational observations separately from violations.

Terminal width is detected automatically and the visual width is clamped to
52–90 columns. Long text wraps by Unicode display width. Source and exception
text is formatter-escaped and terminal control sequences are removed. ANSI is
controlled by Symfony's usual options; pipes and redirects support plain text.
`--quiet` suppresses output, including JSON, while retaining the exit code.

`--format=json` writes exactly the existing schema-version-1 JSON document without
ANSI, headers or debug output. It accepts verbosity and ANSI settings, but cannot
be combined with `--all`, `--issue` or `--couplings`. `--all` and `--issue` are also
mutually exclusive. Invalid options return exit 2 before analysis; they do not
change architecture findings. `--help` works without booting the container.
Buffered configuration output and PHP notices/deprecations are suppressed during
analysis; execution failures become structured fatal findings. The application
bootstrap itself should not print output before returning the Console Application.

JSON uses [schema version 1](architecture-report.schema.json):

```json
{
  "schema_version": 1,
  "status": "valid",
  "valid": true,
  "complete": true,
  "findings": [],
  "modules": [],
  "projects": []
}
```

- `status`: `valid`, `violations` or `unavailable`; `complete` means analysis was not blocked, independently of validity.
- `findings`: ordered/deduplicated objects with `rule`, `severity` (`error`/`fatal`), `message` and `context` (always a JSON object). Context includes available module/project/target, file and numeric line. `report.execution` records configuration/CLI execution failures; analyzer rule identifiers are unchanged.
- `modules`: the existing ID/class, scope/owner, enabled/active, inactive reason, paths, declared/observed dependencies, source-reference evidence, activation reasons and controller/entity directories.
- Module objects may additionally contain `application_dependencies` (unique observed application class names) and `application_references` (class, source file/line and `target_file`). These optional fields are emitted only for nonempty coupling evidence. They do not enter `observed_dependencies`, `references`, activation or findings. Application coupling is informational, including for inactive modules and reports with architecture violations. Both Markdown and console text include an **Application couplings (informational)** section only with `--couplings`, using the same observed reference evidence. This is an additive schema-version-1 extension: all previous fields/types/required keys are retained and the updated schema accepts previous reports. Consumers pinning the old schema with `additionalProperties: false` must update their schema to accept the optional fields.
- `projects`: the existing Project fields, configured hosts and compiled module graph.

Invalid analysis retains discovered catalog information and available references. `active` is `null`, `inactive_reason` is `unknown`, Project `modules` is `null`, and activation reasons are empty when no valid compiled graph exists. Enabled state is retained from resolved configuration for complete analysis; identity/discovery blockers may make it `null`. A fatal discovery failure can leave both catalogs empty. These unknown values never claim a partial graph is valid.

Existing valid JSON module/Project fields are retained; version/status/findings fields are additive. Consumers should select a supported `schema_version` and check the exit code and `complete` before treating activation data as authoritative. See [architecture analysis](architecture-analysis.md) for the shared result API and failure semantics.

Symfony's regular `debug:container`, `debug:router` and optional `doctrine:mapping:info` commands continue to use the ordinary compiled-container path.

## Standalone Markdown report

```bash
php bin/console sillbeam:report
php bin/console sillbeam:report --output=docs/architecture-report.md
php bin/console sillbeam:report --env=prod --no-debug
php bin/console sillbeam:report --couplings --output=architecture-report.md
```

The default file is `<project-root>/architecture-report.md`. Relative output paths
are resolved against the kernel's project root, independently of the current
working directory; absolute local paths are also accepted. The parent directory
must already exist. The command does not create directories or other files.

The report is a standalone document, not a terminal transcript. It includes:

1. Installed Sillbeam version, UTC check time, status and analysis completeness.
2. Separate finding/diagnosis counts, violations/blockers and module/Project counts.
3. A compact overview with module names, scope, state, named dependencies and
   Project hosts. Descriptor classes and absolute paths are kept in technical
   evidence. Invalid analysis identifies these as declarations only; unknown
   activation or graph validity is never presented as approved.
4. Every diagnosis, with the shared engine's problem, reason, next steps and
   locations. Domain options remain explicitly identified.
5. A separate **Technical Evidence** section with catalog identity mappings and
   all assigned original findings, rule codes, severity, original messages and
   complete context, including original paths and line numbers.
6. An optional informative coupling appendix, enabled only by `--couplings`.

The overview distinguishes private modules with their owning Project. Duplicate
names gain their actual IDs; invalid duplicate IDs additionally get deterministic
local ordinals. These labels are display names, never executable PHP references.
Unresolved targets stay explicitly marked and retain their reported class in
technical evidence. Referenced classes that collide with a module/Project label
are marked “(class)”. Long lists are expanded below tables rather than truncated;
individual very long names use list entries while ordinary rows remain compact.
See the [analyzer-backed example reports](../examples/architecture-reports/README.md).

`php bin/console sillbeam:report` excludes Application couplings by default.
`--couplings` uses the same observed classes, source locations and target paths
as `sillbeam:architecture --couplings`, formatted for Markdown. These observations
are information, not architecture errors, and do not change validity or exit codes.
Markdown escapes source text, paths, backslashes and table pipes. Only Sillbeam
architecture rules are checked; the report makes no general code-quality,
security or service-container health assessment. No HTML or diagram export is
provided. See the [reporting guide](../guides/architecture-reporting.md) for a
complete workflow.

The Markdown command uses the same analysis and report data as
`sillbeam:architecture`, including the same effective configuration. It writes the
file even when violations are found (exit 1), or the analysis is unavailable (exit
2). For incomplete analysis, counts represent only available catalog entries and
findings, not an assurance that all sources were checked. Status `unavailable` means that the analysis could not establish a complete result,
not that every module is invalid. Correct the reported blockers and rerun. Fatal
blockers are counted separately from architecture violations. The JSON schema is unchanged.

An existing report is replaced only after the complete new content has been
written to a temporary file in the same directory. Write/replacement failures
return exit 2 with an actionable console error and preserve the previous file;
temporary files are cleaned up. On a valid analysis with successful writing, the
exit code is 0. Invalid command usage returns exit 2 without writing a report.

Use the Console adapter described above to export even when architecture
violations or unrelated service errors prevent container compilation. With the
regular Symfony Console Application the command is registered as usual, but
requires a compilable container. Both reporting commands continue to support
normal Symfony environment, verbosity, help and quiet options.

## Verification

See [contributor checks](../../CONTRIBUTING.md#run-the-checks) for the test suite and quality commands.
Reporting tests cover text grouping and summaries, the unchanged JSON schema,
Markdown output paths and atomic replacement, and reporting without a compilable
container. Run compatibility checks on each supported PHP/Symfony combination;
a local run on one combination does not verify the whole compatibility matrix.

## Minimal module and Project creation

```bash
php bin/console sillbeam:make:module user
php bin/console sillbeam:make:project shop
php bin/console sillbeam:make:module checkout --project=Shop
```

Each command creates only a directory and an empty final PHP descriptor class with
`#[SillbeamModule]` or `#[SillbeamProject]`. With the defaults, these are
`src/Module/User/UserModule.php`, `src/Project/Shop/ShopProject.php`, and
`src/Project/Shop/Module/Checkout/CheckoutModule.php`. No controllers, services,
entities, templates or configuration files are generated. Core provides these
commands without Frame or Symfony MakerBundle.

Names start with an ASCII letter and contain only ASCII letters and digits.
Only an initial lowercase ASCII letter is uppercased: `user` becomes `User`,
`userProfile` becomes `UserProfile`, `shop` becomes `Shop`, and `User` stays `User`.
Every other character is preserved exactly. The effective name is used consistently
for the directory, namespace, descriptor class and filename, and appears in the
success output as part of the created path. Paths, namespaces, leading digits,
spaces, underscores, hyphens and non-ASCII letters are rejected.
Pass the base name without a namespace, path or the corresponding `Module` or
`Project` suffix; that suffix is added automatically, and already suffixed names
are rejected to prevent double suffixes.
`--project` accepts an existing discovered Project's directory name, ID or full
descriptor class name. This selector is matched as supplied, without name
normalization. Missing or ambiguous Projects fail without creating files.

Creation uses the application's resolved `sillbeam.discovery` roots, namespaces,
excludes and Composer PSR-4 mappings, exactly as discovery does. Exactly one root
of the requested type must be configured; empty or multiple root lists require
an explicit configuration adjustment before creation. Explicit custom discovery
roots must already exist, as required by discovery. Private modules use
`Module/<Name>` inside their owning Project and the namespace resolved for that
location. Excluded paths, symbolic links in creation paths, overlapping areas,
invalid PHP namespaces and existing target directories/files are rejected.
Existing content is never overwritten, including when normalization makes `user`
and `User` resolve to the same target. Exit codes are 0 for creation and 1 for
validation or write failures.

Descriptors are discoverable immediately. Discovery and activation are separate:
shared modules still need to be selected by a Project when operating in Project
mode. Private modules belong to their owning Project automatically. When multiple
Projects exist, configure `sillbeam.projects.<id>.hosts` for every Project before
the next application boot; the Project command prints this reminder and never
creates configuration on your behalf. An application with invalid existing
architecture/configuration must be corrected before Symfony can run these commands.

## Installation doctor

```bash
php bin/console sillbeam:doctor
php bin/console sillbeam:doctor --format=json
php bin/console sillbeam:doctor --env=prod --no-debug
php bin/console sillbeam:doctor --help
```

Doctor reads installation files without writing files, booting a Kernel or
executing application configuration. It uses the shared Core CLI prototype 1.2
presentation and distinguishes `verified`, `action required` and `not verified`.
The summary counts all three; only problems and manual checks receive next steps.
`verified` describes static file evidence, not certification of effective runtime
configuration. Doctor does not replace `sillbeam:architecture`, PHPStan or Symfony
configuration/container checks.

Checks cover literal `config/bundles.php` environment declarations, a statically
recognizable Console adapter construction in `bin/console`, conventional
MicroKernel configuration loading, YAML Sillbeam route imports, Symfony service
resource overlap with discovery areas, and discovery option/Composer namespace
consistency using Core's existing resolver. Service imports must exclude complete
module/Project areas so they cannot bypass Sillbeam activation. A single finite brace group in
exclusions (for example `../src/{Entity,Kernel.php,Module,Project}`) is expanded
without scanning or executing source. Wildcards, nested braces, explicit
service definitions, imported configuration, environment overlays, PHP/XML
configuration, custom Kernel hooks and ambiguous Runtime callbacks require
manual review. Conventional Symfony Runtime callbacks are inspected through their
returned Application, resolving imports, aliases and fully qualified names.
Standard Symfony 7.4/8.1 MicroKernelTrait kernels (including a literal private
`getAllowedEnvs()`) and unmodified `SillbeamKernel` subclasses are supported. Comments and unused imports do not establish adapter
use. Duplicate literal Sillbeam imports are action items with both source locations;
keep exactly one to avoid loading the stateful loader twice. A valid
route import uses `resource: .` and `type: sillbeam`; options whose consequences
cannot be established statically require manual review. Discovery consistency
checks do not analyze descriptors, activation graphs or architecture rules.

| Exit | Meaning |
| --- | --- |
| 0 | All supported static installation checks verified |
| 1 | Checks completed; action required |
| 2 | At least one check could not be verified, or command could not run |

Exit 2 takes precedence over 1. Routing uncertainty is evaluated separately from
Discovery and service configuration: an unknown routing overlay does not hide a
proven service-import overlap. If a service check has both a literal conflict and
independent unresolved details, it emits both `action required` with the affected
file/next step and `not verified`; the summary counts both results. Dynamic
container imports can affect Discovery as well as services, so those checks remain
unverified together. Duplicate service IDs and unknown exclusions require manual
review rather than a claimed definitive overlap.

The [F01/F02 follow-up](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/doctor-f01-f02-verification-2026-10-25/README.md) records the corrected Runtime/kernel detection and
routing/service separation against the original independent acceptance. Standard
route overlays can still leave **routing itself** unverified; this does not certify
their effective configuration. Symfony's normal verbosity, ANSI and quiet options
apply. No repair or output-file option is provided.

With the existing `Sillbeam\Core\Console\Application` adapter, Doctor and its help
are available before container compilation, including with invalid architecture.
Keep the consuming application's bootstrap and Kernel when selecting that adapter.
The regular Symfony entry point also registers Doctor, but Symfony itself must
boot first and may write its ordinary cache files before the command starts.
The Doctor implementation never performs those writes. For inspection without
that boot, use the existing adapter. Doctor never edits `bin/console`, services,
routes, configuration or application sources, and does not execute discovered PHP
configuration to obtain a more optimistic result.

### Machine-readable Doctor results

`--format=text|json` selects the presentation; text remains the default. JSON uses
the same Inspector observations with explicit stable category IDs, the original
statuses and unchanged exit priority. `--quiet` suppresses JSON as well. See the
[Doctor JSON v1 contract](doctor-json.md) and [JSON Schema](doctor-report.schema.json)
for fields, repeated IDs, usage/execution errors, clean STDOUT and the distinction
between command output, Symfony startup and help metadata. This schema is separate
from architecture-report JSON.

## Targeted module inspection

```bash
php bin/console sillbeam:inspect:module Billing
php bin/console sillbeam:inspect:module billing --project=shop
```

The compact default shows module state/scope/Project context, whole-analysis status
and known direct relationship counts. DEPENDS ON and USED BY merge declared/observed
evidence per module using short, collision-aware IDs. Declared only does not mean
unnecessary; incomplete observations remain unverified. Relevant problems, unknown
state and global analysis blockers stay visible.

`-v` adds configuration, full classes/paths, activation evidence, Project states,
separate relationship lists, concrete references and original findings/context.
`--project` retains existing ownership/activation selection. The Core adapter supports
this exact command before container compilation. Text only in 0.3.0; no `--format`
or JSON mode. Exit codes remain 0 valid, 1 architecture violations,
2 usage/selection failure or unavailable analysis. See
[module inspection](module-inspection.md) for the User example, evidence labels,
partial-evidence limits and native-container versus fresh-adapter snapshots.

## Architecture graph

`php bin/console sillbeam:graph` writes `architecture.svg` in the current working
directory and confirms the full path. SVG is generated directly in PHP without
Graphviz or other extra tools. `--format=svg` is explicit;
`--output=PATH` creates missing directories recursively and `--force` permits replacing
an existing regular file. Failed rendering leaves existing artifacts untouched.

`--module=ID` selects the same identity as module inspection and includes only its
direct incoming/outgoing declared and observed relationships. `--project=ID`
selects ownership/activation context; cross-boundary incident evidence is retained.
Labels emphasize module IDs; normal dependency arrows have no repeated label.
Cycles, disabled/unknown states and observed undeclared relationships remain distinct.
Status and legend are captions rather than disconnected module nodes.

`--format=mermaid` preserves raw STDOUT export for redirection, without decoration
or a renderer. `--output`/`--force` are SVG-only. `--quiet` suppresses messages/source
but still writes SVG. Analysis exit codes remain 0/1/2 after successful export;
invocation/selection errors use 3, renderer/filesystem errors 4, on STDERR.
See the [graph reference](mermaid-graph.md) for full selection, safety and evidence
semantics. Invalid/incomplete architecture never implies runtime activation.

## 0.3.0 command compatibility

| Command | Default / optional formats | Data source | Exit semantics |
| --- | --- | --- | --- |
| `sillbeam:doctor` | text / JSON schema v1 | Existing static InstallationInspector checks | 0 verified, 1 action required, 2 unknown/unexecutable; 2 takes priority |
| `sillbeam:architecture` | text / existing JSON schema v1 | ArchitectureReport | 0 valid, 1 violations, 2 unavailable/usage |
| `sillbeam:report` | Markdown file | ArchitectureReport | Same architecture status; write/usage errors 2 |
| `sillbeam:inspect:module` | text only | Same report catalog and relationship evidence | Same architecture status; selection/usage errors 2 |
| `sillbeam:graph` | SVG file (built in) / raw Mermaid | Same report catalog, direct edges and findings | 0/1/2 after export; usage/selection 3; renderer/output 4 |

The diagnostic formats do not change the underlying checks, runtime activation,
Project membership or architecture rules. `enabled` never implies `active`, and
incomplete evidence never proves absence. Doctor remains a separate static
installation check; its JSON output does not run architecture analysis.

The Core Console adapter resolves all five exact diagnostic names before container
boot. Native Symfony Console requires a compilable container and may use a compiled
report snapshot. None of these command additions places parser/architecture analysis
on the production HTTP path. See [upgrade guidance](../migration/0.3.0.md) and the
[initial acceptance evidence (2026-10-29)](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/release-0.3.0-acceptance/README.md).
