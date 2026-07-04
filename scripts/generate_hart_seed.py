#!/usr/bin/env python3
"""Generate STS seed SQL for the HART layout from Car Cards source files."""

from __future__ import annotations

import argparse
import csv
import json
import re
import xml.etree.ElementTree as ET
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parent
DEFAULT_HART_DIR = Path.home() / "Desktop/HART/Car Cards"
DEFAULT_CONFIG = SCRIPT_DIR / "hart_seed_config.json"
DEFAULT_OUTPUT = REPO_ROOT / "sts/seed_hart_data.sql"
DEFAULT_MRR_CSV = REPO_ROOT / "sts/uploads/MRR-AAR_Class_Codes.csv"

# Canonical shipment order limits for HART (all shipments use these values).
SHIPMENT_INTERVALS = {
    "min_interval": 0,
    "max_interval": 1,
    "min_amount": 0,
    "max_amount": 1,
}

PASSENGER_TYPES = frozenset(
    {
        "Baggage",
        "Coach",
        "Combine",
        "Dining",
        "Observation",
        "Caboose",
        "MOW",
    }
)


def load_covered_hopper_prefixes(
    metadata_csv: Path,
    roster_xml: Path,
    config: dict,
) -> dict[str, str]:
    """Map roster IDs to covered-hopper AAR prefix: HC (gravity) or HP (pneumatic)."""
    _, marks_to_id = load_roster_lookup(roster_xml)
    prefixes: dict[str, str] = {}

    for roster_id in config.get("pneumatic_covered_hopper_roster_ids", []):
        prefixes[roster_id] = "HP"
    for roster_id in config.get("covered_hopper_roster_ids", []):
        prefixes.setdefault(roster_id, "HC")

    gravity_keywords = [
        k.lower()
        for k in config.get(
            "covered_hopper_note_keywords",
            ["covered hopper", "cement hopper", "portland cement"],
        )
    ]
    pneumatic_keywords = [
        k.lower()
        for k in config.get(
            "pneumatic_hopper_note_keywords",
            ["pneumatic", "plastic pellet"],
        )
    ]

    with metadata_csv.open(encoding="utf-8") as fh:
        for row in csv.DictReader(fh):
            if "CarImagesFinal" not in (row.get("notes") or ""):
                continue
            roster_id = resolve_roster_id(row, marks_to_id)
            if not roster_id:
                continue
            if roster_id in prefixes:
                continue
            car_class = infer_car_class(row)
            if car_class == "LO" and normalize_car_type(row.get("car_type") or "") == "Hopper":
                prefixes[roster_id] = (
                    "HP"
                    if roster_id in config.get("pneumatic_covered_hopper_roster_ids", [])
                    else "HC"
                )
                continue
            notes = (row.get("notes") or "").lower()
            if any(keyword in notes for keyword in pneumatic_keywords):
                prefixes[roster_id] = "HP"
            elif any(keyword in notes for keyword in gravity_keywords):
                prefixes[roster_id] = "HC"
    return prefixes


def load_roster_lookup(roster_xml: Path) -> tuple[dict[str, str], dict[str, str]]:
    root = ET.parse(roster_xml).getroot()
    id_to_marks: dict[str, str] = {}
    marks_to_id: dict[str, str] = {}
    for car in root.findall("cars/car"):
        if car.get("type") in PASSENGER_TYPES:
            continue
        roster_id = car.get("id", "")
        marks = f"{car.get('roadName', '')}{car.get('roadNumber', '')}"
        if roster_id:
            id_to_marks[roster_id] = marks
            marks_to_id[marks] = roster_id
    return id_to_marks, marks_to_id


def resolve_roster_id(row: dict, marks_to_id: dict[str, str]) -> str | None:
    roster_id = (row.get("roster_id") or "").strip()
    if roster_id:
        return roster_id

    road = (row.get("road_name") or "").replace("&", "").replace(" ", "").strip()
    number = (row.get("road_number") or "").strip()
    if road and number:
        return marks_to_id.get(f"{road}{number}")
    return None


def roster_ids_with_final_images(
    metadata_csv: Path,
    final_dir: Path,
    roster_xml: Path,
) -> set[str]:
    """Roster IDs that have a CarImagesFinal source file (same rules as sync_hart_car_images.py)."""
    _, marks_to_id = load_roster_lookup(roster_xml)
    roster_ids: set[str] = set()
    with metadata_csv.open(encoding="utf-8") as fh:
        for row in csv.DictReader(fh):
            if "CarImagesFinal" not in (row.get("notes") or ""):
                continue
            roster_id = resolve_roster_id(row, marks_to_id)
            if not roster_id:
                continue
            src = final_dir / row["source_image"].strip()
            if src.exists():
                roster_ids.add(roster_id)
    return roster_ids


def sql_str(value: str | None) -> str:
    if value is None:
        return "NULL"
    escaped = (
        str(value)
        .replace("\\", "\\\\")
        .replace("'", "''")
        .replace("\r\n", "\n")
        .replace("\r", "\n")
        .replace("\n", "\\n")
    )
    return f"'{escaped}'"


def sql_int(value: int | None) -> str:
    if value is None:
        return "NULL"
    return str(int(value))


def load_config(path: Path) -> dict:
    with path.open(encoding="utf-8") as fh:
        return json.load(fh)


def commodity_code(name: str) -> str:
    if not name or name == "EMPTY":
        return ""
    if name == "Process Chemicals":
        return "PROCCHEM"
    if name == "General Freight":
        return "GENFREIGHT"
    return re.sub(r"[^A-Za-z0-9]", "", name.upper())[:24]


def usage_location_code(industry: str, usage: str) -> str:
    prefix = {
        "Aristech Plastics": "ARIS",
        "A Stucki Co": "STUK",
        "Calgon Carbon": "CALG",
        "Ferrel Gas": "FERR",
        "Kosmos Cement": "KOSM",
    }.get(industry, re.sub(r"[^A-Za-z0-9]", "", industry.upper())[:4])
    suffix = {
        "Pellet Unload": "Pellets",
        "Chemical Unload": "Chemical",
        "Shipping Door": "Shipping",
        "Coal Unload": "Coal",
        "Carbon Load": "Carbon",
        "Cement Unload": "Cement",
        "Aggregate Unload": "Aggregate",
        "LPG Unload": "LPG",
        "Crane Track": "Crane",
        "Team Track": "Team",
    }.get(usage.strip())
    if not suffix:
        suffix = re.sub(r"[^A-Za-z0-9]", "", usage.title())
    return f"NIL-{prefix}-{suffix}"


def is_layout_party(name: str, layout_parties: set[str]) -> bool:
    n = name.strip()
    if not n:
        return False
    if n in layout_parties:
        return True
    return n.lower().startswith("ferrellgas")


# STS mechanical designators (letter pair + length digits) align with the opsig /
# MRR-AAR reference. Classic AAR stencil classes from car cards (LO, HT, HM, …)
# are mapped to these STS prefixes.
AAR_PREFIX_DESCRIPTIONS: dict[str, str] = {
    "HA": "hopper, open top, gravity discharge inside rails",
    "HB": "hopper, open top, gravity discharge outside rails",
    "HC": "hopper, covered, gravity discharge",
    "HP": "hopper, covered, pneumatic discharge",
    "XM": "boxcar, general service",
    "FM": "flatcar, general service",
    "TA": "tankcar, general service",
    "TL": "tankcar, lined for corrosive or specialty liquids",
    "GA": "gondola, open top",
    "GD": "gondola, open top with side doors for dumping",
    "FC": "flatcar, center beam",
    "RM": "refrigerator, mechanical",
    "WF": "work flatcar, MOW service",
    "WC": "work crane car, MOW service",
}

# Classic AAR class (opsig 1987) -> STS prefix when length suffix is appended.
CLASSIC_AAR_TO_STS_PREFIX: dict[str, str] = {
    "LO": "HC",  # covered hopper; pneumatic roster overrides to HP
    "HT": "HA",  # open hopper, crosswise dump between rails
    "HM": "HA",  # twin hopper between rails
    "HA": "HA",
    "HB": "HB",
    "HK": "HB",
    "HD": "HB",
    "XM": "XM",
    "XI": "XM",
    "GB": "GA",
    "GA": "GA",
    "GD": "GD",
    "GT": "GA",
    "FM": "FM",
    "FC": "FC",
    "F3": "FM",
    "TA": "TA",
    "TM": "TA",
    "TL": "TL",
    "TP": "TA",  # pressurized/LPG — STS MRR list uses TA for general tanks
    "RM": "RM",
    "RP": "RM",
}

ROSTER_TYPE_AAR_PREFIX: dict[str, str] = {
    "Boxcar": "XM",
    "Flatcar": "FM",
    "Gondola": "GA",
    "Tank": "TA",
    "Coil": "FC",
    "Reefer": "RM",
}

WAYBILL_TYPE_AAR_PREFIX: dict[str, str] = {
    "Boxcar": "XM",
    "Flat Car": "FM",
    "Gondola": "GA",
    "Tank Car": "TA",
    "Coil Car": "FC",
    "Reefer": "RM",
}

HOPPER_AAR_PREFIXES = frozenset({"HA", "HB", "HC", "HP"})
OPEN_TOP_HOPPER_CODES = frozenset({"HA", "HB"})
OPEN_TOP_HOPPER_AAR = frozenset({"HA", "HB"})
OPEN_TOP_HOPPER_PREFIXES = frozenset({"HA", "HB"})
TANK_AAR_PREFIXES = frozenset({"TA", "TL"})
TANK_CODE_FALLBACKS: dict[str, list[str]] = {"TL": ["TA"]}
PNEUMATIC_COVERED_COMMODITIES = frozenset({"Plastic Pellets"})
MRR_DESCRIPTIONS: dict[str, str] = {}


def load_mrr_aar_descriptions(path: Path) -> dict[str, str]:
    """Load official STS AAR code descriptions from MRR-AAR_Class_Codes.csv."""
    descriptions: dict[str, str] = {}
    if not path.exists():
        return descriptions
    with path.open(newline="", encoding="utf-8") as fh:
        for row in csv.DictReader(fh):
            code = (row.get("Code") or "").strip()
            desc = (row.get("Description") or "").strip()
            if code and desc:
                descriptions[code] = desc
    return descriptions


OPEN_HOPPER_NOTE_KEYWORDS = (
    "coke hopper",
    "coke",
    "coal hopper",
    "coal",
    "gla",
    "open-top",
    "open top",
)
OPEN_HOPPER_CAR_CLASSES = frozenset({"HT", "HM", "HA", "HB", "HK", "HD"})
LO_OCR_PATTERNS = (
    re.compile(r"\bLO\s+LMT\b"),
    re.compile(r"\bLOLMT\b"),
    re.compile(r"\bGAPY\b[^A-Z]{0,32}\bLO\b"),
)


def normalize_car_type(car_type: str) -> str:
    car_type = car_type.strip()
    return "Hopper" if car_type == "Coal" else car_type


def infer_car_class(row: dict[str, str]) -> str:
    """Use reviewed car_class, else LO stencil from OCR on covered-hopper cards."""
    car_class = (row.get("car_class") or "").strip().upper()
    if car_class:
        return car_class
    if normalize_car_type(row.get("car_type") or "") != "Hopper":
        return ""
    ocr = (row.get("ocr_text") or "").upper()
    for pattern in LO_OCR_PATTERNS:
        if pattern.search(ocr):
            return "LO"
    return ""


def load_roster_metadata(
    metadata_csv: Path,
    roster_xml: Path,
) -> dict[str, dict[str, str]]:
    """Map roster ID -> car_class and notes from image_metadata.csv."""
    _, marks_to_id = load_roster_lookup(roster_xml)
    meta: dict[str, dict[str, str]] = {}
    with metadata_csv.open(encoding="utf-8") as fh:
        for row in csv.DictReader(fh):
            roster_id = resolve_roster_id(row, marks_to_id)
            if not roster_id:
                continue
            meta[roster_id] = {
                "car_class": infer_car_class(row),
                "notes": (row.get("notes") or "").strip(),
                "car_type": normalize_car_type(row.get("car_type") or ""),
            }
    return meta


def is_covered_hopper_meta(meta: dict[str, str] | None, config: dict) -> bool:
    if not meta:
        return False
    if normalize_car_type(meta.get("car_type", "")) != "Hopper":
        return False
    car_class = meta.get("car_class", "").upper()
    if car_class in OPEN_HOPPER_CAR_CLASSES:
        return False
    if car_class == "LO":
        return True
    notes = meta.get("notes", "").lower()
    if any(keyword in notes for keyword in OPEN_HOPPER_NOTE_KEYWORDS):
        return False
    keywords = config.get(
        "covered_hopper_note_keywords",
        ["covered hopper", "cement hopper", "portland cement", "kosmos"],
    )
    return any(keyword in notes for keyword in keywords)


def is_outside_dump_hopper(
    roster_id: str,
    meta: dict[str, str] | None,
    config: dict,
) -> bool:
    if roster_id in config.get("open_hopper_outside_roster_ids", []):
        return True
    if not meta:
        return False
    car_class = meta.get("car_class", "").upper()
    if car_class in {"HB", "HK", "HD"}:
        return True
    notes = meta.get("notes", "").lower()
    keywords = config.get("open_hopper_outside_keywords", ["coke hopper", "coke"])
    return any(keyword in notes for keyword in keywords)


def covered_hopper_prefix_for(
    roster_id: str,
    covered_prefixes: dict[str, str],
    meta: dict[str, str] | None,
    config: dict,
) -> str | None:
    if roster_id in covered_prefixes:
        return covered_prefixes[roster_id]
    if is_covered_hopper_meta(meta, config):
        return "HP" if roster_id in config.get("pneumatic_covered_hopper_roster_ids", []) else "HC"
    return None


def open_hopper_sts_prefix(
    roster_id: str,
    meta: dict[str, str] | None,
    config: dict,
) -> str:
    if is_outside_dump_hopper(roster_id, meta, config):
        return "HB"
    if meta:
        classic = meta.get("car_class", "")
        mapped = CLASSIC_AAR_TO_STS_PREFIX.get(classic)
        if mapped in {"HA", "HB"}:
            return mapped
    return "HA"


def tank_prefix_for_commodity(commodity: str, config: dict) -> str:
    commodity = commodity.strip()
    lined = frozenset(config.get("tank_lined_commodities", []))
    if commodity in lined:
        return "TL"
    return "TA"


def aar_code_prefix(prefix: str) -> str:
    """Mechanical designator only (e.g. HA40 -> HA)."""
    prefix = prefix.strip().upper()
    if len(prefix) > 2 and prefix[2:].isdigit():
        return prefix[:2]
    return prefix


def aar_description(code: str) -> str:
    code = aar_code_prefix(code)
    if code in MRR_DESCRIPTIONS:
        return MRR_DESCRIPTIONS[code]
    return AAR_PREFIX_DESCRIPTIONS.get(code, "freight car")


def aar_code_for_roster_car(
    car_type: str,
    length: str | None,
    *,
    covered_hopper_prefix: str | None = None,
    roster_meta: dict[str, str] | None = None,
    roster_id: str = "",
    config: dict | None = None,
) -> str | None:
    config = config or {}

    if car_type == "Hopper":
        if covered_hopper_prefix in {"HC", "HP"}:
            return aar_code_prefix(covered_hopper_prefix)
        if is_covered_hopper_meta(roster_meta, config):
            return "HC"
        return aar_code_prefix(
            open_hopper_sts_prefix(roster_id or "", roster_meta, config)
        )

    if car_type == "Gondola" and roster_meta:
        classic = roster_meta.get("car_class", "")
        if classic == "GD":
            return "GD"
        if classic in {"GB", "GT", "GA"}:
            return "GA"

    if car_type == "Boxcar" and roster_meta:
        classic = roster_meta.get("car_class", "")
        if classic in {"XM", "XI"}:
            return "XM"

    if car_type == "Flatcar" and roster_meta:
        classic = roster_meta.get("car_class", "")
        if classic in {"FM", "F3"}:
            return "FM"

    prefix = ROSTER_TYPE_AAR_PREFIX.get(car_type)
    if roster_meta and not prefix:
        classic = roster_meta.get("car_class", "")
        prefix = CLASSIC_AAR_TO_STS_PREFIX.get(classic)
    if not prefix:
        return None
    return aar_code_prefix(prefix)


def aar_prefix_for_waybill(car_type: str, commodity: str, config: dict) -> str | None:
    if car_type == "Hopper":
        return "HB" if commodity.strip().lower() == "coke" else "HA"
    if car_type == "Covered Hopper":
        commodity = commodity.strip()
        if commodity in PNEUMATIC_COVERED_COMMODITIES:
            return "HP"
        return "HC"
    if car_type == "Tank Car":
        forced = (config.get("shipment_tank_code") or "").strip().upper()
        if forced:
            return aar_code_prefix(forced)
        return tank_prefix_for_commodity(commodity, config)
    return WAYBILL_TYPE_AAR_PREFIX.get(car_type)


class SeedBuilder:
    def __init__(self, config: dict):
        self.config = config
        self.routing_rows: list[dict] = []
        self.location_rows: list[dict] = []
        self.commodity_rows: list[dict] = []
        self.car_code_rows: list[dict] = []
        self.shipment_rows: list[dict] = []
        self.car_rows: list[dict] = []
        self.job_rows: list[dict] = []
        self.pu_criteria_rows: list[dict] = []
        self.empty_location_rows: list[dict] = []
        self.inbound_empty_shipments: list[dict] = []

        self.location_code_to_id: dict[str, int] = {}
        self.commodity_code_to_id: dict[str, int] = {}
        self.car_code_to_id: dict[str, int] = {}
        self.fleet_code_counts: dict[str, int] = {}
        self.freight_car_ids: set[int] = set()
        self.industry_first_location: dict[str, str] = {}
        self.usage_lookup: dict[tuple[str, str], str] = {}

        self._next_location_id = 1
        self._next_commodity_id = 1
        self._next_shipment_id = 1
        self._next_car_id = 1
        self._next_car_code_id = 1

        self.layout_parties = set(config.get("layout_party_names", []))

    def ensure_car_code(self, code: str) -> int:
        code = aar_code_prefix(code)
        if code in self.car_code_to_id:
            return self.car_code_to_id[code]
        car_code_id = self._next_car_code_id
        self._next_car_code_id += 1
        self.car_code_to_id[code] = car_code_id
        self.car_code_rows.append(
            {
                "id": car_code_id,
                "code": code,
                "description": aar_description(code),
                "remarks": "",
            }
        )
        return car_code_id

    def resolve_fleet_car_code(self, prefix: str) -> str:
        """Prefer an AAR code the roster actually has."""
        prefix = aar_code_prefix(prefix)
        if self.fleet_code_counts.get(prefix, 0) > 0:
            return prefix
        for alt in TANK_CODE_FALLBACKS.get(prefix, []):
            alt = aar_code_prefix(alt)
            if self.fleet_code_counts.get(alt, 0) > 0:
                return alt
        matching = [
            code
            for code, count in self.fleet_code_counts.items()
            if code.startswith(prefix) and count > 0
        ]
        if matching:
            return max(matching, key=lambda code: self.fleet_code_counts[code])
        if prefix in TANK_AAR_PREFIXES:
            for tank_code in ("TA", "TL"):
                if self.fleet_code_counts.get(tank_code, 0) > 0:
                    return tank_code
        return prefix

    def pick_shipment_aar_code(self, car_type: str, commodity: str) -> str:
        prefix = aar_prefix_for_waybill(car_type, commodity, self.config)
        if not prefix:
            prefix = "XM"
        return self.resolve_fleet_car_code(prefix)

    def is_hopper_car_code(self, car_code_id: int) -> bool:
        for row in self.car_code_rows:
            if row["id"] == car_code_id:
                return row["code"][:2] in HOPPER_AAR_PREFIXES
        return False

    def add_routing(self) -> None:
        instructions = self.config.get("routing_instructions", {})
        default_setout = self.config.get("default_setout_locations", {})
        for station in self.config["stations"]:
            station_id = station["id"]
            self.routing_rows.append(
                {
                    "id": station_id,
                    "station": station["name"],
                    "station_nbr": None,
                    "instructions": station.get("instructions")
                    or instructions.get(str(station_id))
                    or instructions.get(station["name"], ""),
                    "sort_seq": station["sort_seq"],
                    "color1": 0,
                    "color2": 0,
                    "_default_setout_code": default_setout.get(str(station_id))
                    or default_setout.get(station["name"]),
                }
            )

    def apply_default_setout_locations(self) -> None:
        for row in self.routing_rows:
            code = row.pop("_default_setout_code", None)
            if code and code in self.location_code_to_id:
                row["station_nbr"] = self.location_code_to_id[code]

    def add_location(
        self,
        code: str,
        station_id: int,
        track: str = "",
        spot: str = "",
        rpt_station: str = "",
        remarks: str = "",
        color: str = "",
    ) -> int:
        if code in self.location_code_to_id:
            return self.location_code_to_id[code]
        loc_id = self._next_location_id
        self._next_location_id += 1
        self.location_code_to_id[code] = loc_id
        self.location_rows.append(
            {
                "id": loc_id,
                "code": code,
                "station": station_id,
                "track": track,
                "spot": spot,
                "rpt_station": rpt_station,
                "remarks": remarks,
                "color": color,
            }
        )
        return loc_id

    def add_yard_locations(self) -> None:
        # Staging yards (car home / fleet storage)
        self.add_location("NORTH-YARD", 11, track="Yard")
        self.add_location("WEST-YARD", 2, track="Yard")
        self.add_location("EAST-YARD", 12, track="Yard")
        self.add_location("SOUTH-YARD", 8, track="Yard")
        # Empty-return yards (separate routing stations; see outbound_empty_return in config)
        self.add_location("SCULLY", 9, track="Yard", remarks="POHC")
        self.add_location("DEMMLER", 10, track="Yard", remarks="CSX")

    def ingest_spots(self, spot_csv: Path) -> None:
        skip = {u.lower() for u in self.config["skip_usages"]}
        island_station = int(self.config["island_local_station"])
        seen_usage: set[tuple[str, str]] = set()
        with spot_csv.open(newline="", encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                industry = row["Industry"].strip()
                usage = row["Usage"].strip()
                if usage.lower() in skip:
                    continue
                key = (industry, usage)
                if key in seen_usage:
                    continue
                seen_usage.add(key)
                code = usage_location_code(industry, usage)
                self.add_location(
                    code,
                    island_station,
                    track=industry,
                    spot=usage,
                )
                self.usage_lookup[key] = code
                if industry not in self.industry_first_location:
                    self.industry_first_location[industry] = code

    def external_yard_for_via(self, via: str) -> tuple[str, int, str]:
        via = (via or "CSX").strip().upper()
        if via == "POHC":
            return "SCULLY", 9, "POHC"
        return "DEMMLER", 10, "CSX"

    def interchange_yard_location_id(self, via: str) -> int:
        code, _, _ = self.external_yard_for_via(via)
        return self.location_code_to_id[code]

    def interchange_crossing_yards(self, via: str) -> tuple[int, int, str, str]:
        """Interchange loads at one yard and unloads at the other (POHC↔CSX bridge)."""
        scully_id = self.location_code_to_id["SCULLY"]
        demmler_id = self.location_code_to_id["DEMMLER"]
        via = (via or "CSX").strip().upper()
        if via == "POHC":
            return scully_id, demmler_id, "POHC", "CSX"
        return demmler_id, scully_id, "CSX", "POHC"

    def interchange_yard_label(self, via: str) -> str:
        code, station_id, _ = self.external_yard_for_via(via)
        for station in self.config.get("stations", []):
            if station.get("id") == station_id:
                return station["name"]
        return code

    def off_line_party_label(self, via: str, party: str) -> str:
        party = party.strip()
        yard = self.interchange_yard_label(via)
        if party:
            return f"{yard} ({party})"
        return yard

    def industry_spot_label(
        self, industry: str, usage: str, spots: str, spot_code: str | None
    ) -> str:
        if industry and usage:
            return f"{industry} — {usage}"
        return industry or usage or (spot_code or "")

    def add_commodities_from_waybills(self, waybill_rows: list[dict]) -> None:
        codes: dict[str, str] = {}
        for extra in self.config.get("extra_commodities", []):
            codes[extra["code"]] = extra["description"]
        for row in waybill_rows:
            commodity = row.get("commodity", "").strip()
            if not commodity or commodity == "EMPTY":
                continue
            code = commodity_code(commodity)
            if code:
                summary = row.get("load_summary", "").strip()
                desc = summary if summary else commodity
                codes[code] = desc
        for code in sorted(codes):
            cid = self._next_commodity_id
            self._next_commodity_id += 1
            self.commodity_code_to_id[code] = cid
            self.commodity_rows.append(
                {"id": cid, "code": code, "description": codes[code], "remarks": ""}
            )

    def resolve_industry_spot(
        self, industry: str, usage: str, spots: str
    ) -> str | None:
        usage = usage.strip()
        if usage:
            key = (industry, usage)
            if key in self.usage_lookup:
                return self.usage_lookup[key]
            return usage_location_code(industry, usage)
        return self.industry_first_location.get(industry)

    def shipment_code(self, row: dict) -> str:
        card_id = row.get("card_id", "").strip()
        industry = row.get("industry", "").strip()
        kind = row.get("card_kind", "").strip()
        flow = row.get("flow", "").strip()
        prefix = {
            "Aristech Plastics": "ARIS",
            "A Stucki Co": "STUK",
            "Calgon Carbon": "CALG",
            "Ferrel Gas": "FERR",
            "Kosmos Cement": "KOSM",
            "INTERCHANGE": "IX",
        }.get(industry, "SHP")
        return f"{prefix}-{kind[:3].upper()}-{flow or 'XX'}-{card_id.zfill(3)}"

    def shipment_description(
        self,
        row: dict,
        *,
        loading_label: str,
        unloading_label: str,
        via_label: str | None = None,
    ) -> str:
        industry = row.get("industry", "").strip()
        commodity = row.get("commodity", "").strip()
        via = via_label if via_label is not None else row.get("via", "").strip()
        if industry == "INTERCHANGE":
            return f"{commodity} ({via})" if via else commodity
        if industry:
            return f"{industry} — {commodity}"
        return commodity

    def add_shipments(self, waybill_rows: list[dict]) -> None:
        intervals = SHIPMENT_INTERVALS

        for row in waybill_rows:
            industry = row.get("industry", "").strip()
            kind = row.get("card_kind", "").strip()
            flow = row.get("flow", "").strip()
            usage = row.get("usage", "").strip()
            spots = row.get("spots", "").strip()
            commodity_name = row.get("commodity", "").strip()
            route_from = row.get("route_from", "").strip()
            route_to = row.get("route_to", "").strip()
            via = row.get("via", "").strip()
            car_type = row.get("car_type", "").strip()

            # STS tracks empty/loaded on cars; skip dedicated empty-move orders.
            if kind in {"inbound_empty", "outbound_empty"}:
                continue
            if commodity_name == "EMPTY":
                continue

            car_code = self.pick_shipment_aar_code(car_type, commodity_name)
            car_code_id = self.ensure_car_code(car_code)
            commodity_key = commodity_code(commodity_name)
            consignment_id = self.commodity_code_to_id.get(commodity_key)

            if kind == "interchange":
                if is_layout_party(route_from, self.layout_parties):
                    continue
                if is_layout_party(route_to, self.layout_parties):
                    continue
                loading_id, unloading_id, load_via, unload_via = (
                    self.interchange_crossing_yards(via)
                )
                loading_label = self.off_line_party_label(load_via, route_from)
                unloading_label = self.off_line_party_label(unload_via, route_to)
                special = f"{load_via}→{unload_via}"
            elif kind == "loaded" and flow == "IN":
                if not route_from:
                    continue
                spot_code = self.resolve_industry_spot(industry, usage, spots)
                if not spot_code:
                    continue
                loading_id = self.interchange_yard_location_id(via)
                unloading_id = self.location_code_to_id[spot_code]
                loading_label = self.off_line_party_label(via, route_from)
                unloading_label = self.industry_spot_label(
                    industry, usage, spots, spot_code
                )
                special = via or ""
            elif kind == "loaded" and flow == "OUT":
                if not route_to:
                    continue
                spot_code = self.resolve_industry_spot(industry, usage, spots)
                if not spot_code:
                    continue
                loading_id = self.location_code_to_id[spot_code]
                unloading_id = self.interchange_yard_location_id(via)
                loading_label = self.industry_spot_label(
                    industry, usage, spots, spot_code
                )
                unloading_label = self.off_line_party_label(via, route_to)
                special = via or ""
            else:
                continue

            if consignment_id is None:
                continue

            self.shipment_rows.append(
                {
                    "id": self._next_shipment_id,
                    "code": self.shipment_code(row),
                    "description": self.shipment_description(
                        row,
                        loading_label=loading_label,
                        unloading_label=unloading_label,
                        via_label=special if kind == "interchange" else None,
                    ),
                    "consignment": consignment_id,
                    "car_code": car_code_id,
                    "loading_location": loading_id,
                    "unloading_location": unloading_id,
                    "last_ship_date": 0,
                    "min_interval": intervals["min_interval"],
                    "max_interval": intervals["max_interval"],
                    "min_amount": intervals["min_amount"],
                    "max_amount": intervals["max_amount"],
                    "special_instructions": special,
                    "remarks": "",
                }
            )
            self._next_shipment_id += 1

    def car_code_for_id(self, car_code_id: int) -> str:
        for row in self.car_code_rows:
            if row["id"] == car_code_id:
                return row["code"]
        return "XM"

    def car_length_ft(self, car: dict) -> int | None:
        match = re.search(r"(\d+)ft", car.get("remarks", ""))
        return int(match.group(1)) if match else None

    def is_unit_train_hopper(self, code: str, car: dict) -> bool:
        """Outside-dump and longer open hoppers stage at East Yard for coke/coal unit moves."""
        if code not in OPEN_TOP_HOPPER_CODES:
            return False
        if code == "HB":
            return True
        max_length = int(
            self.config.get("car_home_yard", {}).get(
                "unit_train_hopper_max_length_ft", 40
            )
        )
        length = self.car_length_ft(car)
        return length is None or length > max_length

    def build_interchange_yard_demand_from_shipments(
        self,
    ) -> dict[str, dict[int, int]]:
        scully_id = self.location_code_to_id["SCULLY"]
        demmler_id = self.location_code_to_id["DEMMLER"]
        demand: dict[str, dict[int, int]] = {}

        for shipment in self.shipment_rows:
            yard_id = shipment["loading_location"]
            if yard_id not in (scully_id, demmler_id):
                continue
            code = self.car_code_for_id(shipment["car_code"])
            demand.setdefault(code, {})
            demand[code][yard_id] = demand[code].get(yard_id, 0) + 1
        return demand

    def interchange_yard_targets(
        self,
        demand: dict[str, dict[int, int]],
        interchange_counts: dict[str, int],
    ) -> dict[str, dict[int, int]]:
        scully_id = self.location_code_to_id["SCULLY"]
        demmler_id = self.location_code_to_id["DEMMLER"]
        targets: dict[str, dict[int, int]] = {}

        for code, count in interchange_counts.items():
            if count <= 0:
                continue
            code_demand = demand.get(code, {})
            scully_need = code_demand.get(scully_id, 0)
            demmler_need = code_demand.get(demmler_id, 0)
            total_need = scully_need + demmler_need
            if total_need == 0:
                scully_target = count // 2
            else:
                scully_target = round(count * scully_need / total_need)
            scully_target = min(max(scully_target, 0), count)
            targets[code] = {
                scully_id: scully_target,
                demmler_id: count - scully_target,
            }
        return targets

    def pick_home_yard_for_car_code(
        self,
        code: str,
        targets: dict[str, dict[int, int]],
        assigned: dict[str, dict[int, int]],
        scully_id: int,
        demmler_id: int,
    ) -> int:
        code_targets = targets.get(
            code, {scully_id: 0, demmler_id: 0}
        )
        code_assigned = assigned.setdefault(code, {})
        scully_have = code_assigned.get(scully_id, 0)
        demmler_have = code_assigned.get(demmler_id, 0)
        scully_room = code_targets.get(scully_id, 0) - scully_have
        demmler_room = code_targets.get(demmler_id, 0) - demmler_have

        if scully_room > demmler_room:
            yard = scully_id
        elif demmler_room > scully_room:
            yard = demmler_id
        else:
            yard = scully_id if scully_have <= demmler_have else demmler_id

        code_assigned[yard] = code_assigned.get(yard, 0) + 1
        return yard

    def assign_car_home_yards(self) -> None:
        scully_id = self.location_code_to_id["SCULLY"]
        demmler_id = self.location_code_to_id["DEMMLER"]
        east_id = self.location_code_to_id["EAST-YARD"]
        demand = self.build_interchange_yard_demand_from_shipments()

        interchange_cars: dict[str, list[dict]] = {}
        for car in self.car_rows:
            if car["id"] not in self.freight_car_ids:
                continue
            code = self.car_code_for_id(car["car_code_id"])
            if self.is_unit_train_hopper(code, car):
                car["current_location_id"] = east_id
                car["home_location"] = east_id
                continue
            interchange_cars.setdefault(code, []).append(car)

        targets = self.interchange_yard_targets(
            demand, {code: len(cars) for code, cars in interchange_cars.items()}
        )
        assigned: dict[str, dict[int, int]] = {}

        for code, cars in interchange_cars.items():
            for car in cars:
                yard_id = self.pick_home_yard_for_car_code(
                    code, targets, assigned, scully_id, demmler_id
                )
                car["current_location_id"] = yard_id
                car["home_location"] = yard_id

    def add_cars(
        self,
        roster_xml: Path,
        metadata_csv: Path,
        final_images_dir: Path,
    ) -> None:
        home_yard = self.config["car_home_yard"]
        scully_id = self.location_code_to_id[home_yard["pohc_yard_code"]]
        demmler_id = self.location_code_to_id[home_yard["csx_yard_code"]]

        image_roster_ids = roster_ids_with_final_images(
            metadata_csv, final_images_dir, roster_xml
        )

        covered_hopper_prefixes = load_covered_hopper_prefixes(
            metadata_csv, roster_xml, self.config
        )
        roster_metadata = load_roster_metadata(metadata_csv, roster_xml)

        root = ET.parse(roster_xml).getroot()
        for car in root.findall("cars/car"):
            car_type = car.get("type", "")
            if car_type in PASSENGER_TYPES:
                continue
            roster_id = car.get("id", "")
            if roster_id not in image_roster_ids:
                # Preserve STS car IDs for synced RollingStock/{id}.jpg files.
                self._next_car_id += 1
                continue
            road = car.get("roadName", "")
            number = car.get("roadNumber", "")
            marks = f"{road}{number}"
            length = car.get("length", "")
            meta = roster_metadata.get(roster_id, {})
            covered_prefix = covered_hopper_prefix_for(
                roster_id, covered_hopper_prefixes, meta, self.config
            )
            code = aar_code_for_roster_car(
                car_type,
                length,
                covered_hopper_prefix=covered_prefix,
                roster_meta=meta,
                roster_id=roster_id,
                config=self.config,
            )
            if not code:
                self._next_car_id += 1
                continue
            car_code_id = self.ensure_car_code(code)
            self.fleet_code_counts[code] = self.fleet_code_counts.get(code, 0) + 1
            self.freight_car_ids.add(self._next_car_id)
            self.car_rows.append(
                {
                    "id": self._next_car_id,
                    "reporting_marks": marks,
                    "car_code_id": car_code_id,
                    "current_location_id": scully_id,
                    "position": None,
                    "status": "EMPTY",
                    "handled_by_job_id": None,
                    "remarks": f"{car_type} {length}ft" if length else car_type,
                    "load_count": 0,
                    "home_location": scully_id,
                    "RFID_code": None,
                }
            )
            self._next_car_id += 1

    def add_mow_equipment(
        self,
        roster_xml: Path,
        metadata_csv: Path,
        final_images_dir: Path,
    ) -> None:
        """Append MOW cars that have photos, using fixed car IDs (do not renumber freight fleet)."""
        entries = self.config.get("mow_equipment", [])
        if not entries:
            return

        csx_yard = self.config["car_home_yard"]["csx_yard_code"]
        start_yard = self.config["car_home_yard"]["start_yard_code"]
        start_yard_id = self.location_code_to_id[start_yard]
        demmler_id = self.location_code_to_id[csx_yard]
        image_roster_ids = roster_ids_with_final_images(
            metadata_csv, final_images_dir, roster_xml
        )

        root = ET.parse(roster_xml).getroot()
        roster_cars = {
            car.get("id", ""): car
            for car in root.findall("cars/car")
            if car.get("type") == "MOW"
        }

        for entry in entries:
            roster_id = entry["roster_id"]
            if roster_id not in image_roster_ids:
                continue
            car = roster_cars.get(roster_id)
            if car is None:
                continue

            car_id = int(entry["car_id"])
            length = car.get("length", "")
            aar_code = entry.get("aar_code")
            if not aar_code:
                if "crane" in entry.get("description", "").lower():
                    aar_code = "WC"
                else:
                    aar_code = "WF"
            aar_code = aar_code_prefix(aar_code)

            marks = f"{car.get('roadName', '')}{car.get('roadNumber', '')}"
            label = entry.get("description") or f"MOW {length}ft".strip()
            car_code_id = self.ensure_car_code(aar_code)
            self.fleet_code_counts[aar_code] = self.fleet_code_counts.get(aar_code, 0) + 1
            self.car_rows.append(
                {
                    "id": car_id,
                    "reporting_marks": marks,
                    "car_code_id": car_code_id,
                    "current_location_id": start_yard_id,
                    "position": None,
                    "status": "EMPTY",
                    "handled_by_job_id": None,
                    "remarks": label,
                    "load_count": 0,
                    "home_location": demmler_id,
                    "RFID_code": None,
                }
            )
            self._next_car_id = max(self._next_car_id, car_id + 1)

        self.car_rows.sort(key=lambda row: row["id"])

    def add_jobs(self) -> None:
        for job in self.config.get("jobs", []):
            self.job_rows.append(
                {
                    "id": job["id"],
                    "name": job["name"],
                    "description": job.get("description", ""),
                    "steps": job.get("steps", []),
                }
            )

    def build_default_pickup_criteria(self) -> list[dict]:
        """Pickup criteria for Auto-Assign: one dest_station per row (STS demo pattern)."""
        south_yard = 8
        scully = 9
        demmler = 10
        island = int(self.config["island_local_station"])
        rows: list[dict] = []

        def add(job: str, step: int, dest: int, car_status: str = "") -> None:
            rows.append(
                {
                    "job": job,
                    "step_nbr": step,
                    "car_status": car_status,
                    "commodity_id": None,
                    "car_code_id": None,
                    "dest_station_id": dest,
                }
            )

        # D749 — Demmler pick-ups by destination, South Yard block, Demmler set-out
        add("D749", 10, scully)
        add("D749", 20, island)
        add("D749", 30, demmler)

        # NVL — Scully pick-ups, South Yard island block, island work, Scully return
        add("NVL", 10, island)
        add("NVL", 20, demmler)
        add("NVL", 30, island)
        for dest in (island, scully, demmler):
            add("NVL", 40, dest)
        add("NVL", 50, scully)

        # SY1 — yardmaster satellite-yard moves
        add("SY1", 10, island)
        add("SY1", 10, demmler)
        add("SY1", 10, scully)
        for step in (20, 30, 40):
            add("SY1", step, island)
            add("SY1", step, south_yard)

        # Optional coke moves
        add("CK-1", 10, 8, "Loaded")
        add("CKX", 10, 10, "Loaded")

        return rows

    def add_pickup_criteria(self) -> None:
        configured = self.config.get("pickup_criteria")
        if configured:
            self.pu_criteria_rows = configured
            return
        self.pu_criteria_rows = self.build_default_pickup_criteria()

    def render_sql(self) -> str:
        lines = [
            "-- HART layout seed data for STS",
            "-- Generated by scripts/generate_hart_seed.py — do not edit by hand",
            "",
            "SET NAMES utf8mb4;",
            "",
            "-- pu_criteria is created by open_db.php on first web visit; ensure it exists for seed/reseed",
            "CREATE TABLE IF NOT EXISTS `pu_criteria` (",
            "  `id` int(11) NOT NULL AUTO_INCREMENT,",
            "  `job_id` varchar(64) DEFAULT NULL,",
            "  `step_nbr` int(11) DEFAULT NULL,",
            "  `car_status` varchar(256) DEFAULT NULL,",
            "  `commodity_id` int(11) DEFAULT NULL,",
            "  `car_code_id` int(11) DEFAULT NULL,",
            "  `dest_station_id` int(11) DEFAULT NULL,",
            "  PRIMARY KEY (`id`)",
            ") ENGINE=InnoDB DEFAULT CHARSET=latin1;",
            "ALTER TABLE `pu_criteria` MODIFY `job_id` varchar(64) DEFAULT NULL;",
            "",
        ]

        cfg = self.config
        lines.extend(
            [
                "UPDATE `settings` SET `setting_value` = "
                f"{sql_str(cfg['railroad_name'])} WHERE `setting_name` = 'railroad_name';",
                "UPDATE `settings` SET `setting_value` = "
                f"{sql_str(cfg['railroad_initials'])} WHERE `setting_name` = 'railroad_initials';",
                "",
            ]
        )

        if self.routing_rows:
            lines.append("INSERT INTO `routing` "
                         "(`id`, `station`, `station_nbr`, `instructions`, `sort_seq`, `color1`, `color2`) VALUES")
            values = []
            for row in self.routing_rows:
                values.append(
                    f"({row['id']}, {sql_str(row['station'])}, {sql_int(row['station_nbr'])}, "
                    f"{sql_str(row['instructions'])}, {row['sort_seq']}, {row['color1']}, {row['color2']})"
                )
            lines.append(",\n".join(values) + ";")
            lines.append("")

        if self.location_rows:
            lines.append("INSERT INTO `locations` "
                         "(`Id`, `code`, `station`, `track`, `spot`, `rpt_station`, `remarks`, `color`) VALUES")
            values = []
            for row in self.location_rows:
                values.append(
                    f"({row['id']}, {sql_str(row['code'])}, {row['station']}, "
                    f"{sql_str(row['track'])}, {sql_str(row['spot'])}, "
                    f"{sql_str(row['rpt_station'])}, {sql_str(row['remarks'])}, {sql_str(row['color'])})"
                )
            lines.append(",\n".join(values) + ";")
            lines.append("")

        if self.commodity_rows:
            lines.append("INSERT INTO `commodities` (`Id`, `Code`, `Description`, `Remarks`) VALUES")
            values = []
            for row in self.commodity_rows:
                values.append(
                    f"({row['id']}, {sql_str(row['code'])}, {sql_str(row['description'])}, {sql_str(row['remarks'])})"
                )
            lines.append(",\n".join(values) + ";")
            lines.append("")

        if self.car_code_rows:
            self.car_code_rows.sort(key=lambda row: row["code"])
            lines.append("INSERT INTO `car_codes` (`Id`, `code`, `description`, `remarks`) VALUES")
            values = []
            for row in self.car_code_rows:
                values.append(
                    f"({row['id']}, {sql_str(row['code'])}, {sql_str(row['description'])}, '')"
                )
            lines.append(",\n".join(values) + ";")
            lines.append("")

        if self.shipment_rows:
            lines.append(
                "INSERT INTO `shipments` "
                "(`Id`, `code`, `description`, `consignment`, `car_code`, `loading_location`, "
                "`unloading_location`, `last_ship_date`, `min_interval`, `max_interval`, "
                "`min_amount`, `max_amount`, `special_instructions`, `remarks`) VALUES"
            )
            values = []
            for row in self.shipment_rows:
                values.append(
                    f"({row['id']}, {sql_str(row['code'])}, {sql_str(row['description'])}, "
                    f"{sql_int(row['consignment'])}, {row['car_code']}, {row['loading_location']}, "
                    f"{row['unloading_location']}, {row['last_ship_date']}, {row['min_interval']}, "
                    f"{row['max_interval']}, {row['min_amount']}, {row['max_amount']}, "
                    f"{sql_str(row['special_instructions'])}, {sql_str(row['remarks'])})"
                )
            lines.append(",\n".join(values) + ";")
            lines.append("")

        if self.car_rows:
            lines.append(
                "INSERT INTO `cars` "
                "(`Id`, `reporting_marks`, `car_code_id`, `current_location_id`, `position`, "
                "`status`, `handled_by_job_id`, `remarks`, `load_count`, `home_location`, `RFID_code`) VALUES"
            )
            values = []
            for row in self.car_rows:
                values.append(
                    f"({row['id']}, {sql_str(row['reporting_marks'])}, {row['car_code_id']}, "
                    f"{row['current_location_id']}, {sql_int(row['position'])}, "
                    f"{sql_str(row['status'])}, {sql_int(row['handled_by_job_id'])}, "
                    f"{sql_str(row['remarks'])}, {row['load_count']}, "
                    f"{sql_int(row['home_location'])}, {sql_str(row['RFID_code'])})"
                )
            lines.append(",\n".join(values) + ";")
            lines.append("")

        if self.job_rows:
            lines.append("INSERT INTO `jobs` (`Id`, `name`, `description`) VALUES")
            values = []
            for row in self.job_rows:
                values.append(
                    f"({row['id']}, {sql_str(row['name'])}, {sql_str(row['description'])})"
                )
            lines.append(",\n".join(values) + ";")
            lines.append("")

            for row in self.job_rows:
                table_name = row["name"]
                lines.append(f"CREATE TABLE `{table_name}` (")
                lines.append(
                    "  `step_number` int(11) NOT NULL,"
                )
                lines.append("  `station` int(11) DEFAULT NULL,")
                lines.append("  `pickup` char(1) DEFAULT NULL,")
                lines.append("  `setout` char(1) DEFAULT NULL,")
                lines.append("  `remarks` varchar(256) DEFAULT NULL,")
                lines.append("  PRIMARY KEY (`step_number`)")
                lines.append(") ENGINE=InnoDB DEFAULT CHARSET=latin1;")
                lines.append("")
                if row["steps"]:
                    lines.append(
                        f"INSERT INTO `{table_name}` "
                        "(`step_number`, `station`, `pickup`, `setout`, `remarks`) VALUES"
                    )
                    step_values = []
                    for step in row["steps"]:
                        pickup = "T" if step.get("pickup") else "F"
                        setout = "T" if step.get("setout") else "F"
                        step_values.append(
                            f"({step['step_number']}, {step['station']}, "
                            f"{sql_str(pickup)}, {sql_str(setout)}, "
                            f"{sql_str(step.get('remarks', ''))})"
                        )
                    lines.append(",\n".join(step_values) + ";")
                    lines.append("")

        if self.pu_criteria_rows:
            lines.append(
                "INSERT INTO `pu_criteria` "
                "(`id`, `job_id`, `step_nbr`, `car_status`, `commodity_id`, `car_code_id`, `dest_station_id`) VALUES"
            )
            values = []
            for row in self.pu_criteria_rows:
                values.append(
                    f"(NULL, {sql_str(row['job'])}, {row['step_nbr']}, "
                    f"{sql_str(row.get('car_status', ''))}, "
                    f"{sql_int(row.get('commodity_id'))}, "
                    f"{sql_int(row.get('car_code_id'))}, "
                    f"{sql_int(row['dest_station_id'])})"
                )
            lines.append(",\n".join(values) + ";")
            lines.append("")

        if self.empty_location_rows:
            lines.append(
                "INSERT INTO `empty_locations` (`shipment`, `priority`, `location`) VALUES"
            )
            values = []
            for row in self.empty_location_rows:
                values.append(
                    f"({row['shipment']}, {row['priority']}, {row['location']})"
                )
            lines.append(",\n".join(values) + ";")
            lines.append("")

        auto_tables = [
            ("cars", max((row["id"] for row in self.car_rows), default=0)),
            ("commodities", self._next_commodity_id - 1),
            ("car_codes", len(self.car_code_rows)),
            ("locations", self._next_location_id - 1),
            ("routing", len(self.routing_rows)),
            ("shipments", self._next_shipment_id - 1),
            ("jobs", max((row["id"] for row in self.job_rows), default=0)),
        ]
        lines.append("-- Reset AUTO_INCREMENT counters")
        for table, max_id in auto_tables:
            next_id = max(max_id + 1, 1)
            lines.append(f"ALTER TABLE `{table}` AUTO_INCREMENT = {next_id};")
        lines.append("")

        lines.append("-- Summary")
        lines.append(f"-- routing: {len(self.routing_rows)}")
        lines.append(f"-- locations: {len(self.location_rows)}")
        lines.append(f"-- commodities: {len(self.commodity_rows)}")
        lines.append(f"-- car_codes: {len(self.car_code_rows)}")
        lines.append(f"-- shipments: {len(self.shipment_rows)}")
        lines.append(f"-- cars: {len(self.car_rows)}")
        lines.append(f"-- jobs: {len(self.job_rows)}")
        lines.append(f"-- pu_criteria: {len(self.pu_criteria_rows)}")
        lines.append(f"-- empty_locations: {len(self.empty_location_rows)}")
        lines.append("")
        return "\n".join(lines)


def read_waybills(path: Path) -> list[dict]:
    with path.open(newline="", encoding="utf-8") as fh:
        return list(csv.DictReader(fh))


def main() -> None:
    global MRR_DESCRIPTIONS
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--hart-dir", type=Path, default=DEFAULT_HART_DIR)
    parser.add_argument("--config", type=Path, default=DEFAULT_CONFIG)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--mrr-csv", type=Path, default=DEFAULT_MRR_CSV)
    args = parser.parse_args()

    MRR_DESCRIPTIONS = load_mrr_aar_descriptions(args.mrr_csv)

    hart_dir = args.hart_dir
    config = load_config(args.config)
    builder = SeedBuilder(config)

    builder.add_routing()
    builder.add_yard_locations()
    builder.ingest_spots(hart_dir / "spot_assignments.csv")
    builder.apply_default_setout_locations()

    waybills = read_waybills(hart_dir / "HART_Spot_Waybills.csv")
    builder.add_commodities_from_waybills(waybills)
    builder.add_cars(
        hart_dir / "HART_MergedCarRoster.xml",
        hart_dir / "image_metadata.csv",
        hart_dir / "CarImagesFinal",
    )
    builder.add_mow_equipment(
        hart_dir / "HART_MergedCarRoster.xml",
        hart_dir / "image_metadata.csv",
        hart_dir / "CarImagesFinal",
    )
    builder.add_shipments(waybills)
    builder.assign_car_home_yards()
    builder.add_jobs()
    builder.add_pickup_criteria()

    sql = builder.render_sql()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(sql, encoding="utf-8")
    print(f"Wrote {args.output}")
    print(
        f"  routing={len(builder.routing_rows)} locations={len(builder.location_rows)} "
        f"commodities={len(builder.commodity_rows)} car_codes={len(builder.car_code_rows)} "
        f"shipments={len(builder.shipment_rows)} "
        f"cars={len(builder.car_rows)} "
        f"jobs={len(builder.job_rows)} "
        f"pu_criteria={len(builder.pu_criteria_rows)} "
        f"empty_locations={len(builder.empty_location_rows)}"
    )


if __name__ == "__main__":
    main()
