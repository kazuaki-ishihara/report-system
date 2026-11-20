#!/usr/bin/env python3
"""mdview: Markdown を HTML にしてブラウザーで見る道具（docs/ の設計書・マニュアルを読むため）。

    python3 tools/lib/mdview.py docs/operation_manual.md          # HTML にしてブラウザーで開く
    python3 tools/lib/mdview.py docs                              # docs/ を一覧から読む（ローカルのサーバー）
    python3 tools/lib/mdview.py docs --port 8765 --no-open        # サーバーだけ起動して URL を出す
    python3 tools/lib/mdview.py docs/basic_design.md -o out.html  # HTML のファイルを書き出すだけ
    python3 tools/lib/mdview.py docs --mermaid                    # mermaid の図を描く（CDN から読み込む。ネットが要る）

- ファイルを渡すと、HTML を一時フォルダに書いてブラウザーで開く。
- フォルダを渡すか --serve を付けると、127.0.0.1 だけで待ち受けるサーバーを起動する。
  .md へのリンクをたどって読める（止めるときは Ctrl + C）。
- ブラウザーは Python の webbrowser で開く（Linux は xdg-open・環境変数 BROWSER、Windows・macOS は既定のブラウザー）。
  画面のない端末では --no-open を付け、出た URL・ファイルを別の端末から開く。
- 生の HTML は書き出さない（Markdown の中の <tag> は文字として出す）。

Python 3.8 以上の標準ライブラリだけで動く。Linux・Windows・macOS で同じように使える。
"""
import argparse
import html
import http.server
import os
import pathlib
import re
import socketserver
import sys
import tempfile
import urllib.parse
import webbrowser

DEFAULT_PORT = 8765
MERMAID_SRC = "https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js"

# ====================================================================
# インラインの要素
# ====================================================================

_PLACEHOLDER = "\x00{}\x00"
_CODE_SPAN = re.compile(r"(`+)(.+?)\1", re.S)
_IMAGE = re.compile(r'!\[([^\]]*)\]\(\s*([^)\s]+)(?:\s+"([^"]*)")?\s*\)')
_LINK = re.compile(r'\[([^\]]+)\]\(\s*([^)\s]+)(?:\s+"([^"]*)")?\s*\)')
_AUTOLINK = re.compile(r"&lt;((?:https?|mailto):[^&\s]+)&gt;")
_BARE_URL = re.compile(r"(?<![\"'=/\w])(https?://[^\s<>\"'）」]+[^\s<>\"'）」.,、。:;!?])")
_BOLD = re.compile(r"\*\*(?=\S)(.+?)(?<=\S)\*\*|__(?=\S)(.+?)(?<=\S)__")
_ITALIC = re.compile(r"(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])")
_STRIKE = re.compile(r"~~(?=\S)(.+?)(?<=\S)~~")
_TAG = re.compile(r"<[^>]+>")


def _safe_url(url, link_rewriter=None):
    """javascript: などは使わせない。.md へのリンクは link_rewriter で書き換える。"""
    url = html.unescape(url)
    scheme = urllib.parse.urlsplit(url).scheme.lower()
    if scheme and scheme not in ("http", "https", "mailto", "file"):
        return "#"
    if link_rewriter is not None:
        url = link_rewriter(url)
    return html.escape(url, quote=True)


def render_inline(text, link_rewriter=None):
    """1 行（または段落）の中の Markdown を HTML にする。"""
    stash = []

    def keep(fragment):
        stash.append(fragment)
        return _PLACEHOLDER.format(len(stash) - 1)

    # コードの中は何も解釈しない
    text = _CODE_SPAN.sub(lambda m: keep("<code>" + html.escape(m.group(2).strip()) + "</code>"), text)
    # \* などの逃がし
    text = re.sub(r"\\([\\`*_{}\[\]()#+\-.!|~<>])", lambda m: keep(html.escape(m.group(1))), text)
    text = html.escape(text, quote=False)

    def image(m):
        alt, src, title = m.group(1), m.group(2), m.group(3)
        title_attr = ' title="{}"'.format(html.escape(title)) if title else ""
        return keep('<img src="{}" alt="{}"{}>'.format(_safe_url(src, link_rewriter), alt, title_attr))

    def link(m):
        label, href, title = m.group(1), m.group(2), m.group(3)
        title_attr = ' title="{}"'.format(html.escape(title)) if title else ""
        return keep('<a href="{}"{}>{}</a>'.format(_safe_url(href, link_rewriter), title_attr, label))

    text = _IMAGE.sub(image, text)
    text = _LINK.sub(link, text)
    text = _AUTOLINK.sub(lambda m: keep('<a href="{0}">{0}</a>'.format(m.group(1))), text)
    text = _BARE_URL.sub(lambda m: keep('<a href="{0}">{0}</a>'.format(m.group(1))), text)
    text = _BOLD.sub(lambda m: "<strong>" + (m.group(1) or m.group(2)) + "</strong>", text)
    text = _ITALIC.sub(r"<em>\1</em>", text)
    text = _STRIKE.sub(r"<del>\1</del>", text)
    # 行末の空白 2 つ・\ は改行
    text = re.sub(r"( {2,}|\\)\n", "<br>\n", text)

    # 入れ子の置き換え（リンクの中のコードなど）を戻す
    for _ in range(3):
        if "\x00" not in text:
            break
        text = re.sub(r"\x00(\d+)\x00", lambda m: stash[int(m.group(1))], text)
    return text


# ====================================================================
# ブロックの要素
# ====================================================================

_FENCE = re.compile(r"^(\s{0,3})(`{3,}|~{3,})\s*([^`\s]*)[^`]*$")
_HEADING = re.compile(r"^\s{0,3}(#{1,6})(?:\s+(.*?))?\s*#*\s*$")
_HR = re.compile(r"^\s{0,3}([-*_])(?:\s*\1){2,}\s*$")
_LIST_ITEM = re.compile(r"^(\s*)([-*+]|\d{1,9}[.)])(\s+|$)(.*)$")
_TABLE_SEP = re.compile(r"^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$")
_QUOTE = re.compile(r"^\s{0,3}>\s?(.*)$")
_TASK = re.compile(r"^\[([ xX])\]\s+(.*)$", re.S)


def _indent_width(line):
    return len(line) - len(line.lstrip(" "))


def _split_table_row(line):
    """表の 1 行をセルに分ける（` ` の中と \\| の | では分けない）。"""
    row = line.strip()
    if row.startswith("|"):
        row = row[1:]
    if row.endswith("|") and not row.endswith("\\|"):
        row = row[:-1]
    cells, current, in_code, i = [], [], False, 0
    while i < len(row):
        ch = row[i]
        if ch == "\\" and i + 1 < len(row) and row[i + 1] == "|":
            current.append("|")
            i += 2
            continue
        if ch == "`":
            in_code = not in_code
        if ch == "|" and not in_code:
            cells.append("".join(current).strip())
            current = []
        else:
            current.append(ch)
        i += 1
    cells.append("".join(current).strip())
    return cells


class Renderer:
    """Markdown の文書 1 つを HTML にする。見出しの一覧（目次用）も集める。"""

    def __init__(self, link_rewriter=None, mermaid=False):
        self.link_rewriter = link_rewriter
        self.mermaid = mermaid
        self.headings = []      # (レベル, id, 文字)
        self._used_ids = {}

    # ---- 見出しの id（GitHub に近い形） ----
    def _heading_id(self, plain):
        slug = re.sub(r"[^\w\- ]", "", plain.strip().lower(), flags=re.UNICODE).replace(" ", "-")
        slug = slug or "section"
        count = self._used_ids.get(slug, 0)
        self._used_ids[slug] = count + 1
        return slug if count == 0 else "{}-{}".format(slug, count)

    def inline(self, text):
        return render_inline(text, self.link_rewriter)

    # ---- ブロックの並び ----
    def render(self, text):
        text = text.replace("\r\n", "\n").replace("\r", "\n").replace("\t", "    ")
        return self.blocks(text.split("\n"))

    def blocks(self, lines, tight=False):
        out = []
        i, n = 0, len(lines)
        while i < n:
            line = lines[i]
            if not line.strip():
                i += 1
                continue

            fence = _FENCE.match(line)
            if fence:
                i = self._code_block(lines, i, fence, out)
                continue

            heading = _HEADING.match(line)
            if heading:
                level = len(heading.group(1))
                content = self.inline(heading.group(2) or "")
                plain = html.unescape(_TAG.sub("", content))
                hid = self._heading_id(plain)
                self.headings.append((level, hid, plain))
                out.append('<h{0} id="{1}"><a class="anchor" href="#{1}" aria-hidden="true">#</a>{2}</h{0}>'.format(
                    level, html.escape(hid, quote=True), content))
                i += 1
                continue

            if _HR.match(line):
                out.append("<hr>")
                i += 1
                continue

            if "|" in line and i + 1 < n and _TABLE_SEP.match(lines[i + 1]) and "-" in lines[i + 1]:
                i = self._table(lines, i, out)
                continue

            if _QUOTE.match(line):
                quoted = []
                while i < n and lines[i].strip() and (_QUOTE.match(lines[i]) or quoted):
                    m = _QUOTE.match(lines[i])
                    quoted.append(m.group(1) if m else lines[i])
                    i += 1
                out.append("<blockquote>\n" + self.blocks(quoted) + "\n</blockquote>")
                continue

            if _LIST_ITEM.match(line) and not _HR.match(line):
                i = self._list(lines, i, out)
                continue

            # 段落: 空行か、ほかのブロックの始まりまで
            def keep_break(text):          # 行末の空白 2 つ（改行）は残す
                return text.strip() + ("  " if text.endswith("  ") else "")

            para = [keep_break(line)]
            i += 1
            while i < n and lines[i].strip():
                nxt = lines[i]
                if (_FENCE.match(nxt) or _HEADING.match(nxt) or _HR.match(nxt) or _QUOTE.match(nxt)
                        or _LIST_ITEM.match(nxt)
                        or ("|" in nxt and i + 1 < n and _TABLE_SEP.match(lines[i + 1]) and "-" in lines[i + 1])):
                    break
                para.append(keep_break(nxt))
                i += 1
            body = self.inline("\n".join(para))
            out.append(body if tight else "<p>" + body + "</p>")
        return "\n".join(out)

    def _code_block(self, lines, i, fence, out):
        indent, marker, lang = len(fence.group(1)), fence.group(2), fence.group(3).lower()
        body = []
        i += 1
        while i < len(lines):
            stripped = lines[i].strip()
            if stripped.startswith(marker[0] * len(marker)) and set(stripped) <= {marker[0]}:
                i += 1
                break
            line = lines[i]
            body.append(line[min(indent, _indent_width(line)):])
            i += 1
        code = html.escape("\n".join(body))
        if lang == "mermaid" and self.mermaid:
            out.append('<pre class="mermaid">' + code + "</pre>")
        else:
            cls = ' class="language-{}"'.format(html.escape(lang, quote=True)) if lang else ""
            label = '<span class="lang">{}</span>'.format(html.escape(lang)) if lang else ""
            out.append("<pre>{}<code{}>{}</code></pre>".format(label, cls, code))
        return i

    def _table(self, lines, i, out):
        header = _split_table_row(lines[i])
        aligns = []
        for spec in _split_table_row(lines[i + 1]):
            left, right = spec.startswith(":"), spec.endswith(":")
            aligns.append("center" if left and right else "right" if right else "left" if left else "")
        i += 2
        rows = []
        while i < len(lines) and lines[i].strip() and "|" in lines[i]:
            rows.append(_split_table_row(lines[i]))
            i += 1

        def cell(tag, value, col):
            align = aligns[col] if col < len(aligns) else ""
            style = ' style="text-align:{}"'.format(align) if align else ""
            return "<{0}{1}>{2}</{0}>".format(tag, style, self.inline(value))

        parts = ['<div class="table-wrap"><table>', "<thead><tr>"]
        parts += [cell("th", v, c) for c, v in enumerate(header)]
        parts.append("</tr></thead><tbody>")
        for row in rows:
            row = (row + [""] * len(header))[:len(header)]
            parts.append("<tr>" + "".join(cell("td", v, c) for c, v in enumerate(row)) + "</tr>")
        parts.append("</tbody></table></div>")
        out.append("".join(parts))
        return i

    def _list(self, lines, i, out):
        first = _LIST_ITEM.match(lines[i])
        base_indent = len(first.group(1))
        ordered = first.group(2)[0].isdigit()
        start = int(first.group(2)[:-1]) if ordered else 1
        items, loose = [], False
        n = len(lines)
        while i < n:
            m = _LIST_ITEM.match(lines[i])
            if not m or len(m.group(1)) != base_indent or m.group(2)[0].isdigit() != ordered or _HR.match(lines[i]):
                break
            content_col = len(m.group(1)) + len(m.group(2)) + max(1, min(len(m.group(3)), 4))
            item_lines = [m.group(4)]
            i += 1
            while i < n:
                line = lines[i]
                if not line.strip():
                    # 空行のあと、字下げが続けば同じ項目（ゆるいリスト）
                    j = i + 1
                    while j < n and not lines[j].strip():
                        j += 1
                    if j < n and _indent_width(lines[j]) >= content_col:
                        loose = True
                        item_lines.extend([""] * (j - i))
                        i = j
                        continue
                    if j < n:
                        nm = _LIST_ITEM.match(lines[j])
                        if nm and len(nm.group(1)) == base_indent and nm.group(2)[0].isdigit() == ordered:
                            loose = True
                    break
                if _indent_width(line) >= content_col:
                    item_lines.append(line[content_col:])
                elif _LIST_ITEM.match(line):
                    sub = _LIST_ITEM.match(line)
                    if len(sub.group(1)) <= base_indent:
                        break
                    item_lines.append(line[min(_indent_width(line), content_col):])
                elif _HEADING.match(line) or _FENCE.match(line) or _HR.match(line):
                    break
                else:
                    item_lines.append(line.strip())    # 続きの行（字下げなし）
                i += 1
            items.append(item_lines)

        tag = "ol" if ordered else "ul"
        attrs = ' start="{}"'.format(start) if ordered and start != 1 else ""
        html_items = []
        task_list = False
        for item_lines in items:
            checkbox = ""
            task = _TASK.match(item_lines[0]) if item_lines else None
            if task:
                task_list = True
                checked = " checked" if task.group(1) in "xX" else ""
                checkbox = '<input type="checkbox" disabled{}> '.format(checked)
                item_lines = [task.group(2)] + item_lines[1:]
            body = self.blocks(item_lines, tight=not loose)
            cls = ' class="task"' if task else ""
            html_items.append("<li{}>{}{}</li>".format(cls, checkbox, body))
        cls = ' class="task-list"' if task_list else ""
        out.append("<{0}{1}{2}>\n{3}\n</{0}>".format(tag, attrs, cls, "\n".join(html_items)))
        return i


# ====================================================================
# ページ
# ====================================================================

CSS = """
:root{--bg:#ffffff;--fg:#1f2328;--muted:#59636e;--border:#d1d9e0;--code-bg:#f6f8fa;--link:#0969da;
--th-bg:#f6f8fa;--row-alt:#fafbfc;--side-bg:#f6f8fa;--mark:#0969da}
@media (prefers-color-scheme: dark){:root{--bg:#0d1117;--fg:#e6edf3;--muted:#9198a1;--border:#3d444d;
--code-bg:#151b23;--link:#4493f8;--th-bg:#151b23;--row-alt:#10151c;--side-bg:#010409;--mark:#4493f8}}
*{box-sizing:border-box}
html{scroll-padding-top:16px}
body{margin:0;background:var(--bg);color:var(--fg);line-height:1.7;
font-family:"Noto Sans CJK JP","Noto Sans JP","IPAexGothic","Hiragino Sans","Yu Gothic UI","Meiryo",system-ui,sans-serif;font-size:16px}
.layout{display:flex;align-items:flex-start;min-height:100vh}
nav.toc{position:sticky;top:0;flex:0 0 280px;max-height:100vh;overflow-y:auto;padding:20px 16px;
background:var(--side-bg);border-right:1px solid var(--border);font-size:14px;line-height:1.5}
nav.toc .doc-title{font-weight:700;margin:0 0 4px}
nav.toc .crumb{font-size:12px;color:var(--muted);margin:0 0 12px;word-break:break-all}
nav.toc .crumb a{color:var(--muted)}
nav.toc ul{list-style:none;margin:0;padding:0}
nav.toc li{margin:2px 0}
nav.toc li.l3{padding-left:14px;font-size:13px}
nav.toc a{color:var(--fg);text-decoration:none;display:block;padding:2px 6px;border-radius:4px}
nav.toc a:hover{background:var(--border)}
nav.toc input{width:100%;padding:6px 8px;margin:0 0 10px;border:1px solid var(--border);border-radius:6px;
background:var(--bg);color:var(--fg);font:inherit}
main{flex:1 1 auto;min-width:0;max-width:980px;padding:32px 40px 80px}
h1,h2,h3,h4,h5,h6{line-height:1.35;margin:1.6em 0 .6em;position:relative}
h1{font-size:2em;border-bottom:1px solid var(--border);padding-bottom:.3em;margin-top:0}
h2{font-size:1.5em;border-bottom:1px solid var(--border);padding-bottom:.3em}
h3{font-size:1.25em}
.anchor{position:absolute;left:-1em;color:var(--muted);text-decoration:none;opacity:0;font-weight:400}
h1:hover .anchor,h2:hover .anchor,h3:hover .anchor,h4:hover .anchor{opacity:1}
a{color:var(--link)}
p,ul,ol,blockquote,pre,.table-wrap{margin:0 0 1em}
ul,ol{padding-left:2em}
li>ul,li>ol{margin:.25em 0}
li>p{margin:.4em 0}
ul.task-list{list-style:none;padding-left:1.2em}
code{font-family:"Noto Sans Mono CJK JP","DejaVu Sans Mono",ui-monospace,Consolas,monospace;font-size:.88em;
background:var(--code-bg);padding:.15em .4em;border-radius:5px}
pre{background:var(--code-bg);border:1px solid var(--border);border-radius:6px;padding:14px 16px;overflow:auto;
line-height:1.45;position:relative}
pre code{background:none;padding:0;font-size:.86em;white-space:pre}
pre .lang{position:absolute;top:4px;right:8px;font-size:11px;color:var(--muted)}
pre.mermaid{background:var(--bg);text-align:center}
blockquote{border-left:4px solid var(--border);color:var(--muted);padding:0 1em;margin-left:0}
hr{border:0;border-top:1px solid var(--border);margin:2em 0}
.table-wrap{overflow-x:auto}
table{border-collapse:collapse;font-size:.95em}
th,td{border:1px solid var(--border);padding:6px 12px;vertical-align:top}
th{background:var(--th-bg);text-align:left}
tbody tr:nth-child(2n){background:var(--row-alt)}
img{max-width:100%}
main img{border:1px solid var(--border);border-radius:4px}
.listing li{margin:4px 0}
.listing .dir>a{font-weight:700}
.footer{margin-top:48px;color:var(--muted);font-size:12px}
@media (max-width: 900px){.layout{display:block}nav.toc{position:static;max-height:none;border-right:0;
border-bottom:1px solid var(--border)}main{padding:20px 16px 60px}.anchor{display:none}}
@media print{nav.toc{display:none}main{max-width:none;padding:0}.anchor{display:none}
pre,table,h2,h3{break-inside:avoid}a{color:inherit}}
"""

TOC_FILTER_JS = """
(function(){var box=document.getElementById('toc-filter');if(!box)return;
box.addEventListener('input',function(){var q=box.value.toLowerCase();
document.querySelectorAll('nav.toc li').forEach(function(li){
li.style.display=li.textContent.toLowerCase().indexOf(q)>=0?'':'none';});});})();
"""


def build_page(title, body_html, headings, crumb_html="", mermaid=False, base_href=None):
    toc_items = []
    for level, hid, text in headings:
        if level in (2, 3):
            toc_items.append('<li class="l{}"><a href="#{}">{}</a></li>'.format(
                level, html.escape(hid, quote=True), html.escape(text)))
    toc = ""
    if toc_items or crumb_html:
        toc = ('<nav class="toc"><p class="doc-title">{}</p>{}'.format(html.escape(title), crumb_html)
               + ('<input id="toc-filter" type="search" placeholder="目次を絞り込む">' if len(toc_items) > 8 else "")
               + "<ul>" + "".join(toc_items) + "</ul></nav>")
    base = '<base href="{}">'.format(html.escape(base_href, quote=True)) if base_href else ""
    mermaid_js = ""
    if mermaid:
        mermaid_js = ('<script src="{}"></script><script>mermaid.initialize({{startOnLoad:true,'
                      'theme:window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"default"}});'
                      "</script>").format(MERMAID_SRC)
    return """<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{base}<title>{title}</title><style>{css}</style></head>
<body><div class="layout">{toc}<main>{body}<p class="footer">mdview で表示</p></main></div>
<script>{js}</script>{mermaid}</body></html>
""".format(base=base, title=html.escape(title), css=CSS, toc=toc, body=body_html, js=TOC_FILTER_JS, mermaid=mermaid_js)


def read_markdown(path):
    with open(path, "r", encoding="utf-8-sig", errors="replace") as f:
        return f.read()


def render_file(path, link_rewriter=None, mermaid=False, crumb_html="", base_href=None):
    renderer = Renderer(link_rewriter=link_rewriter, mermaid=mermaid)
    body = renderer.render(read_markdown(path))
    title = next((text for level, _, text in renderer.headings if level == 1), os.path.basename(path))
    return build_page(title, body, renderer.headings, crumb_html, mermaid, base_href)


# ====================================================================
# 1 つのファイルを開く
# ====================================================================

def open_file(path, output=None, open_browser=True, mermaid=False):
    path = os.path.abspath(path)
    base_href = pathlib.Path(os.path.dirname(path)).as_uri() + "/"
    page = render_file(path, mermaid=mermaid, base_href=base_href)
    if output:
        out_path = os.path.abspath(output)
    else:
        out_dir = os.path.join(tempfile.gettempdir(), "mdview")
        os.makedirs(out_dir, exist_ok=True)
        out_path = os.path.join(out_dir, os.path.splitext(os.path.basename(path))[0] + ".html")
    with open(out_path, "w", encoding="utf-8", newline="\n") as f:
        f.write(page)
    print("HTML: {}".format(out_path))
    if open_browser and not output:
        _open_in_browser(pathlib.Path(out_path).as_uri())


def _open_in_browser(url):
    try:
        opened = webbrowser.open(url)
    except Exception:          # noqa: BLE001 ブラウザーがない端末でも止めない
        opened = False
    if not opened:
        print("ブラウザーを開けませんでした。次を開いてください: {}".format(url))


# ====================================================================
# フォルダを読む（ローカルのサーバー）
# ====================================================================

class _Handler(http.server.SimpleHTTPRequestHandler):
    root = "."
    mermaid = False

    def log_message(self, fmt, *args):      # 静かにする（エラーだけ出す）
        if args and str(args[1]).startswith(("4", "5")):
            sys.stderr.write("{} {}\n".format(self.address_string(), fmt % args))

    def _resolve(self):
        rel = urllib.parse.unquote(urllib.parse.urlsplit(self.path).path).lstrip("/")
        target = os.path.realpath(os.path.join(self.root, rel))
        root = os.path.realpath(self.root)
        if target != root and not target.startswith(root + os.sep):
            return None, rel
        return target, rel

    def do_GET(self):
        target, rel = self._resolve()
        if target is None or not os.path.exists(target):
            self.send_error(404, "Not Found")
            return
        if os.path.isdir(target):
            if not self.path.split("?")[0].endswith("/"):
                self.send_response(301)
                self.send_header("Location", self.path.split("?")[0] + "/")
                self.end_headers()
                return
            self._send_html(self._listing(target, rel))
            return
        if target.lower().endswith((".md", ".markdown")):
            crumb = self._crumb(rel)
            self._send_html(render_file(target, mermaid=self.mermaid, crumb_html=crumb))
            return
        super().do_GET()

    def translate_path(self, path):         # 静的なファイル（画像など）も root の中だけ
        target, _ = self._resolve()
        return target or os.path.join(self.root, "__not_found__")

    def _send_html(self, page):
        data = page.encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)

    def _crumb(self, rel):
        parts = [p for p in rel.replace("\\", "/").split("/") if p]
        links = ['<a href="/">{}</a>'.format(html.escape(os.path.basename(os.path.realpath(self.root)) or "/"))]
        for idx in range(len(parts) - 1):
            href = "/" + "/".join(urllib.parse.quote(p) for p in parts[:idx + 1]) + "/"
            links.append('<a href="{}">{}</a>'.format(href, html.escape(parts[idx])))
        return '<p class="crumb">{}</p>'.format(" / ".join(links))

    def _listing(self, directory, rel):
        entries = sorted(os.listdir(directory), key=lambda s: s.lower())
        dirs = [e for e in entries if os.path.isdir(os.path.join(directory, e)) and not e.startswith(".")]
        docs = [e for e in entries if e.lower().endswith((".md", ".markdown"))]
        items = []
        if rel.strip("/"):
            items.append('<li class="dir"><a href="../">../</a></li>')
        for d in dirs:
            items.append('<li class="dir"><a href="{}/">{}/</a></li>'.format(urllib.parse.quote(d), html.escape(d)))
        for doc in docs:
            title = _first_heading(os.path.join(directory, doc))
            label = html.escape(doc) + (" — " + html.escape(title) if title else "")
            items.append('<li><a href="{}">{}</a></li>'.format(urllib.parse.quote(doc), label))
        name = "/" + rel.strip("/") if rel.strip("/") else os.path.basename(os.path.realpath(self.root))
        body = "<h1>{}</h1><ul class=\"listing\">{}</ul>".format(html.escape(name), "".join(items) or "<li>Markdown がありません</li>")
        readme = next((d for d in docs if d.lower() == "readme.md"), None)
        if readme:
            renderer = Renderer()
            body += "<hr>" + renderer.render(read_markdown(os.path.join(directory, readme)))
        return build_page(name, body, [], self._crumb(rel + "/x"))


def _first_heading(path):
    try:
        with open(path, "r", encoding="utf-8-sig", errors="replace") as f:
            for _, line in zip(range(40), f):
                m = re.match(r"^#\s+(.+?)\s*#*\s*$", line)
                if m:
                    return m.group(1)
    except OSError:
        pass
    return ""


class _Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


def serve(root, port, open_browser=True, mermaid=False, start_page=None):
    handler = type("Handler", (_Handler,), {"root": os.path.abspath(root), "mermaid": mermaid})
    try:
        server = _Server(("127.0.0.1", port), handler)
    except OSError as e:
        sys.exit("ポート {} を使えません（{}）。--port で別の番号を指定してください。".format(port, e))
    url = "http://127.0.0.1:{}/{}".format(server.server_address[1], start_page or "")
    print("表示: {}  （止めるときは Ctrl + C）".format(url), flush=True)
    if open_browser:
        _open_in_browser(url)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n止めました。")
    finally:
        server.server_close()


# ====================================================================
# コマンド
# ====================================================================

def main(argv=None):
    parser = argparse.ArgumentParser(
        prog="mdview",
        description="Markdown を HTML にしてブラウザーで見る（ファイル: その場で開く、フォルダ: 一覧から読む）")
    parser.add_argument("path", nargs="?", default=".", help="Markdown のファイルかフォルダ（既定: 今のフォルダ）")
    parser.add_argument("-o", "--output", help="HTML をこのファイルに書き出すだけ（ブラウザーは開かない）")
    parser.add_argument("--serve", action="store_true", help="ファイルでも、そのフォルダをサーバーで開く（リンクをたどれる）")
    parser.add_argument("--port", type=int, default=DEFAULT_PORT, help="サーバーのポート（既定: {}。0 で空いている番号）".format(DEFAULT_PORT))
    parser.add_argument("--no-open", action="store_true", help="ブラウザーを開かない（画面のない端末向け）")
    parser.add_argument("--mermaid", action="store_true", help="mermaid の図を描く（CDN から読み込むため、ネットが要る）")
    args = parser.parse_args(argv)

    path = os.path.abspath(args.path)
    if not os.path.exists(path):
        parser.error("見つかりません: {}".format(args.path))

    if os.path.isdir(path):
        if args.output:
            parser.error("-o はファイルのときだけ使えます")
        serve(path, args.port, not args.no_open, args.mermaid)
    elif args.serve:
        serve(os.path.dirname(path), args.port, not args.no_open, args.mermaid,
              start_page=urllib.parse.quote(os.path.basename(path)))
    else:
        open_file(path, args.output, not args.no_open, args.mermaid)
    return 0


if __name__ == "__main__":
    sys.exit(main())
