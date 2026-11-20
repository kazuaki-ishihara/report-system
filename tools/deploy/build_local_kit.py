#!/usr/bin/env python3
"""ほかの人に渡すローカル検証環境の一式を作る（本番には使わない）。

    python tools/deploy/build_local_kit.py              # コミット済みの HEAD から作る
    python tools/deploy/build_local_kit.py --worktree   # 作業中の変更（コミット前）も入れて作る
    python tools/deploy/build_local_kit.py --no-php     # PHP を同梱しない（Linux・macOS の人に渡すとき）
    python tools/deploy/build_local_kit.py --x86        # 32 ビットの Windows 用（32 ビット版の PHP を同梱。64 ビットの Windows でも動く）
                                                        #   PHP は php.net の 32 ビット版（x86・TS）を展開したフォルダー（既定: リポジトリの隣の php-*-x86）。
                                                        #   php.ini は 64 ビット版と同じ拡張（mbstring・openssl・pdo_sqlite・sqlite3）を有効にしておく

出力: tools/deploy/output/local_<日時>_<コミット>/（--x86 のときは末尾に _x86）
    report-system-local/      ← これを渡す（同じ中身の .zip も作る。フォルダー名は英字）
        はじめにお読みください.txt
        1_起動.bat  2_テストデータを作り直す.bat  3_記録を書き出す.bat  （Windows）
        start.sh  reset_data.sh  export_log.sh      （Linux・macOS。PHP 8.3 以上が要る）
        php/        Windows 用の PHP（この端末の PHP を写したもの。インストール不要）
        app/  public/  tools/test/dev_seed.php  tools/test/export_faq_chat_log.php  操作マニュアル.md
    report-system-local.zip

入れないもの: DB・暗号の鍵・記録・バックアップ・この端末の config.local.php（設定はひな形から作る）。
DB と鍵は、受け取った人が最初に起動したときに新しく作る（テスト用の利用者と、標準のよくある質問が入る）。
Python 3.8 以上の標準ライブラリと git だけで動く。
"""
import argparse
import datetime
import io
import os
import shutil
import subprocess
import sys
import tarfile
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPOSITORY_ROOT = os.path.dirname(os.path.dirname(HERE))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
KIT_NAME = "report-system-local"   # 英字にする（Windows で置き場所に日本語があると、PHP から別の PHP を呼ぶときに開けないことがあるため）

# 一式に入れるもの（リポジトリの中の場所）
INCLUDED_PREFIXES = ("app/", "public/")
INCLUDED_FILES = {"tools/test/dev_seed.php": "tools/test/dev_seed.php", "tools/test/export_faq_chat_log.php": "tools/test/export_faq_chat_log.php",
                  "docs/operation_manual.md": "操作マニュアル.md"}
# 一式に入れてはいけないもの（入っていたら作るのをやめる）
FORBIDDEN_SUFFIXES = (".sqlite", ".sqlite3", ".key", ".log", ".bak", ".pyc")
FORBIDDEN_DIRECTORIES = ("app/data/", "app/keys/", "app/logs/", "app/backups/")
# 同梱する PHP から外すもの（Web サーバーの部品・開発用の部品・WinGet の管理用のファイル）
PHP_EXCLUDED_NAMES = {"dev", "extras", "php8apache2_4.dll", "phpdbg.exe", "php8phpdbg.dll", "deplister.exe"}
PHP_EXCLUDED_SUFFIXES = (".db",)
# テスト用の利用者（tools/test/dev_seed.php と同じ。パスワードは pass-<ID>-1234）
TEST_USERS = [
    ("admin01", "管理者", "システム管理者", "すべての管理の画面。報告の中身は見ない"),
    ("hosa01", "システム補佐", "システム管理者補佐", "管理の代行。個々の情報は見ない"),
    ("kacho_a", "A課長", "A課の課長（最終確認者）", "確認を完了にする・課の管理・臨時の調査・出力・メッセージ"),
    ("dairi_a", "A代理", "A課の課長代理（確認者）", "報告を読んでコメント・チェック"),
    ("leader_a", "Aリーダー", "A課のリーダー（確認者）", "報告を読んでコメント・チェック"),
    ("ippan_a1", "A一般1", "A課の部下", "報告を作って出す・コメントに返信"),
    ("ippan_a2", "A一般2", "A課の部下", "報告を作って出す・コメントに返信"),
    ("kacho_b", "B課長", "B課の課長（最終確認者）", "B課の確認（A課の報告は見えない）"),
    ("ippan_b1", "B一般1", "B課の部下", "報告を作って出す"),
]

START_BAT = r"""@echo off
setlocal
rem 月次報告システム ローカル検証環境を起動する（この PC の中だけで動く。止めるときは Ctrl + C）
cd /d "%~dp0"
if not exist "app\lib" (
  echo 「app」フォルダーが見つかりません。zip のままでは動きません。
  echo zip を展開し、できた「report-system-local」フォルダーの中の 1_起動.bat を動かしてください。
  pause & exit /b 1
)
{x64_only_check}set "MR_PHP_DIR=%~dp0php"
set "PHP_EXE=%~dp0php\php.exe"
if not exist "%PHP_EXE%" (
  where php >nul 2>nul || (echo PHP が見つかりません。はじめにお読みください.txt を見てください。 & pause & exit /b 1)
  set "PHP_EXE=php"
)
"%PHP_EXE%" -v >nul 2>nul
if errorlevel 1 (
  echo PHP を起動できません。
  echo はじめにお読みください.txt の「7. 注意」の「PHP を起動できません」を見てください。
  pause & exit /b 1
)
"%PHP_EXE%" -m 2>nul | findstr /i /c:"pdo_sqlite" >nul
if errorlevel 1 (
  echo PHP の SQLite の拡張を読み込めません。
  echo フォルダーの名前を変えたり、中のファイルを動かしたりしていないか確かめてください。
  pause & exit /b 1
)
if not exist "app\data\dev.sqlite" (
  echo はじめての起動なので、テストデータを作ります...
  chcp 65001 >nul
  "%PHP_EXE%" tools\test\dev_seed.php --yes --with-faq
  if errorlevel 1 (chcp 932 >nul & echo テストデータを作れませんでした。上に出ているメッセージを、開発担当に送ってください。 & pause & exit /b 1)
  chcp 932 >nul
)
echo.
echo ===============================================================
echo  月次報告システム（ローカル検証環境）を起動しました
echo  ブラウザーで http://127.0.0.1:8000/ を開きます
echo  止めるときは、この画面で Ctrl + C を押し、「Y」を押してください
echo ===============================================================
echo.
start "" cmd /c "timeout /t 2 /nobreak >nul & start http://127.0.0.1:8000/login.php"
"%PHP_EXE%" -S 127.0.0.1:8000 -t public
echo.
echo 止まりました。「Failed to listen」と出たときは、ほかのソフトが 8000 番を使っています（はじめにお読みください.txt）。
pause
"""

# 説明書きの、同梱の PHP の版の注意
PHP_ARCH_NOTE_X64 = """  - この一式の PHP は 64 ビットの Windows 用です。32 ビットの Windows では動きません
    （1_起動.bat が「この PC の Windows は 32 ビットです」と出します）。32 ビット用の一式（フォルダー名の最後が _x86）を使ってください。"""
PHP_ARCH_NOTE_X86 = """  - この一式の PHP は 32 ビット版です。32 ビットの Windows でも、64 ビットの Windows でも動きます。"""

# 64 ビット用の一式だけに入れる、32 ビットの Windows なら止める部分（32 ビット用の一式は、どちらの Windows でも動くので入れない）
X64_ONLY_CHECK = r"""rem 同梱の PHP は 64 ビットの Windows 用（32 ビットの Windows では動かない。32 ビット用の一式を使う）
if /i "%PROCESSOR_ARCHITECTURE%"=="x86" if not defined PROCESSOR_ARCHITEW6432 (
  echo この PC の Windows は 32 ビットです。この一式の PHP は 64 ビットの Windows 用のため、動きません。
  echo 32 ビット用の一式（フォルダー名の最後が _x86）を使うか、開発担当に連絡してください。
  pause & exit /b 1
)
"""

RESET_BAT = r"""@echo off
setlocal
rem テストデータを作り直す（入力した報告・コメント・設定はすべて消えて、最初の状態に戻る）
cd /d "%~dp0"
set "MR_PHP_DIR=%~dp0php"
set "PHP_EXE=%~dp0php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"
echo テストデータを作り直します。入力した報告・コメント・設定は、すべて消えます。
echo 起動している場合は、先に起動の画面を閉じて（Ctrl + C）ください。
choice /C YN /M "作り直しますか"
if errorlevel 2 exit /b 0
chcp 65001 >nul
"%PHP_EXE%" tools\test\dev_seed.php --yes --with-faq
if errorlevel 1 (chcp 932 >nul & echo 作り直せませんでした。 & pause & exit /b 1)
chcp 932 >nul
echo 作り直しました。1_起動.bat で起動してください。
pause
"""

EXPORT_BAT = r"""@echo off
setlocal
rem よくある質問のチャットの記録を、CSV に書き出す（開発担当に送る用。報告の中身や鍵は入らない）
cd /d "%~dp0"
set "MR_PHP_DIR=%~dp0php"
set "PHP_EXE=%~dp0php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"
if not exist "app\data\dev.sqlite" (echo まだ記録がありません（一度も起動していません）。 & pause & exit /b 1)
chcp 65001 >nul
"%PHP_EXE%" tools\test\export_faq_chat_log.php
set "EXPORT_RESULT=%errorlevel%"
chcp 932 >nul
if not "%EXPORT_RESULT%"=="0" (echo 書き出せませんでした。 & pause & exit /b 1)
echo.
echo このフォルダーにできた「faq_chat_log_（日時）.csv」と「app_log_errors_（日時）.txt」を、開発担当に送ってください。
pause
"""

EXPORT_SH = """#!/bin/sh
# よくある質問のチャットの記録を、CSV に書き出す（開発担当に送る用。報告の中身や鍵は入らない）
cd "$(dirname "$0")" || exit 1
[ -f app/data/dev.sqlite ] || { echo "まだ記録がありません（一度も起動していません）"; exit 1; }
php tools/test/export_faq_chat_log.php && echo "このフォルダーにできた faq_chat_log_（日時）.csv と app_log_errors_（日時）.txt を、開発担当に送ってください。"
"""

START_SH = """#!/bin/sh
# 月次報告システム ローカル検証環境を起動する（この PC の中だけで動く。止めるときは Ctrl + C）
cd "$(dirname "$0")" || exit 1
command -v php >/dev/null 2>&1 || { echo "PHP が見つかりません（PHP 8.3 以上と pdo_sqlite・mbstring・openssl が要ります）"; exit 1; }
if [ ! -f app/data/dev.sqlite ]; then
  echo "はじめての起動なので、テストデータを作ります..."
  php tools/test/dev_seed.php --yes --with-faq || exit 1
fi
echo "ブラウザーで http://127.0.0.1:8000/ を開いてください（止めるときは Ctrl + C）"
(sleep 2; command -v xdg-open >/dev/null 2>&1 && xdg-open http://127.0.0.1:8000/login.php || open http://127.0.0.1:8000/login.php) >/dev/null 2>&1 &
php -S 127.0.0.1:8000 -t public
"""

RESET_SH = """#!/bin/sh
# テストデータを作り直す（入力した報告・コメント・設定はすべて消えて、最初の状態に戻る）
cd "$(dirname "$0")" || exit 1
printf "テストデータを作り直します。入力した内容はすべて消えます。よろしいですか [y/N] "
read -r answer
[ "$answer" = "y" ] || [ "$answer" = "Y" ] || exit 0
php tools/test/dev_seed.php --yes --with-faq
"""

README = """月次報告システム ローカル検証環境
作成: {stamp}　版: {ref} {commit}{dirty_note}

この PC の中だけで、月次報告システムを試せる一式です。
本番のデータは入っていません。入っているのは、テスト用の利用者・課・案件と、標準のよくある質問（{faq_count} 件）だけです。


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. 使い方（Windows）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  インストールは要りません（PHP をフォルダーの中に入れてあります）。

  1. zip を展開し、できた「report-system-local」フォルダーを、パソコンの中の好きな場所に置く
     （デスクトップなど。zip のままでは動かないので、必ず展開してから使う。フォルダー名は変えない）
  2. 「1_起動.bat」をダブルクリックする
     - はじめての起動のときは、テストデータを作ります（数秒）
     - 黒い画面が開いたままになり、ブラウザーでログインの画面が開きます
     - 「Windows によって PC が保護されました」と出たら「詳細情報」→「実行」を押す
     - 「ファイアウォールでブロックされています」と出たら「キャンセル」でかまいません（この PC の中だけで使うため）
  3. 下の「3. テスト用の利用者」の ID とパスワードでログインする
     （2 段階認証のコードは聞かれません。ローカル検証環境では飛ばしています）
  4. 終わるときは、黒い画面で Ctrl + C を押し、「Y」を押す（または黒い画面を閉じる）

  最初の状態に戻したいとき:
     起動の画面を閉じてから「2_テストデータを作り直す.bat」をダブルクリックし、「Y」を押す。
     入力した報告・コメント・設定は、すべて消えます。


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
2. 使い方（Linux・macOS）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  PHP 8.3 以上（pdo_sqlite・mbstring・openssl）が要ります（Ubuntu: sudo apt install php-cli php-sqlite3 php-mbstring）。
  端末でこのフォルダーに移り、
     sh start.sh          ← 起動（止めるときは Ctrl + C）
     sh reset_data.sh     ← テストデータを作り直す
     sh export_log.sh     ← チャットの記録を書き出す（7.）
  ブラウザーで http://127.0.0.1:8000/ を開きます。


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
3. テスト用の利用者（パスワードは「pass-ログイン ID-1234」）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{user_table}

  例: A課の部下は、ログイン ID「ippan_a1」、パスワード「pass-ippan_a1-1234」
  役割ごとに見える画面・できることが違います。ブラウザーを分ける（Chrome と Edge など）か、
  プライベートウィンドウを使うと、2 人を同時にログインさせて試せます。


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
4. 試してほしいこと（例）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  - 部下（ippan_a1）: 今月の報告を作って提出する。スマートフォンの幅（ブラウザーの幅を狭くする）でも試す
  - 確認者（leader_a）・課長（kacho_a）: 報告を読んでコメント・チェック・確認を完了にする
  - 部下: コメントに返信する
  - だれでも: メニューの「よくある質問」のチャットで質問する（「パスワードを忘れた」「使い方がわからない」など）
  - だれでも: アカウント設定の「表示」で画面のテーマを変える
  - 管理者（admin01）: ユーザーの追加、管理のトップ、よくある質問の記録
  操作の説明は「操作マニュアル.md」（メモ帳などで開けます）と、各画面の「？ この画面の使い方」にあります。


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
5. 気づいたことの伝え方
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  開発担当に、次を伝えてください。
  - どの利用者（ログイン ID）で、どの画面で、何をしたか
  - どうなったか（画面の写し（スクリーンショット）があると助かります）
  - どうなってほしかったか
  - パソコンかスマートフォンの幅か、ブラウザーの名前
  この一式の版: {commit}{dirty_note}


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
6. よくある質問のチャットの記録を返すとき
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  チャットで聞いた質問と、返ってきた答え・押した反応（解決した・解決しない）は、よくある質問をよくするために記録しています。
  試し終わったら、次の手順で記録を開発担当に送ってください。

  1. 「3_記録を書き出す.bat」をダブルクリックする（起動したままでもかまいません）
  2. このフォルダーに「faq_chat_log_（日時）.csv」と「app_log_errors_（日時）.txt」ができる
  3. その 2 つのファイルを、開発担当に送る

  CSV に入るのは、チャットの記録（日時・ログイン ID・役割・質問・返した答え・反応）だけです。
  txt に入るのは、システムのエラー・警告の記録（「通信エラー」などの原因を調べるため）だけです。
  報告の中身・コメント・パスワード・暗号の鍵は入りません。Excel やメモ帳で中身を確かめてから送れます。


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
7. 注意
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  - 検証専用です。本番の報告・客先名・個人名など、本物のデータは入れないでください。
  - この PC の中（127.0.0.1）からだけ開けます。ほかの PC やスマートフォンからは開けません。
  - この一式を、本番のサーバーに上げないでください（2 段階認証を飛ばす設定が入っています）。
  - メールは送りません。
  - 「Failed to listen on 127.0.0.1:8000」と出て止まるときは、ほかのソフトが 8000 番を使っています。
    そのソフトを閉じるか、開発担当に連絡してください。
{php_arch_note}
  - 「PHP を起動できません」と出るときは、Windows の更新が止まっているなどで、PHP が使う部品が足りないおそれがあります。
    出たメッセージを開発担当に送ってください。
  - 使い終わったら、チャットの記録を書き出して送ってから（6.）、フォルダーごと消してかまいません（パソコンに何も残りません）。
  - 同梱の PHP は PHP License のもとで配られているものです（php\\license.txt）。
"""


def run_git(*arguments):
    return subprocess.run(["git"] + list(arguments), cwd=ROOT, check=True, capture_output=True).stdout


def wanted_path(relative_path):
    """一式に入れるファイルか（入れるときは一式の中の場所を返す）"""
    if relative_path in INCLUDED_FILES:
        return INCLUDED_FILES[relative_path]
    if relative_path.startswith(INCLUDED_PREFIXES) and relative_path != "app/config/config.local.php":
        return relative_path
    return None


def collect_files(use_worktree, ref):
    """{一式の中の場所: 中身} を返す"""
    content_by_path = {}
    if use_worktree:
        listed = run_git("ls-files", "-co", "--exclude-standard", "-z").decode("utf-8").split("\0")
        for relative_path in filter(None, listed):
            kit_path = wanted_path(relative_path)
            if kit_path and os.path.isfile(os.path.join(ROOT, relative_path)):
                with open(os.path.join(ROOT, relative_path), "rb") as source_file:
                    content_by_path[kit_path] = source_file.read()
    else:
        archive = tarfile.open(fileobj=io.BytesIO(run_git("archive", "--format=tar", ref)))
        for member in archive.getmembers():
            kit_path = wanted_path(member.name)
            if member.isfile() and kit_path:
                content_by_path[kit_path] = archive.extractfile(member).read()
    return content_by_path


def find_php_directory(given_directory):
    if given_directory:
        return given_directory
    winget_directory = os.path.join(os.environ.get("LOCALAPPDATA", ""), "Microsoft", "WinGet", "Packages")
    if os.path.isdir(winget_directory):
        for name in sorted(os.listdir(winget_directory), reverse=True):
            candidate = os.path.join(winget_directory, name)
            if name.startswith("PHP.PHP.") and os.path.isfile(os.path.join(candidate, "php.exe")):
                return candidate
    php_path = shutil.which("php")
    return os.path.dirname(php_path) if php_path and php_path.lower().endswith(".exe") else ""


# 同梱の PHP が使う Visual C++ ランタイム（vcruntime140_1.dll は 64 ビット用だけにある。無いものは写さない）
VC_RUNTIME_FILE_NAMES = ["vcruntime140.dll", "vcruntime140_1.dll", "msvcp140.dll"]


def find_x86_php_directory():
    """32 ビット版の PHP（php.net の x86 版を展開したもの）を、リポジトリの隣の php-*-x86 から探す"""
    parent_directory = os.path.dirname(REPOSITORY_ROOT)
    for name in sorted(os.listdir(parent_directory), reverse=True):
        candidate = os.path.join(parent_directory, name)
        if name.startswith("php-") and name.endswith("-x86") and os.path.isfile(os.path.join(candidate, "php.exe")):
            return candidate
    return ""


def copy_php(php_directory, kit_directory, is_x86=False):
    """PHP を写し、php.ini の拡張の場所を一式の中（環境変数 MR_PHP_DIR）に向ける"""
    target_directory = os.path.join(kit_directory, "php")
    shutil.copytree(php_directory, target_directory, ignore=lambda _directory, names: [
        name for name in names if name in PHP_EXCLUDED_NAMES or name.endswith(PHP_EXCLUDED_SUFFIXES)])
    ini_path = os.path.join(target_directory, "php.ini")
    if not os.path.isfile(ini_path):
        raise SystemExit("php.ini がありません: " + php_directory)
    ini_lines = open(ini_path, encoding="utf-8", errors="replace").read().splitlines()
    rewritten_lines = []
    for line in ini_lines:
        if line.strip().startswith("extension_dir"):
            line = 'extension_dir = "${MR_PHP_DIR}\\ext"   ; 一式の中の php\\ext（1_起動.bat が MR_PHP_DIR を決める）'
        rewritten_lines.append(line)
    if not any(line.startswith("extension_dir") for line in rewritten_lines):
        rewritten_lines.append('extension_dir = "${MR_PHP_DIR}\\ext"')
    open(ini_path, "w", encoding="utf-8", newline="\r\n").write("\n".join(rewritten_lines) + "\n")
    # Visual C++ ランタイム（同梱の PHP が使う）。入っていない PC でも起動できるよう、PHP と同じフォルダーに置く
    # 64 ビットの Windows では、32 ビット用のランタイムは SysWOW64 にある
    system_directory = os.path.join(os.environ.get("SystemRoot", r"C:\Windows"), "SysWOW64" if is_x86 else "System32")
    for runtime_file_name in VC_RUNTIME_FILE_NAMES:
        runtime_source_path = os.path.join(system_directory, runtime_file_name)
        runtime_target_path = os.path.join(target_directory, runtime_file_name)
        if os.path.isfile(runtime_source_path) and not os.path.isfile(runtime_target_path):
            shutil.copy2(runtime_source_path, runtime_target_path)


def write_text(path, text, encoding="utf-8", newline="\n"):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding=encoding, newline=newline) as output_file:
        output_file.write(text)


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(errors="replace")
    argument_parser = argparse.ArgumentParser(description="ローカル検証環境の一式を作る")
    argument_parser.add_argument("--ref", default="HEAD", help="作るもとのブランチ・コミット（既定 HEAD）")
    argument_parser.add_argument("--worktree", action="store_true", help="作業中の変更（コミット前）も入れる")
    argument_parser.add_argument("--php-dir", default="", help="同梱する PHP のフォルダー（既定: この端末の WinGet の PHP）")
    argument_parser.add_argument("--no-php", action="store_true", help="PHP を同梱しない")
    argument_parser.add_argument("--x86", action="store_true", help="32 ビットの Windows 用を作る（32 ビット版の PHP を同梱。64 ビットの Windows でも動く）")
    arguments = argument_parser.parse_args()

    commit = run_git("rev-parse", "--short", arguments.ref).decode().strip()
    is_dirty = arguments.worktree and run_git("status", "--porcelain").strip() != b""
    stamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    output_directory = os.path.join(HERE, "output", "local_%s_%s%s%s" % (stamp, commit, "_worktree" if is_dirty else "", "_x86" if arguments.x86 else ""))
    kit_directory = os.path.join(output_directory, KIT_NAME)

    content_by_path = collect_files(arguments.worktree, arguments.ref)
    for kit_path in content_by_path:
        if kit_path.endswith(FORBIDDEN_SUFFIXES) or kit_path.startswith(FORBIDDEN_DIRECTORIES):
            raise SystemExit("入れてはいけないファイルが入りそうです: " + kit_path)
    example_config = content_by_path.get("app/config/config.local.example.php")
    if example_config is None:
        raise SystemExit("設定のひな形 app/config/config.local.example.php がありません")
    for required_path in ("app/bootstrap.php", "public/login.php", "tools/test/dev_seed.php", "app/lib/faq_standard.php"):
        if required_path not in content_by_path:
            raise SystemExit("必要なファイルがありません: " + required_path)

    for kit_path, content in content_by_path.items():
        target_path = os.path.join(kit_directory, *kit_path.split("/"))
        os.makedirs(os.path.dirname(target_path), exist_ok=True)
        with open(target_path, "wb") as target_file:
            target_file.write(content)
    # 設定はひな形から作る（この端末の config.local.php は入れない）
    with open(os.path.join(kit_directory, "app", "config", "config.local.php"), "wb") as config_file:
        config_file.write(example_config)
    for runtime_directory in ("data", "keys", "logs", "backups"):
        os.makedirs(os.path.join(kit_directory, "app", runtime_directory), exist_ok=True)

    if not arguments.no_php:
        php_directory = arguments.php_dir or (find_x86_php_directory() if arguments.x86 else find_php_directory(""))
        if not php_directory:
            raise SystemExit("同梱する PHP が見つかりません（--php-dir で指定するか、--no-php を付けてください）"
                             + ("。32 ビット用は php.net の x86 版を、リポジトリの隣の php-<版>-x86 に展開しておく" if arguments.x86 else ""))
        copy_php(php_directory, kit_directory, is_x86=arguments.x86)

    # 起動の道具（.bat は日本語の Windows のコマンドプロンプトで読めるよう Shift_JIS・CRLF）
    # 64 ビット用の一式だけに、32 ビットの Windows なら止める部分を入れる
    start_bat_text = START_BAT.replace("{x64_only_check}", "" if arguments.x86 else X64_ONLY_CHECK)
    write_text(os.path.join(kit_directory, "1_起動.bat"), start_bat_text, encoding="cp932", newline="\r\n")
    write_text(os.path.join(kit_directory, "2_テストデータを作り直す.bat"), RESET_BAT, encoding="cp932", newline="\r\n")
    write_text(os.path.join(kit_directory, "3_記録を書き出す.bat"), EXPORT_BAT, encoding="cp932", newline="\r\n")
    write_text(os.path.join(kit_directory, "export_log.sh"), EXPORT_SH)
    write_text(os.path.join(kit_directory, "start.sh"), START_SH)
    write_text(os.path.join(kit_directory, "reset_data.sh"), RESET_SH)
    faq_count = content_by_path["app/lib/faq_standard.php"].decode("utf-8").count("\n    ['")
    user_table = "\n".join("  %-9s %-14s %s（%s）" % (login_id, display_name, role_text, ability_text)
                           for login_id, display_name, role_text, ability_text in TEST_USERS)
    write_text(os.path.join(kit_directory, "はじめにお読みください.txt"), README.format(
        stamp=datetime.datetime.now().strftime("%Y-%m-%d %H:%M"), ref=arguments.ref, commit=commit,
        dirty_note="（コミット前の変更を含む）" if is_dirty else "", faq_count=faq_count, user_table=user_table,
        php_arch_note=PHP_ARCH_NOTE_X86 if arguments.x86 else PHP_ARCH_NOTE_X64), newline="\r\n")

    zip_path = os.path.join(output_directory, KIT_NAME + ".zip")
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as kit_zip:
        for directory, _subdirectories, file_names in os.walk(kit_directory):
            relative_directory = os.path.relpath(directory, output_directory)
            if not file_names:
                kit_zip.writestr(relative_directory.replace(os.sep, "/") + "/", "")
            for file_name in file_names:
                kit_zip.write(os.path.join(directory, file_name), os.path.join(relative_directory, file_name))
    file_count = sum(len(file_names) for _directory, _subdirectories, file_names in os.walk(kit_directory))
    print("一式を作りました: %s（%d ファイル、zip %.1f MB）" % (output_directory, file_count, os.path.getsize(zip_path) / 1024 / 1024))
    return 0


if __name__ == "__main__":
    sys.exit(main())
