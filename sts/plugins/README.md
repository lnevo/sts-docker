# sts/plugins

Mount point for addons. Addons live in the sibling `sts-plugins` repo
(`../sts-plugins`, i.e. `/Users/lnevo/hart/external/sts-plugins`) as
`plugins/<id>/plugin.php`. docker-compose bind-mounts `../sts-plugins/plugins`
onto this directory. The image ships it empty, and core must work with no
plugins present.

Alternative: set `STS_PLUGINS_DIR` to a colon-separated list of addon
directories; `sts/plugin_registry.php` discovers those as well.

Manifest keys, hooks and the boundary rule: `docs/plans/plugin-hook-contract.md`.
