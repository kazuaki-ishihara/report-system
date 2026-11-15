#!/bin/sh
# 2FA code for: hosa01
# Requires tools/test/output_dev_users.json (made by tools/test/dev_seed.php). Dev/test accounts only.
# Usage: sh tools/2fa/hosa01.sh   (Enter: refresh / Ctrl+C: quit)
DIR=$(cd "$(dirname "$0")" && pwd)
PY=$(command -v python3 || command -v python) || { echo "python3 not found."; exit 1; }
[ -f "$DIR/../test/output_dev_users.json" ] || { echo "tools/test/output_dev_users.json not found."; exit 1; }
export PYTHONIOENCODING=utf-8
while :; do
    clear
    "$PY" "$DIR/../test/dev_code.py" hosa01
    echo
    printf '[Enter] refresh   [Ctrl+C] quit '
    read _ || exit 0
done
