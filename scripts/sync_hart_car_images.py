#!/usr/bin/env python3
"""Copy CarImagesFinal PNGs into STS RollingStock album as {car_id}.jpg files."""

from __future__ import annotations

import argparse
import csv
import json
import re
import subprocess
import xml.etree.ElementTree as ET
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parent
DEFAULT_HART_DIR = Path.home() / "Desktop/HART/Car Cards"
DEFAULT_METADATA = DEFAULT_HART_DIR / "image_metadata.csv"
DEFAULT_FINAL_DIR = DEFAULT_HART_DIR / "CarImagesFinal"
DEFAULT_ROSTER = DEFAULT_HART_DIR / "HART_MergedCarRoster.xml"
DEFAULT_SEED_SQL = REPO_ROOT / "sts/seed_hart_data.sql"
DEFAULT_CONFIG = SCRIPT_DIR / "hart_seed_config.json"
DEFAULT_OUTPUT = REPO_ROOT / "images"


def load_mow_car_ids(config_path: Path) -> dict[str, int]:
    if not config_path.exists():
        return {}
    config = json.loads(config_path.read_text(encoding="utf-8"))
    return {
        entry["roster_id"]: int(entry["car_id"])
        for entry in config.get("mow_equipment", [])
        if entry.get("roster_id") and entry.get("car_id") is not None
    }


PASSENGER_TYPES = frozenset(
    {"Baggage", "Coach", "Combine", "Dining", "Observation", "Caboose", "MOW"}
)


def parse_car_ids_from_seed(seed_sql: Path) -> dict[str, int]:
    text = seed_sql.read_text(encoding="utf-8")
    match = re.search(
        r"INSERT INTO `cars`.*?VALUES\s*(.*?);\s*\n\n",
        text,
        re.DOTALL,
    )
    if not match:
        raise SystemExit(f"Could not parse cars INSERT from {seed_sql}")
    mapping: dict[str, int] = {}
    for row_match in re.finditer(r"\((\d+),\s*'([^']*)'", match.group(1)):
        mapping[row_match.group(2)] = int(row_match.group(1))
    return mapping


def parse_car_ids_from_roster(roster_xml: Path) -> dict[str, int]:
    """Replicate freight-car ID assignment order from generate_hart_seed.py."""
    root = ET.parse(roster_xml).getroot()
    mapping: dict[str, int] = {}
    car_id = 1
    for car in root.findall("cars/car"):
        if car.get("type") in PASSENGER_TYPES:
            continue
        marks = f"{car.get('roadName', '')}{car.get('roadNumber', '')}"
        mapping[marks] = car_id
        roster_id = car.get("id")
        if roster_id:
            mapping[roster_id] = car_id
        car_id += 1
    return mapping


def load_roster_lookup(roster_xml: Path) -> tuple[dict[str, str], dict[str, str]]:
    root = ET.parse(roster_xml).getroot()
    id_to_marks: dict[str, str] = {}
    marks_to_id: dict[str, str] = {}
    for car in root.findall("cars/car"):
        if car.get("type") in PASSENGER_TYPES:
            continue
        rid = car.get("id", "")
        marks = f"{car.get('roadName', '')}{car.get('roadNumber', '')}"
        if rid:
            id_to_marks[rid] = marks
            marks_to_id[marks] = rid
    return id_to_marks, marks_to_id


def resolve_roster_id(row: dict, marks_to_id: dict[str, str]) -> str | None:
    roster_id = (row.get("roster_id") or "").strip()
    if roster_id:
        return roster_id

    road = (row.get("road_name") or "").replace("&", "").replace(" ", "").strip()
    number = (row.get("road_number") or "").strip()
    if road and number:
        marks = f"{road}{number}"
        return marks_to_id.get(marks)
    return None


def convert_png_to_jpg(src: Path, dst: Path, quality: int) -> None:
    try:
        from PIL import Image

        with Image.open(src) as image:
            image.convert("RGB").save(dst, "JPEG", quality=quality, optimize=True)
        return
    except ImportError:
        pass

    dst.parent.mkdir(parents=True, exist_ok=True)
    subprocess.run(
        ["sips", "-s", "format", "jpeg", str(src), "--out", str(dst)],
        check=True,
        capture_output=True,
    )


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--metadata", type=Path, default=DEFAULT_METADATA)
    parser.add_argument("--final-dir", type=Path, default=DEFAULT_FINAL_DIR)
    parser.add_argument("--roster", type=Path, default=DEFAULT_ROSTER)
    parser.add_argument("--seed-sql", type=Path, default=DEFAULT_SEED_SQL)
    parser.add_argument("--config", type=Path, default=DEFAULT_CONFIG)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--quality", type=int, default=85)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    if args.seed_sql.exists():
        marks_to_car_id = parse_car_ids_from_seed(args.seed_sql)
    else:
        marks_to_car_id = {}

    for roster_id, car_id in load_mow_car_ids(args.config).items():
        marks_to_car_id[roster_id] = car_id

    roster_ids = parse_car_ids_from_roster(args.roster)
    id_to_marks, marks_to_roster_id = load_roster_lookup(args.roster)

    for key, value in roster_ids.items():
        if key not in marks_to_car_id:
            marks_to_car_id[key] = value
        marks = id_to_marks.get(key)
        if marks and marks not in marks_to_car_id:
            marks_to_car_id[marks] = value

    rows = list(csv.DictReader(args.metadata.open(encoding="utf-8")))
    final_rows = [r for r in rows if "CarImagesFinal" in (r.get("notes") or "")]

    args.output_dir.mkdir(parents=True, exist_ok=True)

    copied = 0
    skipped = 0
    for row in final_rows:
        roster_id = resolve_roster_id(row, marks_to_roster_id)
        if not roster_id:
            skipped += 1
            continue

        marks = id_to_marks.get(roster_id, roster_id)
        car_id = marks_to_car_id.get(marks) or marks_to_car_id.get(roster_id)
        if not car_id:
            skipped += 1
            continue

        src = args.final_dir / row["source_image"].strip()
        if not src.exists():
            skipped += 1
            continue

        dst = args.output_dir / f"{car_id}.jpg"
        if args.dry_run:
            print(f"would copy {src.name} -> {dst.name} ({marks})")
        else:
            convert_png_to_jpg(src, dst, args.quality)
            print(f"{src.name} -> {dst.name} ({marks})")
        copied += 1

    print(
        f"\nDone: {copied} images synced, {skipped} skipped "
        f"(engines/MOW/promo/not in fleet), output={args.output_dir}"
    )


if __name__ == "__main__":
    main()
