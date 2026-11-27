#!/usr/bin/env python3
"""verify_view: 自動検証（tools/test/verify_all.py）の経過と結果を見る道具。

    python3 tools/lib/verify_view.py                       # このリポジトリの最新の検証（流している途中なら経過）
    python3 tools/lib/verify_view.py --watch               # 5 秒ごとに表示し直す（Ctrl + C で終わる。--watch 10 で 10 秒ごと）
    python3 tools/lib/verify_view.py --list                # 過去の検証の一覧（新しい順に 20 回。--list 50 で 50 回）
    python3 tools/lib/verify_view.py --run 20261127_121321 # 指定した回
    python3 tools/lib/verify_view.py --root ../dev8_3 --root .   # いくつかのリポジトリ（作業ツリー）をまとめて見る
    python3 tools/lib/verify_view.py --ng                  # NG の行をすべて出す（既定は 10 行まで）

- 流している途中の検証は、tools/test/output/検証記録/<日時>/経過.json（verify_all.py が工程の始まりと終わりに書く）から、
  今の工程・終わった工程・OK と NG の数を出す。前に同じ流し方（画面の自動操作の有無・--only・--skip）で最後まで流れた回があれば、
  そこから全体の工程の数と残りの時間の見込みを出す。
- 未検証の分岐の数え直し（tools/test/branch_coverage.py）が流している写しの検証（一時フォルダーの mr_coverage_*）も、流している間は出す。
- 経過.json が無い前の回は、結果.md と工程のログから出す（途中の経過は分からない）。
- 見るだけで、検証の記録・DB には触れない。

Python 3.8 以上の標準ライブラリだけで動く。Linux・Windows・macOS で同じように使える。
"""
import argparse
import datetime
import glob
import json
import os
import re
import sys
import tempfile
import time

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_ROOT = os.path.dirname(os.path.dirname(HERE))
RECORD_PARTS = ("tools", "test", "output", "検証記録")
NG_LINES_SHOWN = 10


def record_root(repository_root):
    return os.path.join(repository_root, *RECORD_PARTS)


def is_process_alive(process_id):
    """そのプロセスが動いているか（Windows でもプロセスを止めない調べ方をする）"""
    if not process_id:
        return False
    if os.name == "nt":
        import ctypes   # Windows だけで使う
        process_query_limited_information, still_active = 0x1000, 259
        kernel32 = ctypes.windll.kernel32
        process_handle = kernel32.OpenProcess(process_query_limited_information, False, int(process_id))
        if not process_handle:
            return False
        try:
            exit_code = ctypes.c_ulong()
            return bool(kernel32.GetExitCodeProcess(process_handle, ctypes.byref(exit_code))) and exit_code.value == still_active
        finally:
            kernel32.CloseHandle(process_handle)
    try:
        os.kill(int(process_id), 0)   # Linux・macOS: 0 は調べるだけ（止めない）
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


def read_text(path):
    try:
        with open(path, encoding="utf-8", errors="replace") as text_file:
            return text_file.read()
    except OSError:
        return ""


def parse_result_markdown(result_text):
    """結果.md から、判定・数・流し方・工程の表を読む（経過.json の無い前の回のため）"""
    def table_value(label):
        match = re.search(r"^\| %s \| (.*?) \|$" % re.escape(label), result_text, re.M)
        return match.group(1) if match else ""
    verdict_text = table_value("判定")
    count_match = re.search(r"OK (\d+) / NG (\d+)", table_value("確認の数"))
    scope_text = table_value("流した工程")
    scope_match = re.search(r"--skip (.*?) / --only (.*?)）", scope_text)
    step_list = []
    for step_match in re.finditer(r"^\| (.+?) \| (成功|失敗) \| (\d+) \| (\d+) \| ([\d.]+) \| .*\|$", result_text, re.M):
        step_list.append({"name": step_match.group(1), "ok": step_match.group(2) == "成功", "passed": int(step_match.group(3)),
                          "failed": int(step_match.group(4)), "seconds": float(step_match.group(5)), "ng": [], "not_done": []})
    ng_list = [line[2:] for line in re.findall(r"^- NG  .*$", result_text, re.M)]
    not_done_list = re.findall(r"^- (.+?: .+)$", result_text.split("## 未実施", 1)[1], re.M) if "## 未実施" in result_text else []
    return {
        "verdict": "中断" if verdict_text.startswith("中断") else verdict_text,
        "passed": int(count_match.group(1)) if count_match else None,
        "failed": int(count_match.group(2)) if count_match else None,
        "options": {"ui": table_value("画面の自動操作") == "実施",
                    "only": "" if not scope_match or scope_match.group(2) == "なし" else scope_match.group(2),
                    "skip": "" if not scope_match or scope_match.group(1) == "なし" else scope_match.group(1)},
        "steps": step_list, "ng": ng_list, "not_done": not_done_list,
    }


def load_run(run_directory, label):
    """1 回の検証の状態をまとめる"""
    progress_text = read_text(os.path.join(run_directory, "経過.json"))
    progress = None
    if progress_text:
        try:
            progress = json.loads(progress_text)
        except ValueError:
            progress = None   # 書いている途中に読んだ（次の表示で読み直す）
    result = parse_result_markdown(read_text(os.path.join(run_directory, "結果.md")))
    run = {"label": label, "directory": run_directory, "stamp": os.path.basename(run_directory), "current": None,
           "options": result["options"], "steps": result["steps"], "verdict": result["verdict"],
           "passed": result["passed"], "failed": result["failed"], "ng": result["ng"], "not_done": result["not_done"],
           "started_at": None, "has_progress": progress is not None, "reason": ""}
    try:
        run["started_at"] = datetime.datetime.strptime(run["stamp"], "%Y%m%d_%H%M%S").timestamp()
    except ValueError:
        run["started_at"] = os.path.getmtime(run_directory)
    if progress is not None:
        run["options"] = progress.get("options") or run["options"]
        run["steps"] = progress.get("done") or []
        run["current"] = progress.get("current")
        run["started_at"] = progress.get("started_at") or run["started_at"]
        finished = progress.get("finished")
        if finished:
            run["verdict"], run["reason"] = finished.get("verdict", ""), finished.get("reason", "")
            run["passed"], run["failed"] = finished.get("passed"), finished.get("failed")
        elif is_process_alive(progress.get("pid")):
            run["verdict"] = "実行中"
        else:
            run["verdict"] = "止まった"   # 最後まで流れずにプロセスが無い（強制終了・端末を閉じたなど）
        run["ng"] = [ng_line for step in run["steps"] for ng_line in step.get("ng", [])]
        run["not_done"] = ["%s: %s" % (step["name"], text) for step in run["steps"] for text in step.get("not_done", [])]
    elif run["verdict"] == "中断":
        # 前の版の道具の回: 結果.md は始めに「中断」と書かれ、終わったときに書き直される。ログが新しければ流している途中とみなす
        log_times = [os.path.getmtime(path) for path in glob.glob(os.path.join(run_directory, "*.log"))]
        if log_times and time.time() - max(log_times) < 20 * 60:
            run["verdict"] = "実行中（経過の記録なし）"
        run["options"] = None   # 結果.md がまだ始めの形なので、流し方は分からない
        run["log_count"] = len(log_times)
    if run["passed"] is None or run["verdict"].startswith("実行中") or run["verdict"] == "止まった":
        run["passed"] = sum(step["passed"] for step in run["steps"])
        run["failed"] = sum(step["failed"] for step in run["steps"])
        if not run["has_progress"]:
            for log_path in glob.glob(os.path.join(run_directory, "*.log")):
                log_text = read_text(log_path)
                run["passed"] += len(re.findall(r"^OK  ", log_text, re.M))
                run["failed"] += len(re.findall(r"^NG  ", log_text, re.M))
                run["ng"] += re.findall(r"^NG  .*$", log_text, re.M)
    return run


def options_key(options):
    options = options or {}
    return (bool(options.get("ui")), options.get("only") or "", options.get("skip") or "")


def options_text(options):
    if options is None:
        return "分からない（経過の記録（経過.json）を書く前の版の道具で流した回）"
    scope_text = "すべての工程"
    if options.get("only") or options.get("skip"):
        scope_text = "一部（%s）" % "・".join(part for part in ("--only " + options["only"] if options.get("only") else "",
                                                                  "--skip " + options["skip"] if options.get("skip") else "") if part)
    return "%s・画面の自動操作 %s" % (scope_text, "あり" if options.get("ui") else "なし")


def previous_complete_run(records_directory, run):
    """同じ流し方で最後まで流れた（成功か失敗）いちばん新しい前の回（見込みに使う）"""
    for run_directory in sorted(glob.glob(os.path.join(records_directory, "2*")), reverse=True):
        if os.path.basename(run_directory) >= run["stamp"]:
            continue
        candidate = load_run(run_directory, run["label"])
        if candidate["verdict"] in ("成功", "失敗") and options_key(candidate["options"]) == options_key(run["options"]) and candidate["steps"]:
            return candidate
    return None


def format_duration(seconds):
    seconds = int(max(0, seconds))
    if seconds < 60:
        return "%d 秒" % seconds
    if seconds < 3600:
        return "%d 分 %02d 秒" % (seconds // 60, seconds % 60)
    return "%d 時間 %02d 分" % (seconds // 3600, seconds % 3600 // 60)


def format_count(number):
    return "{:,}".format(number)


def progress_bar(ratio, width=30):
    """進みの棒（■ が終わった分。Windows の端末の文字コードでも出せる文字にする）"""
    filled_count = int(round(max(0.0, min(ratio, 1.0)) * width))
    return "[" + "■" * filled_count + "□" * (width - filled_count) + "]"


def format_clock(timestamp):
    return datetime.datetime.fromtimestamp(timestamp).strftime("%H:%M") if timestamp else "?"


def describe_run(run, records_directory, show_all_ng, show_estimate=True):
    lines = []
    now = time.time()
    is_running = run["verdict"].startswith("実行中")
    header = "[%s] %s  %s" % (run["label"], run["stamp"], run["verdict"])
    if run["reason"]:
        header += "（%s）" % run["reason"]
    lines.append(header)
    lines.append("  流し方: %s" % options_text(run["options"]))
    finished_steps = run["steps"]
    elapsed_seconds = sum(step.get("seconds", 0) for step in finished_steps) if not is_running else now - (run["started_at"] or now)
    if run["current"]:
        elapsed_seconds = now - (run["started_at"] or now)
    lines.append("  始め: %s　かかった時間: %s" % (format_clock(run["started_at"]), format_duration(elapsed_seconds)))
    previous_run = previous_complete_run(records_directory, run) if (is_running and show_estimate and run["has_progress"]) else None
    if is_running:
        progress_line = "  進み: %d 工程が終わった" % (len(finished_steps) if run["has_progress"] else run.get("log_count", 0))
        if previous_run:
            previous_seconds_by_name = {step["name"]: step["seconds"] for step in previous_run["steps"]}
            done_names = {step["name"] for step in finished_steps}
            remaining_seconds = sum(seconds for name, seconds in previous_seconds_by_name.items() if name not in done_names)
            if run["current"]:
                remaining_seconds -= min(previous_seconds_by_name.get(run["current"]["name"], 0), now - run["current"]["started_at"])
            total_count = max(len(previous_run["steps"]), len(finished_steps))
            # 進みの棒は時間で出す（工程ごとに確認の数の多さと時間が違うため。確認の数は件数で並べて出す）。
            # 確認の総数は、前回の同じ流し方の OK と NG の合計
            total_checks = (previous_run["passed"] or 0) + (previous_run["failed"] or 0)
            done_checks = (run["passed"] or 0) + (run["failed"] or 0)
            spent_seconds = now - (run["started_at"] or now)
            time_ratio = min(spent_seconds / (spent_seconds + max(remaining_seconds, 0)), 0.99) if spent_seconds > 0 else 0.0
            lines.append("  進み: %s %3d%%" % (progress_bar(time_ratio), int(time_ratio * 100)))
            lines.append("  確認: %s / 約 %s 件　工程: %d / 約 %d" % (format_count(done_checks), format_count(total_checks), len(finished_steps), total_count))
            progress_line = "  残り: 約 %s（%s ごろ終わる見込み。前回 %s の同じ流し方から）" % (
                format_duration(remaining_seconds), format_clock(now + max(remaining_seconds, 0)), previous_run["stamp"])
        lines.append(progress_line)
        if run["current"]:
            lines.append("  今の工程: %s（%s）" % (run["current"]["name"], format_duration(now - run["current"]["started_at"])))
    lines.append("  確認の数: OK %s / NG %s%s" % (format_count(run["passed"] or 0), format_count(run["failed"] or 0), "　未実施 %d" % len(run["not_done"]) if run["not_done"] else ""))
    failed_steps = [step for step in finished_steps if not step.get("ok", True)]
    if failed_steps:
        lines.append("  失敗した工程: " + "、".join(step["name"] for step in failed_steps))
    if is_running and finished_steps:
        lines.append("  最近終わった工程:")
        for step in finished_steps[-5:]:
            lines.append("    %s  %s（OK %d / NG %d、%s）" % ("成功" if step.get("ok", True) else "失敗", step["name"], step["passed"], step["failed"], format_duration(step.get("seconds", 0))))
    ng_lines = run["ng"] if show_all_ng else run["ng"][:NG_LINES_SHOWN]
    if ng_lines:
        lines.append("  NG:")
        lines += ["    " + ng_line for ng_line in ng_lines]
        if len(run["ng"]) > len(ng_lines):
            lines.append("    ほか %d 行（--ng ですべて出す）" % (len(run["ng"]) - len(ng_lines)))
    for not_done_text in run["not_done"]:
        lines.append("  未実施: " + not_done_text)
    lines.append("  記録: " + os.path.join(run["directory"], "結果.md"))
    return lines


def coverage_runs():
    """未検証の分岐の数え直し（branch_coverage.py）が写しで流している検証"""
    run_list = []
    for run_directory in glob.glob(os.path.join(tempfile.gettempdir(), "mr_coverage_*", "copy", *RECORD_PARTS, "2*")):
        if os.path.exists(os.path.join(run_directory, "経過.json")):
            run = load_run(run_directory, "分岐の数え直し")
            if run["verdict"].startswith("実行中"):
                run_list.append(run)
    return run_list


def repository_label(repository_root):
    return os.path.basename(os.path.normpath(repository_root)) or repository_root


def show_latest(root_list, run_stamp, show_all_ng):
    output_lines = ["自動検証の経過と結果（%s）" % datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"), ""]
    for repository_root in root_list:
        records_directory = record_root(repository_root)
        run_directory_list = sorted(glob.glob(os.path.join(records_directory, "2*")))
        if run_stamp:
            run_directory_list = [path for path in run_directory_list if os.path.basename(path) == run_stamp]
        if not run_directory_list:
            output_lines += ["[%s] 検証の記録がありません（%s）" % (repository_label(repository_root), records_directory), ""]
            continue
        output_lines += describe_run(load_run(run_directory_list[-1], repository_label(repository_root)), records_directory, show_all_ng) + [""]
    if not run_stamp:
        for run in coverage_runs():
            output_lines += describe_run(run, os.path.dirname(run["directory"]), show_all_ng, show_estimate=False) + [""]
    return output_lines


def show_list(root_list, count):
    output_lines = []
    for repository_root in root_list:
        records_directory = record_root(repository_root)
        output_lines.append("[%s] %s" % (repository_label(repository_root), records_directory))
        output_lines.append("  %-15s  %-8s  %7s  %5s  %-10s  %s" % ("日時", "判定", "OK", "NG", "時間", "流し方"))
        for run_directory in sorted(glob.glob(os.path.join(records_directory, "2*")), reverse=True)[:count]:
            run = load_run(run_directory, repository_label(repository_root))
            seconds = sum(step.get("seconds", 0) for step in run["steps"])
            output_lines.append("  %-15s  %-8s  %7s  %5s  %-10s  %s" % (run["stamp"], run["verdict"], run["passed"], run["failed"],
                                                                     format_duration(seconds) if seconds else "", options_text(run["options"])))
        output_lines.append("")
    return output_lines


def clear_screen():
    if os.name == "nt":
        os.system("cls")
    else:
        sys.stdout.write("\033[2J\033[H")


def main():
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(errors="replace")   # 表示できない文字があっても止めない
    argument_parser = argparse.ArgumentParser(description="自動検証（verify_all.py）の経過と結果を見る")
    argument_parser.add_argument("--root", action="append", help="リポジトリ（作業ツリー）の場所。何回でも付けられる（既定: この道具のあるリポジトリ）")
    argument_parser.add_argument("--watch", nargs="?", const=5, type=int, help="指定した秒ごとに表示し直す（既定 5 秒。Ctrl + C で終わる）")
    argument_parser.add_argument("--list", nargs="?", const=20, type=int, help="過去の検証の一覧（新しい順。既定 20 回）")
    argument_parser.add_argument("--run", default="", help="見る回の日時（例: 20261127_121321。既定は最新）")
    argument_parser.add_argument("--ng", action="store_true", help="NG の行をすべて出す")
    arguments = argument_parser.parse_args()
    root_list = [os.path.abspath(path) for path in (arguments.root or [DEFAULT_ROOT])]
    for repository_root in root_list:
        if not os.path.isdir(repository_root):
            print("リポジトリがありません: %s" % repository_root)
            return 2

    def render():
        return show_list(root_list, arguments.list) if arguments.list else show_latest(root_list, arguments.run, arguments.ng)

    if not arguments.watch:
        print("\n".join(render()))
        return 0
    try:
        while True:
            screen_lines = render()
            clear_screen()
            print("\n".join(screen_lines))
            print("（%d 秒ごとに表示し直します。終わるときは Ctrl + C）" % arguments.watch)
            time.sleep(max(1, arguments.watch))
    except KeyboardInterrupt:
        return 0


if __name__ == "__main__":
    sys.exit(main())
