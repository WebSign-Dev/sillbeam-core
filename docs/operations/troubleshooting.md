# Troubleshooting

- **Ambiguous namespace:** specify `discovery.namespace` or a namespace per root; check Composer PSR-4 and `composer dump-autoload`.
- **Missing required discovery root:** restore the directory or correct the explicit path and namespace in [configuration](../reference/configuration.md). The conventional Module and Project directories may be absent unless explicitly configured.
- **Descriptor outside the boundary:** configure a Module/Project root or move the file inside the permitted boundary. A private module needs a Project descriptor as its physical owner.
- **Overlapping areas:** separate descriptor directories. Domain subdirectories without their own descriptor are allowed.
- **Missing dependency:** add the directly referenced target module to `dependencies`; check the reference at the file/line reported by the exception.
- **Disabled target:** remove the dependency or enable the target. `enabled` and `active` are different states; with Projects, shared modules need an assignment.
- **Missing service:** check exclusions, environment attributes and active assignment; native overrides are possible. Multiple implementations require an explicit alias.
- **Unexpected route/entity:** explicit native imports can bypass Sillbeam exclusions. With multiple Projects, account for route name prefixes and host filters.
- **Missing Doctrine:** install/register the bundle or disable Sillbeam integration. The core package does not require Doctrine.
- **Activation change has no effect:** build environment variables require a cache rebuild. Development resources monitor source directories; production caches are rebuilt during deployment.
- **Non-debug HTTP returns 503 on a cold build:** prepare the Symfony cache with CLI commands and require a successful explicit architecture check before serving traffic. See the [deployment checks](../migration/0.4.0.md#deployment-checks).
- **Descriptor was changed while the process was running:** start a fresh PHP process. See [source index and cache invalidation](../reference/architecture-analysis.md#source-index-and-cache-invalidation).

Diagnostics: `sillbeam:architecture --format=json`, `debug:container`, `debug:router`; see [CLI](../reference/cli.md).
