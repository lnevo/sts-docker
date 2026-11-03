"""Minimal MCP stdio server using only the Python standard library.

Adapted from lnevo/model-railroad-skills (plugins/operations-pro/servers/mcp_stdio.py, MIT).
Change: a tool may set destructive explicitly; the default stays "not read_only".
"""
from __future__ import annotations

import json
from pathlib import Path
import sys
from typing import Callable

PROTOCOL_VERSION = "2025-06-18"


class Tool:
    def __init__(self, name: str, description: str, schema: dict, run: Callable[[dict], object],
                 read_only: bool = True, destructive: bool | None = None):
        self.name = name
        self.description = description
        self.schema = schema
        self.run = run
        self.read_only = read_only
        self.destructive = (not read_only) if destructive is None else destructive


PLUGIN_VERSION = json.loads((Path(__file__).parents[1] / ".claude-plugin" / "plugin.json").read_text())["version"]


def handle(message: dict, server_name: str, tools: dict[str, Tool], instructions: str = "") -> dict | None:
    """Return the response to one JSON-RPC message, or None for a notification."""
    method = message.get("method")
    request_id = message.get("id")
    if request_id is None:
        return None
    if method == "initialize":
        result: dict = {
            "protocolVersion": message.get("params", {}).get("protocolVersion", PROTOCOL_VERSION),
            "capabilities": {"tools": {}},
            "serverInfo": {"name": server_name, "version": PLUGIN_VERSION},
        }
        if instructions:  # For clients without the skill: they may show this to the model.
            result["instructions"] = instructions
    elif method == "ping":
        result = {}
    elif method == "tools/list":
        result = {
            "tools": [
                {
                    "name": t.name,
                    "description": t.description,
                    "inputSchema": t.schema,
                    "annotations": {"readOnlyHint": t.read_only, "destructiveHint": t.destructive},
                }
                for t in tools.values()
            ]
        }
    elif method == "tools/call":
        params = message.get("params", {})
        tool = tools.get(params.get("name"))
        if tool is None:
            return _error(request_id, -32602, f"Unknown tool {params.get('name')}")
        try:
            text = json.dumps(tool.run(params.get("arguments") or {}), indent=1)
            result = {"content": [{"type": "text", "text": text}]}
        except Exception as exc:  # A failed read is a tool result, not a server crash.
            result = {"content": [{"type": "text", "text": str(exc)}], "isError": True}
    else:
        return _error(request_id, -32601, f"Unknown method {method}")
    return {"jsonrpc": "2.0", "id": request_id, "result": result}


def _error(request_id: object, code: int, message: str) -> dict:
    return {"jsonrpc": "2.0", "id": request_id, "error": {"code": code, "message": message}}


def serve(server_name: str, tools: list[Tool], instructions: str = "") -> None:
    by_name = {t.name: t for t in tools}
    for line in sys.stdin:
        if not line.strip():
            continue
        try:
            message = json.loads(line)
        except ValueError:
            continue
        response = handle(message, server_name, by_name, instructions)
        if response is not None:
            print(json.dumps(response), flush=True)
