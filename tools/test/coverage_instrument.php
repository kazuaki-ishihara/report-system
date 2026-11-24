<?php
// 分岐の計測（branch_coverage.py）: 写しの PHP・JavaScript の分岐に印を入れる
//   php tools/test/coverage_instrument.php <写しのルート> <印の一覧の出力>
// 写しの外では動かさない（ファイルを書き換える）。
if (PHP_SAPI !== 'cli' || !is_file(($argv[1] ?? '') . '/.coverage_copy')) {
    fwrite(STDERR, "計測用の写し（.coverage_copy があるフォルダー）だけで使います
");
    exit(1);
}
[$self, $root, $mapFile] = $argv;
$map = [];
$n = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', (string) $file);
    if (substr($path, -4) !== '.php' || !preg_match('#/app/(lib|pages|bin|views)/#', $path) || preg_match('#(messages_catalog|faq_standard|goal_criteria|goal_templates)\.php$#', $path)) {
        continue;
    }
    $src = file_get_contents($path);
    $tokens = token_get_all($src);
    $out = '';
    $line = 1;
    $parenStack = [];
    $lastKeyword = null;       // 直前の意味のあるトークン
    $closedBy = null;          // 直前に閉じた ( の前にあった語
    $afterParen = false;       // ) のあと、{ までが型の宣言だけか
    $pendingCase = false;
    $caseDepth = 0;
    $ternary = 0;
    $depth = 0;
    foreach ($tokens as $i => $tok) {
        $id = is_array($tok) ? $tok[0] : null;
        $text = is_array($tok) ? $tok[1] : $tok;
        if (is_array($tok)) {
            $line = $tok[2];
        }
        $out .= $text;
        if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $line += substr_count($text, "\n");
            continue;
        }
        $mark = function () use (&$n, &$map, $path, $root, &$line, $src) {
            $n++;
            $lines = explode("\n", $src);
            $map[$n] = [substr($path, strlen($root) + 1), $line, trim($lines[$line - 1] ?? '')];
            return " \\function_exists('__cov') && \\__cov($n); ";
        };
        $wasAfterParen = $afterParen && $lastKeyword === ')';
        if ($text === '(') {
            $parenStack[] = $lastKeyword;
            $afterParen = false;
        } elseif ($text === ')') {
            $closedBy = array_pop($parenStack);
            $afterParen = true;
        } elseif ($text === '{' && $id === null) {
            $isBody = ($afterParen && !in_array($closedBy, [T_SWITCH, T_MATCH], true)) || in_array($lastKeyword, [T_ELSE, T_TRY, T_FINALLY, T_DO], true);
            if ($isBody) {
                $out .= $mark();
            }
            $afterParen = false;
        } elseif ($afterParen && !(in_array($text, [':', '?', '|'], true) || in_array($id, [T_STRING, T_ARRAY, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) || $id === T_USE)) {
            $afterParen = false;
        }
        // 画面のひな形の書き方（if (…): else: foreach (…):）
        if ($text === ':' && $id === null && (($wasAfterParen && in_array($closedBy, [T_IF, T_ELSEIF, T_FOREACH, T_FOR, T_WHILE], true)) || $lastKeyword === T_ELSE)) {
            $out .= $mark();
        }
        if ($id === T_CASE || ($id === T_DEFAULT)) {
            $pendingCase = true;
            $caseDepth = count($parenStack);
        } elseif ($pendingCase && $text === '=>') {
            $pendingCase = false;   // match の default
        } elseif ($id === T_DOUBLE_ARROW) {
            $pendingCase = false;
        } elseif ($pendingCase && $text === ':' && count($parenStack) === $caseDepth) {
            $out .= $mark();
            $pendingCase = false;
        }
        $lastKeyword = $id ?? $text;
    }
    file_put_contents($path, $out);
}
// JavaScript
$jsPath = $root . '/public/js/app.js';
$lines = explode("\n", file_get_contents($jsPath));
foreach ($lines as $k => $l) {
    if (preg_match('#^\s*(//|\*|/\*)#', $l) || $k < 4) {
        continue;
    }
    $lines[$k] = preg_replace_callback('#(\)\s*\{|\belse\s*\{|\btry\s*\{)#', function ($m) use (&$n, &$map, $k, $l) {
        if (preg_match('#switch \(#', $l)) {
            return $m[0];
        }
        $n++;
        $map[$n] = ['public/js/app.js', $k + 1, trim($l)];
        return $m[0] . " window.__jc && window.__jc($n);";
    }, $l);
}
$head = "window.__jc=(function(){var s={},q=[];function f(){if(q.length){var b=q.join(',');q=[];try{navigator.sendBeacon('/__jc.php',b);}catch(e){}}}window.addEventListener('pagehide',f);return function(n){if(!s[n]){s[n]=1;q.push(n);if(q.length===1){Promise.resolve().then(f);}}};})();\n";
file_put_contents($jsPath, $head . implode("\n", $lines));
file_put_contents($root . '/public/__jc.php', "<?php foreach (explode(',', file_get_contents('php://input')) as \$x) { if (ctype_digit(\$x)) { \\__cov((int) \$x); } }\n");
file_put_contents($mapFile, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
echo "印: $n\n";
