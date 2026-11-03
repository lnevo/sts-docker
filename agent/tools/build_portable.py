#!/usr/bin/env python3
"""Write each plugin's portable Agent Plugins files from its Claude Code files.

  python3 tools/build_portable.py           write plugin.json and mcp.json
  python3 tools/build_portable.py --check   fail if a written file is out of date

.claude-plugin/plugin.json and .mcp.json are the source: edit those, then run this.
Server paths are relative because the standard starts a server in the plugin root, and
because Cursor does not expand the standard's ${PLUGIN_ROOT}.
"""
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGINS = (ROOT,)
SCHEMAS = "https://agent-plugins.org/schemas/1.0.0"
MANIFEST_FIELDS = ("name", "version", "description", "author", "homepage", "repository", "license", "keywords")
CLAUDE_ROOT = "${CLAUDE_PLUGIN_ROOT}/"


def manifest(plugin: Path) -> dict:
    source = json.loads((plugin / ".claude-plugin" / "plugin.json").read_text())
    kept = {k: source[k] for k in MANIFEST_FIELDS if k in source}
    return {"$schema": f"{SCHEMAS}/plugin.schema.json", **kept}


def server(entry: dict) -> dict:
    args = [a.removeprefix(CLAUDE_ROOT) for a in entry.get("args", [])]
    return {"type": "stdio", "command": entry["command"], "args": args}


def mcp(plugin: Path) -> dict:
    source = json.loads((plugin / ".mcp.json").read_text())
    servers = {name: server(entry) for name, entry in source["mcpServers"].items()}
    return {"$schema": f"{SCHEMAS}/mcp.schema.json", "mcpServers": servers}


def outputs() -> dict[Path, str]:
    built = {}
    for plugin in PLUGINS:
        built[plugin / "plugin.json"] = json.dumps(manifest(plugin), indent=2) + "\n"
        built[plugin / "mcp.json"] = json.dumps(mcp(plugin), indent=2) + "\n"
    return built


def stale() -> list[Path]:
    return [p for p, text in outputs().items() if not p.is_file() or p.read_text() != text]


def main() -> int:
    if "--check" in sys.argv[1:]:
        old = stale()
        for path in old:
            print(f"Out of date: {path.relative_to(ROOT)}. Run python3 tools/build_portable.py", file=sys.stderr)
        return 1 if old else 0
    for path, text in outputs().items():
        path.write_text(text)
    return 0


if __name__ == "__main__":
    sys.exit(main())
