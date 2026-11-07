# Sillbeam Core v0.4.0 API Contract

This contract applies to the API milestone within v0.4.0. Implementation requires user approval. The four JSON files under `schemas/` are normative. This document defines semantic invariants, operational behavior, security and scope boundaries, and acceptance criteria. The files under `examples/` are validated illustrative responses.

## 1. Responsibilities and architecture

- Changes apply only to Sillbeam Core. Hub, Connector, VS Code, and PhpStorm are external consumers and remain outside the scope of this work.
- Core uses one architecture engine and one source of domain data. The two profiles, `live` and `analysis`, define the scope of data and processing. They confer no permissions and are independent of product profiles.
- `live` provides diagnostic and module catalogs with complete information about the current architecture state in one requested locale. It omits the additional source inventory, full graph data preparation, and Doctor collection.
- `analysis` adds structured graph data, a complete catalog of relevant source files, Doctor results, and Core text in all available locales. It produces one consistent export without a series of CLI subprocesses or any SVG or Mermaid rendering.
- Additional Core operations remain explicitly callable, including `sillbeam:graph`, `sillbeam:doctor`, `sillbeam:inspect:module`, and source inventory. Extensions do not require special profiles.
- The public PHP API returns immutable data models. The CLI serves as an adapter and serializer. Core has no dependency on the network, Hub, Connector, or IDE plugins.

### Binding scope boundary

Architecture rules evaluate only code within the existing Sillbeam architecture scope. Outside that scope, Analysis may collect inventory and symbol information. Syntax errors, unresolved references, style deviations, and other code quality problems in outside code produce no architecture findings. If an inventory error is collected, it appears only in `errors` with domain `source_inventory` and limits inventory completeness.

Existing, permitted evidence for module rules may include references from modules to outside classes. The presence of such a reference does not by itself establish a violation. Preserve technically necessary discovery and autoload evidence within the existing Core scope. The existing `SourceInspector` also reads outside PHP code and can report parse errors as fatal. Separate those cases for the new scope rule while preserving module rule checks. Deep tracing, call graph construction, and general-purpose source analysis are outside scope.

## 2. Public interfaces

The new CLI adapter uses the following invocations for this milestone.

```sh
php bin/console sillbeam:api --profile=live --locale=de --schema-version=1
php bin/console sillbeam:api --profile=analysis --schema-version=1
php bin/console sillbeam:api:capabilities --format=json
php bin/console sillbeam:inspect:sources --format=json
```

- Live accepts an optional `--locale`, which defaults to `en`. Analysis rejects locale selection because it includes all available Core locales. An unknown locale, profile, or schema version produces a deterministic invocation error.
- `sillbeam:inspect:sources --format=json` may return the `#/$defs/sourceInventory` object from `core-data-common.v1.schema.json` directly. Both interfaces use the same source inventory type.
- `capabilities` must run without a full project architecture analysis. It reports supported profiles, schema versions, locales, and individual Core operations.
- STDOUT contains JSON only. Send technical messages to STDERR and keep banners, warnings, and progress messages out of STDOUT. Both `--no-ansi` and `--no-interaction` must work for automated consumers.
- Preserve schema v1 for `sillbeam:architecture --format=json` and `doctor --format=json`, along with the existing graph output, module inspection output, and exit semantics. The VS Code plugin must continue to work without an update.

### Public PHP interface

Choose and document the final namespaces and class names based on the repository. The responsibilities are as follows.

- `CoreDataProviderInterface::collect(CoreDataRequest): CoreDataDocument` collects the result.
- The request carries `profile`, optional `locale`, and `schemaVersion`.
- The result is an immutable, typed model that can be serialized to the JSON contract without rerunning the domain analysis.
- `CoreDataSerializerInterface` produces JSON for the requested schema version using the existing result.
- `CoreCapabilitiesProviderInterface` reports the available profiles, contracts, and locales.

A generic plugin registration engine, dynamic field filtering, and automatic external data transfers are outside scope.

## 3. Normative response bodies and types

The JSON Schemas govern the response. Domain objects reject unknown fields unless explicitly exempted. Original technical finding contexts and the versioned `extensions` map are exempt.

### Shared sections

`document_type`, `schema_version`, `profile`, `metadata`, `i18n`, `analysis`, `export`, `completeness`, `summary`, `architecture`, `diagnostics`, `references`, `errors`, `extensions`.

### Analysis-only sections

`graph`, `source_inventory`, and `doctor` are forbidden in Live. The Analysis profile represents the graph as structured nodes, edges, and groups. The source inventory excludes raw PHP source. Doctor checks retain their original order and distinct entries even when IDs repeat. The `index` records their position.

### Field semantics

| Field | Meaning |
|---|---|
| `metadata.analysis_run_id` | UUID of the analysis run itself, independent of JSON serialization or transfer |
| `metadata.analyzed_at` | UTC timestamp when the domain analysis completed, independent of when an external system receives the result |
| `metadata.duration_ms` | Measured duration of the base analysis. Additional export work is excluded from this measurement |
| `metadata.architecture_fingerprint` | `sha256:vN:<64 hex>`, derived consistently from defined canonical architecture data. Use `null` when completeness cannot be established |
| `metadata.fingerprint_version` | Version of the normalization algorithm. It is `null` if and only if the fingerprint is `null` |
| `i18n.available_locales` | Catalog locales actually available in Core, starting with `en` and `de` |
| `i18n.included_locales` | Locales in the response body. Live includes exactly one and Analysis includes all available locales |
| `i18n.requested_locale` | Selected locale for Live and `null` for Analysis |
| `i18n.fallbacks` | Each actual fallback to a resource in another locale, with `resource_key`, `locale`, and `source_locale` |
| `analysis.status` | Architecture check status, one of `valid`, `violations`, or `unavailable` |
| `analysis.valid` | True only for `valid`. False with `unavailable` establishes no violation |
| `analysis.complete` | Indicates that no fatal technical blockers prevented the architecture check |
| `export.status` | API export completeness, one of `complete`, `partial`, or `unavailable` |
| `completeness.*` | Per-domain status, one of `complete`, `partial`, `unavailable`, or `not_collected` |
| `summary.*_count` | Number of items emitted. A partial result gives no total for the underlying inventory |
| `architecture.modules[].class` | Technical descriptor identity. The logical `id` alone is not globally unique |
| `architecture.modules[].active` | Activation supported by evidence. Use `null` when the runtime graph is invalid or incomplete |
| `architecture.modules[].enabled` | Availability in configuration, independent of whether the module is `active` |
| `architecture.projects[].modules` | Compiled membership or `null` when unknown |
| `diagnostics.findings[].message` | Original English technical Core message retained for traceability |
| `diagnostics.findings[].context` | Unchanged original parameters, which may contain historical absolute paths |
| `diagnostics.findings[].location` | Separately verified relative, POSIX-normalized, one-based file position, or `null` when it cannot be verified |
| `diagnostics.diagnoses[].id` | Run-local ID such as `D1` or `D2`, with no historical identity |
| `diagnostics.diagnoses[].correlation_key` | Versioned best-effort key. Use `null` when a unique, reliable correlation is unavailable |
| `diagnostics.diagnoses[].finding_indexes` | Positions in `diagnostics.findings`, reciprocal to `diagnosis_id` |
| `diagnostics.diagnoses[].details` | Domain problems, impacts, and cautions tied to the original findings |
| `diagnostics.diagnoses[].resolutions` | `technical` or `option`, with no unsupported repair guarantees |
| `references.module` | Observed references from modules. Declared edges without observed references appear in the architecture or graph catalog |
| `references.application` | Informational references from modules to outside application code |
| `graph.validation_state` | `validated`, `evidence_only`, or `unavailable`. An invalid graph can never be a confirmed runtime graph |
| `graph.edges[].reference_ids` | References to `references.module[].id`, or an empty array for unobserved edges |
| `source_inventory.files[].ownership` | `module`, `project`, `application`, or `unknown`. Files outside scope receive no architecture assessment |
| `source_inventory.files[].parse_status` | `parsed`, `not_parsed`, or `failed`. Non-PHP files always use `not_parsed` |
| `doctor.status` | Normalized status of `verified`, `action_required`, or `not_verified`. The existing Doctor JSON v1 format stays intact |
| `errors` | Export and collection errors. These do not create architecture findings |
| `extensions` | Versioned, namespaced third-party or future data. Extensions must not override existing Core fields |

### Null, empty, and missing values

- `null` means unknown or not reliably determinable, as in `active:null` and `project.modules:null`.
- `[]` means a complete check found no entries. A partial domain lists only the entries that are known.
- Omitting a required field violates the contract. When the profile includes a domain whose collection is intentionally skipped, that domain still appears with `not_collected`.
- All relative export paths use POSIX normalization and cannot escape the root via `..` or any other mechanism. Raw legacy contexts may contain other paths. For external transfers, the Connector must handle sensitive original values separately.

## 4. Internationalization

- Core provides a central source for rule messages, diagnostic categories, problems, impacts, cautions, resolution steps, Doctor text, and API and CLI messages. English (`en`) is the default and German (`de`) has full coverage. Additional catalogs can be added independently of product profiles.
- Rule codes, module and Project identities, classes, paths, and developer-supplied descriptions are exempt from automatic translation.
- Localize text where it originates in the domain, using stable resource keys and structured parameters. Do not apply regular-expression replacements to finished English sentences.
- Live includes exactly the requested locale with documented fallbacks. Analysis includes resolved strings for every available locale. Consumers can switch the language of stored results independently of Core.
- Original JSON v1 text outputs must remain contract-compatible. If the new API cannot resolve a translation, it must use an explicit fallback and record it in `i18n.fallbacks`.

## 5. Versioning and identity

- Use JSON Schema Draft 2020-12, public versioned `$id` values, and strictly defined objects. Keep shared definitions in the common `$defs`. Each profile has a separate top-level schema that rejects unknown properties.
- Both profiles use `schema_version=1` in v0.4.0 and have separate schema files. A future required field or a breaking change to a type, meaning, or enum requires a new schema version. Additional locales, rule codes, and localized content may be added within the existing dynamic locale and rule contracts.
- Exclude timestamps, localized text, ordering of semantically unordered collections, and temporary IDs from `architecture_fingerprint`. Document and implement a verifiable v1 algorithm using canonical sorting and SHA-256. Return `null` when the data is incomplete or unstable.
- Build each diagnostic `correlation_key` only from stably normalized domain attributes such as rule codes, basis, module and Project identities, and necessary relative locations. Exclude standalone line numbers, timestamps, and localized text. Use `null` on collision or ambiguity. Arbitrary renames may prevent deterministic correlation.
- Assign snapshot-local IDs such as `MR1`, `AR1`, `GE1`, `D1`, and `R1` deterministically. Core provides no history storage, database history, or change history.

## 6. Errors, scope, and export completeness

The following exit codes apply only to the new `sillbeam:api` command.

| Code | Meaning |
|---|---|
| `0` | Valid architecture and complete export |
| `1` | Architecture violations and complete export |
| `2` | The architecture check cannot produce a reliable result |
| `3` | The architecture status is known and the export is incomplete |
| `4` | Invalid invocation, export that cannot be serialized, or contract violation |

Apply priority `4 > 2 > 3 > 1 > 0`. Codes `0..3` always produce exactly one valid JSON document on STDOUT. Code `4` must not present a response as a valid domain result. Existing CLI exit codes remain intact.

- An invalid architecture with complete evidence can yield `analysis.status=violations` and `export.status=complete`.
- A valid architecture with an unreadable file outside scope yields `analysis.status=valid`, `export.status=partial`, `completeness.source_inventory=partial`, and a technical error. It produces no architecture finding.
- A fully executed Doctor check with `action_required` yields `completeness.doctor=complete` and produces no transport or export error.
- Data that was not collected cannot establish absence. In incomplete results, report `active=true` or empty dependencies only where evidence supports those claims.
- When a rule is violated, the graph is `evidence_only` and includes known edges. It makes no claim of runtime activation.
- Detect file changes during collection by recording at least a verifiable before-and-after state for the sources used in the snapshot. A detected inconsistency reduces export completeness. Concurrent writes preclude any guarantee of a fully atomic filesystem view.

## 7. Semantic invariants beyond JSON Schema

Dedicated PHP tests and, where appropriate, validators check the following rules.

1. `findings[i].index == i` and Doctor `checks[i].index == i`. Doctor IDs may repeat.
2. Each `summary.*_count` matches the length of its emitted array. Source inventory counts match the emitted directories, files, PHP files, and symbols.
3. Module and Project `class` values are unique within their respective catalogs. `project` and `project_states.project_class` refer to cataloged Projects when that domain is complete.
4. Every `diagnosis_id` refers to an existing diagnosis, and every position in `finding_indexes` refers to an existing original finding. When `completeness.diagnostics=complete`, every finding belongs to exactly one diagnosis and the links are reciprocal.
5. Each `details[].finding_index` refers to a finding in the same diagnosis. The group has severity `fatal` if any member does.
6. `references.module` and `references.application` have unique IDs within their respective namespaces. References retain source lines and target identities without extending architecture assessments outside scope.
7. Graph nodes have unique IDs, each edge refers to existing nodes, and every entry in `reference_ids` refers to an existing module reference ID.
8. A graph may be `validated` only when it represents a valid and reliably verified runtime graph. `evidence_only` does not establish activation.
9. `included_locales` is a subset of `available_locales`. Live includes exactly one and Analysis includes all available locales. Every resource shown is resolved in each included locale or has a documented fallback.
10. If `architecture_fingerprint=null`, then `fingerprint_version=null`. Otherwise the version is documented. Identical canonical architecture states yield identical fingerprints regardless of analysis time, locale, or serialization order.
11. `export.status=complete` requires every completeness domain mandatory for the profile to be `complete`. A `partial` export has at least one incomplete domain. A `not_collected` domain cannot count as complete.
12. Doctor counts match the checks. Check failures must be distinguished from technically incomplete Doctor collection.
13. Source file entries outside the architecture scope must not produce new findings or drive runtime graph activation decisions.
14. Snapshots exclude raw PHP contents, credentials, secrets, and unfiltered exception stack traces. The Connector must handle raw technical text from legacy findings separately.

## 8. Stability, performance, and implementation limits

- Reuse existing v0.3.0 components and preserve their contractual behavior. The components include `ArchitectureAnalysisResult`, `DiagnosticCollection`, `ArchitectureReport`, `ModuleInspection`, `SourceInspector`, `SourceFileParser`, `GraphView`, and `DoctorReport`.
- Run one actual analysis and share its source index and domain findings. Collect source inventory and additional hashes only for Analysis or an explicit inventory command. Do not add a complete inventory pass to `cache:clear`.
- Core makes no network calls, has no optional Connector dependency, and adds no generic PhpParser analysis of external application code.
- This milestone delivers the structured graph data catalog and preserves the local SVG and Mermaid graph layouts.
- Measure against the existing Core baseline with realistic small, medium, and approximately 100-module configurations. Additional Analysis collection must not cause uncontrolled performance regressions in Live.
- Repository, branch, CI, push, and release actions require separate authorization. In particular, do not modify or publish development and main branches on your own initiative.

## 9. Acceptance and implementation packages

**Package 1** introduces Core internationalization in English and German, shared internal data and text structures, the normative JSON Schema contract, and the PHP API and capabilities foundation. Hub and plugin implementation are outside scope.

**Package 2** implements both profiles, CLI adapters, source inventory, graph data, Doctor, fingerprints, and semantic mappings. It reuses the base analysis efficiently.

**Package 3** covers integrated tests, legacy regression, localization, partial failures, large projects, benchmarks, and API documentation. Correct only verified defects.

Accept the milestone when real Core output can reproduce both profile examples and validate against their schemas. The error and status matrix and cross-references must be correct. Live must remain lightweight, Analysis must provide the complete catalog, and code outside scope must produce no new architecture findings. Existing JSON v1 contracts and local graph, Doctor, and inspection commands must continue to work unchanged. Core text must have complete English and German coverage, and all external projects involved must remain unchanged.
