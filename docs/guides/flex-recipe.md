# Symfony Flex

Use the [getting-started guide](getting-started.md) for the complete Composer →
Console adapter → Doctor → first `/hello` module path. The compatible
[recipe source](https://github.com/WebSign-Dev/sillbeam-core/blob/main/flex-recipe/README.md)
is kept under `flex-recipe/websign-dev/sillbeam-core/0.3`. The directory names
the minimum Core version for that recipe. Core v0.4.0 uses the same
bundle and route registration.

The manifest performs two tasks when the developer installs this recipe:

1. Register `Sillbeam\Core\SillbeamCoreBundle` for all environments.
2. Copy `config/routes/sillbeam.yaml` containing one `type: sillbeam` import.

It does not edit `services.yaml`, `bin/console`, the kernel, database settings or
existing application routes. It creates no empty package configuration. Doctor
also writes no files. Review native Symfony changes yourself; retain existing
exclusions, definitions, comments and bootstrap code. Ordinary Flex installation
preserves an existing destination; never use a forced recipe reinstall as a repair.
A preserved but incorrect route file still needs manual correction.

## Package installation and recipe availability

```bash
composer require websign-dev/sillbeam-core:^0.4.0
```

For an existing 0.3 installation, follow the [upgrade guide](../migration/0.4.0.md).
Public recipe installation requires
Contrib approval, an eligible recipe on the configured endpoint, permission for
the Flex plugin and the application's decision to allow Contrib recipes
(`composer config extra.symfony.allow-contrib true`). None of these is implied by
package installation or a package tag. See [release status](../../README.md#release-status).
The repository tests use a local endpoint with either a simulated local version or
`SILLBEAM_TEST_RELEASED=1` for the published 0.3.0 package; they do not
publish or submit anything. Check Composer output and `symfony.lock` rather than
assuming the recipe ran.

When no recipe is installed, manually register the bundle and exactly one route
import as shown in the installation guide. Keep an existing registration/import.
No alternative installer command is needed.

## After installation

1. Manually select `use Sillbeam\Core\Console\Application;` in the existing
   `bin/console`, preserving the bootstrap, aliases and kernel. This makes Doctor
   available when the bundle is absent or container compilation fails.
2. Run `php bin/console sillbeam:doctor`. Apply reviewed fixes for missing bundle,
   routing or service exclusions, then rerun it. Unknown dynamic configuration
   stays `not verified`; inspect it manually.
3. Run `php bin/console sillbeam:architecture`, then
   `php bin/console sillbeam:make:module Hello`. Add the guide's service/controller
   and verify `/hello` with Symfony's router and an HTTP request.

Broad native `App\` imports must exclude entire module/Project discovery areas
when Sillbeam should control activation. Doctor reports the actual conflicting
file, import and paths, including custom roots. Literal finite brace exclusions
are supported; wildcard resources, nested groups, imported configuration and
unknown overlays remain unverified. Keep existing services/exclusions; Sillbeam
uses Symfony's native service loader within active areas.

Doctrine mappings activate only when DoctrineBundle is installed and registered;
the recipe never installs Doctrine. Existing database and native mapping choices
remain application-owned. The standard Symfony kernel is sufficient.

These instructions are displayed from `post-install.txt`, not copied into the
application. The source manifest contains only `bundles` and `copy-from-recipe`;
the endpoint derives the post-install output metadata.

## Verification and removal

`composer test:flex-recipe` exercises a pre-existing Symfony application;
`SILLBEAM_TEST_FRESH=1 composer test:flex-recipe` exercises a fresh consumer.
Repeat each with `SILLBEAM_TEST_DOCTRINE=1` for the Doctrine variants. The test
installs through real Composer/Flex, preserves native files, diagnoses a broken
installation, applies explicit test-authored corrections, reruns Doctor, creates
the first module and checks HTTP, activation and mappings. Both Symfony and
Sillbeam kernels boot with cold/warm development/production caches.

Flex removal deletes its bundle registration and recipe-managed route file.
Back up application-specific changes to `config/routes/sillbeam.yaml` first:
Flex tracks that filename even if it existed before installation. Other
application-owned files and manually created package options are retained.
After removal, explicitly remove `sillbeam` options from package imports, restore
the Console Application to Symfony and, if selected, switch an optional
`SillbeamKernel` subclass back to Symfony. These are manual application decisions.
See the [recipe source and submission notes](https://github.com/WebSign-Dev/sillbeam-core/blob/main/flex-recipe/README.md).
