<?php

declare(strict_types=1);

// ====================================================================
// 静的な安全点検。コードを動かさずに読んで、SQL インジェクション・XSS などのおそれがある書き方を洗い出す
//   php tools/test/check_static_security.php          点検して OK/NG を出す
//   php tools/test/check_static_security.php --draft  仕分けていない箇所を、例外の TSV の行の下書きの形で出す
// app/ と public/ の PHP を token_get_all で読む（正規表現で PHP を読まない）。DB・サーバーには触れない。
// 確かめて安全と分かった箇所は tools/test/static_security_exceptions.tsv に理由を付けて書く。
// PHP 7.4 でも動く書き方にする。
//
// 点検すること（種類のコード）
//   SQL   db_one・db_all・db_val・db_exec（app/lib/db.php。中で prepare する）と ->query・->exec・->prepare の第 1 引数
//   XSS   <?= の式、echo・print の式（app/bin/ はコマンドの道具なので除く）。HTML を組み立てて返す関数の return の式
//   属性  属性の値を引用符で囲まずに <?= を書いている所（直前の HTML が = で終わる）
//   危険  eval・assert・system・exec・shell_exec・passthru・popen・proc_open・unserialize・extract・逆引用符、
//         変数を含むパスの include・require（変数が固定の値だけから来ると分かれば安全）
//   転送  header('Location…') は app/lib/util.php の redirect() の中だけ（is_safe_local_path で外への転送を防ぐ）
//
// 安全とみなすもの（SQL・XSS で同じ。XSS ではこれに加えて h() と HTML を組み立てて返す関数）
//   ・文字列のリテラル・数・定数（クラスの定数を含む）・それらの . でのつなぎ、変数を含まない heredoc・nowdoc
//   ・. でつないだ各部分、三項演算子・?? の各枝が、どれも安全なもの
//   ・数・真偽になるもの（(int) などのキャスト、count・intval など、算術・比較・論理の演算）
//   ・implode・sprintf・array_fill・str_repeat・trim 類・array_merge などは、引数がすべて安全なら結果も安全
//   ・変数は、同じ関数の中（関数の外のコードなら、関数の本体を除いたファイル全体）の代入
//     （=・.=・[] =・[k] =・??=・foreach の as・分割代入）の右辺がすべて安全なら安全。[k] = の k（キー）も調べる。
//     use (&$x)・foreach (… as &$v)・$a = &$b の参照で書き換わる分も足す。
//     配列のリテラルは要素のキーと値を調べる。$map[$key] のような添え字の参照は、もとの変数を調べる。
//     クロージャーの use の変数は外側の関数で調べる。循環は打ち切る（それ以上の出どころを足さない）
//   ・変数に入れたクロージャーを呼ぶ所（$fn(...)）は、そのクロージャーの return の式を調べる
// 分からないもの（NG として報告し、確かめたら例外に書く）
//   関数の引数・参照渡しで書き換わる値・代入が見つからない変数（テンプレートに渡された値など）・スーパーグローバル・
//   メソッドやプロパティ・知らない関数・new・読めない形の式
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$repositoryRoot = dirname(__DIR__, 2);
$exceptionFilePath = __DIR__ . '/static_security_exceptions.tsv';
$isDraftMode = in_array('--draft', array_slice($argv, 1), true);

/** db.php の SQL を受け取る関数（中で prepare して、値は ? で渡す） */
const SQL_WRAPPER_FUNCTION_NAMES = ['db_one', 'db_all', 'db_val', 'db_exec'];
/** PDO の、SQL の文を受け取るメソッド */
const SQL_METHOD_NAMES = ['query', 'exec', 'prepare'];

/**
 * HTML を組み立てて返す関数（呼ぶ側では安全とし、その代わり定義の return の式を点検する）。
 * 名前が _html・_svg で終わる関数もこれに入る。app/lib を grep して決めた（2026-11-26）:
 *   form_hidden（app/lib/html.php。CSRF の鍵などの hidden の input）、form_badges（app/lib/form.php。決まった文の札）、
 *   h_lines・h_phrases（app/lib/html.php。h() で書き出してから <br> などでつなぐ）
 */
const HTML_BUILDER_FUNCTION_NAMES = ['form_hidden', 'form_badges', 'h_lines', 'h_phrases'];

/** 結果が数・真偽・決まった文字だけになる関数（引数が何でも、SQL・HTML の文字として安全） */
const ALWAYS_SAFE_FUNCTION_NAMES = [
    'count', 'sizeof', 'intval', 'floatval', 'boolval', 'strlen', 'mb_strlen', 'abs', 'floor', 'ceil', 'round', 'intdiv', 'fmod',
    'sqrt', 'pow', 'array_sum', 'array_product', 'number_format', 'time', 'mktime', 'strtotime', 'idate', 'ord', 'crc32',
    'filesize', 'filemtime', 'array_key_exists', 'key_exists', 'in_array', 'isset', 'empty', 'is_array', 'is_string', 'is_int',
    'is_numeric', 'is_null', 'is_bool', 'is_float', 'is_file', 'is_dir', 'file_exists', 'function_exists', 'preg_match',
    'preg_match_all', 'strpos', 'stripos', 'strrpos', 'mb_strpos', 'strcmp', 'strcasecmp', 'strncmp', 'substr_count',
    'mb_substr_count', 'ctype_digit', 'ctype_alnum', 'ctype_alpha', 'checkdate', 'hash_equals', 'password_verify', 'random_int',
    'mt_rand', 'rand', 'md5', 'sha1', 'hash', 'hash_hmac', 'bin2hex', 'base64_encode', 'dechex', 'uniqid', 'urlencode',
    'rawurlencode', 'version_compare', 'cal_days_in_month',
];

/** 引数がすべて安全なら結果も安全な関数（文字や配列を並べ替え・つなぎ・切り出すだけ） */
const PASS_THROUGH_FUNCTION_NAMES = [
    'implode', 'join', 'array_fill', 'array_fill_keys', 'str_repeat', 'trim', 'rtrim', 'ltrim', 'chop', 'sprintf', 'vsprintf',
    'array_merge', 'array_merge_recursive', 'array_values', 'array_keys', 'array_slice', 'array_unique', 'array_reverse',
    'array_pad', 'array_combine', 'array_column', 'array_flip', 'array_diff', 'array_intersect', 'array_diff_key',
    'array_intersect_key', 'array_chunk', 'array_key_first', 'array_key_last', 'strtoupper', 'strtolower', 'mb_strtoupper',
    'mb_strtolower', 'ucfirst', 'lcfirst', 'ucwords', 'substr', 'mb_substr', 'mb_strimwidth', 'str_pad', 'str_replace',
    'nl2br', 'max', 'min', 'date', 'gmdate', 'strval', 'reset', 'end', 'current', 'array_shift', 'array_pop', 'range',
    'wordwrap', 'str_split', 'mb_str_split', 'explode',
];

/** 第 1 引数の要素だけが結果に残る関数（あとの引数はコールバックなど） */
const FIRST_ARGUMENT_FUNCTION_NAMES = ['array_filter'];

/** 参照渡しの引数に、分からない値を書き込む組み込み関数（関数名 => 引数の位置） */
const BY_REFERENCE_UNKNOWN_POSITIONS = [
    'preg_match' => [2], 'preg_match_all' => [2], 'parse_str' => [1], 'mb_parse_str' => [1], 'exec' => [1],
    'sscanf' => [2, 3, 4, 5, 6], 'settype' => [0],
];

/** 第 1 引数の配列に、ほかの引数の値を足す組み込み関数（関数名 => 値の引数の最初の位置。array_splice は 3 番目だけ） */
const BY_REFERENCE_APPEND_FUNCTIONS = ['array_push' => 1, 'array_unshift' => 1, 'array_splice' => 3];

const SUPERGLOBAL_NAMES = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES', '$_ENV', '$_SESSION', '$GLOBALS'];

/** 危険な関数（関数として呼ぶもの。->exec などのメソッドは見ない） */
const DANGEROUS_FUNCTION_NAMES = [
    'eval', 'assert', 'system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'unserialize', 'extract',
];

/** 種類のコードと、問題がないときの文（出す順） */
const CHECK_KIND_SUMMARIES = [
    'SQL'  => 'SQL の文は、固定の文か安全な部品だけで組み立てている',
    'XSS'  => '画面に出す値は、h() などで書き出すか安全な値だけ',
    '属性' => '属性の値に出す <?= は引用符で囲んでいる',
    '危険' => '危険な関数・逆引用符・変数を含むパスの include は、安全と確かめた所だけ',
    '転送' => 'Location の転送は redirect()（is_safe_local_path で外への転送を防ぐ）の中だけ',
];

// ---- ファイルを読む ----

/** 1 つの PHP ファイルを token_get_all で読んだもの */
final class SourceFile
{
    /** @var string リポジトリからの相対パス（区切りは /） */
    public $relativePath = '';
    /** @var array<int, int|string> トークンの種類（空白・コメントは除く。1 文字の記号はその文字） */
    public $tokenIdList = [];
    /** @var array<int, string> トークンの文字 */
    public $tokenTextList = [];
    /** @var array<int, int> トークンの行 */
    public $tokenLineList = [];
    /** @var array<int, int> 括弧の位置 => 対になる括弧の位置 */
    public $pairIndexByIndex = [];
    /** @var array<int, array<string, mixed>> 関数の定義（番号 => 名前・引数・本体の範囲など） */
    public $functionList = [];
    /** @var array<int, int> トークンの位置 => 属する関数の番号（関数の外は -1） */
    public $scopeIdByIndex = [];
    /** @var array<int, bool> 関数の引数の並びの中の位置（引数の既定値の = を代入と見ないため） */
    public $isInParameterListByIndex = [];
    /** @var array<int, array<string, array<int, array<string, mixed>>>> 関数の番号 => 変数名 => 値の出どころの一覧 */
    public $valueSourceListByScopeAndVariable = [];
}

function load_source_file(string $absolutePath, string $relativePath): SourceFile
{
    $sourceFile = new SourceFile();
    $sourceFile->relativePath = $relativePath;
    // PHP 8 では名前空間つきの名前が 1 つのトークンになる。T_STRING とそろえる
    $nameTokenIdList = [T_STRING];
    foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constantName) {
        if (defined($constantName)) {
            $nameTokenIdList[] = constant($constantName);
        }
    }
    $nullsafeOperatorId = defined('T_NULLSAFE_OBJECT_OPERATOR') ? constant('T_NULLSAFE_OBJECT_OPERATOR') : null;
    $currentLineNumber = 1;
    foreach (token_get_all((string) file_get_contents($absolutePath)) as $rawToken) {
        if (is_array($rawToken)) {
            $tokenId = $rawToken[0];
            $tokenText = $rawToken[1];
            $currentLineNumber = $rawToken[2];
        } else {
            $tokenId = $rawToken;
            $tokenText = $rawToken;
        }
        $tokenLineNumber = $currentLineNumber;
        $currentLineNumber += substr_count($tokenText, "\n");
        if ($tokenId === T_WHITESPACE || $tokenId === T_COMMENT || $tokenId === T_DOC_COMMENT || $tokenId === T_NS_SEPARATOR) {
            continue;
        }
        if (in_array($tokenId, $nameTokenIdList, true)) {
            $tokenId = T_STRING;
            $tokenText = ltrim($tokenText, '\\');
        } elseif ($nullsafeOperatorId !== null && $tokenId === $nullsafeOperatorId) {
            $tokenId = T_OBJECT_OPERATOR;
        } elseif (is_int($tokenId) && $tokenText === '&') {
            // PHP 8.1 の「& の後に変数」のトークンも & にそろえる
            $tokenId = '&';
        }
        $sourceFile->tokenIdList[] = $tokenId;
        $sourceFile->tokenTextList[] = $tokenText;
        $sourceFile->tokenLineList[] = $tokenLineNumber;
    }
    $openIndexStack = [];
    foreach ($sourceFile->tokenIdList as $tokenIndex => $tokenId) {
        if (is_opening_bracket($tokenId)) {
            $openIndexStack[] = $tokenIndex;
        } elseif (($tokenId === ')' || $tokenId === ']' || $tokenId === '}') && $openIndexStack) {
            $openIndex = array_pop($openIndexStack);
            $sourceFile->pairIndexByIndex[$openIndex] = $tokenIndex;
            $sourceFile->pairIndexByIndex[$tokenIndex] = $openIndex;
        }
    }
    find_functions($sourceFile);
    return $sourceFile;
}

/** @param int|string $tokenId */
function is_opening_bracket($tokenId): bool
{
    return $tokenId === '(' || $tokenId === '[' || $tokenId === '{' || $tokenId === T_CURLY_OPEN || $tokenId === T_DOLLAR_OPEN_CURLY_BRACES;
}

/** 名前の前のトークンが ->・::・function・new なら、関数の呼び出しではない */
function is_plain_function_call(SourceFile $sourceFile, int $nameIndex): bool
{
    $previousId = $sourceFile->tokenIdList[$nameIndex - 1] ?? null;
    return ($sourceFile->tokenIdList[$nameIndex + 1] ?? null) === '('
        && !in_array($previousId, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true);
}

/** 式の終わり（最後のトークンの位置）。; , 閉じ括弧・閉じタグ・=>・as の手前で終わる */
function expression_end(SourceFile $sourceFile, int $startIndex): int
{
    $tokenCount = count($sourceFile->tokenIdList);
    $cursorIndex = $startIndex;
    while ($cursorIndex < $tokenCount) {
        $tokenId = $sourceFile->tokenIdList[$cursorIndex];
        if (is_opening_bracket($tokenId) && isset($sourceFile->pairIndexByIndex[$cursorIndex])) {
            $cursorIndex = $sourceFile->pairIndexByIndex[$cursorIndex] + 1;
            continue;
        }
        if (in_array($tokenId, [';', ',', ')', ']', '}', T_CLOSE_TAG, T_INLINE_HTML, T_OPEN_TAG, T_DOUBLE_ARROW, T_AS], true)) {
            return $cursorIndex - 1;
        }
        $cursorIndex++;
    }
    return $tokenCount - 1;
}

/** 範囲を、括弧・文字列の外にある区切り（, など）で分ける。[開始, 終わり] の並び */
function split_top_level(SourceFile $sourceFile, int $startIndex, int $endIndex, string $separatorId): array
{
    if ($startIndex > $endIndex) {
        return [];
    }
    $rangeList = [];
    $partStartIndex = $startIndex;
    foreach (top_level_index_list($sourceFile, $startIndex, $endIndex) as $tokenIndex) {
        if ($sourceFile->tokenIdList[$tokenIndex] === $separatorId) {
            $rangeList[] = [$partStartIndex, $tokenIndex - 1];
            $partStartIndex = $tokenIndex + 1;
        }
    }
    $rangeList[] = [$partStartIndex, $endIndex];
    return $rangeList;
}

/** 範囲の中で、括弧の中・文字列の中を飛ばしたトークンの位置（開き括弧・閉じ括弧の位置は入れる） */
function top_level_index_list(SourceFile $sourceFile, int $startIndex, int $endIndex): array
{
    $tokenIdList = $sourceFile->tokenIdList;
    $indexList = [];
    $cursorIndex = $startIndex;
    while ($cursorIndex <= $endIndex) {
        $indexList[] = $cursorIndex;
        $tokenId = $tokenIdList[$cursorIndex];
        $closingIndex = null;
        if (is_opening_bracket($tokenId) && isset($sourceFile->pairIndexByIndex[$cursorIndex])) {
            $closingIndex = $sourceFile->pairIndexByIndex[$cursorIndex];
        } elseif ($tokenId === '"' || $tokenId === '`' || $tokenId === T_START_HEREDOC) {
            $closingId = $tokenId === T_START_HEREDOC ? T_END_HEREDOC : $tokenId;
            for ($searchIndex = $cursorIndex + 1; $searchIndex <= $endIndex; $searchIndex++) {
                if ($tokenIdList[$searchIndex] === $closingId) {
                    $closingIndex = $searchIndex;
                    break;
                }
            }
        }
        if ($closingIndex !== null && $closingIndex <= $endIndex) {
            $indexList[] = $closingIndex;
            $cursorIndex = $closingIndex + 1;
            continue;
        }
        $cursorIndex++;
    }
    return $indexList;
}

/** 範囲の式を、空白を詰めた 1 行の文字列にする（例外の TSV の「式」） */
function expression_text(SourceFile $sourceFile, int $startIndex, int $endIndex): string
{
    $textPartList = [];
    for ($tokenIndex = $startIndex; $tokenIndex <= $endIndex; $tokenIndex++) {
        $textPartList[] = $sourceFile->tokenTextList[$tokenIndex];
    }
    return trim((string) preg_replace('/\s+/u', ' ', implode('', $textPartList)));
}

/** 関数の定義（名前つきの関数・メソッド・クロージャー・アロー関数）を探し、トークンごとに属する関数を決める */
function find_functions(SourceFile $sourceFile): void
{
    $tokenIdList = $sourceFile->tokenIdList;
    $tokenCount = count($tokenIdList);
    foreach ($tokenIdList as $keywordIndex => $tokenId) {
        if ($tokenId !== T_FUNCTION && $tokenId !== T_FN) {
            continue;
        }
        $cursorIndex = $keywordIndex + 1;
        if (($tokenIdList[$cursorIndex] ?? null) === '&') {
            $cursorIndex++;
        }
        $functionName = null;
        if (($tokenIdList[$cursorIndex] ?? null) === T_STRING) {
            $functionName = $sourceFile->tokenTextList[$cursorIndex];
            $cursorIndex++;
        }
        if (($tokenIdList[$cursorIndex] ?? null) !== '(' || !isset($sourceFile->pairIndexByIndex[$cursorIndex])) {
            continue;   // use function など
        }
        $parameterOpenIndex = $cursorIndex;
        $parameterCloseIndex = $sourceFile->pairIndexByIndex[$cursorIndex];
        $parameterNameSet = [];
        $numericParameterNameSet = [];
        $isByReferenceByPosition = [];
        $parameterPosition = 0;
        $isNameFoundInParameter = false;
        $parameterTypeNameList = [];
        foreach (top_level_index_list($sourceFile, $parameterOpenIndex + 1, $parameterCloseIndex - 1) as $parameterTokenIndex) {
            $parameterTokenId = $tokenIdList[$parameterTokenIndex];
            if ($parameterTokenId === ',') {
                $parameterPosition++;
                $isNameFoundInParameter = false;
                $parameterTypeNameList = [];
            } elseif (!$isNameFoundInParameter && in_array($parameterTokenId, [T_STRING, T_ARRAY, T_CALLABLE], true)) {
                $parameterTypeNameList[] = strtolower($sourceFile->tokenTextList[$parameterTokenIndex]);
            } elseif ($parameterTokenId === T_VARIABLE && !$isNameFoundInParameter) {
                $isNameFoundInParameter = true;
                $parameterNameSet[$sourceFile->tokenTextList[$parameterTokenIndex]] = true;
                // 型が int・float・bool（? 付きを含む）の引数は、数・真偽しか入らない
                if ($parameterTypeNameList && !array_diff($parameterTypeNameList, ['int', 'float', 'bool'])) {
                    $numericParameterNameSet[$sourceFile->tokenTextList[$parameterTokenIndex]] = true;
                }
                $previousId = $tokenIdList[$parameterTokenIndex - 1];
                $isByReferenceByPosition[$parameterPosition] = $previousId === '&'
                    || ($previousId === T_ELLIPSIS && $tokenIdList[$parameterTokenIndex - 2] === '&');
            }
        }
        $cursorIndex = $parameterCloseIndex + 1;
        $useNameSet = [];
        $byReferenceUseNameSet = [];
        if (($tokenIdList[$cursorIndex] ?? null) === T_USE && ($tokenIdList[$cursorIndex + 1] ?? null) === '(') {
            $useCloseIndex = $sourceFile->pairIndexByIndex[$cursorIndex + 1];
            for ($useTokenIndex = $cursorIndex + 2; $useTokenIndex < $useCloseIndex; $useTokenIndex++) {
                if ($tokenIdList[$useTokenIndex] === T_VARIABLE) {
                    $useNameSet[$sourceFile->tokenTextList[$useTokenIndex]] = true;
                    if ($tokenIdList[$useTokenIndex - 1] === '&') {
                        $byReferenceUseNameSet[$sourceFile->tokenTextList[$useTokenIndex]] = true;
                    }
                }
            }
            $cursorIndex = $useCloseIndex + 1;
        }
        $isArrowFunction = $tokenId === T_FN;
        if ($isArrowFunction) {
            while ($cursorIndex < $tokenCount && $tokenIdList[$cursorIndex] !== T_DOUBLE_ARROW) {
                $cursorIndex++;
            }
            $bodyStartIndex = $cursorIndex + 1;
            $bodyEndIndex = expression_end($sourceFile, $bodyStartIndex);
        } else {
            while ($cursorIndex < $tokenCount && $tokenIdList[$cursorIndex] !== '{' && $tokenIdList[$cursorIndex] !== ';') {
                $cursorIndex++;
            }
            if (($tokenIdList[$cursorIndex] ?? null) === '{') {
                $bodyStartIndex = $cursorIndex;
                $bodyEndIndex = $sourceFile->pairIndexByIndex[$cursorIndex];
            } else {
                $bodyStartIndex = null;   // 抽象メソッド・インターフェース
                $bodyEndIndex = $cursorIndex;
            }
        }
        $sourceFile->functionList[] = [
            'name' => $functionName,
            'keywordIndex' => $keywordIndex,
            'parameterOpenIndex' => $parameterOpenIndex,
            'parameterCloseIndex' => $parameterCloseIndex,
            'parameterNameSet' => $parameterNameSet,
            'numericParameterNameSet' => $numericParameterNameSet,
            'isByReferenceByPosition' => $isByReferenceByPosition,
            'useNameSet' => $useNameSet,
            'byReferenceUseNameSet' => $byReferenceUseNameSet,
            'bodyStartIndex' => $bodyStartIndex,
            'bodyEndIndex' => $bodyEndIndex,
            'isArrowFunction' => $isArrowFunction,
            'parentScopeId' => -1,
        ];
    }
    $sourceFile->scopeIdByIndex = array_fill(0, $tokenCount, -1);
    // 定義の順（外側が先）に塗るので、内側の関数が上書きする
    foreach ($sourceFile->functionList as $functionId => $functionDefinition) {
        for ($tokenIndex = $functionDefinition['parameterOpenIndex']; $tokenIndex <= $functionDefinition['bodyEndIndex']; $tokenIndex++) {
            $sourceFile->scopeIdByIndex[$tokenIndex] = $functionId;
        }
        for ($tokenIndex = $functionDefinition['parameterOpenIndex']; $tokenIndex <= $functionDefinition['parameterCloseIndex']; $tokenIndex++) {
            $sourceFile->isInParameterListByIndex[$tokenIndex] = true;
        }
    }
    foreach ($sourceFile->functionList as $functionId => $functionDefinition) {
        $sourceFile->functionList[$functionId]['parentScopeId'] = $sourceFile->scopeIdByIndex[$functionDefinition['keywordIndex']];
    }
}

// ---- 変数の値の出どころを集める ----

function add_value_source(SourceFile $sourceFile, int $scopeId, string $variableName, array $valueSource): void
{
    $sourceFile->valueSourceListByScopeAndVariable[$scopeId][$variableName][] = $valueSource;
    // クロージャーが use (&$x) で受け取った変数に書くと、外側の変数も変わる
    while ($scopeId >= 0 && isset($sourceFile->functionList[$scopeId]['byReferenceUseNameSet'][$variableName])) {
        $scopeId = $sourceFile->functionList[$scopeId]['parentScopeId'];
        $sourceFile->valueSourceListByScopeAndVariable[$scopeId][$variableName][] = $valueSource;
    }
}

/**
 * 代入の演算子の左辺の変数（分割代入ならその中の変数すべて。プロパティ・静的プロパティは空）。
 * $x[$key] = … の添え字の範囲は $keyRangeList に入れる（キーも foreach・array_keys で値として出てくるため）。
 */
function assigned_variable_name_list(SourceFile $sourceFile, int $operatorIndex, array &$keyRangeList): array
{
    $tokenIdList = $sourceFile->tokenIdList;
    $cursorIndex = $operatorIndex - 1;
    $keyRangeList = [];
    while ($cursorIndex >= 0) {
        $tokenId = $tokenIdList[$cursorIndex];
        if ($tokenId === ']' && isset($sourceFile->pairIndexByIndex[$cursorIndex])) {
            $openIndex = $sourceFile->pairIndexByIndex[$cursorIndex];
            if (in_array($tokenIdList[$openIndex - 1] ?? null, [T_VARIABLE, ']', '}', T_STRING, ')'], true)) {
                if ($openIndex + 1 <= $cursorIndex - 1) {
                    $keyRangeList[] = [$openIndex + 1, $cursorIndex - 1];
                }
                $cursorIndex = $openIndex - 1;   // $x[…] = の添え字。もとの変数へ戻る
                continue;
            }
            return destructured_variable_name_list($sourceFile, $openIndex, $cursorIndex);
        }
        if ($tokenId === ')' && isset($sourceFile->pairIndexByIndex[$cursorIndex])) {
            $openIndex = $sourceFile->pairIndexByIndex[$cursorIndex];
            return ($tokenIdList[$openIndex - 1] ?? null) === T_LIST ? destructured_variable_name_list($sourceFile, $openIndex, $cursorIndex) : [];
        }
        if ($tokenId === T_VARIABLE) {
            $previousId = $tokenIdList[$cursorIndex - 1] ?? null;
            return ($previousId === T_DOUBLE_COLON || $previousId === '$') ? [] : [$sourceFile->tokenTextList[$cursorIndex]];
        }
        return [];   // -> のあとのプロパティ名など
    }
    return [];
}

/** 分割代入（[$a, 'k' => $b] = …・list(…) = …）の中の変数 */
function destructured_variable_name_list(SourceFile $sourceFile, int $openIndex, int $closeIndex): array
{
    $variableNameList = [];
    for ($tokenIndex = $openIndex + 1; $tokenIndex < $closeIndex; $tokenIndex++) {
        if ($sourceFile->tokenIdList[$tokenIndex] === T_VARIABLE && ($sourceFile->tokenIdList[$tokenIndex + 1] ?? null) !== T_DOUBLE_ARROW) {
            $variableNameList[] = $sourceFile->tokenTextList[$tokenIndex];
        }
    }
    return $variableNameList;
}

/** 引数が「変数そのもの」（$x・$x[…]）なら変数名 */
function bare_variable_name(SourceFile $sourceFile, int $startIndex, int $endIndex): ?string
{
    if ($startIndex > $endIndex || $sourceFile->tokenIdList[$startIndex] !== T_VARIABLE) {
        return null;
    }
    $cursorIndex = $startIndex + 1;
    while ($cursorIndex <= $endIndex && $sourceFile->tokenIdList[$cursorIndex] === '[' && isset($sourceFile->pairIndexByIndex[$cursorIndex])) {
        $cursorIndex = $sourceFile->pairIndexByIndex[$cursorIndex] + 1;
    }
    return $cursorIndex > $endIndex ? $sourceFile->tokenTextList[$startIndex] : null;
}

/**
 * ファイルの中の代入・foreach・参照渡しを集める。
 *
 * @param array<string, array<int, bool>> $isByReferenceByPositionByFunctionName 利用者の関数の参照渡しの引数
 */
function collect_value_sources(SourceFile $sourceFile, array $isByReferenceByPositionByFunctionName): void
{
    $tokenIdList = $sourceFile->tokenIdList;
    $valueAssignmentIdList = ['=', T_CONCAT_EQUAL, T_COALESCE_EQUAL];
    $numericAssignmentIdList = [T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_POW_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL];
    foreach ($tokenIdList as $tokenIndex => $tokenId) {
        $scopeId = $sourceFile->scopeIdByIndex[$tokenIndex];
        $isValueAssignment = in_array($tokenId, $valueAssignmentIdList, true);
        if (($isValueAssignment || in_array($tokenId, $numericAssignmentIdList, true)) && !isset($sourceFile->isInParameterListByIndex[$tokenIndex])) {
            $rightStartIndex = $tokenIndex + 1;
            $rightEndIndex = expression_end($sourceFile, $rightStartIndex);
            $valueSource = $isValueAssignment ? ['kind' => 'expression', 'startIndex' => $rightStartIndex, 'endIndex' => $rightEndIndex] : ['kind' => 'safe'];
            $keyRangeList = [];
            foreach (assigned_variable_name_list($sourceFile, $tokenIndex, $keyRangeList) as $variableName) {
                add_value_source($sourceFile, $scopeId, $variableName, $valueSource);
                foreach ($keyRangeList as $keyRange) {
                    add_value_source($sourceFile, $scopeId, $variableName, ['kind' => 'expression', 'startIndex' => $keyRange[0], 'endIndex' => $keyRange[1]]);
                }
                // $a = &$b: $a に書くと $b も変わる
                if ($tokenId === '=' && ($tokenIdList[$rightStartIndex] ?? null) === '&' && !$keyRangeList) {
                    $aliasedVariableName = bare_variable_name($sourceFile, $rightStartIndex + 1, $rightEndIndex);
                    if ($aliasedVariableName !== null) {
                        add_value_source($sourceFile, $scopeId, $aliasedVariableName, ['kind' => 'alias', 'variableName' => $variableName]);
                    }
                }
            }
            continue;
        }
        if ($tokenId === T_FOREACH && ($tokenIdList[$tokenIndex + 1] ?? null) === '(') {
            $closeIndex = $sourceFile->pairIndexByIndex[$tokenIndex + 1];
            foreach (top_level_index_list($sourceFile, $tokenIndex + 2, $closeIndex - 1) as $headerTokenIndex) {
                if ($tokenIdList[$headerTokenIndex] !== T_AS) {
                    continue;
                }
                // キーも値も、もとの配列から来る（配列のリテラルはキーと値を調べる）
                $valueSource = ['kind' => 'expression', 'startIndex' => $tokenIndex + 2, 'endIndex' => $headerTokenIndex - 1];
                $iteratedVariableName = bare_variable_name($sourceFile, $tokenIndex + 2, $headerTokenIndex - 1);
                for ($targetTokenIndex = $headerTokenIndex + 1; $targetTokenIndex < $closeIndex; $targetTokenIndex++) {
                    if ($tokenIdList[$targetTokenIndex] === T_VARIABLE) {
                        add_value_source($sourceFile, $scopeId, $sourceFile->tokenTextList[$targetTokenIndex], $valueSource);
                        // as &$v: $v に書くと配列の要素が変わる
                        if ($tokenIdList[$targetTokenIndex - 1] === '&' && $iteratedVariableName !== null) {
                            add_value_source($sourceFile, $scopeId, $iteratedVariableName, ['kind' => 'alias', 'variableName' => $sourceFile->tokenTextList[$targetTokenIndex]]);
                        }
                    }
                }
                break;
            }
            continue;
        }
        if ($tokenId === T_GLOBAL) {
            for ($globalTokenIndex = $tokenIndex + 1; ($tokenIdList[$globalTokenIndex] ?? ';') !== ';'; $globalTokenIndex++) {
                if ($tokenIdList[$globalTokenIndex] === T_VARIABLE) {
                    add_value_source($sourceFile, $scopeId, $sourceFile->tokenTextList[$globalTokenIndex], ['kind' => 'unknown', 'reason' => 'global で受け取る値']);
                }
            }
            continue;
        }
        if ($tokenId !== T_STRING || !is_plain_function_call($sourceFile, $tokenIndex)) {
            continue;
        }
        $functionName = strtolower($sourceFile->tokenTextList[$tokenIndex]);
        $unknownPositionList = BY_REFERENCE_UNKNOWN_POSITIONS[$functionName] ?? [];
        foreach ($isByReferenceByPositionByFunctionName[$functionName] ?? [] as $parameterPosition => $isByReference) {
            if ($isByReference) {
                $unknownPositionList[] = $parameterPosition;
            }
        }
        if (!$unknownPositionList && !isset(BY_REFERENCE_APPEND_FUNCTIONS[$functionName])) {
            continue;
        }
        $openIndex = $tokenIndex + 1;
        $argumentRangeList = split_top_level($sourceFile, $openIndex + 1, $sourceFile->pairIndexByIndex[$openIndex] - 1, ',');
        foreach ($unknownPositionList as $parameterPosition) {
            if (!isset($argumentRangeList[$parameterPosition])) {
                continue;
            }
            $variableName = bare_variable_name($sourceFile, $argumentRangeList[$parameterPosition][0], $argumentRangeList[$parameterPosition][1]);
            if ($variableName !== null) {
                add_value_source($sourceFile, $scopeId, $variableName, ['kind' => 'unknown', 'reason' => '参照渡しで書き換わる ' . $variableName . '（' . $functionName . '()・' . $sourceFile->tokenLineList[$tokenIndex] . ' 行）']);
            }
        }
        if (isset(BY_REFERENCE_APPEND_FUNCTIONS[$functionName]) && $argumentRangeList) {
            $variableName = bare_variable_name($sourceFile, $argumentRangeList[0][0], $argumentRangeList[0][1]);
            $firstValuePosition = BY_REFERENCE_APPEND_FUNCTIONS[$functionName];
            foreach ($argumentRangeList as $argumentPosition => $argumentRange) {
                if ($variableName !== null && $argumentPosition >= $firstValuePosition && ($functionName !== 'array_splice' || $argumentPosition === $firstValuePosition)) {
                    add_value_source($sourceFile, $scopeId, $variableName, ['kind' => 'expression', 'startIndex' => $argumentRange[0], 'endIndex' => $argumentRange[1]]);
                }
            }
        }
    }
}

// ---- 式が安全かを調べる ----

/**
 * 式が SQL・HTML の文字として安全かを調べる。返すのは「分からない」理由の並び（空なら安全）。
 * mode は sql（SQL の文）・html（画面の出力）・path（include のパス）。
 */
final class SafetyAnalyzer
{
    /** @var array<string, array<int, string>> 変数・関数ごとの結果 */
    private $reasonListByKey = [];
    /** @var array<string, bool> 調べている途中の変数（循環を打ち切る） */
    private $isInProgressByKey = [];

    public static function isHtmlBuilderName(string $functionName): bool
    {
        $lowerName = strtolower($functionName);
        return in_array($lowerName, HTML_BUILDER_FUNCTION_NAMES, true)
            || substr($lowerName, -5) === '_html' || substr($lowerName, -4) === '_svg';
    }

    public function analyzeRange(SourceFile $sourceFile, int $startIndex, int $endIndex, string $mode): array
    {
        $tokenIdList = $sourceFile->tokenIdList;
        while ($startIndex < $endIndex && $tokenIdList[$startIndex] === '(' && ($sourceFile->pairIndexByIndex[$startIndex] ?? -1) === $endIndex) {
            $startIndex++;
            $endIndex--;
        }
        while ($startIndex <= $endIndex && ($tokenIdList[$startIndex] === '@' || $tokenIdList[$startIndex] === '&')) {
            $startIndex++;
        }
        if ($startIndex > $endIndex) {
            return ['空の式（' . ($sourceFile->tokenLineList[$startIndex] ?? 0) . ' 行）'];
        }
        // 優先順位の低い演算子から見る（PHP 7.4 の優先順位。. と + - は同じ）
        $hasLowLogicalOperator = false;
        $assignmentIndex = null;
        $questionIndex = null;
        $coalesceIndexList = [];
        $hasComparisonOperator = false;
        $concatIndexList = [];
        $hasArithmeticOperator = false;
        $topLevelIndexList = top_level_index_list($sourceFile, $startIndex, $endIndex);
        foreach ($topLevelIndexList as $tokenIndex) {
            $tokenId = $tokenIdList[$tokenIndex];
            if (in_array($tokenId, [T_LOGICAL_OR, T_LOGICAL_AND, T_LOGICAL_XOR], true)) {
                $hasLowLogicalOperator = true;
            } elseif (in_array($tokenId, ['=', T_CONCAT_EQUAL, T_COALESCE_EQUAL], true) && $assignmentIndex === null) {
                $assignmentIndex = $tokenIndex;
            } elseif ($tokenId === '?' && $questionIndex === null) {
                $questionIndex = $tokenIndex;
            } elseif ($tokenId === T_COALESCE) {
                $coalesceIndexList[] = $tokenIndex;
            } elseif (in_array($tokenId, [T_BOOLEAN_OR, T_BOOLEAN_AND, T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL,
                '<', '>', T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP, T_INSTANCEOF, '|', '^', '&', T_SL, T_SR], true)) {
                $hasComparisonOperator = true;
            } elseif ($tokenId === '.') {
                $concatIndexList[] = $tokenIndex;
            } elseif (in_array($tokenId, ['+', '-', '*', '/', '%', T_POW], true)) {
                $hasArithmeticOperator = true;
            }
        }
        if ($hasLowLogicalOperator) {
            return [];   // 真偽
        }
        if ($assignmentIndex !== null) {
            $reasonList = $this->analyzeRange($sourceFile, $assignmentIndex + 1, $endIndex, $mode);
            if ($tokenIdList[$assignmentIndex] !== '=') {
                $reasonList = array_merge($reasonList, $this->analyzeRange($sourceFile, $startIndex, $assignmentIndex - 1, $mode));
            }
            return unique_list($reasonList);
        }
        if ($questionIndex !== null) {
            if ($tokenIdList[$questionIndex + 1] === ':') {
                // 短い三項（a ?: b）は a も値になる
                return unique_list(array_merge($this->analyzeRange($sourceFile, $startIndex, $questionIndex - 1, $mode), $this->analyzeRange($sourceFile, $questionIndex + 2, $endIndex, $mode)));
            }
            $nestedQuestionCount = 0;
            foreach ($topLevelIndexList as $tokenIndex) {
                if ($tokenIndex <= $questionIndex) {
                    continue;
                }
                if ($tokenIdList[$tokenIndex] === '?') {
                    $nestedQuestionCount++;
                } elseif ($tokenIdList[$tokenIndex] === ':') {
                    if ($nestedQuestionCount === 0) {
                        // 条件は値にならないので見ない。どちらの枝も値になる
                        return unique_list(array_merge($this->analyzeRange($sourceFile, $questionIndex + 1, $tokenIndex - 1, $mode), $this->analyzeRange($sourceFile, $tokenIndex + 1, $endIndex, $mode)));
                    }
                    $nestedQuestionCount--;
                }
            }
            return ['式の形を読めない（三項演算子・' . $sourceFile->tokenLineList[$questionIndex] . ' 行）'];
        }
        if ($coalesceIndexList) {
            return $this->analyzeSplitParts($sourceFile, $startIndex, $endIndex, $coalesceIndexList, $mode);
        }
        if ($hasComparisonOperator) {
            return [];   // 真偽・数
        }
        if ($concatIndexList) {
            return $this->analyzeSplitParts($sourceFile, $startIndex, $endIndex, $concatIndexList, $mode);
        }
        if ($hasArithmeticOperator) {
            return [];   // 数
        }
        return $this->analyzePrimary($sourceFile, $startIndex, $endIndex, $mode);
    }

    /** 演算子の位置で分けた各部分を調べる */
    private function analyzeSplitParts(SourceFile $sourceFile, int $startIndex, int $endIndex, array $operatorIndexList, string $mode): array
    {
        $reasonList = [];
        $partStartIndex = $startIndex;
        foreach (array_merge($operatorIndexList, [$endIndex + 1]) as $operatorIndex) {
            $reasonList = array_merge($reasonList, $this->analyzeRange($sourceFile, $partStartIndex, $operatorIndex - 1, $mode));
            $partStartIndex = $operatorIndex + 1;
        }
        return unique_list($reasonList);
    }

    /** 演算子を含まない式（リテラル・変数・関数の呼び出し・配列など）と、その後ろの [ ]・-> */
    private function analyzePrimary(SourceFile $sourceFile, int $startIndex, int $endIndex, string $mode): array
    {
        $tokenIdList = $sourceFile->tokenIdList;
        $tokenId = $tokenIdList[$startIndex];
        $lineNumber = $sourceFile->tokenLineList[$startIndex];
        // 数・真偽になる前置き
        if (in_array($tokenId, [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST, '!', '~', T_ISSET, T_EMPTY, T_PRINT, T_EXIT, T_INC, T_DEC], true)) {
            return [];
        }
        if (in_array($tokenId, [T_STRING_CAST, T_ARRAY_CAST, T_OBJECT_CAST, T_CLONE], true)) {
            return $this->analyzeRange($sourceFile, $startIndex + 1, $endIndex, $mode);
        }
        if ($tokenId === T_NEW) {
            return ['new で作るもの（' . $lineNumber . ' 行）'];
        }
        if ($tokenId === T_FUNCTION || $tokenId === T_FN || $tokenId === T_STATIC) {
            return ['クロージャー（' . $lineNumber . ' 行）'];
        }
        if (in_array($tokenId, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL], true)) {
            return ['include・eval の値（' . $lineNumber . ' 行）'];
        }
        if ($tokenId === '`') {
            return ['逆引用符（' . $lineNumber . ' 行）'];
        }
        // PHP 8 の match 式（PHP 7.4 には字句の定数が無いので、あるときだけ見る）。比べる値と条件は値にならないので、各分岐の結果だけを調べる
        if (defined('T_MATCH') && $tokenId === constant('T_MATCH')) {
            return $this->analyzeMatchArms($sourceFile, $startIndex, $endIndex, $mode);
        }
        if ($startIndex === $endIndex) {
            if (in_array($tokenId, [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_STRING, T_DIR, T_FILE, T_LINE, T_FUNC_C, T_CLASS_C, T_METHOD_C, T_NS_C, T_TRAIT_C], true)) {
                return [];   // 文字列のリテラル・数・定数（true・false・null を含む）
            }
            if ($tokenId === T_VARIABLE) {
                return $this->analyzeVariable($sourceFile, $sourceFile->scopeIdByIndex[$startIndex], $sourceFile->tokenTextList[$startIndex], $mode);
            }
        }
        if (($tokenId === '"' && $tokenIdList[$endIndex] === '"') || ($tokenId === T_START_HEREDOC && $tokenIdList[$endIndex] === T_END_HEREDOC)) {
            return $this->analyzeInterpolation($sourceFile, $startIndex + 1, $endIndex - 1, $mode);
        }
        if ($tokenId === '[' && ($sourceFile->pairIndexByIndex[$startIndex] ?? -1) === $endIndex) {
            return $this->analyzeArrayLiteral($sourceFile, $startIndex + 1, $endIndex - 1, $mode);
        }
        if ($tokenId === T_ARRAY && $tokenIdList[$startIndex + 1] === '(' && ($sourceFile->pairIndexByIndex[$startIndex + 1] ?? -1) === $endIndex) {
            return $this->analyzeArrayLiteral($sourceFile, $startIndex + 2, $endIndex - 1, $mode);
        }
        // もとになる値
        $nextIndex = $startIndex + 1;
        $baseAnalyzer = null;
        if ($tokenId === T_VARIABLE) {
            $variableName = $sourceFile->tokenTextList[$startIndex];
            $scopeId = $sourceFile->scopeIdByIndex[$startIndex];
            if ($tokenIdList[$nextIndex] === '(' && isset($sourceFile->pairIndexByIndex[$nextIndex])) {
                $nextIndex = $sourceFile->pairIndexByIndex[$nextIndex] + 1;
                $baseAnalyzer = function () use ($sourceFile, $scopeId, $variableName, $mode, $lineNumber): array {
                    return $this->analyzeVariableCall($sourceFile, $scopeId, $variableName, $mode, $lineNumber);
                };
            } else {
                $baseAnalyzer = function () use ($sourceFile, $scopeId, $variableName, $mode): array {
                    return $this->analyzeVariable($sourceFile, $scopeId, $variableName, $mode);
                };
            }
        } elseif ($tokenId === T_STRING) {
            $nameText = $sourceFile->tokenTextList[$startIndex];
            if ($tokenIdList[$nextIndex] === '(' && isset($sourceFile->pairIndexByIndex[$nextIndex])) {
                $openIndex = $nextIndex;
                $nextIndex = $sourceFile->pairIndexByIndex[$openIndex] + 1;
                $baseAnalyzer = function () use ($sourceFile, $nameText, $openIndex, $mode): array {
                    return $this->analyzeFunctionCall($sourceFile, $nameText, $openIndex, $mode);
                };
            } elseif ($tokenIdList[$nextIndex] === T_DOUBLE_COLON) {
                $memberId = $tokenIdList[$nextIndex + 1] ?? null;
                if ($memberId === T_STRING && ($tokenIdList[$nextIndex + 2] ?? null) === '(') {
                    return ['静的メソッド ' . $nameText . '::' . $sourceFile->tokenTextList[$nextIndex + 1] . '()（' . $lineNumber . ' 行）'];
                }
                if ($memberId !== T_STRING && $memberId !== T_CLASS) {
                    return ['静的プロパティ ' . $nameText . '::（' . $lineNumber . ' 行）'];
                }
                $nextIndex += 2;
                $baseAnalyzer = function (): array {
                    return [];   // クラスの定数
                };
            } else {
                $baseAnalyzer = function (): array {
                    return [];   // 定数
                };
            }
        } elseif ($tokenId === '(' && isset($sourceFile->pairIndexByIndex[$startIndex])) {
            $closeIndex = $sourceFile->pairIndexByIndex[$startIndex];
            $nextIndex = $closeIndex + 1;
            $baseAnalyzer = function () use ($sourceFile, $startIndex, $closeIndex, $mode): array {
                return $this->analyzeRange($sourceFile, $startIndex + 1, $closeIndex - 1, $mode);
            };
        } elseif ($tokenId === T_CONSTANT_ENCAPSED_STRING) {
            $baseAnalyzer = function (): array {
                return [];
            };
        } else {
            return ['式の形を読めない（' . $sourceFile->tokenTextList[$startIndex] . '・' . $lineNumber . ' 行）'];
        }
        // 後ろに続くもの。添え字はもとの値を調べる
        while ($nextIndex <= $endIndex) {
            $followingId = $tokenIdList[$nextIndex];
            if (($followingId === '[' || $followingId === '{') && isset($sourceFile->pairIndexByIndex[$nextIndex])) {
                $nextIndex = $sourceFile->pairIndexByIndex[$nextIndex] + 1;
                continue;
            }
            if ($followingId === T_OBJECT_OPERATOR) {
                $memberName = $sourceFile->tokenTextList[$nextIndex + 1] ?? '';
                return (($tokenIdList[$nextIndex + 2] ?? null) === '(')
                    ? ['メソッドの呼び出し ->' . $memberName . '()（' . $lineNumber . ' 行）']
                    : ['プロパティ ->' . $memberName . '（' . $lineNumber . ' 行）'];
            }
            if ($followingId === T_INC || $followingId === T_DEC) {
                return [];   // 数
            }
            return ['式の形を読めない（' . expression_text($sourceFile, $startIndex, $endIndex) . '・' . $lineNumber . ' 行）'];
        }
        return $baseAnalyzer();
    }

    /** match 式の各分岐の結果（「条件 => 結果」の => より後ろ）。形が読めなければ報告する */
    private function analyzeMatchArms(SourceFile $sourceFile, int $startIndex, int $endIndex, string $mode): array
    {
        $tokenIdList = $sourceFile->tokenIdList;
        $lineNumber = $sourceFile->tokenLineList[$startIndex];
        $subjectOpenIndex = $startIndex + 1;
        if (($tokenIdList[$subjectOpenIndex] ?? null) !== '(' || !isset($sourceFile->pairIndexByIndex[$subjectOpenIndex])) {
            return ['式の形を読めない（match・' . $lineNumber . ' 行）'];
        }
        $armsOpenIndex = $sourceFile->pairIndexByIndex[$subjectOpenIndex] + 1;
        if (($tokenIdList[$armsOpenIndex] ?? null) !== '{' || ($sourceFile->pairIndexByIndex[$armsOpenIndex] ?? -1) !== $endIndex) {
            return ['式の形を読めない（match・' . $lineNumber . ' 行）'];
        }
        $reasonList = [];
        foreach (split_top_level($sourceFile, $armsOpenIndex + 1, $endIndex - 1, ',') as $armRange) {
            [$armStartIndex, $armEndIndex] = $armRange;
            if ($armStartIndex > $armEndIndex) {
                continue;   // 末尾の ,
            }
            $arrowIndex = null;
            foreach (top_level_index_list($sourceFile, $armStartIndex, $armEndIndex) as $tokenIndex) {
                if ($tokenIdList[$tokenIndex] === T_DOUBLE_ARROW) {
                    $arrowIndex = $tokenIndex;
                    break;
                }
            }
            if ($arrowIndex === null) {
                // 「条件, 条件 => 結果」の前の条件（, で分かれたもの）。値にならない
                continue;
            }
            $reasonList = array_merge($reasonList, $this->analyzeRange($sourceFile, $arrowIndex + 1, $armEndIndex, $mode));
        }
        return unique_list($reasonList);
    }

    /** "…$x…"・heredoc の中の変数・{$式} */
    private function analyzeInterpolation(SourceFile $sourceFile, int $startIndex, int $endIndex, string $mode): array
    {
        $tokenIdList = $sourceFile->tokenIdList;
        $reasonList = [];
        $cursorIndex = $startIndex;
        while ($cursorIndex <= $endIndex) {
            $tokenId = $tokenIdList[$cursorIndex];
            if ($tokenId === T_VARIABLE) {
                $followingId = $tokenIdList[$cursorIndex + 1] ?? null;
                if ($followingId === T_OBJECT_OPERATOR) {
                    $reasonList[] = 'プロパティ ->' . ($sourceFile->tokenTextList[$cursorIndex + 2] ?? '') . '（' . $sourceFile->tokenLineList[$cursorIndex] . ' 行）';
                } else {
                    $reasonList = array_merge($reasonList, $this->analyzeVariable($sourceFile, $sourceFile->scopeIdByIndex[$cursorIndex], $sourceFile->tokenTextList[$cursorIndex], $mode));
                }
                if ($followingId === '[' && isset($sourceFile->pairIndexByIndex[$cursorIndex + 1])) {
                    $cursorIndex = $sourceFile->pairIndexByIndex[$cursorIndex + 1];
                }
            } elseif ($tokenId === T_CURLY_OPEN && isset($sourceFile->pairIndexByIndex[$cursorIndex])) {
                $closeIndex = $sourceFile->pairIndexByIndex[$cursorIndex];
                $reasonList = array_merge($reasonList, $this->analyzeRange($sourceFile, $cursorIndex + 1, $closeIndex - 1, $mode));
                $cursorIndex = $closeIndex;
            } elseif ($tokenId === T_DOLLAR_OPEN_CURLY_BRACES && isset($sourceFile->pairIndexByIndex[$cursorIndex])) {
                $closeIndex = $sourceFile->pairIndexByIndex[$cursorIndex];
                if ($tokenIdList[$cursorIndex + 1] === T_STRING_VARNAME && $closeIndex === $cursorIndex + 2) {
                    $reasonList = array_merge($reasonList, $this->analyzeVariable($sourceFile, $sourceFile->scopeIdByIndex[$cursorIndex], '$' . $sourceFile->tokenTextList[$cursorIndex + 1], $mode));
                } else {
                    $reasonList[] = '式の形を読めない（${…}・' . $sourceFile->tokenLineList[$cursorIndex] . ' 行）';
                }
                $cursorIndex = $closeIndex;
            }
            $cursorIndex++;
        }
        return unique_list($reasonList);
    }

    /** 配列のリテラルの要素（キーと値） */
    private function analyzeArrayLiteral(SourceFile $sourceFile, int $startIndex, int $endIndex, string $mode): array
    {
        $reasonList = [];
        foreach (split_top_level($sourceFile, $startIndex, $endIndex, ',') as $elementRange) {
            [$elementStartIndex, $elementEndIndex] = $elementRange;
            if ($elementStartIndex > $elementEndIndex) {
                continue;   // 末尾の ,
            }
            if ($sourceFile->tokenIdList[$elementStartIndex] === T_ELLIPSIS) {
                $elementStartIndex++;
            }
            $arrowIndex = null;
            foreach (top_level_index_list($sourceFile, $elementStartIndex, $elementEndIndex) as $tokenIndex) {
                if ($sourceFile->tokenIdList[$tokenIndex] === T_DOUBLE_ARROW) {
                    $arrowIndex = $tokenIndex;
                    break;
                }
            }
            if ($arrowIndex === null) {
                $reasonList = array_merge($reasonList, $this->analyzeRange($sourceFile, $elementStartIndex, $elementEndIndex, $mode));
            } else {
                $reasonList = array_merge($reasonList,
                    $this->analyzeRange($sourceFile, $elementStartIndex, $arrowIndex - 1, $mode),
                    $this->analyzeRange($sourceFile, $arrowIndex + 1, $elementEndIndex, $mode));
            }
        }
        return unique_list($reasonList);
    }

    private function analyzeFunctionCall(SourceFile $sourceFile, string $functionName, int $openIndex, string $mode): array
    {
        $lowerName = strtolower($functionName);
        $closeIndex = $sourceFile->pairIndexByIndex[$openIndex];
        $argumentRangeList = split_top_level($sourceFile, $openIndex + 1, $closeIndex - 1, ',');
        $lineNumber = $sourceFile->tokenLineList[$openIndex];
        if ($mode === 'html') {
            // h() は htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') を返す（app/lib/html.php。
            // 中身は目で確かめた。定義が変わっていないかは check_escape_function で毎回確かめる）。
            // 引用符で囲んだ属性の値・要素の中身のどちらに出しても、< > & " ' が文字参照になる
            if ($lowerName === 'h') {
                return [];
            }
            // htmlspecialchars を直に呼ぶときは、ENT_QUOTES を渡しているときだけ安全（PHP 7.4 の既定は ' を変えない）
            if ($lowerName === 'htmlspecialchars' && isset($argumentRangeList[1])
                && strpos(expression_text($sourceFile, $argumentRangeList[1][0], $argumentRangeList[1][1]), 'ENT_QUOTES') !== false) {
                return [];
            }
            if (self::isHtmlBuilderName($lowerName)) {
                return [];   // 定義の return の式を別に点検する
            }
        }
        if (in_array($lowerName, ALWAYS_SAFE_FUNCTION_NAMES, true)) {
            return [];
        }
        if ($lowerName === 'array_map' && $argumentRangeList) {
            [$callbackStartIndex, $callbackEndIndex] = $argumentRangeList[0];
            $callbackText = trim($sourceFile->tokenTextList[$callbackStartIndex], '\'"');
            if ($callbackStartIndex === $callbackEndIndex && $sourceFile->tokenIdList[$callbackStartIndex] === T_CONSTANT_ENCAPSED_STRING
                && in_array(strtolower($callbackText), ALWAYS_SAFE_FUNCTION_NAMES, true)) {
                return [];
            }
            return ['array_map のコールバック（' . $lineNumber . ' 行）'];
        }
        if (in_array($lowerName, FIRST_ARGUMENT_FUNCTION_NAMES, true)) {
            $argumentRangeList = array_slice($argumentRangeList, 0, 1);
        } elseif (!in_array($lowerName, PASS_THROUGH_FUNCTION_NAMES, true)) {
            return ['関数 ' . $functionName . '()（' . $lineNumber . ' 行）'];
        }
        $reasonList = [];
        foreach ($argumentRangeList as $argumentRange) {
            if ($argumentRange[0] <= $argumentRange[1]) {
                $reasonList = array_merge($reasonList, $this->analyzeRange($sourceFile, $argumentRange[0], $argumentRange[1], $mode));
            }
        }
        return unique_list($reasonList);
    }

    /** 変数の値の出どころをすべて調べる */
    public function analyzeVariable(SourceFile $sourceFile, int $scopeId, string $variableName, string $mode): array
    {
        if (in_array($variableName, SUPERGLOBAL_NAMES, true)) {
            return ['スーパーグローバル ' . $variableName];
        }
        if ($variableName === '$this') {
            return ['$this（オブジェクト）'];
        }
        $memoKey = $mode . '|' . $sourceFile->relativePath . '|' . $scopeId . '|' . $variableName;
        if (isset($this->reasonListByKey[$memoKey])) {
            return $this->reasonListByKey[$memoKey];
        }
        if (isset($this->isInProgressByKey[$memoKey])) {
            return [];   // 循環。ここで打ち切る（ほかの出どころは呼んだ側で調べる）
        }
        $this->isInProgressByKey[$memoKey] = true;
        $reasonList = [];
        $isFoundOutside = false;
        $valueSourceList = $sourceFile->valueSourceListByScopeAndVariable[$scopeId][$variableName] ?? [];
        if ($scopeId >= 0) {
            $functionDefinition = $sourceFile->functionList[$scopeId];
            if (isset($functionDefinition['numericParameterNameSet'][$variableName])) {
                $isFoundOutside = true;   // 型が int・float・bool の引数
            } elseif (isset($functionDefinition['parameterNameSet'][$variableName])) {
                $reasonList[] = '関数の引数 ' . $variableName . '（' . ($functionDefinition['name'] ?? 'クロージャー') . '・' . $sourceFile->tokenLineList[$functionDefinition['keywordIndex']] . ' 行）';
                $isFoundOutside = true;
            } elseif (isset($functionDefinition['useNameSet'][$variableName]) || ($functionDefinition['isArrowFunction'] && !$valueSourceList)) {
                // クロージャーの use・アロー関数が取り込む変数は、外側で調べる
                $reasonList = $this->analyzeVariable($sourceFile, $functionDefinition['parentScopeId'], $variableName, $mode);
                $isFoundOutside = true;
            }
        }
        if (!$valueSourceList && !$isFoundOutside) {
            $reasonList[] = '代入が見つからない ' . $variableName;
        }
        foreach ($valueSourceList as $valueSource) {
            if ($valueSource['kind'] === 'expression') {
                $reasonList = array_merge($reasonList, $this->analyzeRange($sourceFile, $valueSource['startIndex'], $valueSource['endIndex'], $mode));
            } elseif ($valueSource['kind'] === 'alias') {
                $reasonList = array_merge($reasonList, $this->analyzeVariable($sourceFile, $scopeId, $valueSource['variableName'], $mode));
            } elseif ($valueSource['kind'] === 'unknown') {
                $reasonList[] = $valueSource['reason'];
            }
        }
        unset($this->isInProgressByKey[$memoKey]);
        $reasonList = unique_list($reasonList);
        $this->reasonListByKey[$memoKey] = $reasonList;
        return $reasonList;
    }

    /** $fn(...) の呼び出し。$fn にクロージャーだけが入るなら、その return の式を調べる */
    private function analyzeVariableCall(SourceFile $sourceFile, int $scopeId, string $variableName, string $mode, int $lineNumber): array
    {
        $lookupScopeId = $scopeId;
        while ($lookupScopeId >= 0 && !isset($sourceFile->valueSourceListByScopeAndVariable[$lookupScopeId][$variableName])
            && isset($sourceFile->functionList[$lookupScopeId]['useNameSet'][$variableName])) {
            $lookupScopeId = $sourceFile->functionList[$lookupScopeId]['parentScopeId'];
        }
        $valueSourceList = $sourceFile->valueSourceListByScopeAndVariable[$lookupScopeId][$variableName] ?? [];
        $unknownReasonList = ['変数の関数の呼び出し ' . $variableName . '()（' . $lineNumber . ' 行）'];
        if (!$valueSourceList) {
            return $unknownReasonList;
        }
        $reasonList = [];
        foreach ($valueSourceList as $valueSource) {
            if ($valueSource['kind'] !== 'expression') {
                return $unknownReasonList;
            }
            $keywordIndex = $valueSource['startIndex'];
            if ($sourceFile->tokenIdList[$keywordIndex] === T_STATIC) {
                $keywordIndex++;
            }
            $closureId = null;
            foreach ($sourceFile->functionList as $functionId => $functionDefinition) {
                if ($functionDefinition['keywordIndex'] === $keywordIndex && $functionDefinition['bodyEndIndex'] === $valueSource['endIndex']) {
                    $closureId = $functionId;
                }
            }
            if ($closureId === null) {
                return $unknownReasonList;
            }
            $reasonList = array_merge($reasonList, $this->analyzeFunctionReturns($sourceFile, $closureId, $mode));
        }
        return unique_list($reasonList);
    }

    /** 関数の return の式（アロー関数は本体の式）をすべて調べる */
    public function analyzeFunctionReturns(SourceFile $sourceFile, int $functionId, string $mode): array
    {
        $reasonList = [];
        foreach (self::returnRangeList($sourceFile, $functionId) as $returnRange) {
            $reasonList = array_merge($reasonList, $this->analyzeRange($sourceFile, $returnRange[0], $returnRange[1], $mode));
        }
        return unique_list($reasonList);
    }

    /** 関数の return の式の範囲（値のない return は除く） */
    public static function returnRangeList(SourceFile $sourceFile, int $functionId): array
    {
        $functionDefinition = $sourceFile->functionList[$functionId];
        if ($functionDefinition['isArrowFunction']) {
            return [[$functionDefinition['bodyStartIndex'], $functionDefinition['bodyEndIndex']]];
        }
        $rangeList = [];
        if ($functionDefinition['bodyStartIndex'] === null) {
            return $rangeList;
        }
        for ($tokenIndex = $functionDefinition['bodyStartIndex']; $tokenIndex < $functionDefinition['bodyEndIndex']; $tokenIndex++) {
            if ($sourceFile->tokenIdList[$tokenIndex] === T_RETURN && $sourceFile->scopeIdByIndex[$tokenIndex] === $functionId) {
                $returnEndIndex = expression_end($sourceFile, $tokenIndex + 1);
                if ($returnEndIndex >= $tokenIndex + 1) {
                    $rangeList[] = [$tokenIndex + 1, $returnEndIndex];
                }
            }
        }
        return $rangeList;
    }
}

function unique_list(array $textList): array
{
    return array_values(array_unique($textList));
}

// ---- 点検する箇所を集める ----

/**
 * 1 つのファイルの点検する箇所。各箇所は 種類・ファイル・行・式・分からない理由（空なら自動で安全）。
 */
function collect_check_sites(SourceFile $sourceFile, SafetyAnalyzer $analyzer): array
{
    $siteList = [];
    $tokenIdList = $sourceFile->tokenIdList;
    $relativePath = $sourceFile->relativePath;
    $isCommandTool = strpos($relativePath, 'app/bin/') === 0;
    $addSite = function (string $checkKind, int $startIndex, int $endIndex, array $reasonList, ?string $expressionText = null) use (&$siteList, $sourceFile): void {
        $siteList[] = [
            'kind' => $checkKind,
            'path' => $sourceFile->relativePath,
            'line' => $sourceFile->tokenLineList[$startIndex],
            'expression' => $expressionText ?? expression_text($sourceFile, $startIndex, $endIndex),
            'reasonList' => $reasonList,
        ];
    };
    $functionNameAt = function (int $tokenIndex) use ($sourceFile): ?string {
        $scopeId = $sourceFile->scopeIdByIndex[$tokenIndex];
        return $scopeId >= 0 ? $sourceFile->functionList[$scopeId]['name'] : null;
    };
    $isInsideBacktick = false;
    foreach ($tokenIdList as $tokenIndex => $tokenId) {
        // ---- SQL ----
        $sqlOpenIndex = null;
        if ($tokenId === T_STRING && in_array($sourceFile->tokenTextList[$tokenIndex], SQL_WRAPPER_FUNCTION_NAMES, true) && is_plain_function_call($sourceFile, $tokenIndex)) {
            $sqlOpenIndex = $tokenIndex + 1;
        } elseif ($tokenId === T_OBJECT_OPERATOR && ($tokenIdList[$tokenIndex + 1] ?? null) === T_STRING
            && in_array(strtolower($sourceFile->tokenTextList[$tokenIndex + 1]), SQL_METHOD_NAMES, true) && ($tokenIdList[$tokenIndex + 2] ?? null) === '(') {
            $sqlOpenIndex = $tokenIndex + 2;
        }
        // db_one などの定義の中（受け取った SQL を prepare する所）は、呼ぶ側で点検するので見ない
        if ($sqlOpenIndex !== null && !($relativePath === 'app/lib/db.php' && in_array($functionNameAt($tokenIndex), SQL_WRAPPER_FUNCTION_NAMES, true))) {
            $argumentRangeList = split_top_level($sourceFile, $sqlOpenIndex + 1, $sourceFile->pairIndexByIndex[$sqlOpenIndex] - 1, ',');
            if (!$argumentRangeList) {
                $addSite('SQL', $tokenIndex, $sqlOpenIndex, ['引数がない']);
            } else {
                [$sqlStartIndex, $sqlEndIndex] = $argumentRangeList[0];
                $addSite('SQL', $sqlStartIndex, $sqlEndIndex, $analyzer->analyzeRange($sourceFile, $sqlStartIndex, $sqlEndIndex, 'sql'));
            }
        }
        // ---- 画面の出力 ----
        if ($tokenId === T_OPEN_TAG_WITH_ECHO || ($tokenId === T_ECHO && !$isCommandTool)) {
            // 出す式は ; か閉じタグまで（括弧の中は飛ばす）
            $outputEndIndex = $tokenIndex;
            $tokenCount = count($tokenIdList);
            while ($outputEndIndex + 1 < $tokenCount && !in_array($tokenIdList[$outputEndIndex + 1], [';', T_CLOSE_TAG, T_INLINE_HTML, T_OPEN_TAG], true)) {
                $outputEndIndex++;
                if (is_opening_bracket($tokenIdList[$outputEndIndex]) && isset($sourceFile->pairIndexByIndex[$outputEndIndex])) {
                    $outputEndIndex = $sourceFile->pairIndexByIndex[$outputEndIndex];
                }
            }
            foreach (split_top_level($sourceFile, $tokenIndex + 1, $outputEndIndex, ',') as $outputRange) {
                $addSite('XSS', $outputRange[0], $outputRange[1], $analyzer->analyzeRange($sourceFile, $outputRange[0], $outputRange[1], 'html'));
            }
            if ($tokenId === T_OPEN_TAG_WITH_ECHO) {
                $isUnquotedAttribute = ($tokenIdList[$tokenIndex - 1] ?? null) === T_INLINE_HTML
                    && substr(rtrim($sourceFile->tokenTextList[$tokenIndex - 1]), -1) === '=';
                $addSite('属性', $tokenIndex + 1, $outputEndIndex, $isUnquotedAttribute ? ['属性の値を引用符で囲んでいない（h() でも空白や > で属性の外に出られる）'] : []);
            }
        }
        if ($tokenId === T_PRINT && !$isCommandTool) {
            $printEndIndex = expression_end($sourceFile, $tokenIndex + 1);
            $addSite('XSS', $tokenIndex + 1, $printEndIndex, $analyzer->analyzeRange($sourceFile, $tokenIndex + 1, $printEndIndex, 'html'));
        }
        // ---- 危険な関数・逆引用符・include ----
        if ($tokenId === T_EVAL) {
            $addSite('危険', $tokenIndex, expression_end($sourceFile, $tokenIndex + 1), ['eval（文字列をコードとして動かす）']);
        }
        if ($tokenId === T_STRING && in_array(strtolower($sourceFile->tokenTextList[$tokenIndex]), DANGEROUS_FUNCTION_NAMES, true) && is_plain_function_call($sourceFile, $tokenIndex)) {
            $addSite('危険', $tokenIndex, $sourceFile->pairIndexByIndex[$tokenIndex + 1], ['危険な関数 ' . $sourceFile->tokenTextList[$tokenIndex] . '()']);
        }
        if ($tokenId === '`') {
            $isInsideBacktick = !$isInsideBacktick;   // 開きの逆引用符だけ数える
            if ($isInsideBacktick) {
                $addSite('危険', $tokenIndex, $tokenIndex, ['逆引用符（シェルのコマンドを動かす）']);
            }
        }
        if (in_array($tokenId, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
            $pathEndIndex = expression_end($sourceFile, $tokenIndex + 1);
            $hasVariable = false;
            for ($pathTokenIndex = $tokenIndex + 1; $pathTokenIndex <= $pathEndIndex; $pathTokenIndex++) {
                $hasVariable = $hasVariable || $tokenIdList[$pathTokenIndex] === T_VARIABLE;
            }
            // 変数を含まないパスは固定。含むときは、変数が固定の値だけから来るかを調べる
            $addSite('危険', $tokenIndex, $pathEndIndex, $hasVariable ? $analyzer->analyzeRange($sourceFile, $tokenIndex + 1, $pathEndIndex, 'path') : []);
        }
        // ---- 転送 ----
        if ($tokenId === T_STRING && strtolower($sourceFile->tokenTextList[$tokenIndex]) === 'header' && is_plain_function_call($sourceFile, $tokenIndex)) {
            $closeIndex = $sourceFile->pairIndexByIndex[$tokenIndex + 1];
            $headerText = ltrim(expression_text($sourceFile, $tokenIndex + 2, $closeIndex - 1), '\'"');
            if (stripos($headerText, 'location') === 0) {
                $isInRedirect = $relativePath === 'app/lib/util.php' && $functionNameAt($tokenIndex) === 'redirect';
                $addSite('転送', $tokenIndex, $closeIndex, $isInRedirect ? [] : ['redirect() の外で Location を出している（外のサイトへ転送されないかを確かめていない）']);
            }
        }
    }
    // ---- HTML を組み立てて返す関数の定義の return の式 ----
    foreach ($sourceFile->functionList as $functionId => $functionDefinition) {
        if ($functionDefinition['name'] === null || !SafetyAnalyzer::isHtmlBuilderName($functionDefinition['name'])) {
            continue;
        }
        foreach (SafetyAnalyzer::returnRangeList($sourceFile, $functionId) as $returnRange) {
            $addSite('XSS', $returnRange[0], $returnRange[1], $analyzer->analyzeRange($sourceFile, $returnRange[0], $returnRange[1], 'html'),
                $functionDefinition['name'] . '(): return ' . expression_text($sourceFile, $returnRange[0], $returnRange[1]));
        }
    }
    return $siteList;
}

/** h() の定義が htmlspecialchars に ENT_QUOTES を渡しているか（app/lib/html.php） */
function check_escape_function(array $sourceFileByPath): bool
{
    $htmlLibraryFile = $sourceFileByPath['app/lib/html.php'] ?? null;
    if ($htmlLibraryFile === null) {
        return false;
    }
    foreach ($htmlLibraryFile->functionList as $functionDefinition) {
        if ($functionDefinition['name'] !== 'h' || $functionDefinition['bodyStartIndex'] === null) {
            continue;
        }
        $bodyText = expression_text($htmlLibraryFile, $functionDefinition['bodyStartIndex'], $functionDefinition['bodyEndIndex']);
        return strpos($bodyText, 'returnhtmlspecialchars(') !== false && strpos($bodyText, 'ENT_QUOTES') !== false;
    }
    return false;
}

// ---- 例外の TSV ----

/** 例外の行を読む。キー（種類・ファイル・式）=> [行番号, 理由]。形の違う行は $problemList に入れる */
function load_exceptions(string $exceptionFilePath, array &$problemList): array
{
    $exceptionByKey = [];
    if (!is_file($exceptionFilePath)) {
        return $exceptionByKey;
    }
    foreach (file($exceptionFilePath, FILE_IGNORE_NEW_LINES) ?: [] as $lineIndex => $lineText) {
        $lineText = rtrim($lineText, "\r");
        if (trim($lineText) === '' || $lineText[0] === '#') {
            continue;
        }
        $fieldList = explode("\t", $lineText);
        if (count($fieldList) !== 4 || !isset(CHECK_KIND_SUMMARIES[$fieldList[0]]) || trim($fieldList[3]) === '') {
            $problemList[] = '例外の行の形が違う（種類<TAB>ファイル<TAB>式<TAB>理由）: ' . ($lineIndex + 1) . ' 行';
            continue;
        }
        $exceptionKey = $fieldList[0] . "\t" . $fieldList[1] . "\t" . $fieldList[2];
        if (isset($exceptionByKey[$exceptionKey])) {
            $problemList[] = '例外の行が重なっている: ' . ($lineIndex + 1) . ' 行';
        }
        $exceptionByKey[$exceptionKey] = ['lineNumber' => $lineIndex + 1, 'reason' => $fieldList[3]];
    }
    return $exceptionByKey;
}

// ---- 実行 ----

$sourceFileByPath = [];
foreach (['app', 'public'] as $topDirectoryName) {
    $directoryIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repositoryRoot . '/' . $topDirectoryName, FilesystemIterator::SKIP_DOTS));
    foreach ($directoryIterator as $fileInfo) {
        $absolutePath = (string) $fileInfo;
        if (substr($absolutePath, -4) !== '.php') {
            continue;
        }
        $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($repositoryRoot) + 1));
        if ($relativePath === 'app/config/config.local.php' || preg_match('~\Aapp/(data|logs|backups)/~', $relativePath)) {
            continue;
        }
        $sourceFileByPath[$relativePath] = load_source_file($absolutePath, $relativePath);
    }
}
ksort($sourceFileByPath);

// 利用者の関数の参照渡しの引数（呼んだ側の変数が書き換わる）
$isByReferenceByPositionByFunctionName = [];
foreach ($sourceFileByPath as $sourceFile) {
    foreach ($sourceFile->functionList as $functionDefinition) {
        if ($functionDefinition['name'] !== null && in_array(true, $functionDefinition['isByReferenceByPosition'], true)) {
            $isByReferenceByPositionByFunctionName[strtolower($functionDefinition['name'])] = $functionDefinition['isByReferenceByPosition'];
        }
    }
}
foreach ($sourceFileByPath as $sourceFile) {
    collect_value_sources($sourceFile, $isByReferenceByPositionByFunctionName);
}

$analyzer = new SafetyAnalyzer();
$siteList = [];
foreach ($sourceFileByPath as $sourceFile) {
    $siteList = array_merge($siteList, collect_check_sites($sourceFile, $analyzer));
}

$problemList = [];
$exceptionByKey = load_exceptions($exceptionFilePath, $problemList);
$usedCountByExceptionKey = [];
$countByKind = [];
$unresolvedSiteListByKind = [];
foreach (array_keys(CHECK_KIND_SUMMARIES) as $checkKind) {
    $countByKind[$checkKind] = ['checked' => 0, 'automatic' => 0, 'excepted' => 0];
    $unresolvedSiteListByKind[$checkKind] = [];
}
foreach ($siteList as $site) {
    $countByKind[$site['kind']]['checked']++;
    if (!$site['reasonList']) {
        $countByKind[$site['kind']]['automatic']++;
        continue;
    }
    $exceptionKey = $site['kind'] . "\t" . $site['path'] . "\t" . $site['expression'];
    if (isset($exceptionByKey[$exceptionKey])) {
        $countByKind[$site['kind']]['excepted']++;
        $usedCountByExceptionKey[$exceptionKey] = ($usedCountByExceptionKey[$exceptionKey] ?? 0) + 1;
        continue;
    }
    $unresolvedSiteListByKind[$site['kind']][] = $site;
}

if ($isDraftMode) {
    // 同じファイルの同じ式は 1 行にまとめる
    echo "# 仕分けていない箇所の、例外の行の下書き（理由は、コードを読んで安全と確かめてから書く。危ないものは例外に入れない）\n";
    foreach ($unresolvedSiteListByKind as $checkKind => $unresolvedSiteList) {
        $siteListByKey = [];
        foreach ($unresolvedSiteList as $site) {
            $siteListByKey[$checkKind . "\t" . $site['path'] . "\t" . $site['expression']][] = $site;
        }
        foreach ($siteListByKey as $exceptionKey => $sameSiteList) {
            $lineNumberList = array_map(function (array $site): int { return $site['line']; }, $sameSiteList);
            echo '# ' . $sameSiteList[0]['path'] . ':' . implode(',', $lineNumberList) . '  ' . implode(' / ', $sameSiteList[0]['reasonList']) . "\n";
            echo $exceptionKey . "\t（なぜ安全かを書く）\n";
        }
    }
    exit(0);
}

$ngCount = 0;
$isEscapeFunctionOk = check_escape_function($sourceFileByPath);
echo ($isEscapeFunctionOk ? 'OK  ' : 'NG  ') . '[コード] h() は htmlspecialchars に ENT_QUOTES を渡して書き出す（app/lib/html.php）' . "\n";
$ngCount += $isEscapeFunctionOk ? 0 : 1;
foreach (HTML_BUILDER_FUNCTION_NAMES as $builderName) {
    $isDefined = false;
    foreach ($sourceFileByPath as $sourceFile) {
        foreach ($sourceFile->functionList as $functionDefinition) {
            $isDefined = $isDefined || $functionDefinition['name'] === $builderName;
        }
    }
    if (!$isDefined) {
        echo 'NG  [コード] HTML を組み立てて返す関数の一覧にある ' . $builderName . '() の定義が見つからない' . "\n";
        $ngCount++;
    }
}
foreach (CHECK_KIND_SUMMARIES as $checkKind => $summaryText) {
    foreach ($unresolvedSiteListByKind[$checkKind] as $site) {
        echo 'NG  [コード] ' . $checkKind . ': ' . $site['path'] . ':' . $site['line'] . '  ' . $site['expression'] . '  … ' . implode(' / ', $site['reasonList']) . "\n";
        $ngCount++;
    }
    $counts = $countByKind[$checkKind];
    $countText = '（点検 ' . $counts['checked'] . ' か所: 自動で安全 ' . $counts['automatic'] . '・仕分け済み ' . $counts['excepted'] . '）';
    if ($unresolvedSiteListByKind[$checkKind]) {
        echo 'NG  [コード] ' . $checkKind . ': 仕分けていない箇所が ' . count($unresolvedSiteListByKind[$checkKind]) . ' か所ある' . $countText . "\n";
        $ngCount++;
    } else {
        echo 'OK  [コード] ' . $checkKind . ': ' . $summaryText . $countText . "\n";
    }
}
foreach ($exceptionByKey as $exceptionKey => $exception) {
    if (!isset($usedCountByExceptionKey[$exceptionKey])) {
        $problemList[] = '例外の行がどの箇所にも当たらない（古い例外は消す）: ' . $exception['lineNumber'] . ' 行  ' . str_replace("\t", ' | ', $exceptionKey);
    }
}
foreach ($problemList as $problemText) {
    echo 'NG  [コード] ' . $problemText . "\n";
    $ngCount++;
}
if (!$problemList) {
    echo 'OK  [コード] 例外の行（' . count($exceptionByKey) . ' 行）は、どれも点検した箇所に当たる' . "\n";
}
exit($ngCount > 0 ? 1 : 0);
