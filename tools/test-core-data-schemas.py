"""Validate Core Data v1 contracts offline with python3-jsonschema."""
import argparse
import json
import sys
from pathlib import Path, PurePosixPath

from jsonschema import Draft202012Validator
from referencing import Registry, Resource

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--capabilities-stdin", action="store_true", help="also validate capabilities JSON from stdin")
parser.add_argument("--live", type=Path, action="append", default=[], help="also validate a generated Live document")
parser.add_argument("--analysis", type=Path, action="append", default=[], help="also validate a generated Analysis document")
parser.add_argument("--inventory", type=Path, action="append", default=[], help="also validate a standalone source inventory")
args = parser.parse_args()

root = Path(__file__).resolve().parents[1] / "docs/reference/core-data"
schemas = {}
registry = Registry()
for name in ("common", "live", "analysis", "capabilities"):
    schema = json.loads((root / "schemas" / f"core-data-{name}.v1.schema.json").read_text())
    Draft202012Validator.check_schema(schema)
    registry = registry.with_resource(schema["$id"], Resource.from_contents(schema))
    schemas[name] = schema

def check_references(value, resolver):
    if isinstance(value, dict):
        if "$ref" in value:
            resolver.lookup(value["$ref"])
        for child in value.values():
            check_references(child, resolver)
    elif isinstance(value, list):
        for child in value:
            check_references(child, resolver)



def unique_index(rows, field):
    index = {row[field]: row for row in rows}
    assert len(index) == len(rows), f"Duplicate {field}"
    return index


def check_path(path):
    if path is not None:
        normalized = path.rstrip("/")
        assert "\\" not in path and not path.startswith("/")
        assert ".." not in PurePosixPath(normalized).parts
        assert str(PurePosixPath(normalized)) == normalized


def check_inventory(inventory):
    files = unique_index(inventory["files"], "path")
    unique_index(inventory["roots"], "id")
    assert inventory["summary"] == {
        "directory_count": len(inventory["directories"]),
        "file_count": len(files),
        "php_file_count": sum((row["extension"] or "").lower() == "php" for row in files.values()),
        "symbol_count": sum(len(row["symbols"]) for row in files.values()),
    }
    for path in [*files, *inventory["directories"], *inventory["excluded_paths"], *(row["path"] for row in inventory["roots"])]:
        check_path(path)
    for row in files.values():
        if (row["extension"] or "").lower() != "php":
            assert row["parse_status"] == "not_parsed" and not row["symbols"]
        if row["ownership"] == "module":
            assert row["module_class"]
        if row["ownership"] == "project":
            assert row["project_class"]


def check_document(data):
    architecture, diagnostics, references = (data[key] for key in ("architecture", "diagnostics", "references"))
    modules = unique_index(architecture["modules"], "class")
    projects = unique_index(architecture["projects"], "class")
    findings = diagnostics["findings"]
    diagnoses = unique_index(diagnostics["diagnoses"], "id")
    module_refs = unique_index(references["module"], "id")
    application_refs = unique_index(references["application"], "id")
    assert data["summary"] == {
        "module_count": len(modules), "project_count": len(projects),
        "finding_count": len(findings), "diagnosis_count": len(diagnoses),
        "module_reference_count": len(module_refs), "application_reference_count": len(application_refs),
    }
    completeness = data["completeness"]
    assert (data["export"]["status"] == "complete") == (
        data["analysis"]["complete"] and all(value == "complete" for value in completeness.values())
    )
    if data["analysis"]["status"] != "valid":
        assert all(row["active"] is None and row["activation_reasons"] is None for row in modules.values())
        assert all(row["modules"] is None for row in projects.values())
    if completeness["architecture"] == "complete":
        assert all(row["project"] is None or row["project"] in projects for row in modules.values())
        assert all(state["project_class"] in projects for row in modules.values() for state in row["project_states"])
        assert all(member in modules for row in projects.values() for member in (row["modules"] or []))
    for row in module_refs.values():
        source = modules[row["source_module"]]
        assert row["target_module"] in modules
        assert row["target_module"] in source["observed_dependencies"]
        assert row["declared"] == (row["target_module"] in source["declared_dependencies"])
    assert all(row["source_module"] in modules for row in application_refs.values())
    for index, row in enumerate(findings):
        assert row["index"] == index
        if row["diagnosis_id"] is not None:
            assert index in diagnoses[row["diagnosis_id"]]["finding_indexes"]
        elif completeness["diagnostics"] == "complete":
            raise AssertionError("Complete diagnostics has an unassigned finding")
    correlations = set()
    resolutions = []
    for diagnosis in diagnoses.values():
        indexes = diagnosis["finding_indexes"]
        assert indexes and all(0 <= index < len(findings) for index in indexes)
        assert all(findings[index]["diagnosis_id"] == diagnosis["id"] for index in indexes)
        assert {findings[index]["rule"] for index in indexes} == set(diagnosis["rule_codes"])
        assert all(detail["finding_index"] in indexes for detail in diagnosis["details"])
        assert (diagnosis["severity"] == "fatal") == any(findings[index]["severity"] == "fatal" for index in indexes)
        key = diagnosis["correlation_key"]
        assert (key is None) == (diagnosis["correlation_version"] is None)
        if key is not None:
            assert key not in correlations
            correlations.add(key)
        resolutions.extend(diagnosis["resolutions"])
    unique_index(resolutions, "id")
    assert (data["metadata"]["architecture_fingerprint"] is None) == (data["metadata"]["fingerprint_version"] is None)
    i18n = data["i18n"]
    locales = set(i18n["included_locales"])
    assert i18n["default_locale"] == "en" and locales <= set(i18n["available_locales"])
    assert all(row["locale"] in locales and row["source_locale"] in i18n["available_locales"] for row in i18n["fallbacks"])
    if data["profile"] == "live":
        assert locales == {i18n["requested_locale"]}
    else:
        assert i18n["requested_locale"] is None and locales == set(i18n["available_locales"])

    def translations(value):
        if isinstance(value, dict):
            if "translations" in value:
                assert set(value["translations"]) == locales
            for key, child in value.items():
                if key in ("context", "extensions", "translations"):
                    continue
                if key in ("path", "directory", "descriptor", "source_file", "target_file"):
                    check_path(child)
                elif key in ("controllers", "entities") or key == "files" and all(isinstance(item, str) for item in child):
                    for path in child:
                        check_path(path)
                elif key == "descriptors":
                    for path in child.values():
                        check_path(path)
                translations(child)
        elif isinstance(value, list):
            for child in value:
                translations(child)

    translations({key: value for key, value in data.items() if key != "extensions"})
    if data["profile"] == "analysis":
        graph = data["graph"]
        nodes = unique_index(graph["nodes"], "id")
        groups = unique_index(graph["groups"], "id")
        assert all(row["group_id"] is None or row["group_id"] in groups for row in nodes.values())
        if graph["validation_state"] == "validated":
            assert data["analysis"]["status"] == "valid" and completeness["graph"] == "complete"
        else:
            assert all(row["state"] != "active" for row in nodes.values())
        dependency_pairs = set()
        represented_refs = set()
        for edge in unique_index(graph["edges"], "id").values():
            assert edge["source"] in nodes and edge["target"] in nodes
            if edge["type"] != "project_membership":
                pair = (edge["source"], edge["target"])
                assert pair not in dependency_pairs
                dependency_pairs.add(pair)
                assert edge["declared"] == (edge["type"] == "declared_dependency")
                assert (edge["observed"] is True) == bool(edge["reference_ids"])
            for reference_id in edge["reference_ids"]:
                assert reference_id not in represented_refs
                represented_refs.add(reference_id)
                reference = module_refs[reference_id]
                assert (edge["source"], edge["target"]) == (reference["source_module"], reference["target_module"])
        assert represented_refs == set(module_refs)
        inventory = data["source_inventory"]
        check_inventory(inventory)
        if completeness["architecture"] == "complete":
            assert all(row["module_class"] is None or row["module_class"] in modules for row in inventory["files"])
            assert all(row["project_class"] is None or row["project_class"] in projects for row in inventory["files"])
        doctor = data["doctor"]
        assert [row["index"] for row in doctor["checks"]] == list(range(len(doctor["checks"])))
        assert doctor["summary"] == {status: sum(row["status"] == status for row in doctor["checks"]) for status in doctor["summary"]}
        assert doctor["status"] == ["verified", "action_required", "not_verified"][doctor["exit_code"]]

for schema in schemas.values():
    check_references(schema, registry.resolver(schema["$id"]))

for name, file in (("live", "live.de.json"), ("analysis", "analysis.json"), ("capabilities", "capabilities.json")):
    validator = Draft202012Validator(schemas[name], registry=registry, format_checker=Draft202012Validator.FORMAT_CHECKER)
    example = json.loads((root / "examples" / file).read_text())
    validator.validate(example)
    if name != "capabilities":
        check_document(example)
    if name in ("live", "analysis"):
        for path in getattr(args, name):
            document = json.loads(path.read_text())
            validator.validate(document)
            check_document(document)
            print(f"Generated {name} document validated: {path.name}")
    if name == "capabilities" and args.capabilities_stdin:
        validator.validate(json.load(sys.stdin))
        print("Generated capabilities validated")

inventory = Draft202012Validator({"$ref": schemas["common"]["$id"] + "#/$defs/sourceInventory"}, registry=registry, format_checker=Draft202012Validator.FORMAT_CHECKER)
for path in args.inventory:
    document = json.loads(path.read_text())
    inventory.validate(document)
    check_inventory(document)
    print(f"Generated inventory validated: {path.name}")

print("4 schemas and 3 examples validated (Draft 2020-12)")
