# Sillbeam Core v0.4.0 API Contract

This contract covers the Core Data API introduced in v0.4.0. The four [JSON Schemas](README.md) are normative. This document defines the semantics, operational behavior and scope of their responses. The files under `examples/` are illustrative responses.

## 1. Responsibilities and architecture

- Hub, Connector and IDE plugins are external consumers. The contract is provided by Sillbeam Core alone.
- Core uses one architecture engine and one source of domain data. The two profiles, `live` and `analysis`, define the scope of data and processing. They confer no permissions and are independent of product profiles.
- `live` provides available architecture, reference and diagnostic data in one locale. It omits the additional source inventory, full graph data preparation and Doctor collection.
- `analysis` adds structured graph data, a catalog of relevant source files, Doctor results and Core text in all available locales. It describes the completeness of each domain in one export, without invoking CLI subprocesses or rendering SVG or Mermaid.
- Additional Core operations remain explicitly callable, including `sillbeam:graph`, `sillbeam:doctor`, `sillbeam:inspect:module`, and source inventory. Extensions do not require special profiles.
- The public PHP API returns immutable data models. The CLI serves as an adapter and serializer. Core has no dependency on the network, Hub, Connector, or IDE plugins.

### Binding scope boundary

Architecture rules evaluate only code within the existing Sillbeam architecture scope. Outside that scope, Analysis may collect inventory and symbol information. Syntax errors, unresolved references, style deviations, and other code quality problems in outside code produce no architecture findings. If an inventory error is collected, it appears only in `errors` with domain `source_inventory` and limits inventory completeness.

Module rules can use references to outside classes as evidence. A reference to an outside class alone establishes no violation. Discovery still validates descriptor placement and other evidence required for the configured architecture scope. Deep tracing, call graph construction and general-purpose source analysis are outside scope.

## 2. Public interfaces

The Core console adapter exposes these commands.

```sh
php bin/console sillbeam:api --profile=live --locale=de --schema-version=1
php bin/console sillbeam:api --profile=analysis --schema-version=1
php bin/console sillbeam:api:capabilities --format=json
php bin/console sillbeam:inspect:sources --format=json
```

- Live accepts an optional `--locale`, which defaults to `en`. Analysis rejects locale selection because it includes all available Core locales. An unknown locale, profile, or schema version produces a deterministic invocation error.
- `sillbeam:inspect:sources --format=json` returns the `#/$defs/sourceInventory` object from `core-data-common.v1.schema.json` directly. Analysis uses the same source inventory type.
- `capabilities` reports supported profiles, schema versions, locales and individual Core operations without running project architecture analysis.
- Core writes the JSON document to STDOUT and technical CLI messages to STDERR. Automated consumers can pass `--no-ansi` and `--no-interaction`.
- The separate `sillbeam:architecture --format=json` and `sillbeam:doctor --format=json` retain their existing schema v1 contracts. Local graph and module inspection commands retain their exit semantics.

### Public PHP interface

The namespace is `Sillbeam\Core\Api`.

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
| `diagnostics.findings[].message` | English technical Core message. Free exception text from descriptor attribute instantiation is omitted |
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
- Analysis uses [fingerprint algorithm v2](README.md#analysis-snapshot) with canonical sorting and SHA-256. The fingerprint version is independent of the response schema version. An incomplete or changing architecture projection has a null fingerprint. Live leaves it null.
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
- In Analysis, a valid architecture with an unreadable discovered file outside scope yields `analysis.status=valid`, `export.status=partial`, `completeness.source_inventory=partial`, and a technical inventory error. It produces no architecture finding.
- A fully executed Doctor check with `action_required` yields `completeness.doctor=complete` and produces no transport or export error.
- Data that was not collected cannot establish absence. In incomplete results, report `active=true` or empty dependencies only where evidence supports those claims.
- When a rule is violated, the graph is `evidence_only` and includes known edges. It makes no claim of runtime activation.
- Analysis checks the before-and-after state of collected sources. A detected inconsistency reduces export completeness. Concurrent writes preclude any guarantee of a fully atomic filesystem view.

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
14. The export contains no raw PHP contents or exception stack traces. Core replaces free descriptor and Doctor exception messages with localized technical messages in Core Data. Preserve confidentiality when sharing paths, structured contexts, application-supplied descriptions or legacy diagnostic output.

## 8. Stability, performance, and implementation limits

- Each collection runs the architecture analysis once and shares its findings and static evidence. Analysis adds the inventory, consistency checks and other Analysis-only domains. The standalone inventory avoids architecture rule evaluation.
- Symfony cache builds use the base architecture checks. Analysis exports and standalone source inventory run only when requested. See [source index and cache invalidation](../architecture-analysis.md#source-index-and-cache-invalidation) for index reuse, rebuilds and PHP process limits.
- The structured graph export is separate from the local [SVG and Mermaid commands](../mermaid-graph.md).
- To validate the examples against the schemas from a source checkout, run `python3 tools/test-core-data-schemas.py`. See the [release notes](../../releases/0.4.0.md) for measured lifecycle behavior and the verified environment.
