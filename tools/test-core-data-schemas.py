"""Validate Core Data v1 contracts offline with python3-jsonschema."""
import argparse
import json
import sys
from pathlib import Path

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


for schema in schemas.values():
    check_references(schema, registry.resolver(schema["$id"]))

for name, file in (("live", "live.de.json"), ("analysis", "analysis.json"), ("capabilities", "capabilities.json")):
    validator = Draft202012Validator(schemas[name], registry=registry, format_checker=Draft202012Validator.FORMAT_CHECKER)
    validator.validate(json.loads((root / "examples" / file).read_text()))
    if name in ("live", "analysis"):
        for path in getattr(args, name):
            validator.validate(json.loads(path.read_text()))
            print(f"Generated {name} document validated: {path.name}")
    if name == "capabilities" and args.capabilities_stdin:
        validator.validate(json.load(sys.stdin))
        print("Generated capabilities validated")

inventory = Draft202012Validator({"$ref": schemas["common"]["$id"] + "#/$defs/sourceInventory"}, registry=registry, format_checker=Draft202012Validator.FORMAT_CHECKER)
for path in args.inventory:
    inventory.validate(json.loads(path.read_text()))
    print(f"Generated inventory validated: {path.name}")

print("4 schemas and 3 examples validated (Draft 2020-12)")
