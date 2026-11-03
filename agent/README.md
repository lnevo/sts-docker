# STS agent plugin

<!-- markdownlint-disable MD013 -- tables and commands -->

A Claude Code plugin for operating and inspecting an STS railroad through its
REST API v1: the `sts-api` skill, an MCP server (`servers/sts_api.py`, Python
standard library only), and the `sts-inspector` (read-only) and `sts-operator`
agents.

## Install

From GitHub:

```bash
claude plugin marketplace add lnevo/sts-docker
claude plugin install sts@sts-docker
```

From a local checkout:

```bash
claude plugin marketplace add /path/to/sts-docker
claude plugin install sts@sts-docker
```

Or load it for one session: `claude --plugin-dir /path/to/sts-docker/agent`.

## Configure

| Variable             | Default                            | Meaning                                                    |
| -------------------- | ---------------------------------- | ---------------------------------------------------------- |
| `STS_API_URL`        | `http://localhost:8980/sts/api/v1` | Live is 8980, sandbox 8981                                 |
| `STS_API_TOKEN`      | none                               | Bearer token; needed for any write                         |
| `STS_API_TOKEN_FILE` | none                               | File holding the token, used when `STS_API_TOKEN` is unset |
| `STS_API_TIMEOUT`    | `30`                               | Seconds per request                                        |

The bootstrap admin token is at `sts-docker-data/config/bootstrap_admin.token`
on the docker host (sandbox: `sts-docker-data/sandbox/config/`).

## Tools

Read (reader role; open by default): `sts_health`, `sts_session`, `sts_list`,
`sts_get`, `sts_car_history`, `sts_catalog`. `sts_task` needs the operator role
(task records are never open); so does `sts_list`/`sts_get` on `tasks`.
Operator: `sts_command` (admin for the destructive commands), `sts_car_action`,
`sts_advance_session`, `sts_workflow_run`. Editor: `sts_update`,
`sts_create_car`, `sts_workflow_save`, `sts_workflow_delete`. Admin:
`sts_backup`, `sts_restore`, `sts_warm_start`, `sts_session_begin`,
`sts_session_play`, `sts_tokens`.

## Develop

```bash
python3 -m unittest discover -s agent/tests
python3 agent/tools/build_portable.py          # regenerate plugin.json / mcp.json
python3 agent/tools/build_portable.py --check
```

`.claude-plugin/plugin.json` and `.mcp.json` are the source; `plugin.json` and
`mcp.json` are the portable Agent Plugins copies.
