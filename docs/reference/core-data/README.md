# Core Data v1

[API contract](API_CONTRACT.md) · [JSON Schemas](schemas/) · [Contract examples](examples/)

The four Draft 2020-12 schemas retain their published IDs. The examples are fixtures for the contract. Validate them offline from the repository root with `python3 tools/test-core-data-schemas.py` and Python's `jsonschema` and `referencing` packages.

## PHP foundation

The public namespace is `Sillbeam\Core\Api`.

- `CoreDataRequest` takes a `CoreDataProfile`, optional locale and schema version. `fromProfile()` is the string entry point for adapters. The Live provider accepts `live` with one supported locale, defaulting to `en`. The request type also describes the planned `analysis` profile, which has no locale selection. Invalid profile, locale and schema version are argument errors.
- `CoreDataProvider` accepts a resolved `CoreDataConfiguration` or a closure that resolves it once per collection. It runs the existing architecture analyzer once. `CoreDataProviderInterface::collect()` returns a readonly `CoreDataDocument`. Its common and profile-specific sections are explicit. `CoreDataObject` represents immutable nested JSON objects, including empty maps. PHP lists represent JSON arrays. The provider is responsible for the section contents and cross-section invariants.
- `CoreDataJsonSerializer` encodes an assembled document using its request's schema version. It neither collects data nor performs JSON Schema validation at runtime.
- `CoreCapabilitiesProvider` returns the v0.4.0 contract metadata without a kernel, application or architecture scan. The response advertises only implemented profiles and operations. The Capabilities v1 schema permits omission of the Analysis entry before that profile is available. The full milestone example still shows both profiles.

## Live CLI

Use the Sillbeam Console application in `bin/console`. It routes these commands before Symfony compiles the container and reserves `--profile` for data profile selection on `sillbeam:api`. Symfony's profiler flag remains available on its regular commands.

```sh
php bin/console sillbeam:api --profile=live --locale=de --schema-version=1 --no-ansi --no-interaction
php bin/console sillbeam:api:capabilities --format=json --no-ansi --no-interaction
```

Live uses the normal architecture source index for discovery and reference evidence. It omits additional source inventory, graph data, Doctor collection, and architecture fingerprints. A source read or parse failure outside configured module and Project discovery roots is not a Live architecture finding. The legacy Architecture JSON v1 command keeps its existing behavior. If a required exported path cannot be verified inside the project directory, Live omits the affected evidence, reports the affected domain as partial, and emits an export error.

V1 correlation keys are best effort. For relationship groups they hash the basis, sorted unique rule codes, and ordered module classes as a JSON tuple using SHA-256. Directed cycles use the lexicographically smallest class as the starting point. Ambiguous or unsupported groups use `null`.

## Core text resources

`Sillbeam\Core\I18n\CoreTranslator` supports the exact keys `en` and `de`. Resource keys are defined at the point where Core produces a message. Named placeholders take original values such as class names, paths, IDs and configuration addresses. Interpolation substitutes them once and does not translate their contents.

Use one translator instance per output locale and export. If a key has no entry for that translator's locale, the English entry is used and `fallbacks()` records one `resource_key`, `locale` and `source_locale` tuple per key. A key missing from English is a programming error. There is no implicit locale normalization or regional matching. The public request rejects unsupported locale values before any collection.

Parser and framework messages and caller-supplied finding text remain verbatim when embedded in a Core resource. The legacy Architecture and Doctor JSON v1 outputs use their existing English technical text and structure. Diagnostic presentation and Doctor inspection can use an explicit translator. Existing CLI invocations keep their default language.
