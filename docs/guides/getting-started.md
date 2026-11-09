# Getting started

Use this path for a fresh or existing Symfony application: install with Composer,
select the existing Core Console adapter, run Doctor, review the native Symfony
integration, then create a working module. Keep your Symfony kernel and existing
configuration. Sillbeam requires PHP 8.4+ and Symfony 7.4 or 8.x; Doctrine is optional.
The application must have a Composer PSR-4 mapping such as `"App\\": "src/"`.
The examples use those default directories and namespace. PHP files below start with `<?php`.

## 1. Install with Composer

From the Symfony application's root:

```bash
composer require websign-dev/sillbeam-core
```

Composer selects an available release compatible with the application. This guide
prepares the 0.3.0 onboarding flow; use `:^0.3.0` when that release is published.
Package installation does not prove that the Flex recipe ran. Inspect the Composer
output and `symfony.lock`; recipe availability depends on the configured endpoint
and Contrib approval. This checkout does not publish a version or a recipe.
See [release status](../../README.md#release-status) and the [Flex guide](flex-recipe.md).
Development consumers can use this checkout through a local Composer path repository;
the repository's Flex acceptance test uses a simulated 0.3.0 version, not a public tag.

Core architecture findings allow Composer's cache auto-scripts to proceed with Core
runtime integration deferred. If Symfony still cannot compile the application, correct
that reported error or repeat the require command with `--no-scripts` to defer
application scripts. After the manual corrections below, rerun the application's
normal Composer auto-scripts (typically `composer run-script post-update-cmd`) and
its cache build. Do not force recipe installation or replace native files to get
past a boot error.

## 2. Make Doctor available before boot

In the existing `bin/console`, change only the Symfony Console Application class
used by the bootstrap:

```diff
-use Symfony\Bundle\FrameworkBundle\Console\Application;
+use Sillbeam\Core\Console\Application;
```

Keep the kernel construction, Runtime callback, autoloading, environment handling
and the rest of the bootstrap. Preserve any import alias, or change the fully
qualified Application construction if that is what the file uses. The adapter
supports the existing Symfony console; it does not replace the kernel.
Neither the Flex recipe nor Doctor makes this edit for you.

```bash
php bin/console sillbeam:doctor
```

This exact command now runs without booting or compiling the container, even if
the bundle is missing. If the command was previously unavailable or Symfony failed
before showing Doctor, verify this adapter selection first. Ordinary Symfony console
commands, including `sillbeam:make:module`, still need a working container.

## 3. Review and correct the integration

Doctor reports the affected files and the next manual change. Apply only changes
you have reviewed; preserve comments, services, routes, bundles and existing exclusions.
The recipe handles bundle registration and a route import when explicitly installed.
Do not add them again if Flex already provided them. No package configuration file
is needed for the defaults.

**Bundle.** If missing, add this entry to the existing `config/bundles.php` array:

```php
Sillbeam\Core\SillbeamCoreBundle::class => ['all' => true],
```

Review environment-specific flags: an explicit `dev => false` or `prod => false`
overrides `all => true` for that environment. Keep all other bundles.

**Routing.** Keep exactly one effective Sillbeam import. If none exists, create
`config/routes/sillbeam.yaml` or add an entry to your existing route configuration:

```yaml
sillbeam:
    resource: .
    type: sillbeam
```

Do not overwrite an existing destination or add a second import under another
name: the loader runs once. Keep native controller routes and other route imports.

**Services.** Broad Symfony resource imports must exclude complete discovery areas
when Sillbeam should control automatic activation. Append `Module` and `Project`
to the existing exclusion list. For the default layout, the resulting entry can
look like this; adapt your entry rather than replacing `config/services.yaml`:

```yaml
services:
    _defaults: { autowire: true, autoconfigure: true }
    App\:
        resource: '../src/'
        exclude: '../src/{DependencyInjection,Entity,Kernel.php,Module,Project}'
```

Keep your existing defaults, exclusions and explicit services. For custom roots,
use the actual paths reported by Doctor. A list of literal paths is also supported.
Doctor recognizes one finite brace group in excludes; wildcards, nested groups,
unresolved parameters, imports and dynamic configuration require manual review.

```bash
php bin/console sillbeam:doctor
php bin/console sillbeam:architecture
```

Doctor's `verified` means static file evidence, not a runtime certificate. Exit 1
means a proven action item; exit 2 means at least one check is `not verified` and
takes precedence. PHP configuration, environment overlays and custom kernels may
remain unverified after a correct integration. Review those manually using your
kernel's real loading rules and Symfony's configuration/route/container checks;
do not replace custom files merely to obtain a green Doctor result. Doctor never
executes such files or repairs them. Use `--env=prod --no-debug` to inspect the
production environment as well. [CLI reference](../reference/cli.md#installation-doctor)
describes the limits and statuses.

## 4. Create the first module

Once the integration and existing architecture are usable:

```bash
php bin/console sillbeam:make:module Hello
```

This existing command creates only `src/Module/Hello/HelloModule.php` and refuses
an existing target. Alternatively, create that file yourself:

```php
namespace App\Module\Hello;

#[\Sillbeam\Core\Module\Attribute\SillbeamModule]
final class HelloModule {}
```

Add `src/Module/Hello/Greeting.php`:

```php
namespace App\Module\Hello;

final class Greeting
{
    public function message(): string
    {
        return 'Hello from Sillbeam';
    }
}
```

Add `src/Module/Hello/Controller/HelloController.php`:

```php
namespace App\Module\Hello\Controller;

use App\Module\Hello\Greeting;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HelloController
{
    public function __construct(private Greeting $greeting) {}

    #[Route('/hello', name: 'hello', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response($this->greeting->message());
    }
}
```

No manual registration of these module services or controllers is needed. With
Projects, select this shared module in the intended Project descriptor first;
its route will receive the Project prefix and any configured host filter.
With multiple discovery roots, create the descriptor manually in the intended
root; the make command deliberately requires an unambiguous target.

```bash
php bin/console cache:clear
php bin/console sillbeam:architecture
php bin/console debug:router hello
```

In a module-only application, `debug:router hello` shows `/hello`. Request that
path through your application's existing development server: it returns HTTP 200
with `Hello from Sillbeam`. A descriptor alone does not create a route; add the
controller above. If a Project owns the route, inspect its prefixed name and use
its configured host. Doctor checks installation evidence; architecture, Symfony
container/routing checks and this HTTP request verify the working module.

## Optional Doctrine

Nothing in the first-module example requires Doctrine. When DoctrineBundle is
installed and registered, Sillbeam automatically maps entities in active module
and Project `Entity/` directories. Keep the application's existing database
configuration. `sillbeam: { doctrine: false }` disables only automatic Sillbeam
mappings; enabling it without DoctrineBundle is a configuration error.

## Preserve Symfony integration

The service-import example in [step 3](#3-review-and-correct-the-integration) keeps
native `App\` registration while excluding complete Sillbeam areas. Keep that
single ownership boundary for services, including disabled and unassigned modules.

Keep existing exclusions/services and adjust them for custom roots. Also review broad native route and Doctrine imports: explicit Symfony imports can intentionally include inactive areas again. Sillbeam does not remove such application configuration. A single `type: sillbeam` import is sufficient for module routes.

An unchanged skeleton import of all `src/` remains an independent Symfony registration: an `#[AsAlias]` found there, for example, can remain accessible as a service even with `enabled: false`. Overlapping imports do not create two instances per service ID; the native definition takes precedence, but can bypass Sillbeam's activation and default exclusions. Area exclusions are therefore required when Sillbeam should control automatic registration, including unassigned shared modules and disabled private modules in Project mode.

Within active areas, Sillbeam uses the Symfony service loader. `#[Exclude]`, `#[When]`, `#[WhenNot]` and `#[AsAlias]` still apply. Explicit service definitions, constructor arguments and interface aliases in the application take precedence; an intentionally registered manual service is an escape hatch even for a disabled area. The Flex recipe does not change existing service imports: add exclusions during integration and retain existing exclusions. A standard Symfony kernel with `MicroKernelTrait` is sufficient.

For native Doctrine mappings, prefer the actual native entity directories and namespace prefixes; a broad mapping of `src/` can include inactive module entities. Explicit overrides of generated mappings remain authoritative. Without a registered DoctrineBundle, no automatic mappings are created; `sillbeam.doctrine: true` without the bundle is a configuration error.

Development caches monitor source, configuration and routing resources. Production caches are rebuilt during deployments that change code, routing, Composer mappings or activation; they are not a live discovery mechanism. Changes to build environment values also require a rebuild.

## Check and share the architecture

Run a check explicitly after changing descriptors or module references:

```bash
php bin/console sillbeam:architecture
php bin/console sillbeam:architecture --all
php bin/console sillbeam:architecture --issue=1 -v
php bin/console sillbeam:architecture --format=json
php bin/console sillbeam:report
```

The default view explains one diagnosis; with several diagnoses it first shows
all groups in a compact index. `--all` explains all groups, `--issue=N` selects
one, and `-v` adds technical details. Issue numbers apply only to the current
run. `sillbeam:report` writes `architecture-report.md` in your project root.
Use `--output=docs/architecture-report.md` when the `docs` directory exists.
Both commands accept `--couplings` to include informative references to
application code outside modules; these are not architecture violations.
JSON always contains the complete schema-version-1 result and is separate from
text selectors. Exit codes are 0 (valid), 1 (violations), and 2 (unavailable,
invalid usage, or report write failure). `unavailable` means analysis is incomplete;
correct the blocker and check again.

To use reporting even when invalid architecture prevents container compilation,
replace the Console Application import in your existing `bin/console` with
`use Sillbeam\Core\Console\Application;`, preserving the bootstrap and kernel.
The adapter runs these exact reporting commands without booting the container.
See [architecture checking and reports](architecture-reporting.md) for the full
adapter example, option combinations and failure boundaries.
