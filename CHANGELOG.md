# Changelog

This changelog records the version history of Sillbeam Core.

## v0.4.0

### Public additions

- Add Core Data v1 through the PHP API and the Core Console adapter. Live exports architecture, diagnostics and references in one locale. Analysis adds structured graph data, source inventory, Doctor checks, both locales and an architecture fingerprint when the evidence is reliable. Capabilities and standalone source inventory have their own commands.
- Add English and German CLI presentation, the project setting `sillbeam.locale` and the personal `sillbeam:locale` preference. Machine-readable commands retain their documented locale behavior and exit codes.
- Ship Graph 2.0 for native SVG output with deterministic dependency layers, scope bands and orthogonal routes. Keep raw Mermaid export and the existing graph command options.

### Lifecycle and reliability

- Keep Symfony maintenance and Composer cache scripts usable when Core finds invalid architecture. Defer automatic Core services, routes and Doctrine mappings in that state. Require a successful explicit architecture check before serving a production release.
- Make missing required discovery roots and changed, previously inspected descriptors explicit analysis failures. Keep automatic discovery and integration within the selected source boundaries. Report runtime activation as unknown when Analysis detects changing inputs and omit free descriptor exception text from Core Data.

### Internal changes and maintenance

- Reuse parser work and source-tree walks, persist static parse results by source content and parser inputs, and bound the snapshot read budget. Compact debug cache resources while retaining Symfony freshness checks. Warm non-debug HTTP continues to consume the compiled state.
- Update the development and reference-application lockfiles with patched Symfony versions. Keep the dependency constraints and legacy Architecture and Doctor JSON v1 schemas.
- Prepare the `0.4.x-dev` aliases for the current development line. See the [release notes](docs/releases/0.4.0.md) and [upgrade guide](docs/migration/0.4.0.md) for verification scope and rollout steps.

## v0.3.0 (release candidate)

- Document public contracts and relevant implementation decisions with English PHPDoc; add development-only phpDocumentor 3, `composer docs:build`, contributor conventions and ignored generated output.
- Add manual `composer docs:export-json`: English public API JSON at `build/docs/api/sillbeam-core.en.json`, schema v1, original signatures/code examples, stable IDs and internal filtering. Reuse existing phpDocumentor descriptors; no translation, synchronization, website integration or release/CI hook.
- Improve Composer/Flex installation guidance and static Doctor diagnoses for bundle/routing integration, service-import overlap and the Core Console adapter. Preserve developer ownership of existing Symfony files; retain recipe version `0.2`.
- Add `sillbeam:doctor --format=text|json` with text as default and documented JSON schema v1. Reuse InstallationInspector results, stable check IDs/statuses and existing 0/1/2 exit-code priority.
- Add text-only `sillbeam:inspect:module` with compact state/scope/Project context and merged DEPENDS ON/USED BY rows with declared/observed evidence. Preserve ID/descriptor selection, ambiguity handling, unknown states and source-specific completeness. `-v` exposes configuration, full classes/paths, activation evidence, separate lists and original findings/context.
- Add `sillbeam:graph`: built-in PHP SVG output with automatic output-directory creation, compact module labels, direct incoming/outgoing module filters, Project context, safe output replacement and raw `--format=mermaid` export. Both formats share existing report evidence, retaining direct declarations, observed undeclared references, disabled/unknown states and recorded cycle participants. No external renderer or extra installation is required.
- Reuse existing ArchitectureReport/analyzer evidence and Core Console pre-container dispatch. Preserve unknown/incomplete states, public architecture JSON v1, runtime contracts and production HTTP compilation guards; add no runtime dependencies or architecture rules.
- Align current metadata and test versions with 0.3.0. Alias the actual `dev-v0.3.x` branch (`dev-dev-v0.3.x` in Composer), `dev` and `main` to `0.3.x-dev`. Keep automatic push CI restricted to `main` and `dev`, with PHP 8.4/8.5 × Symfony 7.4/8.1 configured. Integrate development → `dev` CI → maintainer approval → `main` CI → separately authorized release. See [release notes](docs/releases/0.3.0.md), [upgrade guide](docs/migration/0.3.0.md) and [initial acceptance evidence (2026-10-29)](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/release-0.3.0-acceptance/README.md). This entry does not authorize or imply publication.

## v0.2.0

### Breaking changes

- Rename the Composer package from `websign-dev/sillbeam` to `websign-dev/sillbeam-core`, move PHP APIs from `Sillbeam\` to `Sillbeam\Core\`, and replace `Sillbeam\SillbeamBundle` with `Sillbeam\Core\SillbeamCoreBundle`. The extension is now `Sillbeam\Core\DependencyInjection\SillbeamCoreExtension`; no compatibility aliases for the former PHP APIs are provided.
- Retain the `sillbeam` configuration root/extension alias, existing `sillbeam:*` command names, route-loader type and named service IDs. Class-based service references must use the new namespace. Module/Project ownership, activation and dependency boundaries remain in place.
- Require prebuilt containers for non-debug production HTTP: cold requests that would need compilation receive a generic 503. CLI builds remain strict and cache clear/warmup recheck current sources even when a previous container is cached.
- Replace the earlier architecture text layout with CLI prototype 1.2 presentation. Scripts that consume human-readable output should use schema-version-1 JSON instead. See the [upgrade guide](docs/migration/0.2.0.md).

### Architecture diagnostics and developer guidance

- Collect independently detectable violations as structured findings with stable rule identifiers, severity, locations and context; distinguish architecture violations from fatal/incomplete analysis. Invalid architectures still block container builds through a `LogicException`-compatible `ArchitectureValidationException`.
- Add a shared Diagnostic Engine that explains problems and their impact, groups related evidence into deterministic diagnoses, and provides contextual next steps for CLI and Markdown. It preserves original findings and separates technical corrections from decisions about application design.
- Use declared dependency paths, privacy and activation evidence when explaining undeclared references. Warn about prospective cycles and disabled/unassigned targets; incomplete evidence does not justify unconditional dependency advice. No automatic repairs are applied.
- Make error wording clearer and preserve fully qualified, executable PHP descriptor `::class` references in dependency and Project-selection advice. Distinguish descriptors from similarly named entities and services.
- Add `code.unknown_class` for unresolved application-owned static references in Module/Project code, including descriptor constructors and inactive modules. Use indexed sources and Composer/discovery ownership without treating ordinary external types as missing application classes; retain file/line evidence and avoid executing referenced target files solely for classification.
- Record Application Couplings to indexed application classes outside module areas as informational evidence. These observations neither create declared dependencies nor change activation or violation exit codes. Add optional JSON evidence fields while retaining schema version 1.

### CLI and Markdown reports

- Apply prototype 1.2 styling through the shared Symfony Console presentation layer: compact success output; a complete diagnosis index with the first diagnosis expanded when several exist; readable relative locations and separate finding/diagnosis counts. Technical rule codes and full paths are available with `-v`.
- Add `--all`, `--issue=N` and opt-in `--couplings` to architecture text output. Issue numbers identify diagnosis groups only within the current run. JSON always contains the complete result and cannot be combined with these text selectors.
- Add the opt-in `Sillbeam\Core\Console\Application` adapter for `sillbeam:architecture`, `sillbeam:report` and `sillbeam:doctor` before container boot, retaining the application's existing kernel/bootstrap. Architecture reporting uses the real Symfony configuration preparation and the same analyzer as container validation.
- Add `sillbeam:report` with project-relative/absolute destinations and atomic Markdown replacement. The document contains version/time/status, a readable module/Project overview, shared diagnoses and next steps, and complete original Technical Evidence. Unknown activation stays unknown; `--couplings` adds a separate informational appendix.
- Keep architecture/report exit codes 0 (valid), 1 (violations), 2 (incomplete/unavailable, invalid usage or report write failure). Markdown can be produced for invalid/incomplete analysis; JSON remains the machine interface, not a transcript of the new text presentation. See the [CLI reference](docs/reference/cli.md).

### Installation and descriptor creation

- Add minimal `sillbeam:make:module` and `sillbeam:make:project` generators, including private modules with `--project`. Resolve configured roots/namespaces and create only the descriptor directory/file; existing content and application configuration are not overwritten.
- Normalize only an initial lowercase ASCII letter in generator names (`userProfile` → `UserProfile`), retaining remaining characters and rejecting invalid names, suffixes and normalized collisions.
- Add `sillbeam:doctor`, a static, read-only installation inspector with `verified`, `action required` and `not verified` results. It does not boot the kernel or execute configuration itself; unsupported/dynamic configuration requires manual review. Exit 2 for unverified checks takes precedence over action-required exit 1.
- Correct Doctor F01/F02: resolve the returned Application in conventional Symfony Runtime callbacks, including aliases/FQCNs and stock 7.4/8.1 kernel conventions; keep routing uncertainty separate from Discovery/service checks so proven overlaps remain visible. Mixed known conflicts and unknown details retain both statuses and exit-2 precedence. Custom hooks and dynamic configuration remain unverified. See the [follow-up verification](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/doctor-f01-f02-verification-2026-10-25/README.md).
- Finalize local recipe source at `flex-recipe/websign-dev/sillbeam-core/0.2`: register the central bundle, copy the necessary route import and show separate `post-install.txt` instructions. Do not generate an empty package configuration, edit `bin/console` or change existing service imports. Local installation/output/removal tests do not establish public Recipes Contrib availability.

### Development, production and verification

- Follow Symfony debug/resource tracking for cold or rebuilt development containers. Non-debug production HTTP consumes prebuilt registries/services/routes/mappings without fresh source analysis. Validate immutable releases through CLI before activation; a successful `--no-warmup` is not sufficient for serving traffic.
- Update the development/reference-app HttpFoundation locks to 8.1.9/7.4.21 for the recorded advisory, without changing dependency constraints. Target Symfony 7.4/8.1 in the current PHP 8.4/8.5 CI matrix and include lockfile audits and no-dev ZIP installation checks.
- Record synthetic module/native-Symfony benchmarks with measurement limits, not a general performance claim. See the [RC report](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/core-rc-2026-10-23.md).
- Record the [independent installation acceptance](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/installation-acceptance-2026-10-25/INSTALLATIONS-ABNAHME.md): 542 tests/6050 assertions in the isolated PHP-8.5 run, consumer checks on PHP 8.4/8.5 with Symfony 7.4/8.1, HTTP, reports, Doctrine and uninstall evidence. Preserve its historical findings and verdict. The [completed follow-up](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/doctor-f01-f02-verification-2026-10-25/README.md) verifies both Doctor corrections with four full PHP/Symfony suite runs (550 tests each), 56 passing consumer scenarios and 40 unchanged read-only snapshots. Unknown configuration still needs manual review; ordinary Symfony boot can write cache files before Doctor runs (F03). Package registration is complete; public Recipes Contrib approval remains a separate external integration.

## v0.1.0

Sillbeam v0.1.0 is the first public release of the Symfony module architecture layer, distributed as the Composer package `websign-dev/sillbeam`.

### Added

- Attribute-based Module and optional Project descriptors with configurable discovery roots, PSR-4 namespaces and exclusions.
- Build-time validation of descriptor ownership, unique IDs, declared dependencies, cycles and private module boundaries.
- Static validation of direct PHP class references between active modules, with file and line diagnostics for undeclared or forbidden dependencies.
- Immutable Module and Project catalogs, active dependency graphs and activation diagnostics through registry contracts.
- Native Symfony service imports with autowiring, autoconfiguration, environment attributes, exclusions and interface aliases.
- Attribute routes for active modules and Projects, including Project route-name prefixes and host filters.
- Optional DoctrineBundle integration for active module and direct Project entities, preserving native mappings and explicit overrides.
- Request-based Project context, `sillbeam:architecture` text/JSON output and `sillbeam:about` runtime diagnostics.
- Support for the standard Symfony kernel and the optional `SillbeamKernel`.
- Symfony Flex recipe source for bundle registration and a single route import; default operation requires no package configuration file.

### Fixed

- Excluded `Tests/`, `Fixtures/` and `Migrations/` from automatic Module/Project service imports, including nested directories; production-only Composer installations can retain development source files without registering them as services.

- Preserved discovery exclusions across overlapping roots and subsequent service, route and entity imports.
- Excluded entity subdirectories from generated parent Doctrine mappings while retaining intentional native mapping overrides.
- Invalidated development containers when the first Sillbeam package configuration is added after a zero-config boot.
- Rejected unknown Project configuration even when no Projects are discovered, multiple descriptor attributes on one class and IDs with trailing newlines.
- Resolved descriptor attribute names consistently with PHP's case-insensitive class resolution.
- Preserved native Doctrine driver priority when sorting generated Sillbeam mappings.
- Corrected configuration type annotations for static analysis with Symfony 7.4.0.

### Changed

- Standardized installation on `composer require websign-dev/sillbeam:^0.1.0`, with `0.1.0` available as an exact version constraint.
- Aligned example applications, the isolated test application and independent installation checks with the 0.1.0 package version.
- Published English guides, configuration and API references, migration instructions and release notes.
- Updated the security policy with private reporting channels and support for v0.1.0.
- Restricted library distribution to runtime code, package metadata, license and public documentation; development applications, tools, caches and internal plans are excluded.
- Removed the former private Nexum/Foundation APIs. API/CRUD, authentication, entity bases, stores and infrastructure helpers are no longer included. Existing development consumers must follow the [migration guide](docs/migration/modular-monolith.md).

### Compatibility and verification

- Requires PHP `^8.4`, Composer runtime API `^2.2` and Symfony `^7.4 || ^8.0`; Doctrine remains optional.
- Verified PHP 8.4/8.5 with Symfony 7.4/8.0/8.1 through the quality suite and independent Composer installations.
- Covered Symfony-only, Modules-only, Project-only, Single-Project, Multi-Project and Hybrid layouts, both kernels and operation with/without Doctrine.
- Verified cold/warm development and production containers, documented service/controller examples, and Flex installation/removal preserving application configuration.
- Enforced PHPUnit handler ownership checks so risky integration tests fail rather than returning a successful exit code.

Projects share the Symfony container, event system and database; they do not provide tenant or security isolation. Static validation covers supported PHP class references within module areas, not dynamic class strings or application-wide access control. This is a pre-1.0 API.

See the [v0.1.0 release notes](docs/releases/0.1.0.md) for installation, compatibility evidence and known limitations.
