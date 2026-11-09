# Architecture analysis

`Sillbeam\Core\Architecture\ArchitectureAnalyzer` is the reusable entry point for the existing Module, Project and static dependency rules. It accepts `DiscoveryOptions` and the resolved architecture configuration already used by `ArchitectureCompiler`. It does not require a Symfony container or console application.

```php
use Sillbeam\Core\Architecture\ArchitectureAnalyzer;
use Sillbeam\Core\Architecture\DiscoveryOptions;

$result = (new ArchitectureAnalyzer())->analyze(
    new DiscoveryOptions($projectDir.'/src', 'App\\'),
    ['modules' => [], 'projects' => []],
);

foreach ($result->findings as $finding) {
    // Scalar schema-version-1 report data.
    $data = [
        'rule' => $finding->rule,
        'severity' => $finding->severity,
        'message' => $finding->message,
        'context' => $finding->context,
    ];
}
$result->throwIfInvalid();
$architecture = $result->architecture;
```

`ArchitectureAnalysisResult` exposes immutable findings, discovered input and observed code references. `isValid()` means no findings. `isComplete()` means no fatal blocker prevented further reliable checks. A completed run may still contain errors. `architecture` is available only for valid analysis; invalid or incomplete runs never expose a runtime graph. References accompanying an invalid result are evidence, not an approved dependency graph.

Each `ArchitectureFinding` contains `rule`, `severity`, `message` and a scalar `context` map. Available context includes descriptor/module/project class, file, dependency target, host, or source-reference line. Descriptor graph checks have no line in their existing discovery metadata; no line number is invented. File/line locations are present for parser errors and static code references when available. Configuration-only failures may have no file or module.

## Internal diagnostic foundation

`Sillbeam\Core\Architecture\Diagnostic` is internal and read-only:

- `ArchitectureAnalyzer` runs existing discovery, compilation and reference collection. Before rejecting an invalid compiler candidate, it copies original Module/Project declarations, available references and configured activation states/membership and host assignments into `DiagnosticContext`. The context retains **no `CompiledArchitecture` object**. Declaration objects remain discovery input; configured enabled/activation states are queried separately through `state()`.
- `DiagnosticContext::fromAnalysis($result)` reads that snapshot without parsing, compiling, autoloading or writing files. Results constructed outside the analyzer use available discovery/approved architecture; absent activation evidence stays `unknown`.
- `DiagnosticCollection::fromAnalysis($result)` attributes every original Finding position exactly once. `Diagnosis` contains an evidence basis, explanation, original entries, modules, descriptor paths and optional resolutions. The collection never rewrites/deduplicates Findings; existing analyzer deduplication is unchanged.

Grouping requires an evidenced **directed** declared/observed module relationship, a verified declared cycle, or a proven common cause (the same disabled module, duplicate scoped identity or configured host assignment). Equal rules, filenames or unverified endpoint labels alone never establish a group. Other Findings remain individual diagnoses. IDs (`D1`, `D2`, …) and order are deterministic for the input/run, with no promise of persistent issue numbers across changed runs.

`DeclaredDependencyGraph` uses **only declarations**. Sorted traversal gives shortest directed paths with lexical tie breaking and closed, ordered DFS cycle witnesses (`User → Catalog → User`). It does not enumerate every elementary cycle. Source descriptor paths prove outgoing declared edges; no line numbers are invented. Legacy cycle Findings and JSON output are unchanged.

`assessDependency(source, target)` checks a hypothetical direct declaration for an existing return path, privacy boundaries, an existing declaration, global activation and target activation in each Project currently activating the source. States distinguish `active`, `disabled`, `unassigned` and `unknown`; global activation does not imply activation in every Project. Observed references never become declared/activation edges.

After **any fatal failure**, including code-analysis failure after compilation, activation becomes `unknown`. Incomplete evidence retains positive path witnesses but cannot prove absent return paths/declarations or approve dependency/activation edits. Concrete recovery steps for the fatal blocker itself remain available. Unknown endpoints or reachable unresolved declarations also prevent negative return-path claims and dependency options. A null path alone is not proof of absence.

`Resolution` explicitly distinguishes technical recovery/investigation steps from domain `option`s. The [diagnostic rule catalog](diagnostics.md) supplies problem, impact and next steps for every existing rule. Conditional dependency options require clear local checks, a domain decision and fresh analysis; local checks do not certify the whole modified architecture or its changed activation. No edits are automatic.

The context never feeds Runtime registries, service activation or routing. `ArchitectureAnalysisResult::architecture` remains `null` for any Finding. The console presentation adapter consumes diagnoses as scalar presentation data, separately from schema-version-1 JSON and the standalone Markdown document renderer. No source analysis is added to warm production HTTP.

## Existing checks and identifiers

| Rule identifiers | Existing checks |
| --- | --- |
| `module.unknown_override`, `project.unknown_configuration` | Configured module/project must exist |
| `module.duplicate_key`, `project.duplicate_id`, `module.owner_missing` | Unambiguous logical identities and project ownership |
| `dependency.unknown`, `dependency.private`, `dependency.cycle`, `dependency.disabled` | Declared targets, private boundaries, acyclic graphs, enabled activation closure |
| `project.unknown_module`, `project.private_module` | Project activation must reference existing and permitted modules |
| `project.host_invalid`, `project.host_required`, `project.host_duplicate` | Exact, required and unique Project hosts |
| `code.unknown_class` | Unresolved application-owned static classes/types, classified through Composer PSR-4 and discovery namespace/path mappings; external types are outside this rule |
| `code.private`, `code.inactive`, `code.undeclared_dependency` | Existing static cross-module access/dependency checks for active modules |
| `discovery.module_boundary`, `discovery.project_boundary`, `discovery.owner_missing`, `discovery.project_inside_module`, `discovery.module_overlap`, `discovery.project_overlap` | Existing discovery and ownership boundaries |
| `discovery.descriptor_class`, `discovery.descriptor_file`, `discovery.multiple_descriptors`, `discovery.multiple_attributes`, `discovery.descriptor_metadata`, `discovery.descriptor_id` | Usable, unambiguous PHP descriptors |
| `source.parse`, `source.read`, `source.duplicate_class` | Readable PHP and an unambiguous source-symbol index |

`code.unknown_class` checks only statically referenced **application-owned** types
in Module and Project areas, including descriptor constructors and inactive
modules. Ownership comes from the application's Composer `autoload` and
`autoload-dev` PSR-4 namespace/path mappings together with configured discovery
roots. Empty fallback prefixes do not establish ownership. Composer's configured
vendor directory is excluded from application mappings. More specific external
Composer prefixes are not claimed by a broad application prefix. For overlapping runtime
PSR-4 mappings, the longest matching prefix decides ownership; a broader vendor
prefix cannot hide a more-specific mapping back into application source. There is no
Framework/Symfony/Doctrine/PSR namespace blacklist. External references never
produce this finding, even when a wrapped/custom autoloader cannot be inspected.

Known indexed types, already loaded types, existing files under the application's
configured PSR-4/discovery mappings and registered Composer file/classmap lookup
satisfy resolution. Target files are not executed, recursively scanned or
validated for correct declarations. Custom non-Composer runtime aliases cannot
be inferred from missing application names. This remains a bounded application
classification/resolution check, not PHP or Symfony service/autowiring validation.
Existing application targets outside modules retain their informational coupling
evidence when visible in the source index.

Development-only `Tests` and `Fixtures` directories (also nested) remain excluded,
and bare optional attribute names are not required to resolve. Class references
inside attribute arguments keep the same application-ownership boundary.
Dynamic names, PHPDoc, unused imports and descriptor attribute metadata retain
their existing scope. Unknown declared module dependencies still produce
`dependency.unknown`, independently of namespace ownership.

Findings retain class, source module/Project, file and line. Repeated uses on the
same file/line are deduplicated case-insensitively; distinct locations are retained.
The same classification applies to text, JSON and Markdown. No runtime hooks or
checks were added to warm production HTTP.

Other identifiers retain their existing checks and failure semantics.

## Informational application couplings

The existing parser and source index also identify module references to indexed
classes outside every discovered module. `ArchitectureAnalysisResult::applicationReferences`
contains evidence keyed by source module class: target `class`, source `file` and
`line`, and `target_file`. It is separate from module-edge `references`, and never
creates a declared dependency, activation edge, Project assignment or violation.

Classification uses the actual indexed declaration file and module ownership,
not a namespace prefix. Classes/interfaces in other modules remain subject to
unchanged direct-dependency, activation and private-Project rules. Indexed classes
in the classic application structure (including Project-owned code outside its
private modules) are recorded as application coupling. This adds no new rule for
Project-owned application code. Composer targets outside the configured source
index are not scanned/classified; external libraries, Symfony and Doctrine are
not recursively analyzed. A class with a vendor-like namespace in indexed
application source is still classified by its file.

Logical targets are unique per source module in reporting. Evidence is deduplicated
case-insensitively per source file/line/target class; separate uses on separate
lines retain their locations. Declared and observed module relationships remain
separate views of the same existing graph, with unique observed target modules.
Inactive modules retain observed evidence without being activated. All previously
supported static-reference positions participate; dynamic names, PHPDoc and
unused imports keep their existing limits. The preceding unresolved-class check
is unchanged; this extension adds no class-existence or autowiring validation.

Only files visible in configured discovery roots can supply application coupling
targets. Excluded/generated code, runtime aliases and application code outside
those roots may therefore have no coupling evidence. Reports with incomplete
analysis retain their existing unavailable/unknown semantics. See the optional
JSON fields and compatibility note in [CLI reporting](cli.md).

## Continuation and ordering

- `error`: a rule violation. Independent graph, host and code checks continue. Unknown edges are reported at the declaring module and skipped during traversal; cycles terminate through visited-node tracking. Disabled or inaccessible nodes are not activated to continue the analysis.
- `fatal`: source or ownership/identity ambiguity makes subsequent checks unreliable. All independently parseable files are examined for parse/read/attribute failures first; if any fail, discovery does not continue. Discovery-area ambiguities stop discovery. Identity failures still permit independent declared-dependency and cycle checks, but prevent ambiguous configuration/activation and code analysis. Duplicate symbols stop code analysis. Earlier findings are preserved.
- Only expected parser, descriptor and discovery failures are converted. Arbitrary exceptions from application descriptor execution and internal programming errors propagate. Configuration/option resolution before the analyzer retains its existing Symfony/PHP exceptions.

Findings are sorted by rule, file, numeric line and canonical remaining context, then severity/message. Context keys are sorted before deduplication; identical rule/severity/message/context tuples appear once. Repeated references to the same PHP symbol on the same source line are deduplicated case-insensitively. Separate source lines and separate rule violations remain distinct. Cycle checks traverse sorted classes/dependencies and report detected cycles without enumerating every possible path. Default analyzer instances use fresh source indexes and collectors on each run.

## Strict entry points and compatibility

The bundle's container build now uses the same analyzer and throws `ArchitectureValidationException` containing the complete available result in `$exception->result`. The exception extends `LogicException`, preserving existing catches and descriptive message fragments. Its message contains one `[rule] description` per finding; fatal parser/descriptor causes are retained through `getPrevious()`.

`ArchitectureCompiler::compile()` and `CodeDependencyAnalyzer::analyze()` remain strict and aggregate their own findings before rejecting invalid input. The `collect()` methods and mutable collector are internal implementation details; candidate graphs from them must not be used at runtime.

The shared result also powers [CLI architecture reporting](cli.md), including the entry point that works without a compiled container. CLI names, configuration alias, services, routing and Doctrine behavior remain unchanged.

## Development and production lifecycle

When Symfony compiles a container, Core runs the existing analyzer. An invalid or incomplete result keeps its findings and defers the entire graph-dependent integration. No module or Project registry, automatic services or Doctrine mappings are generated. The `type: sillbeam` route import contributes no routes in this state, so Symfony can still load native routes and warm its routing cache.

| Operation | Validation |
| --- | --- |
| CLI or debug container compilation | Analyzes once per container build and integrates Core only if the result is valid |
| `cache:clear`, `cache:warmup` and Composer auto-scripts | Follow Symfony's cache lifecycle. Core findings alone allow these operations to succeed |
| Warm debug request | Symfony checks tracked source and configuration resources and rebuilds when they change |
| Non-debug HTTP with a compiled container | Uses the previously built state without analyzing source files |
| Non-debug HTTP without a compiled container | Refuses compilation before discovery with HTTP 503, `Service temporarily unavailable.` |
| Explicit `sillbeam:architecture` with the Core console adapter | Analyzes current sources without compiling the container, with the documented [JSON and exit codes](cli.md) |

The cold HTTP policy also covers CLI-based workers using `Kernel::handle()` and Symfony's web runtime mode. The standard Symfony Console Application can display the architecture report stored in a successfully compiled container. Use the Core console adapter for fresh CI checks and for diagnostics when Symfony cannot compile the container. Configuration must still be readable to prepare a kernel-based report. Doctor checks installation and bootstrap evidence.

Symfony and Composer still enforce their own requirements. A native service that requires a deferred Core registry can fail autowiring. A native route or service import can fail on a syntax error that Core detected independently. A missing bundle or PHP package required by the application can prevent boot. Core configuration errors and unexpected exceptions still propagate. If Symfony requires the missing package during boot, Composer must install it before running the application's cache scripts.

Create each production release with its own fresh Symfony cache/build directory, then run:

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear
APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
APP_ENV=prod APP_DEBUG=0 php bin/console sillbeam:architecture --format=json
```

Require a successful cache build and a successful explicit architecture check before switching traffic. A successful cache command can leave Core integration deferred. After repair, rebuild the container to restore module services, routes and mappings. Use a fresh build directory for each release because warm non-debug containers retain their compiled state.
