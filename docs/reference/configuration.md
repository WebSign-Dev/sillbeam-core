# Configuration

Without a package configuration file, the same defaults apply as for `sillbeam: {}`. The source boundary is `%kernel.project_dir%/src`; its namespace is determined from Composer PSR-4. Relative paths refer to the application directory. Ambiguous PSR-4 mappings require an explicit namespace.

```yaml
sillbeam:
    locale: en
    discovery:
        root: '%kernel.project_dir%/src'
        namespace: 'App\'
        excludes: ['Generated/**', '**/Tests/**']
        # When specified, each list replaces the default for that kind.
        modules:
            - path: src/Domain
              namespace: 'App\Domain\'
              excludes: ['Legacy/**']
            - path: packages/Accounting/src
              namespace: 'Accounting\'
        projects:
            - path: src/Surface
              namespace: 'App\Surface\'
    integrations:
        controllers: [Http, '**/Controller']
        entities: [Model, '**/Entity']
        services:
            exclude: ['Model/**', '**/Entity/**', '**/Dto/**', '**/Tests/**', '**/Fixtures/**', '**/Migrations/**']
    modules:
        legacy: { enabled: false }
        'project:shop:old-reports': { enabled: false }
    projects:
        shop: { hosts: [shop.example.com, www.example.com] }
        admin: { hosts: [admin.example.com] }
    doctrine:
        enabled: null
```

Multiple configuration files are merged according to Symfony rules: root, exclusion and integration lists are appended; an explicit entry replaces the corresponding default. An empty list removes the default but does not delete entries from earlier configuration files.

Without specified lists, Module roots are `src/Module` and Project roots are `src/Project`. These conventional directories may be absent. An empty list removes the corresponding default. Explicit roots must exist and Composer autoloading must be able to load their namespaces. A missing required root that reaches analysis is reported as `source.read`. Identical paths with conflicting namespaces are errors. The source root remains monitored as well, and descriptors outside permitted boundaries are reported.

Patterns use `/`, `*` (one segment), `**` (recursive) and `?`. Global exclusions are relative to the source root or absolute; local exclusions are relative to their root. Exclusions apply to discovery and automatic service, route and entity imports. Controller/entity patterns select directories relative to the descriptor area. Defaults: `Controller`, `**/Controller` and `Entity`, `**/Entity`.

Service imports exclude `Entity/`, `Dto/`, `Tests/`, `Fixtures/` and `Migrations/` by default, directly under the Module/Project descriptor and at any nesting depth. These exclusions apply only to automatic service registration; discovery, routing and Doctrine retain their own configuration. An explicit `integrations.services.exclude` list replaces these defaults, so retain the development-directory patterns when customizing it. An empty list opts into importing all directories. If entities are placed under `Model`, add its service exclusion. Native Symfony attributes and explicit `services.yaml` remain available.

Doctrine: `null` activates automatically when DoctrineBundle is registered; `true` requires it and fails without the bundle; `false` disables Sillbeam mappings. The shorthand `doctrine: false` is allowed. Only active modules are mapped automatically; direct Project entities are also mapped. Manual native mappings are preserved, even when they intentionally include excluded areas again.

Activation options can use `%env(bool:...)%`. They are resolved at build time. Rebuild the container cache after changing build environment variables.

Former Foundation keys such as `api`, `security`, `user` or `management` are invalid; see [Migration](../migration/modular-monolith.md).

Project hosts are exact, trimmed hostnames without scheme, port, path or wildcard; case is normalized. IDs match `[a-z][a-z0-9-]*`. Unknown Module or Project configuration addresses produce architecture findings and defer Core runtime integration during container compilation.

`locale` sets the project's language for human-readable output. Supported values are `en` (default) and `de`. A personal preference or an explicit CLI locale can override it. Machine-readable profiles follow their own language rules. See [CLI locale selection](cli.md#locale-configuration) and the [Core Data contract](core-data/README.md).

See [source index and cache invalidation](architecture-analysis.md#source-index-and-cache-invalidation) for persistent cache placement, Symfony rebuilds and long-lived PHP processes.

## Configuration prepended by other bundles

Symfony runs bundle extension `prepend()` methods in registration order before loading extensions. Without active Doctrine integration, Sillbeam applies the final configuration when loading its extension, including configuration prepended by a later bundle.

When Doctrine integration is enabled, Sillbeam prepares its mappings during `prepend()`. Register any bundle that prepends Sillbeam configuration **before `SillbeamCoreBundle`**, or set the options directly in application configuration. If a later bundle changes Sillbeam configuration while Doctrine integration is enabled or becomes enabled, the build fails with an explicit configuration-order error rather than using conflicting architecture and mapping states. Prepended configuration that leaves the processed settings and discovery options unchanged is allowed.
