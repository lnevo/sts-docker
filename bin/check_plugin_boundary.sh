#!/usr/bin/env bash
# Boundary lint: core (sts/ minus plugins, vendor, uploads, ImageStore,
# *.generated.yaml) must not name plugin ids/functions or HART identifiers.
# Allowlist: bin/plugin_boundary_allow.txt ("<path>" or "<path>:<token>").
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
allow_file="bin/plugin_boundary_allow.txt"

pattern='track_scale|track-scale|coke_orders|replenish_coke|keep_coke|\bCK1\b|\bD749\b|\bNVL\b|STG-SCULLY|STG-DEMMLER|Shenango|Scully|Neville|Demmler|\bCOKE-|\bSCL-|\bNIL-|SOUTH-SCALE'

matches="$(grep -rnEi \
    --exclude-dir=plugins --exclude-dir=vendor --exclude-dir=uploads --exclude-dir=ImageStore \
    --exclude='*.generated.yaml' -e "$pattern" sts || true)"

whole=()
tokpaths=()
toks=()
if [ -f "$allow_file" ]; then
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%%[[:space:]]}"
        case "$line" in ''|'#'*) continue ;; esac
        if [[ "$line" == *:* ]]; then
            tokpaths+=("${line%%:*}")
            toks+=("${line#*:}")
        else
            whole+=("$line")
        fi
    done < "$allow_file"
fi

fail=0
used_whole=" "
used_tok=" "
remaining=0
total=0
allowed=0

while IFS= read -r m; do
    [ -n "$m" ] || continue
    total=$((total + 1))
    path="${m%%:*}"
    rest="${m#*:}"
    text="${rest#*:}"
    covered=0
    for w in ${whole[@]+"${whole[@]}"}; do
        if [ "$w" = "$path" ]; then covered=1; used_whole="$used_whole$w "; fi
    done
    i=0
    for tp in ${tokpaths[@]+"${tokpaths[@]}"}; do
        if [ "$tp" = "$path" ] && printf '%s\n' "$text" | grep -qiF -- "${toks[$i]}"; then
            covered=1; used_tok="$used_tok$i "
        fi
        i=$((i + 1))
    done
    if [ "$covered" -eq 1 ]; then
        allowed=$((allowed + 1))
    else
        remaining=$((remaining + 1))
        printf '%s:%s: %s\n' "$path" "${rest%%:*}" "$text"
    fi
done <<< "$matches"

stale=0
for w in ${whole[@]+"${whole[@]}"}; do
    if [ ! -e "$w" ]; then echo "STALE allowlist entry (path missing): $w"; stale=$((stale + 1))
    elif [[ "$used_whole" != *" $w "* ]]; then echo "STALE allowlist entry (no matches): $w"; stale=$((stale + 1)); fi
done
i=0
for tp in ${tokpaths[@]+"${tokpaths[@]}"}; do
    if [ ! -e "$tp" ]; then echo "STALE allowlist entry (path missing): $tp:${toks[$i]}"; stale=$((stale + 1))
    elif [[ "$used_tok" != *" $i "* ]]; then echo "STALE allowlist entry (no matches): $tp:${toks[$i]}"; stale=$((stale + 1)); fi
    i=$((i + 1))
done

echo "plugin boundary: $total match(es), $allowed allowlisted, $remaining violation(s), $stale stale allowlist entr(ies)"
if [ "$remaining" -gt 0 ] || [ "$stale" -gt 0 ]; then fail=1; fi
exit "$fail"
