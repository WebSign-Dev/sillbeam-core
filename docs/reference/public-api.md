# Public API

Container-independent CLI reporting uses `Sillbeam\Core\Console\Application` with `ArchitectureReport`; see [CLI setup and schema](cli.md).

Structured validation is available through `Sillbeam\Core\Architecture\ArchitectureAnalyzer`; see [Architecture analysis](architecture-analysis.md) for findings, failure semantics and strict entry points.

- `SillbeamCoreBundle`: central Symfony entry point.
- `Module\Attribute\SillbeamModule`: `id`, `description`, `dependencies`.
- `Project\Attribute\SillbeamProject`: `id`, `description`, `modules`.
- `ModuleRegistryInterface`: `all()`, `get(class)`, `active()`, `forApplication()`, `forProject(class)`.
- `ProjectRegistryInterface`: `all()`, `get(idOrClass)` and `forHost(host)`.
- `ProjectContextInterface`: `current()`, `id()`, `is(id)`, `hasModule(class)`.
- `ModuleDefinition`/`ProjectDefinition`: immutable catalog and activation data. Modules include declared/observed dependencies, reference evidence and activation reasons.
- `Kernel\SillbeamKernel`: optional kernel; standard Symfony kernels remain supported.
- `KernelInformationInterface`: runtime information for `sillbeam:about`.

Discovery, compiler, AST index and native loaders are build implementation components. Foundation contracts are no longer part of the public API since the evolution. The [migration guide](../migration/modular-monolith.md) lists removed areas.

Registry lookups for unknown classes, IDs or hosts throw `OutOfBoundsException`. The optional request context distinguishes missing Projects/requests (`null`) from unassigned hosts when Projects exist (exception); see [Projects](../guides/projects.md).

## Types and return values

The full namespaces of the registry contracts are `Sillbeam\Core\Module\ModuleRegistryInterface`, `Sillbeam\Core\Project\ProjectRegistryInterface` and `Sillbeam\Core\Project\ProjectContextInterface`. They can be injected directly through constructors.

Registry lists are arrays keyed by descriptor class names with `ModuleDefinition` or `ProjectDefinition` values. `ModuleRegistryInterface::get()` and `forProject()` accept descriptor class names, not IDs; `ProjectRegistryInterface::get()` accepts an ID or class. `active()` is the union of active graphs. `forApplication()` is empty in Project mode.

`ModuleDefinition` provides, among other fields, `id`, `class`, `description`, `dependencies`, `enabled`, `projectClass`, directories/namespace, `observedDependencies`, `dependencyReferences` and `activationReasons`. `enabled` does not automatically mean active; activity is determined by registry membership, with no `active` property. `isPrivate()` checks Project ownership; `key($projectId)` requires the owning Project ID for private modules.

`ProjectDefinition` contains `declaredModules`, the compiled `modules` graph, `hosts` and discovery data. `canonicalHost(): ?string` returns the first host or `null`. `Sillbeam\Core\Kernel\Runtime\KernelInformationInterface::all()` returns an `array<string, string|int>`.

```php
use Sillbeam\Core\Module\ModuleRegistryInterface;

final readonly class ArchitectureStatus
{
    public function __construct(private ModuleRegistryInterface $modules) {}

    public function activeModuleCount(): int
    {
        return count($this->modules->active());
    }
}
```

## Generated PHPDoc reference

Run `composer docs:build` and open `build/docs/api/index.html` for the English HTML
reference generated from source. The [Core Data v1 reference](core-data/README.md)
describes the new PHP and JSON contracts, and the [CLI reference](cli.md) covers the
console interfaces. phpDocumentor is a development dependency only.

Supported declarations are marked `@api`. The analyzer's input types `DiscoveryOptions`
and `DiscoveryRoot`, and output types `ArchitectureAnalysisResult`, `ArchitectureFinding`,
`ArchitectureValidationException`, `CompiledArchitecture` and `DiscoveryResult` document
the analysis contract alongside `ArchitectureAnalyzer` and `ArchitectureReport`.
The graph/catalog objects are data snapshots: constructing them directly performs no
validation. Use the analyzer to obtain validated runtime graph data. Discovery and
compiler services themselves remain internal. Expected findings are returned by
`analyze()`; arbitrary exceptions from application descriptor code propagate.
`throwIfInvalid()` provides strict failure with the available result attached.

Concrete registry/context/information services are internal; inject the documented
interfaces. `@internal` declarations and members are excluded from the public HTML.
See [Contributing](../../CONTRIBUTING.md#code-documentation-and-api-reference) for the
PHPDoc standard, tooling prerequisites and generated-file policy.

## English JSON artifact

`composer docs:export-json` exports the same public boundary to
`build/docs/api/sillbeam-core.en.json`. See the [JSON contract](phpdoc-json.md)
and [schema v1](phpdoc-api.schema.json) for stable IDs, text/code fidelity, fields
and phpDocumentor 3.10 limitations. HTML remains available through `docs:build`.
