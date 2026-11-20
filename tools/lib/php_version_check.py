#!/usr/bin/env python3
"""PHP のバージョンを調べ、新しい版があるか・ダウンロード（入れ替え）したほうがよいかを出す。取ってくると答えたら適用までする。
Windows・Linux 共通。

    python3 tools/lib/php_version_check.py                       この端末の PHP を調べ、php.net の最新版と比べる
    python3 tools/lib/php_version_check.py --php C:\\path\\php.exe  調べる PHP を指定する
    python3 tools/lib/php_version_check.py --prod-web 8.3.20 --prod-cli 8.3.20
                                                                 本番（さくら）の版（課長から聞いた値）も判定する
    python3 tools/lib/php_version_check.py --download 8.3        8.3 系の最新版を取ってきて、この端末の既定の PHP にする（確かめてから）
    python3 tools/lib/php_version_check.py --download 8.3 --arch x86   Windows: 32 ビット版（ローカル検証環境の一式 --x86 が同梱する PHP）
    python3 tools/lib/php_version_check.py --download 8.6 --no-apply   取ってくるだけ（互換の確認用。既定の PHP は変えない）
    python3 tools/lib/php_version_check.py --restore             Windows: 直前の適用を取り消す

調べること:
  - この端末の PHP の版・32/64 ビット・スレッドセーフ（TS/NTS）・このシステムに要る拡張（pdo_sqlite・sqlite3・mbstring・openssl）
  - Linux・さくらでは、入っているほかの PHP（/usr/bin/php8.x・/usr/local/bin/php8.x など）も並べる
  - php.net の系列ごとの最新版と、サポートの状態（積極的なサポート・セキュリティの直しだけ・終了）
判定:
  - このシステムは PHP 8.3 以上で動く書き方（dev_ono_check8_3）。8.3 より古ければ「要更新」
  - 同じ系列に新しい版があれば「ダウンロードをすすめる」（セキュリティの直しを含むため）
  - 新しい系列（8.4 以降）は「互換の確認に使うなら取ってくるだけ（--no-apply）」。開発の既定の PHP は本番に合わせる
取ってきて適用する（--download。「取ってくる」と答えたときだけ動く。--no-apply なら取ってくるだけ）:
  - Windows: 公式（downloads.php.net/~windows）の zip を SHA-256 で確かめ、<リポジトリの親>/php-<版>-<x64|x86>[-nts]/ に展開し、
    要る拡張を有効にした php.ini を置く（インストーラーは使わない）。適用は、利用者の環境変数だけを変える:
      64 ビット: PATH の先頭にそのフォルダーを足し（前に適用したフォルダーは外す。winget の PHP は残る）、PHP_BIN をその php.exe にする
                 （自動検証・ローカル検証環境の一式は PHP_BIN を先に見る）
      32 ビット: PHP_BIN_X86 をその php.exe にする（ローカル検証環境の一式 --x86 が同梱する）
    変える前の値は <リポジトリの親>/php_download/php_apply_backup.json に残し、--restore で戻せる。
    変えた環境変数は、新しく開いた端末（ターミナル）から効く
  - Linux（Ubuntu）: apt で php<系列>-cli・-sqlite3・-mbstring を入れ（標準の apt に無ければ ondrej/php の PPA を足す）、
    update-alternatives で既定の php をその版にする。sudo のパスワードを聞かれる。戻すときは sudo update-alternatives --config php
  - さくら（apt が無い）: 自分では入れられないので、方法を出すだけ
ネットワークに出るのは php.net・downloads.php.net（Linux は apt の取得先）だけ。送るのはバージョンの問い合わせだけ。
Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import glob
import hashlib
import json
import os
import platform
import re
import shutil
import subprocess
import sys
import urllib.request
import zipfile

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
# 新しい PHP を展開する場所（リポジトリの外。既存の C:\ReportSystem\php-8.3.36-x86\ と同じ並び）
DEFAULT_DOWNLOAD_ROOT = os.path.dirname(REPOSITORY_ROOT)

REQUIRED_MINIMUM_BRANCH = (8, 3)
REQUIRED_EXTENSION_LIST = ["pdo_sqlite", "sqlite3", "mbstring", "openssl"]

PHP_STATES_URL = "https://www.php.net/releases/states.php?json"
PHP_BRANCH_RELEASE_URL = "https://www.php.net/releases/index.php?json&version=%s"
WINDOWS_RELEASES_URL = "https://downloads.php.net/~windows/releases/releases.json"
WINDOWS_DOWNLOAD_BASE_URL = "https://downloads.php.net/~windows/releases/"
HTTP_USER_AGENT = "php-version-check/1.0"
HTTP_TIMEOUT_SECONDS = 30
APPLY_BACKUP_FILE_NAME = "php_apply_backup.json"

# さくらのレンタルサーバーで選べる PHP（系列 => 提供の版）。さくらのお知らせ n-2700（2026-11-09 更新。PHP2Shell の対応後）による。
# 8.4 以降は選べない。さくらが新しい系列を出したら、ここを直す
SAKURA_PROVIDED_VERSION_BY_BRANCH = {"8.3": "8.3.36", "8.2": "8.2.35", "8.1": "8.1.34", "8.0": "8.0.30", "7.4": "7.4.33"}
SAKURA_NOTICE_URL = "https://help.sakura.ad.jp/notification/n-2700/"

STATE_LABELS = {"stable": "積極的なサポート中", "security": "セキュリティの直しだけ", "eol": "サポート終了"}

# 版と状態を PHP に聞く（1 行で返す）
PHP_INFO_CODE = ('echo PHP_VERSION, "|", PHP_INT_SIZE * 8, "|", (ZEND_THREAD_SAFE ? "ts" : "nts"), "|", PHP_OS_FAMILY, "|",'
                 ' implode(",", array_map("strtolower", get_loaded_extensions())), "|", (string) php_ini_loaded_file();')


def say(text=""):
    print(text, flush=True)


def version_tuple(version_text):
    """'8.3.36' → (8, 3, 36)。RC などの後ろの文字は捨てる"""
    number_list = [int(part) for part in re.findall(r"\d+", version_text)[:3]]
    return tuple(number_list + [0] * (3 - len(number_list)))


def branch_text_of(version_text):
    major_number, minor_number, _ = version_tuple(version_text)
    return "%d.%d" % (major_number, minor_number)


def is_windows():
    return platform.system() == "Windows"


def fetch_json(url):
    request = urllib.request.Request(url, headers={"User-Agent": HTTP_USER_AGENT})
    with urllib.request.urlopen(request, timeout=HTTP_TIMEOUT_SECONDS) as response:
        return json.loads(response.read().decode("utf-8"))


def ask_yes(question_text, assume_yes):
    if assume_yes:
        return True
    answer_text = input("%s [y/N]: " % question_text).strip().lower()
    if answer_text in ("y", "yes"):
        return True
    say("やめました。")
    return False


# ---- この端末の PHP ----

def candidate_php_path_list():
    """この端末で見つかる PHP（重なりは除く）。PATH の php、Windows は winget とリポジトリの親の php-*、Linux・さくらは php8.x など"""
    found_path_list = []
    path_on_search_path = shutil.which("php")
    if path_on_search_path:
        found_path_list.append(path_on_search_path)
    if is_windows():
        local_app_data_dir = os.environ.get("LOCALAPPDATA", "")
        if local_app_data_dir:
            found_path_list += glob.glob(os.path.join(local_app_data_dir, "Microsoft", "WinGet", "Packages", "PHP.PHP.*", "php.exe"))
        found_path_list += sorted(glob.glob(os.path.join(DEFAULT_DOWNLOAD_ROOT, "php-*", "php.exe")))
    else:
        for bin_dir in ("/usr/bin", "/usr/local/bin", "/opt/php/bin"):
            found_path_list += [path for path in sorted(glob.glob(os.path.join(bin_dir, "php*")))
                                if re.fullmatch(r"php(\d+(\.\d+)?)?", os.path.basename(path))]
        found_path_list += sorted(glob.glob("/opt/php/*/bin/php"))
    unique_path_list = []
    seen_real_path_set = set()
    for php_path in found_path_list:
        real_path = os.path.realpath(php_path)
        if os.path.isfile(real_path) and real_path not in seen_real_path_set:
            seen_real_path_set.add(real_path)
            unique_path_list.append(php_path)
    return unique_path_list


def read_php_info(php_path):
    """PHP に版・ビット数・TS/NTS・OS・拡張・php.ini を聞く。動かなければ None"""
    # ローカル検証環境の一式の php.ini は、拡張の場所を MR_PHP_DIR で指す（無ければ、その PHP のフォルダーにする）
    child_environment = dict(os.environ)
    child_environment.setdefault("MR_PHP_DIR", os.path.dirname(os.path.abspath(php_path)))
    try:
        completed = subprocess.run([php_path, "-r", PHP_INFO_CODE], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30,
                                   env=child_environment)
    except (OSError, subprocess.TimeoutExpired):
        return None
    info_text = completed.stdout.decode("utf-8", "replace").strip().splitlines()[-1:] or [""]
    info_part_list = info_text[0].split("|")
    if completed.returncode != 0 or len(info_part_list) != 6:
        return None
    return {
        "path": php_path,
        "version": info_part_list[0],
        "bits": int(info_part_list[1]),
        "thread_safety": info_part_list[2],
        "os_family": info_part_list[3],
        "extension_set": set(filter(None, info_part_list[4].split(","))),
        "ini_path": info_part_list[5],
    }


def missing_extension_list(php_info):
    return [extension_name for extension_name in REQUIRED_EXTENSION_LIST if extension_name not in php_info["extension_set"]]


def report_installed_php(php_path):
    """入れた・展開した PHP が動くか、要る拡張があるかを出す"""
    php_info = read_php_info(php_path)
    if php_info is None:
        say("NG  PHP が動かない: %s（Windows では Visual C++ の再頒布可能パッケージが要ることがある）" % php_path)
        return False
    missing_list = missing_extension_list(php_info)
    say("%s  PHP %s が動く（%s）。要る拡張: %s" % ("OK" if not missing_list else "NG", php_info["version"], php_path,
                                             "すべてある" if not missing_list else "足りない " + "、".join(missing_list)))
    say('  互換の確認: python3 tools/test/php_compat_check.py --php "%s" --verify' % php_path)
    return not missing_list


# ---- php.net ----

def fetch_branch_status():
    """系列（'8.3' など）=> {state, latest_version, security_support_end}。8 系だけ"""
    states_by_major = fetch_json(PHP_STATES_URL)
    status_by_branch = {}
    for branch_text, state_row in states_by_major.get("8", {}).items():
        status_by_branch[branch_text] = {"state": state_row.get("state", ""),
                                         "security_support_end": (state_row.get("security_support_end") or "")[:10],
                                         "latest_version": ""}
    for branch_text in status_by_branch:
        release_row = fetch_json(PHP_BRANCH_RELEASE_URL % branch_text)
        status_by_branch[branch_text]["latest_version"] = release_row.get("version", "")
    return status_by_branch


# ---- 判定 ----

def judge_version(label_text, version_text, status_by_branch, is_production=False):
    """1 つの PHP の版を判定して、行の並びを返す。本番（さくら）は自分で入れ替えられないので、言い方を変える"""
    line_list = []
    branch_text = branch_text_of(version_text)
    branch_status = status_by_branch.get(branch_text)
    if version_tuple(version_text)[:2] < REQUIRED_MINIMUM_BRANCH:
        line_list.append("  要更新: %s は %s。このシステムは PHP %d.%d 以上が要る" % (label_text, version_text, *REQUIRED_MINIMUM_BRANCH))
        if is_production:
            line_list.append("    → 画面はコントロールパネルの「言語のバージョン設定」で %d.%d 以上を選ぶ。"
                             "コマンド（migrate.php・cron）は /usr/local/bin/php%d.%d のように版の付いた php で動かす" % (REQUIRED_MINIMUM_BRANCH * 2))
        else:
            line_list.append("    → python3 tools/lib/php_version_check.py --download %d.%d（取ってきて適用する）" % REQUIRED_MINIMUM_BRANCH)
    if branch_status is None:
        if version_tuple(version_text)[0] < 8:
            line_list.append("  要更新: %s 系はサポート終了（7 系以前。セキュリティの直しも出ない）" % branch_text)
        else:
            line_list.append("  注意: %s 系は php.net の一覧に無い（まだ出ていない版）" % branch_text)
        return line_list
    if branch_status["state"] == "eol":
        line_list.append("  要更新: %s 系はサポート終了（セキュリティの直しも出ない）" % branch_text)
    if branch_status["latest_version"] and version_tuple(branch_status["latest_version"]) > version_tuple(version_text):
        if is_production:
            sakura_version_text = SAKURA_PROVIDED_VERSION_BY_BRANCH.get(branch_text, "")
            if sakura_version_text and version_tuple(version_text) >= version_tuple(sakura_version_text):
                line_list.append("  参考: %s はさくらが提供している %s 系の版（php.net の最新は %s。さくらの側の更新で上がる）"
                                 % (version_text, branch_text, branch_status["latest_version"]))
            else:
                line_list.append("  参考: %s 系の新しい版 %s が出ている（今 %s）。さくらの側の更新で上がる（自分では入れ替えられない）"
                                 % (branch_text, branch_status["latest_version"], version_text))
        elif version_tuple(version_text) == version_tuple(SAKURA_PROVIDED_VERSION_BY_BRANCH.get(branch_text, "0")):
            line_list.append("  問題なし: 本番（さくら）が提供している版 %s と同じ。php.net の最新は %s"
                             "（上げるなら --download %s。上げると本番と版がずれる）" % (version_text, branch_status["latest_version"], branch_text))
        else:
            line_list.append("  ダウンロードをすすめる: %s 系の新しい版 %s が出ている（今 %s。セキュリティの直しを含む）"
                             % (branch_text, branch_status["latest_version"], version_text))
            line_list.append("    → python3 tools/lib/php_version_check.py --download %s（取ってきて適用する）" % branch_text)
    if not line_list:
        line_list.append("  問題なし: %s 系の最新版" % branch_text if not is_production else "  問題なし: %s 系（このシステムが動く版）" % branch_text)
    return line_list


def newer_branch_line(php_info_list, status_by_branch):
    """この端末の PHP より新しい系列（サポート中）を 1 行で。無ければ空"""
    if not php_info_list:
        return ""
    newest_local_branch = max((branch_text_of(php_info["version"]) for php_info in php_info_list), key=version_tuple)
    newer_branch_list = sorted((other_branch for other_branch in status_by_branch
                                if version_tuple(other_branch) > version_tuple(newest_local_branch) and status_by_branch[other_branch]["state"] != "eol"),
                               key=version_tuple)
    if not newer_branch_list:
        return ""
    sakura_newest_branch = max(SAKURA_PROVIDED_VERSION_BY_BRANCH, key=version_tuple)
    return (" 新しい系列: %s。互換の確認（tools/test/php_compat_check.py）に使うなら、取ってくるだけにする（--download 系列 --no-apply）。"
            "開発の既定の PHP は本番に合わせたままにする（本番のさくらで選べるのは %s（%s）まで）"
            % ("、".join("%s（%s）" % (other_branch, status_by_branch[other_branch]["latest_version"]) for other_branch in newer_branch_list),
               sakura_newest_branch, SAKURA_PROVIDED_VERSION_BY_BRANCH[sakura_newest_branch]))


def print_sakura_table():
    say("さくらで選べる PHP（%s）: %s" % (SAKURA_NOTICE_URL, "、".join(
        "%s（%s）" % (branch_text, SAKURA_PROVIDED_VERSION_BY_BRANCH[branch_text])
        for branch_text in sorted(SAKURA_PROVIDED_VERSION_BY_BRANCH, key=version_tuple, reverse=True))))
    say("  ほかに 5.6・5.4・5.3・5.2 も選べる。8.4 以降は選べない")


def print_branch_table(status_by_branch):
    say("php.net の系列（8 系）:")
    for branch_text in sorted(status_by_branch, key=version_tuple, reverse=True):
        branch_status = status_by_branch[branch_text]
        if branch_status["state"] == "eol" and version_tuple(branch_text) < (8, 1, 0):
            continue
        say("  %-4s 最新 %-8s %s（セキュリティの直しは %s まで）%s" % (
            branch_text, branch_status["latest_version"], STATE_LABELS.get(branch_status["state"], branch_status["state"]),
            branch_status["security_support_end"] or "?",
            "  ← このシステムの最低" if version_tuple(branch_text)[:2] == REQUIRED_MINIMUM_BRANCH else ""))


# ---- Linux: apt で入れて、既定の php にする ----

def apt_has_candidate(package_name):
    """apt にそのパッケージの入れられる版があるか"""
    completed = subprocess.run(["apt-cache", "policy", package_name], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    candidate_match = re.search(r"Candidate:\s*(\S+)", completed.stdout.decode("utf-8", "replace"))
    return candidate_match is not None and candidate_match.group(1) != "(none)"


def run_step(command_list):
    say("  実行: %s" % " ".join(command_list))
    return subprocess.run(command_list).returncode == 0


def install_linux_php(branch_text, should_apply, assume_yes):
    if shutil.which("apt-get") is None:
        say("この端末には apt が無い（さくらのレンタルサーバーなど）。自分では入れられない。")
        say("  さくら: コントロールパネルの「言語のバージョン設定」で %s を選び、コマンドでは /usr/local/bin/php%s のように版の付いた php を使う。"
            % (branch_text, branch_text))
        return False
    package_list = ["php%s-cli" % branch_text, "php%s-sqlite3" % branch_text, "php%s-mbstring" % branch_text]
    needs_ppa = not apt_has_candidate(package_list[0])
    php_path = "/usr/bin/php%s" % branch_text
    say("入れるもの: %s%s" % (" ".join(package_list), "（標準の apt に無いので ondrej/php の PPA を足す）" if needs_ppa else ""))
    if should_apply:
        say("適用      : update-alternatives で既定の php を %s にする（もとの版は消さない。戻すときは sudo update-alternatives --config php）" % php_path)
    if not ask_yes("取ってきて%sしますか（sudo のパスワードを聞かれます）" % ("適用" if should_apply else "入れるだけに"), assume_yes):
        return False
    if needs_ppa:
        if not (run_step(["sudo", "apt-get", "install", "-y", "software-properties-common"])
                and run_step(["sudo", "add-apt-repository", "-y", "ppa:ondrej/php"])):
            say("NG  PPA を足せなかった")
            return False
    if not (run_step(["sudo", "apt-get", "update"]) and run_step(["sudo", "apt-get", "install", "-y"] + package_list)):
        say("NG  入れられなかった")
        return False
    if should_apply and not run_step(["sudo", "update-alternatives", "--set", "php", php_path]):
        say("NG  既定の php を切り替えられなかった")
        return False
    return report_installed_php(php_path)


# ---- Windows: 取ってきて展開する ----

def windows_release_key(release_row, thread_safety, architecture_name):
    """releases.json の 'ts-vs17-x64' のような見出しを探す"""
    for key_text in release_row:
        key_part_list = key_text.split("-")
        if len(key_part_list) == 3 and key_part_list[0] == thread_safety and key_part_list[2] == architecture_name:
            return key_text
    return None


def safe_extract_zip(zip_path, target_dir):
    """zip の中の名前がフォルダーの外を指していないか確かめてから展開する"""
    target_real_dir = os.path.realpath(target_dir)
    with zipfile.ZipFile(zip_path) as zip_archive:
        for member_name in zip_archive.namelist():
            member_real_path = os.path.realpath(os.path.join(target_real_dir, member_name))
            if member_real_path != target_real_dir and not member_real_path.startswith(target_real_dir + os.sep):
                raise RuntimeError("zip の中にフォルダーの外を指す名前がある: %s" % member_name)
        zip_archive.extractall(target_real_dir)


def write_php_ini(php_dir):
    """php.ini-development を写し、拡張の場所と、このシステムに要る拡張を有効にした php.ini を置く"""
    template_path = os.path.join(php_dir, "php.ini-development")
    with open(template_path, encoding="utf-8", errors="replace") as template_file:
        ini_text = template_file.read()
    extension_dir_text = os.path.join(php_dir, "ext")
    ini_text, replaced_count = re.subn(r'(?m)^;\s*extension_dir\s*=\s*"ext"\s*$', lambda _match: 'extension_dir = "%s"' % extension_dir_text, ini_text)
    if replaced_count == 0:
        ini_text += '\nextension_dir = "%s"\n' % extension_dir_text
    for extension_name in REQUIRED_EXTENSION_LIST:
        ini_text, replaced_count = re.subn(r"(?m)^;\s*extension\s*=\s*%s\s*$" % re.escape(extension_name), "extension=%s" % extension_name, ini_text)
        if replaced_count == 0:
            ini_text += "\nextension=%s\n" % extension_name
    with open(os.path.join(php_dir, "php.ini"), "w", encoding="utf-8") as ini_file:
        ini_file.write(ini_text)


def download_windows_php(branch_text, architecture_name, thread_safety, download_root, should_apply, assume_yes):
    releases_by_branch = fetch_json(WINDOWS_RELEASES_URL)
    release_row = releases_by_branch.get(branch_text)
    if release_row is None:
        say("NG  %s 系は Windows 用の配布の一覧に無い（ある系列: %s）" % (branch_text, "、".join(sorted(releases_by_branch, key=version_tuple))))
        return False
    release_key = windows_release_key(release_row, thread_safety, architecture_name)
    if release_key is None or "zip" not in release_row[release_key]:
        say("NG  %s 系の %s・%s の zip が一覧に無い" % (branch_text, thread_safety.upper(), architecture_name))
        return False
    version_text = release_row["version"]
    zip_row = release_row[release_key]["zip"]
    php_dir = os.path.join(download_root, "php-%s-%s%s" % (version_text, architecture_name, "" if thread_safety == "ts" else "-nts"))
    php_exe_path = os.path.join(php_dir, "php.exe")
    already_extracted = os.path.isfile(php_exe_path)
    if already_extracted:
        say("取ってくる必要なし: %s はもうある（%s）" % (version_text, php_dir))
        if not should_apply:
            return True
    else:
        say("取ってくるもの: %s（%s・%s・%s）" % (zip_row["path"], version_text, thread_safety.upper(), architecture_name))
        say("  取ってくる元: %s%s" % (WINDOWS_DOWNLOAD_BASE_URL, zip_row["path"]))
        say("  展開する先 : %s（インストーラーは使わない）" % php_dir)
    if should_apply:
        say("  適用      : %s" % describe_windows_apply(architecture_name, php_dir))
    question_text = "適用しますか" if already_extracted else "ダウンロードして%sしますか" % ("適用" if should_apply else "展開")
    if not ask_yes(question_text, assume_yes):
        return False
    if not already_extracted:
        zip_store_dir = os.path.join(download_root, "php_download")
        os.makedirs(zip_store_dir, exist_ok=True)
        zip_path = os.path.join(zip_store_dir, zip_row["path"])
        request = urllib.request.Request(WINDOWS_DOWNLOAD_BASE_URL + zip_row["path"], headers={"User-Agent": HTTP_USER_AGENT})
        with urllib.request.urlopen(request, timeout=HTTP_TIMEOUT_SECONDS) as response, open(zip_path, "wb") as zip_file:
            shutil.copyfileobj(response, zip_file)
        sha256_hasher = hashlib.sha256()
        with open(zip_path, "rb") as zip_file:
            for data_block in iter(lambda: zip_file.read(1024 * 1024), b""):
                sha256_hasher.update(data_block)
        if sha256_hasher.hexdigest().lower() != zip_row["sha256"].lower():
            os.remove(zip_path)
            say("NG  SHA-256 が一覧と合わない（壊れたか、すり替えられた）。消した: %s" % zip_path)
            return False
        say("OK  SHA-256 が一覧と一致")
        os.makedirs(php_dir)
        safe_extract_zip(zip_path, php_dir)
        write_php_ini(php_dir)
    if not report_installed_php(php_exe_path):
        say("  動かないか拡張が足りないので、適用はしない")
        return False
    return apply_windows_php(architecture_name, php_dir, download_root) if should_apply else True


# ---- Windows: 適用（利用者の環境変数だけを変える。もとの値は記録して --restore で戻す） ----

def describe_windows_apply(architecture_name, php_dir):
    if architecture_name == "x86":
        return "利用者の環境変数 PHP_BIN_X86 を %s にする（ローカル検証環境の一式 --x86 が同梱する）" % os.path.join(php_dir, "php.exe")
    return "利用者の PATH の先頭に %s を足し、PHP_BIN をその php.exe にする（この端末の既定の PHP になる）" % php_dir


def read_user_environment(variable_name):
    """利用者の環境変数（レジストリ HKCU\\Environment）の値と型。無ければ (None, None)"""
    import winreg
    try:
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, "Environment") as environment_key:
            stored_value, stored_type = winreg.QueryValueEx(environment_key, variable_name)
            return stored_value, stored_type
    except FileNotFoundError:
        return None, None


def write_user_environment(variable_name, new_value, value_type=None):
    """利用者の環境変数を書く（None は消す）。書いたあと、ほかのアプリに変わったことを知らせる"""
    import ctypes
    import winreg
    with winreg.OpenKey(winreg.HKEY_CURRENT_USER, "Environment", 0, winreg.KEY_SET_VALUE) as environment_key:
        if new_value is None:
            try:
                winreg.DeleteValue(environment_key, variable_name)
            except FileNotFoundError:
                pass
        else:
            winreg.SetValueEx(environment_key, variable_name, 0, value_type or winreg.REG_SZ, new_value)
    broadcast_result = ctypes.c_ulong()
    hwnd_broadcast, wm_settingchange, smto_abortifhung = 0xFFFF, 0x001A, 0x0002
    ctypes.windll.user32.SendMessageTimeoutW(hwnd_broadcast, wm_settingchange, 0, "Environment", smto_abortifhung, 5000,
                                             ctypes.byref(broadcast_result))


def path_with_php_first(old_path_text, php_dir, download_root):
    """PATH の先頭に php_dir を足す。前に適用した <download_root> の php-*-x64 は外す（winget など、ほかの PHP は残す）"""
    download_root_normalized = os.path.normcase(os.path.abspath(download_root))
    php_dir_normalized = os.path.normcase(os.path.abspath(php_dir))
    kept_entry_list = []
    for path_entry in (old_path_text or "").split(";"):
        if not path_entry.strip():
            continue
        entry_normalized = os.path.normcase(os.path.abspath(os.path.expandvars(path_entry.strip())))
        is_applied_php_dir = (os.path.dirname(entry_normalized) == download_root_normalized
                              and re.fullmatch(r"php-[\d.]+-x64(-nts)?", os.path.basename(entry_normalized)) is not None)
        if is_applied_php_dir or entry_normalized == php_dir_normalized:
            continue
        kept_entry_list.append(path_entry)
    return ";".join([php_dir] + kept_entry_list)


def apply_windows_php(architecture_name, php_dir, download_root):
    import winreg
    backup_dir = os.path.join(download_root, "php_download")
    os.makedirs(backup_dir, exist_ok=True)
    backup_path = os.path.join(backup_dir, APPLY_BACKUP_FILE_NAME)
    variable_name_list = ["PHP_BIN_X86"] if architecture_name == "x86" else ["Path", "PHP_BIN"]
    backup_row = {"applied_dir": php_dir, "values": {}}
    for variable_name in variable_name_list:
        stored_value, stored_type = read_user_environment(variable_name)
        backup_row["values"][variable_name] = {"value": stored_value, "type": stored_type}
    with open(backup_path, "w", encoding="utf-8") as backup_file:
        json.dump(backup_row, backup_file, ensure_ascii=False, indent=1)
    php_exe_path = os.path.join(php_dir, "php.exe")
    if architecture_name == "x86":
        write_user_environment("PHP_BIN_X86", php_exe_path)
    else:
        old_path_value, old_path_type = read_user_environment("Path")
        write_user_environment("Path", path_with_php_first(old_path_value, php_dir, download_root), old_path_type or winreg.REG_EXPAND_SZ)
        write_user_environment("PHP_BIN", php_exe_path)
    say("OK  適用した: %s" % describe_windows_apply(architecture_name, php_dir))
    say("  もとの値: %s（python tools/lib/php_version_check.py --restore で戻す）" % backup_path)
    say("  新しく開いた端末から効く。今の端末では、開き直すまで前の PHP のまま")
    return True


def restore_windows_php(download_root):
    backup_path = os.path.join(download_root, "php_download", APPLY_BACKUP_FILE_NAME)
    if not os.path.isfile(backup_path):
        say("戻すものが無い（%s が無い）" % backup_path)
        return False
    with open(backup_path, encoding="utf-8") as backup_file:
        backup_row = json.load(backup_file)
    for variable_name, stored_row in backup_row["values"].items():
        write_user_environment(variable_name, stored_row["value"], stored_row["type"])
        say("  戻した: %s" % variable_name)
    os.replace(backup_path, backup_path + ".restored")
    say("OK  適用を取り消した（%s を当てる前の値）。新しく開いた端末から効く" % backup_row["applied_dir"])
    return True


# ---- 本体 ----

def main():
    parser = argparse.ArgumentParser(description="PHP のバージョンを調べ、新しい版があるか・ダウンロードしたほうがよいかを出す。取ってくると答えたら適用まで（Windows・Linux）")
    parser.add_argument("--php", help="調べる PHP（省くと、この端末で見つかる PHP をすべて調べる）")
    parser.add_argument("--prod-web", help="本番の画面で動く PHP の版（コントロールパネルの「言語のバージョン設定」）")
    parser.add_argument("--prod-cli", help="本番のコマンドで動く PHP の版（サーバーで php -v）")
    parser.add_argument("--download", metavar="系列", help="この系列（8.3・8.6 など）の最新版を取ってきて適用する（Windows: zip を展開。Linux: apt）")
    parser.add_argument("--no-apply", action="store_true", help="取ってくるだけで、既定の PHP にしない（互換の確認用）")
    parser.add_argument("--arch", choices=["x64", "x86"], help="Windows: 取ってくる PHP のビット数（省くと調べた PHP に合わせる。無ければ x64）")
    parser.add_argument("--nts", action="store_true", help="Windows: スレッドセーフでない版（NTS）を取ってくる（省くと調べた PHP に合わせる）")
    parser.add_argument("--dest", default=DEFAULT_DOWNLOAD_ROOT, help="Windows: 展開する場所（既定: リポジトリの親のフォルダー）")
    parser.add_argument("--restore", action="store_true", help="Windows: 直前の適用を取り消す（環境変数をもとに戻す）")
    parser.add_argument("--yes", action="store_true", help="取ってくる・適用するの確かめを聞かない")
    args = parser.parse_args()

    if args.restore:
        if not is_windows():
            say("--restore は Windows 用。Linux は sudo update-alternatives --config php で選び直す")
            return 2
        return 0 if restore_windows_php(os.path.abspath(args.dest)) else 1

    php_path_list = [args.php] if args.php else candidate_php_path_list()
    php_info_list = []
    say("この端末（%s）の PHP:" % platform.system())
    if not php_path_list:
        say("  見つからない（PATH に php が無い）。--php で場所を指定する")
    path_php = shutil.which("php")
    for php_path in php_path_list:
        php_info = read_php_info(php_path)
        if php_info is None:
            say("  %s: 動かない" % php_path)
            continue
        php_info_list.append(php_info)
        missing_list = missing_extension_list(php_info)
        say("  %s %s（%d ビット・%s）%s" % (php_info["version"], php_info["path"], php_info["bits"], php_info["thread_safety"].upper(),
                                         " ← PATH の php" if php_path == path_php else ""))
        say("    要る拡張: %s / php.ini: %s" % ("すべてある" if not missing_list else "足りない " + "、".join(missing_list),
                                             php_info["ini_path"] or "（読んでいない）"))
    say()

    try:
        status_by_branch = fetch_branch_status()
    except Exception as error:  # ネットワークにつながらない・php.net の形が変わったなど
        say("php.net に問い合わせられない（%s）。新しい版があるかは判定できない。" % error)
        return 1
    print_branch_table(status_by_branch)
    print_sakura_table()
    say()

    say("判定:")
    for php_info in php_info_list:
        say(" この端末の %s（%s）" % (php_info["version"], php_info["path"]))
        for line_text in judge_version("この端末の PHP", php_info["version"], status_by_branch):
            say(line_text)
    for label_text, version_text in (("本番の画面の PHP", args.prod_web), ("本番のコマンドの PHP", args.prod_cli)):
        if version_text:
            say(" %s %s" % (label_text, version_text))
            for line_text in judge_version(label_text, version_text, status_by_branch, is_production=True):
                say(line_text)
    newer_line_text = newer_branch_line(php_info_list, status_by_branch)
    if newer_line_text:
        say(newer_line_text)
    if not (args.prod_web and args.prod_cli):
        say(" 本番（さくら）: 外からは版が分からない。課長に次の 2 つを聞いて --prod-web・--prod-cli に入れる")
        say("   1. コントロールパネルの「スクリプト設定」→「言語のバージョン設定」（画面で動く PHP）")
        say("   2. サーバーで php -v（migrate.php・backup.php・cron で動く PHP）。さくらでこの道具を python3 で動かしてもよい")
    say()

    if not args.download:
        return 0
    if not re.fullmatch(r"\d+\.\d+", args.download):
        say("NG  --download は系列（8.3・8.6 など）で指定する")
        return 2
    if not is_windows():
        return 0 if install_linux_php(args.download, not args.no_apply, args.yes) else 1
    reference_info = php_info_list[0] if php_info_list else None
    architecture_name = args.arch or ("x86" if reference_info and reference_info["bits"] == 32 else "x64")
    thread_safety = "nts" if args.nts or (reference_info and reference_info["thread_safety"] == "nts" and not args.arch) else "ts"
    return 0 if download_windows_php(args.download, architecture_name, thread_safety, os.path.abspath(args.dest),
                                     not args.no_apply, args.yes) else 1


if __name__ == "__main__":
    sys.exit(main())
