#!/bin/sh
# レポート管理システム 監査の起動スクリプト
#   ./run_audit.sh              全部実行
#   ./run_audit.sh --skip-http  サーバーにアクセスしない
# オプションは python3 audit.py --help を参照
cd "$(dirname "$0")" || exit 1

if ! command -v python3 >/dev/null 2>&1; then
    echo "python3 が見つかりません。sudo apt install python3 でインストールしてください。" >&2
    exit 2
fi

exec python3 ./audit.py "$@"
