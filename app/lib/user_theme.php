<?php

declare(strict_types=1);

// ====================================================================
// 自分の色（G-16 §4.6。K-046）: 土台（明るい／暗い）・強調の色・背景の色を利用者が選ぶ。
// 注意・エラー・状態の札の色は土台の決まった色のまま（色の意味を変えない）。
// 文字が読みにくい組み合わせは、保存はできるが注意を出す（明るさの比を確かめる。K-046 決定）。
// 色は /css/user_theme.php（その人の色の決まり）で渡す（CSP のため画面の中に色を書かない）。
// ====================================================================

/** 自分の色の既定（初めて選んだとき） */
const USER_THEME_DEFAULT_COLORS = ['base' => 'light', 'accent' => '#1f6feb', 'background' => '#f5f6f8'];

/** 土台ごとの決まった色（面・文字。明るさの比の確かめに使う。public/css/app.css の既定・ダークと同じ値） */
const USER_THEME_BASE_COLORS = [
    'light' => ['card' => '#ffffff', 'text' => '#1f2328'],
    'dark'  => ['card' => '#161b22', 'text' => '#e6edf3'],
];

/**
 * 利用者の自分の色（保存してあれば）。形が違えば既定。
 *
 * @return array{base: string, accent: string, background: string}
 */
function user_theme_colors(array $user): array
{
    $storedColors = json_decode((string) ($user['ui_custom_colors'] ?? ''), true);
    if (!is_array($storedColors)) {
        return USER_THEME_DEFAULT_COLORS;
    }
    $colors = USER_THEME_DEFAULT_COLORS;
    if (isset($storedColors['base']) && isset(USER_THEME_BASE_COLORS[$storedColors['base']])) {
        $colors['base'] = (string) $storedColors['base'];
    }
    foreach (['accent', 'background'] as $colorName) {
        if (isset($storedColors[$colorName]) && user_theme_is_hex_color((string) $storedColors[$colorName])) {
            $colors[$colorName] = strtolower((string) $storedColors[$colorName]);
        }
    }
    return $colors;
}

function user_theme_is_hex_color(string $colorText): bool
{
    // 「#」と 16 進数 6 桁（# を \x23 と書くのは、PHP 7.4 の書き方の確かめで PHP 8 の属性と紛れないため）
    return preg_match('/^\x23[0-9a-fA-F]{6}$/', $colorText) === 1;
}

/** 色の相対的な明るさ（WCAG 2.x の式） */
function user_theme_relative_luminance(string $hexColor): float
{
    $channelValueList = [];
    foreach ([1, 3, 5] as $offset) {
        $channelRatio = hexdec(substr($hexColor, $offset, 2)) / 255;
        $channelValueList[] = $channelRatio <= 0.03928 ? $channelRatio / 12.92 : (($channelRatio + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $channelValueList[0] + 0.7152 * $channelValueList[1] + 0.0722 * $channelValueList[2];
}

/** 2 つの色の明るさの比（1〜21。文字は 4.5 以上、大きな文字・部品は 3 以上が読みやすさの目安） */
function user_theme_contrast_ratio(string $firstHexColor, string $secondHexColor): float
{
    $firstLuminance = user_theme_relative_luminance($firstHexColor);
    $secondLuminance = user_theme_relative_luminance($secondHexColor);
    return (max($firstLuminance, $secondLuminance) + 0.05) / (min($firstLuminance, $secondLuminance) + 0.05);
}

/** 強調の色の上に載せる文字の色（白か黒の、読みやすい方） */
function user_theme_text_on(string $hexColor): string
{
    return user_theme_contrast_ratio($hexColor, '#ffffff') >= user_theme_contrast_ratio($hexColor, '#000000') ? '#ffffff' : '#000000';
}

/**
 * 選んだ色の形を確かめる（土台の値、#rrggbb の形）。誤りがあれば保存しない。
 *
 * @param array{base: string, accent: string, background: string} $colors
 * @return string[] 誤りの文
 */
function user_theme_format_errors(array $colors): array
{
    $errorTextList = [];
    if (!isset(USER_THEME_BASE_COLORS[$colors['base']])) {
        $errorTextList[] = msg('土台は「明るい」か「暗い」を選んでください');
        return $errorTextList;
    }
    foreach (['accent' => msg('強調の色'), 'background' => msg('背景の色')] as $colorName => $colorLabel) {
        if (!user_theme_is_hex_color($colors[$colorName])) {
            $errorTextList[] = msg('{label}が正しくありません', ['label' => $colorLabel]);
        }
    }
    return $errorTextList;
}

/**
 * 読みやすさの注意（明るさの比が足りない組み合わせ）。保存はできる（K-046 決定）。形が正しい色だけに使う。
 *
 * @param array{base: string, accent: string, background: string} $colors
 * @return string[] 注意の文
 */
function user_theme_readability_warnings(array $colors): array
{
    $errorTextList = [];
    $baseColors = USER_THEME_BASE_COLORS[$colors['base']];
    // 背景の上の文字（あいさつ・説明文など）
    if (user_theme_contrast_ratio($colors['background'], $baseColors['text']) < 4.5) {
        $errorTextList[] = msg('背景の色が{base}の土台の文字の色と近く、文字が読みにくくなります。背景の色を{direction}してください',
            ['base' => $colors['base'] === 'dark' ? '暗い' : '明るい', 'direction' => $colors['base'] === 'dark' ? '暗く' : '明るく']);
    }
    // 面（カード）の上の強調の色（見出し・リンク）
    if (user_theme_contrast_ratio($colors['accent'], $baseColors['card']) < 3.0) {
        $errorTextList[] = msg('強調の色が面の色と近く、見出しやリンクが見えにくくなります。強調の色を{direction}してください',
            ['direction' => $colors['base'] === 'dark' ? '明るく' : '濃く']);
    }
    // ボタンの文字（強調の色の上の白か黒）
    if (user_theme_contrast_ratio($colors['accent'], user_theme_text_on($colors['accent'])) < 4.5) {
        $errorTextList[] = msg('強調の色の上のボタンの文字が読みにくくなります。強調の色を、もう少し濃くするか明るくしてください');
    }
    return $errorTextList;
}

/** 色を混ぜる（$weight は 2 つ目の色の割合 0〜1） */
function user_theme_mix(string $firstHexColor, string $secondHexColor, float $weight): string
{
    $mixedText = '#';
    foreach ([1, 3, 5] as $offset) {
        $mixedValue = (int) round(hexdec(substr($firstHexColor, $offset, 2)) * (1 - $weight) + hexdec(substr($secondHexColor, $offset, 2)) * $weight);
        $mixedText .= str_pad(dechex(max(0, min(255, $mixedValue))), 2, '0', STR_PAD_LEFT);
    }
    return $mixedText;
}

/**
 * 自分の色の決まり（CSS）。html に data-custom-colors が付いた画面にだけ効く（app.css のあとに読む）。
 *
 * @param array{base: string, accent: string, background: string} $colors
 */
function user_theme_css(array $colors): string
{
    $baseColors = USER_THEME_BASE_COLORS[$colors['base']];
    $accentColor = $colors['accent'];
    $backgroundColor = $colors['background'];
    $variableByName = [
        '--primary'      => $accentColor,
        '--primary-text' => user_theme_text_on($accentColor),
        '--bg'           => $backgroundColor,
        '--button-bg'    => user_theme_mix($baseColors['card'], $accentColor, 0.08),
        '--row-hover-bg' => user_theme_mix($baseColors['card'], $accentColor, 0.12),
        '--panel-bg'     => user_theme_mix($baseColors['card'], $accentColor, 0.05),
        '--line'         => user_theme_mix($colors['base'] === 'dark' ? '#30363d' : '#d0d7de', $accentColor, 0.15),
    ];
    $cssText = ":root[data-custom-colors] {\n";
    foreach ($variableByName as $variableName => $variableValue) {
        $cssText .= '  ' . $variableName . ': ' . $variableValue . ";\n";
    }
    $cssText .= "}\n";
    $cssText .= ":root[data-custom-colors] body { background: var(--bg); }\n";
    // テーマを選ぶ画面の色見本（背景・面・強調）
    $cssText .= '.theme-swatch-custom span:nth-child(1) { background: ' . $backgroundColor . "; }\n";
    $cssText .= '.theme-swatch-custom span:nth-child(2) { background: ' . $baseColors['card'] . "; }\n";
    $cssText .= '.theme-swatch-custom span:nth-child(3) { background: ' . $accentColor . "; }\n";
    return $cssText;
}

/** 自分の色の CSS の版（色が変わったらブラウザーが読み直すよう、URL に付ける） */
function user_theme_version(array $colors): string
{
    return substr(hash('sha256', json_encode($colors)), 0, 12);
}
