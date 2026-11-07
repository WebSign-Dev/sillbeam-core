# Core Data v1

[API contract](API_CONTRACT.md) · [JSON Schemas](schemas/) · [Contract examples](examples/)

The four Draft 2020-12 schemas retain their published IDs. The examples are fixtures for the contract. Validate them offline from the repository root with `python3 tools/test-core-data-schemas.py` and Python's `jsonschema` and `referencing` packages.

## PHP foundation

The public namespace is `Sillbeam\Core\Api`.

- `CoreDataRequest` takes a `CoreDataProfile`, optional locale and schema version. `fromProfile()` is the string entry point for adapters. V1 accepts `live` with one supported locale, defaulting to `en`, or `analysis` with no locale selection. Invalid profile, locale and schema version are argument errors.
- `CoreDataProviderInterface::collect()` returns a readonly `CoreDataDocument`. Its common and profile-specific sections are explicit. `CoreDataObject` represents immutable nested JSON objects, including empty maps. PHP lists represent JSON arrays. The provider is responsible for the section contents and cross-section invariants.
- `CoreDataJsonSerializer` encodes an assembled document using its request's schema version. It neither collects data nor performs JSON Schema validation at runtime.
- `CoreCapabilitiesProvider` returns the v0.4.0 contract metadata without a kernel, application or architecture scan. The operations describe the milestone contract. Profile collection and the additional CLI adapters are delivered separately.

## Core text resources

`Sillbeam\Core\I18n\CoreTranslator` supports the exact keys `en` and `de`. Resource keys are defined at the point where Core produces a message. Named placeholders take original values such as class names, paths, IDs and configuration addresses. Interpolation substitutes them once and does not translate their contents.

Use one translator instance per output locale and export. If a key has no entry for that translator's locale, the English entry is used and `fallbacks()` records one `resource_key`, `locale` and `source_locale` tuple per key. A key missing from English is a programming error. There is no implicit locale normalization or regional matching. The public request rejects unsupported locale values before any collection.

Parser and framework messages and caller-supplied finding text remain verbatim when embedded in a Core resource. The legacy Architecture and Doctor JSON v1 outputs use their existing English technical text and structure. Diagnostic presentation and Doctor inspection can use an explicit translator. Existing CLI invocations keep their default language.
