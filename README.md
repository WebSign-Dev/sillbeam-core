<a id="readme-top"></a>

<div align="center">
  <img src="docs/assets/sillbeam-header.png" alt="Sillbeam — modular Symfony architecture, connecting feature modules, shared contracts and infrastructure." width="100%">
</div>

<div align="center">

[![Composer][composer-shield]][composer-url]
[![PHP][php-shield]](#requirements)
[![Symfony][symfony-shield]](#requirements)
[![License][license-shield]](LICENSE)

**A modular architecture layer for scalable, maintainable Symfony applications.**

[Website](https://getsillbeam.websign.de/) · [Quick start](#quick-start) · [Documentation](docs/README.md) · [v0.4.0 release notes](docs/releases/0.4.0.md) · [Report an issue][issues-url]

</div>

Sillbeam Core extends Symfony with explicit module boundaries, declared dependencies and optional Projects. It discovers your architecture, validates its dependency graph and checks static code references when Symfony builds the container. Symfony continues to handle services, routing and the application runtime.

Use it in an existing Symfony application or build a modular monolith from the start. Doctrine integration is optional, and the standard Symfony kernel is sufficient. `SillbeamKernel` provides a convenient alternative.

## Modular architecture

| Capability | Scope |
| :--- | :--- |
| **Feature modules** | Organize code through `#[SillbeamModule]` descriptors and declare direct dependencies. |
| **Architecture checks** | Detect cycles, missing dependencies, invalid boundaries and undeclared static cross-module references during container compilation. |
| **Symfony services** | Discover module services through native autowiring, autoconfiguration and Symfony service attributes. |
| **Module routing** | Import controller routes from active modules and Projects through one route-loader entry. |
| **Optional Projects** | Compose multiple surfaces with shared and private modules, including host-based routing. |
| **Optional Doctrine integration** | Register entity mappings from active modules and Projects when DoctrineBundle is installed. |
| **Flexible discovery** | Configure multiple roots, namespaces, exclusions and alternative controller or entity directories. |
| **Architecture diagnostics** | Inspect the catalog, activation reasons and dependency evidence as text or JSON. |

Modules normally live in `src/Module`; Projects live in `src/Project`. These defaults can be [configured](docs/reference/configuration.md). Each descriptor owns its directory and descendants. Descriptor filenames are flexible, but must match their PSR-4 class names.

## Quick start

### Requirements

- **PHP** `^8.4` and **Composer runtime API** `^2.2`.
- **Symfony** components `^7.4 || ^8.0` in the consuming application.
- **DoctrineBundle**, only if you want automatic Doctrine entity mappings.

Local v0.4.0 verification covered PHP 8.4 and 8.5 with Symfony 7.4 and 8.1.
Symfony 8.0 could not be resolved with Composer advisory blocking enabled.
See the [tested patch versions and verification scope](docs/releases/0.4.0.md#compatibility-and-scope).

### Install with Composer

From the root of a fresh or existing Symfony application:

```bash
composer require websign-dev/sillbeam-core:^0.4.0
```

For an existing 0.3 installation, follow the [upgrade guide](docs/migration/0.4.0.md).
The [Flex recipe](docs/guides/flex-recipe.md) registers the bundle and one route
import when available through your configured endpoint. Package availability and
public recipe availability are separate. The following steps cover manual setup.

Before running Doctor, select the Core Application in your **existing** `bin/console`:

```diff
-use Symfony\Bundle\FrameworkBundle\Console\Application;
+use Sillbeam\Core\Console\Application;
```

Keep the bootstrap, Runtime callback, import aliases and kernel. This deliberate
manual edit makes Doctor available even without the bundle or a working container.
Then run:

```bash
php bin/console sillbeam:doctor
```

Review its concrete next steps:

- Enable `Sillbeam\Core\SillbeamCoreBundle::class => ['all' => true]` in the existing
  bundle array if missing; preserve the other bundles and environment flags.
- Keep exactly one `sillbeam: { resource: ., type: sillbeam }` import, normally in
  `config/routes/sillbeam.yaml`. Do not duplicate a Flex-provided import.
- Append complete Module/Project discovery areas to broad native service imports'
  existing exclusions. Keep other exclusions and explicit services. For the usual
  `App\` import of `../src/`, an exclusion can be
  `../src/{DependencyInjection,Entity,Kernel.php,Module,Project}`; use actual paths
  for custom roots.

Neither Doctor nor the recipe overwrites those native files. Dynamic configuration
remains `not verified`; review it manually. A green Doctor result confirms static
evidence only. No `config/packages/sillbeam.yaml` is required for the defaults.
The [installation guide](docs/guides/getting-started.md) includes exact edits,
boot-failure recovery and the optional Doctrine setup.

```bash
php bin/console sillbeam:doctor
php bin/console sillbeam:architecture
php bin/console sillbeam:make:module Hello
```

Add the guide's [Greeting service and HelloController](docs/guides/getting-started.md#4-create-the-first-module)
to the new module. Then verify it:

```bash
php bin/console cache:clear
php bin/console sillbeam:architecture
php bin/console debug:router hello
```

A request to `/hello` through your existing development server returns
`Hello from Sillbeam`. The descriptor command alone creates no route. Existing
Projects must select the shared Hello module first; Project routes have prefixed
names and optional host filters. The Core Console adapter provides Doctor and
fresh architecture reporting before boot.

## Modules and Projects

Declare another module in `dependencies` whenever your module directly references its classes. Sillbeam checks declarations and observed static references, and reports invalid access with file and line information. Unused imports do not create dependencies. Dynamic class names, PHPDoc and string service IDs are outside this analysis.

Without Projects, all enabled shared modules are active. With Projects, each Project selects shared modules and automatically includes its enabled private modules; dependencies complete its graph. Unassigned shared modules remain in the catalog but contribute no automatic services, routes or entity mappings.

| Application layout | Use case |
| :--- | :--- |
| **Symfony only** | Keep a conventional Symfony application with no module or Project descriptors. |
| **Modules only** | Structure one application around enabled shared feature modules. |
| **Project only** | Use direct Project services, controllers and entities without modules. |
| **Single Project** | Group a surface and its private modules without requiring host configuration. |
| **Multiple Projects** | Compose surfaces with distinct hosts and shared or private modules. |
| **Hybrid** | Combine conventional Symfony code with module and Project directories. |

Projects share the container, database and event system. They provide architectural ownership and routing composition, rather than process or tenant isolation. Global subscribers and handlers are not automatically restricted to a Project's host.

Read the [module guide](docs/guides/modules.md) and [Project guide](docs/guides/projects.md) for dependency rules, activation and routing.

## Everyday workflow

Run these commands from your Symfony application's root. The Core Data and
locale commands shown here were introduced in v0.4.0.

| Command | Purpose |
| :--- | :--- |
| `php bin/console sillbeam:architecture` | Explain architecture findings and contextual next steps. |
| `php bin/console sillbeam:graph` | Write `architecture.svg` with the native Graph 2.0 layout in v0.4.0. Use `--module=Billing` for direct relationships or `--format=mermaid` for raw source ([reference](docs/reference/mermaid-graph.md)). |
| `php bin/console sillbeam:inspect:module Billing` | Inspect module state and merged direct relationships; use `-v` for technical evidence ([reference](docs/reference/module-inspection.md)). |
| `php bin/console sillbeam:doctor` | Inspect static installation evidence; review unverified checks manually. |
| `php bin/console sillbeam:doctor --format=json` | Export the [versioned Doctor result](docs/reference/doctor-json.md) with stable check IDs. |
| `php bin/console sillbeam:architecture --format=json` | Export the architecture catalog and dependency evidence. |
| `php bin/console sillbeam:api --profile=live --locale=de` | Export architecture, diagnostics and references in one locale. |
| `php bin/console sillbeam:api --profile=analysis` | Add structured graph data, source inventory, Doctor results and both locales. |
| `php bin/console sillbeam:locale --set=de` | Set the personal default for human-readable CLI output. |
| `php bin/console sillbeam:report` | Write a standalone Markdown architecture report. |
| `php bin/console sillbeam:about` | Show application, runtime and module information. |
| `php bin/console debug:container` | Inspect Symfony service registration. |
| `php bin/console debug:router` | Inspect imported application routes. |
| `php bin/console doctrine:mapping:info` | Inspect entity mappings when DoctrineBundle is installed. |

In v0.4.0, invalid architecture defers Core's automatic runtime integration
while Symfony cache maintenance can proceed. Before routing production traffic
to a release, require successful cache preparation and a successful explicit
architecture check through the Core Console adapter. Doctor checks installation
evidence statically and reports dynamic or ambiguous configuration as
`not verified`. See the [CLI reference](docs/reference/cli.md),
[lifecycle and cache behavior](docs/reference/architecture-analysis.md#development-and-production-lifecycle)
and [troubleshooting guide](docs/operations/troubleshooting.md).

## Documentation

| Guide | Contents |
| :--- | :--- |
| [Documentation overview](docs/README.md) | Guide index and starting points. |
| [Getting started](docs/guides/getting-started.md) | Installation, descriptors and native Symfony integration. |
| [Architecture](docs/architecture/overview.md) | Discovery, ownership, compilation and dependency boundaries. |
| [Modules](docs/guides/modules.md) | Descriptors, dependencies and activation rules. |
| [Projects](docs/guides/projects.md) | Shared and private modules, hosts and request context. |
| [Configuration](docs/reference/configuration.md) | Discovery roots, exclusions and integration settings. |
| [Public API](docs/reference/public-api.md) | Attributes, registries and runtime interfaces. |
| [Core Data API v1](docs/reference/core-data/README.md) | Live, Analysis, capabilities, source inventory and versioned JSON schemas. |
| [English API JSON](docs/reference/phpdoc-json.md) | Manually export English API JSON with `composer docs:export-json`; translation and integration are separate manual work. |
| [Doctrine](docs/modules/doctrine.md) | Optional entity mapping integration. |
| [Upgrade from v0.3.0](docs/migration/0.4.0.md) | Package, CLI and deployment steps for v0.4.0. |
| [Testing](CONTRIBUTING.md#run-the-checks) | Repository checks and integration coverage. |
| [Test applications](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/test-apps.md) | Example layouts and the isolated consumer application. |

## Release status

See the v0.4.0 [release notes](docs/releases/0.4.0.md),
[changelog](CHANGELOG.md) and [upgrade guide](docs/migration/0.4.0.md).
The [v0.3.0 release notes](docs/releases/0.3.0.md) and
[v0.3.0 upgrade guide](docs/migration/0.3.0.md) remain available for the published line.

Check [Packagist][composer-url] for installable versions and
[GitHub Releases][releases-url] for release history. Composer derives stable
versions from Git tags. Existing `^0.3.0` requirements stay on the 0.3 line.

## Scope and migration

Sillbeam focuses on module architecture within Symfony. Services, HTTP handling and runtime behavior use Symfony's existing mechanisms. Applications supply their own business functionality and infrastructure integrations.

Applications upgrading from the former Foundation layer should follow the
[historical migration guide](docs/migration/modular-monolith.md).

## Development & support

From the Sillbeam repository root:

```bash
composer install
composer validate --strict
composer test
composer analyse
composer architecture
composer cs:check
composer test:consumer
composer test:flex-recipe
SILLBEAM_TEST_DOCTRINE=1 composer test:flex-recipe
```

The example applications cover six operating variants. The isolated consumer lives under `apps/test-app`; its setup is described in the [test-app guide](https://github.com/WebSign-Dev/sillbeam-core/blob/3f891afbff43a0ab48af4ec5991f19aa59fe80b0/docs/development/test-apps.md).

Read [Contributing](CONTRIBUTING.md) for development setup, required checks and pull request guidance, [Support](SUPPORT.md) for assistance and [Security](SECURITY.md) for private vulnerability reporting.

When [reporting an issue][issues-url], include your PHP and Symfony versions, relevant Sillbeam configuration, the application layout and diagnostic output. Remove credentials from shared output.

## License

Sillbeam is licensed under the [MIT License](LICENSE).

---

<div align="center">

[Documentation](docs/README.md) · [Report an issue][issues-url] · [Back to top](#readme-top)

</div>

<!-- Navigation -->
[issues-url]: https://github.com/WebSign-Dev/sillbeam-core/issues
[releases-url]: https://github.com/WebSign-Dev/sillbeam-core/releases
[composer-url]: https://packagist.org/packages/websign-dev/sillbeam-core

<!-- Badges: package version and compatibility -->
[composer-shield]: https://img.shields.io/packagist/v/websign-dev/sillbeam-core?label=Composer&logo=composer&logoColor=white
[php-shield]: https://img.shields.io/badge/PHP-%5E8.4-777bb4?logo=php&logoColor=white
[symfony-shield]: https://img.shields.io/badge/Symfony-7.4%20%7C%208.1-000000?logo=symfony&logoColor=white
[license-shield]: https://img.shields.io/badge/license-MIT-57606a
