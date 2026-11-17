# Targeted module inspection

```bash
php bin/console sillbeam:inspect:module Billing
php bin/console sillbeam:inspect:module billing --project=shop
php bin/console sillbeam:inspect:module 'App\Project\Shop\Module\Billing\BillingModule' -v
```

The command explains one module using the existing `ArchitectureAnalyzer` →
`ArchitectureAnalysisResult` → `ArchitectureReport` pipeline. It reads the same
catalog, configuration, validated activation graphs, static-reference evidence
and findings as architecture reporting. It neither discovers modules nor analyzes
dependencies a second time. Incoming relationships reverse the existing direct
edges; no transitive graph or additional validation rules are computed.

## Module and Project selection

Pass a module ID or the **fully qualified** descriptor class. Matching is
case-insensitive, so `Billing` selects the conventional `billing` ID. An optional
leading backslash on a descriptor class is accepted. Folder names, fuzzy matches
and abbreviated class names are not selectors; a descriptor with a custom ID must
be selected by that ID or its full class.

If several modules share an ID, the command lists their classes, scope and owning
Project rather than selecting the first. IDs are scoped identities: for example,
`billing` can legitimately exist in both Shop and Admin as a private module.

`--project` accepts a Project ID or full descriptor class. It:

- restricts private-module candidates to that owner;
- provides a Project activation context for shared modules, including shared
  modules that are disabled or unassigned there.

It does not assign or enable anything. A shared module remains a candidate even
when unassigned; otherwise its unassigned state could not be inspected. If a
shared and private module still collide in that Project, pass the full descriptor
class. There is no implicit preference for private or shared scope. Ambiguous
Project IDs likewise require their descriptor class. A private module from a
different owner is not selected in the requested Project context.

Use the command-first spelling above with ordinary Symfony Console. The Core
adapter additionally recognizes `--project shop sillbeam:inspect:module Billing`.

## Text compatibility

The 0.3.0 compact view changes the earlier human-readable layout. Scripts parsing
those lines must adapt; `-v` exposes the preserved technical evidence, not a stable
serialization. Module selection and 0/1/2 exit semantics remain unchanged. There is
no `--format` option or inspection JSON schema; use architecture-report JSON when
its existing fields meet automation needs.

## Compact default output

The normal view focuses on the selected module and problems:

- Module ID and **Active**, **Disabled**, **Unassigned** or **Unknown** state.
- Shared/Private scope, owning Project for private modules, selected Project context.
- Whole-analysis status and counts of known direct outgoing/incoming relationships.
- **DEPENDS ON** and **USED BY**, with one row per descriptor identity and evidence label.
- Related issues and fatal global blockers, or a brief success message when evidence
  and activation are known and the complete architecture is valid.

IDs are display labels, never identities. A shared/private collision appears as
`user [shared]` and `user [private: shop]`. Same-owner collisions receive a unique
short descriptor suffix; duplicate Project IDs receive descriptor context. Unknown
owners are explicitly unknown, with distinguishing descriptor context. Unresolved
targets are marked `[unresolved]`, not assigned an invented logical ID. Full classes
remain accessible with `-v` and remain valid selectors.

The counts describe unique direct relationships recorded in the available evidence,
including inactive modules and unresolved descriptor targets. They are not a count
of references, transitive dependencies, Project memberships or runtime services.

### Evidence labels

| Label | Meaning |
| --- | --- |
| Declared + Observed | Direct dependency is declared and a matching static reference was recorded. |
| Declared only | Dependency is declared; no matching reference was recorded in the source module's complete reference evidence. **This does not mean the declaration is unnecessary.** |
| Observed without declaration | A recorded reference targets a module absent from the source's direct declarations. Marked as a problem. |
| Declared; observation not verified | Declaration is known, but missing/incomplete reference evidence cannot establish whether it was observed. |

Outgoing rows use the selected module's reference completeness. Incoming rows use
**each source module's** completeness; an unrelated incomplete source does not
turn another source's complete evidence into unknown evidence. A global completeness
warning still stays visible. Positive recorded observations remain evidence even
when other observations are incomplete. Duplicate references to the same target
produce one row. A row can appear in both directions when the existing evidence
contains reciprocal relationships; no cycle or transitive path is inferred here.

Declared-only relationships can support dynamic wiring, runtime integration or
contracts outside the existing static-reference scope. This view neither detects
unused dependencies nor recommends deleting such declarations. Observed references
neither declare nor activate a target. Existing architecture cautions remain visible,
including privacy, unknown activation and cases where adding a declaration would
create a cycle. There is no new dependency assessment or automatic repair.

### User example

The following was generated from a real analyzed fixture: User declares Mail and
Profile, references Profile, and is declared and referenced by Admin. Exit code: 0.
It illustrates output, not the architecture of an external consumer application.

```text
  SILLBEAM CORE  / Module inspection
  ────────────────────────────────────────────────────────────────────────────
  Module: user — Active
  Scope: Shared
  Architecture: Valid (whole analysis)
  Known direct relationships: 2 depends on · 1 used by
  ────────────────────────────────────────────────────────────────────────────
  DEPENDS ON
    mail — Declared only
    profile — Declared + Observed
  ────────────────────────────────────────────────────────────────────────────
  USED BY
    admin — Declared + Observed
  ────────────────────────────────────────────────────────────────────────────
  ARCHITECTURE ISSUES
  ✓ No related architecture findings in the available evidence.
```

Standard findings reuse existing diagnosis titles with relevant short identities,
offending host/key values, source locations and existing cautions. Where the report
already provides a correct relative path, it is retained. Files outside a known
root fall back to the actual basename; no project-relative root is invented.
Long absolute paths and repeated configuration/activation fields are technical
output. Original rule IDs remain visible in normal findings.

## Verbose evidence (`-v`)

Verbose mode adds technical evidence after the same compact view:

- Full descriptor classes and complete descriptor file paths.
- Canonical configuration key, actual override source and configured enabled override.
- Effective enabled flag, selected-context active/state, global active flag and
  recorded activation reasons, without treating those inputs as runtime proof.
- Owner/inspection Project classes and applicable per-Project activation states.
- Separate declared and observed outgoing/incoming class lists.
- Concrete referenced classes and full source file/line locations in both directions.
- Original related findings and global blockers, including severity, unchanged
  original message and complete scalar context (including available suggestions).

Paths/classes may wrap to terminal width in verbose mode; their contents are not
shortened. When selection fails because analysis is unavailable, verbose output
still includes original blocker messages/context. Unrelated nonfatal findings stay
outside the module view; use `sillbeam:architecture --all` for the full analysis.

The configuration key is normally `shared:user` or `project:shop:user`. Legacy
shared overrides such as `modules.user` remain supported; the canonical key takes
precedence. Unknown identity/configuration provenance remains unknown.

## Availability, activation and failures

| State | Meaning |
| --- | --- |
| Active | Enabled and proven active in the selected validated graph/context. |
| Disabled | Known disabled configuration and proven inactivity. |
| Unassigned | Enabled but proven absent from the selected validated graph/context. |
| Unknown | The required validated graph or effective configuration is unavailable. |

Without `--project`, the state describes the union of application/Project graphs.
With it, the state describes the selected Project. A shared module may therefore
be **Unassigned in Admin but active globally**. The default names the selected
Project and explicitly notes a different global activation. `-v` always includes
both values and per-Project states. Private modules only have their owning Project
as an applicable Project context. Selecting a Project never assigns anything.

Any architecture finding prevents publication of a runtime graph. Diagnostic
candidate membership never becomes actual activation. Even when configuration
permits a module, its state can therefore remain **Unknown**. A configured
`enabled: false` input can remain visible in verbose mode while the actual state
is unknown; it does not establish a proven disabled runtime state.

Missing reference evidence is not proof of absence. The default always warns about
incomplete observations, and incomplete analysis/catalogs qualify the direct counts.
Related findings and fatal global blockers remain visible without `-v`. An empty
related-findings list does not produce a green success when the whole analysis is
invalid/incomplete, activation is unknown or references are incomplete. The global
status and pointer to the full architecture command still explain failures elsewhere.

If source parsing prevents discovery, selection explains that the catalog is
unavailable and shows existing blockers instead of claiming that the module is
absent. Existing relevance rules remain unchanged: source/target identities, module
source locations, disabled declared targets, recorded cycle participation and
applicable Project host issues. No additional architecture rule is introduced.

## Execution, exit codes and limits

| Exit | Meaning |
| --- | --- |
| 0 | Selection succeeded and the architecture snapshot is valid; disabled/unassigned modules are not failures. |
| 1 | Selection succeeded but the whole analysis has architecture violations. |
| 2 | Invalid usage, unknown/ambiguous module or Project, execution failure or incomplete/unavailable analysis. |

This command is **text only**. There is no `--format` option or JSON inspection
mode. `--quiet` suppresses text without changing the exit code. `--help` describes
usage without running the report provider in the Core adapter.

Ordinary Symfony Console obtains the existing report stored with its compiled
container, as architecture reporting does. In production a warm container is a
snapshot; rebuild it when deployment inputs change. Activation means membership
in the Sillbeam graph, not an audit of every Symfony service, route or explicit
application override.

To inspect current source even when architecture violations or broken services
prevent container compilation, use the existing
[Core Console adapter](cli.md#enable-reporting-before-container-compilation).
It obtains a fresh report through `KernelArchitectureReporter`, using the same
Symfony configuration preparation and analyzer as other reporting commands.
Normal Symfony startup can fail before a container-backed command becomes available.

The command does not change module settings, generate descriptors, repair files
or prepare runtime services. Existing reporting preparation can initialize bundle
hooks and execute application configuration/descriptor code; their behavior is
unchanged. No new discovery, parser, dependency analyzer or runtime hook is added.
