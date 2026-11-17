<?php

declare(strict_types=1);

// ====================================================================
// 更新のお知らせ（便利機能 A。G-24）・よくある質問（便利機能 J。G-25）・画面ごとの使い方（便利機能 I）
// ====================================================================

const NOTICE_TITLE_MAX_LENGTH = 60;
const NOTICE_BODY_MAX_LENGTH = 1000;
const FAQ_QUESTION_MAX_LENGTH = 200;
const FAQ_ANSWER_MAX_LENGTH = 2000;
const FAQ_MAX_COUNT = 200;

/**
 * 今出している更新のお知らせ（表示の期間の中。新しい順に $limit 件）。
 *
 * @return array<int, array<string, mixed>>
 */
function notices_active(int $limit = 5): array
{
    $todayText = date('Y-m-d');
    return db_all('SELECT * FROM update_notices WHERE show_from <= ? AND show_until >= ? ORDER BY show_from DESC, id DESC LIMIT ?', [$todayText, $todayText, $limit]);
}

/** 更新のお知らせの状態（表示の期間と今日から） */
function notice_state_label(array $noticeRow): string
{
    $todayText = date('Y-m-d');
    if ((string) $noticeRow['show_from'] > $todayText) {
        return '出す前';
    }
    return (string) $noticeRow['show_until'] < $todayText ? '終わった' : '出している';
}

/**
 * 画面ごとの使い方（便利機能 I。K-047 決定: ログインして使う全画面）。テンプレート => 使い方の文（G-17 で文言を変えられる）。
 * ログイン・パスワードの再設定・エラー・印刷用の画面には出さない。
 */
function screen_help_text(string $templateName): string
{
    switch ($templateName) {
        case 'home':
            return msg('ホームには、今月の報告の状態、回答待ちのコメント、臨時の調査、（確認者には）確認の欄が出ます。まず「今月の報告を作る」から報告を作ってください。締め日が近づくと赤で知らせます。');
        case 'report_edit':
            return msg('案件ごとに項目を入れて、「提出する」を押します。途中で「下書き保存」もできます（入力が変わると 60 秒ごとに自動で下書き保存します）。前月から変わりがなければ「前月から変更なしで提出」で提出できます。過去の報告の内容を写すこともできます。「必須」の札が付いた項目は入力が必要です。客先名・個人名は書かないでください。スマートフォンでは 1 問ずつ答えられます（「一覧で表示する」で切り替え）。');
        case 'report_view':
            return msg('報告の中身と、確認者のコメント・チェックの状態を見られます。部下は、コメントに「返信」で答えます。確認者は、項目ごとに「＋ コメント」でコメントし、下の「チェック済みにする」でチェックします。「前月との差分」で前月から変わった所が分かります。');
        case 'report_mine':
            return msg('自分の報告の一覧です。月ごとの状態（下書き・提出済み・確認完了）と、回答待ちのコメントの数が分かります。押すと報告の詳細を開きます。');
        case 'status_index':
            return msg('課の人ごとの提出の状態と、チェックの進み具合です。「未チェックを順に見る」で、チェックしていない報告を順に開けます。「前月から変更なし」の報告は、まとめてチェックできます。');
        case 'status_compare':
            return msg('課の報告を、案件ごとに 1 行で横に並べて見られます。「前月との差分」で変わった値に印が付きます。自由記述は「開く」を押したときだけ出ます（開いたことは記録されます）。');
        case 'comment_list':
            return msg('自分の報告へのコメントと、自分が出したコメントの一覧です。「回答待ち」は返信を待っているもの、「回答あり」は返信が来たものです。');
        case 'search':
            return msg('対象月・課・選択式の値・キーワードで報告を探します。キーワードは「検索の対象」の項目と、案件のコード・呼び名から探します。');
        case 'account':
            return msg('2 段階認証・予備のコード・ログインを覚えた端末・パスワード・画面のテーマを設定します。パスワードは「自動で作る」で作ることもできます。');
        case 'comment_templates':
            return msg('コメントで選んで使う定型文を用意します。課長は課の定型文、確認者は自分だけの定型文を 30 件まで登録できます。');
        case 'support_request_list':
            return msg('システムへの改善要望・対応依頼・不具合・質問を、システム管理者へ出せます。画面のことは、その画面の下の「この画面について要望・依頼を出す」から出すと、どの画面かが自動で入ります。返信はホームにも出ます。');
        case 'admin_fields':
        case 'admin_field_edit':
            return msg('報告の項目（共通の項目・課の項目）を追加・編集・並べ替えます。変更は、これから作る報告に反映されます（すでにある報告は、作ったときの項目のまま）。「プレビュー」で入力画面を確かめられます。');
        case 'department_manage':
            return msg('自分が課長の課の、締め日・管理の仕方（課全体／班ごと）・確認者（課長代理・リーダー）・チェックする人・項目管理者・班を決めます。');
        case 'admin_home':
            return msg('管理の仕事ごとの入口と、今の状態です。目立つ札は、対応が要るものです。管理者・補佐には、バックアップの状態とメンテナンスの切り替えも出ます。');
        case 'admin_field_options':
            return msg('共通の選択式の項目の選択肢を、この課でだけ、表示名を変える・出さない・足すことができます（IT 系以外の課のため）。ほかの課には影響しません。');
        case 'admin_field_preview':
            return msg('報告の作成の画面が、今の項目でどう見えるかを確かめます。ここでは保存されません。');
        case 'admin_org':
            return msg('課の追加・名前・課長、ユーザーの追加と所属、注意の言葉、組織の一括の入れ替え（CSV）を行います。毎年の組織改訂は「一括（CSV）」で、事前に予告がある改訂は日を予約できます。課の中のこと（締め日・課長代理・リーダー・班など）は、その課の課長が「課の管理」で決めます。');
        case 'admin_user_edit':
            return msg('ユーザーの表示名・役職・所属の課・有効を変えます。仮のパスワードの発行、2 段階認証の初期化、ロックの解除もここで行います。仮のパスワードは一度だけ出るので、本人に伝えてください。');
        case 'admin_projects':
            return msg('案件コードと社内向けの呼び名を管理します。客先名は入れないでください。案件台帳との突き合わせには「案件の一覧を CSV に出力」を使います。');
        case 'admin_logs':
            return msg('閲覧・出力・管理の操作・ログインの記録を探します。「ログインの失敗」で、不正なアクセスの疑いを確かめられます。記録に報告の中身は入っていません。');
        case 'admin_trash':
            return msg('削除した報告・コメント・臨時の調査を、削除から一定の日数の間は戻せます。');
        case 'admin_messages':
            return msg('画面に出す文言を変えます。変えた文言は全員の画面に出ます。{ } の中の言葉は、そのまま残してください。');
        case 'admin_faq_logs':
            return msg('よくある質問のチャットで聞かれた質問と、返した答え・反応です。答えられなかった質問は「よくある質問に載せる」で載せ、「解決しない」が多い答えは文を直してください。聞いた人は、不適切な表現への対応のためだけに使ってください。');
        case 'admin_notices':
            return msg('システムの改善や変更を、出す期間の間、全員のホームに知らせます。要望を出した人の名前や要望の文は、そのまま書かないでください。');
        case 'support_request_view':
            return msg('要望・依頼の中身と、システム管理者とのやり取りです。管理者側は、状態のボタンで状態を変えて返信します（返信が空なら定型の返信）。出した人は、補足を返信できます。');
        case 'survey_manage':
            return msg('臨時の調査（月次報告とは別の、その時だけの質問）を作って公開し、回答を集めます。');
        case 'survey_edit':
            return msg('臨時の調査の題名・依頼元・期限・対象と、質問を決めます。公開すると質問は変えられません。');
        case 'survey_result':
            return msg('臨時の調査の回答の集計と、未回答の人を見ます。個人の回答を開くと記録に残ります。');
        case 'survey_answer':
            return msg('臨時の調査に答えます。期限までは答えを直せます。');
        case 'export_index':
            return msg('報告・臨時の調査を CSV や印刷用に出力します。出力したファイルは社外秘です。出力は PC から行い、記録に残ります。');
        case 'faq':
            return msg('チャットに知りたいことを入れるか、分野を選ぶと、よくある質問の答えを出します。見つからないときは、課長・確認者は「質問として送る」でシステム管理者に質問でき、部下は課長・確認者に聞いてください。');
        default:
            return '';
    }
}

// ---- よくある質問の分野（G-25 §2.3。曖昧な質問のときに分野から選んでもらう） ----
/** 分野（コード => 表示名）。並びは画面に出す順。空は「そのほか」 */
const FAQ_CATEGORY_LABELS = [
    'about'      => 'このシステムについて',
    'login'      => 'ログイン・パスワード・2 段階認証',
    'report'     => '報告の作成・提出',
    'comment'    => 'コメント・返信',
    'review'     => '報告の確認・チェック（確認者・課長）',
    'status'     => '提出状況・横並び・検索・出力',
    'survey'     => '臨時の調査',
    'account'    => 'アカウント設定・画面の表示',
    'request'    => '要望・依頼・よくある質問',
    'department' => '課の管理・項目（課長）',
    'admin'      => 'システムの管理（管理者・補佐）',
    'device'     => 'スマートフォン・PC・ブラウザー',
    ''           => 'そのほか',
];

/** 画面の番号 => 分野（「この画面の使い方」から来たとき、その画面の分野をすすめる） */
const FAQ_CATEGORY_BY_SCREEN = [
    'G-02' => 'login', 'G-03' => 'report', 'G-04' => 'report', 'G-05' => 'review', 'G-06' => 'report', 'G-07' => 'survey',
    'G-08' => 'status', 'G-09' => 'status', 'G-10' => 'status', 'G-11' => 'survey', 'G-12' => 'department', 'G-13' => 'admin',
    'G-14' => 'admin', 'G-15' => 'admin', 'G-16' => 'account', 'G-17' => 'department', 'G-18' => 'comment', 'G-19' => 'review',
    'G-20' => 'status', 'G-21' => 'admin', 'G-22' => 'request', 'G-23' => 'department', 'G-24' => 'admin', 'G-25' => 'request',
];

/**
 * 中身のない言い方（これだけでは何を知りたいか分からない言葉）。faq_normalize_text() でそろえた形で書く。
 * 入れた文からこれらを除いて、探す言葉が残らなければ「曖昧な質問」として分野を聞き返す。
 */
const FAQ_VAGUE_WORDS = [
    '使い方', 'つかいかた', '使いかた', 'わからない', '分からない', '判らない', 'わかりません', '分かりません', 'わかんない', '不明',
    '教えて', 'おしえて', '困った', '困って', '困り', 'どうすれば', 'どうしたら', 'どうやって', 'どうする', 'やり方', 'やりかた', '方法',
    'ヘルプ', 'help', '助けて', 'たすけて', '質問', '問い合わせ', '全部', '全体', 'いろいろ', '色々', 'システム', 'このシステム', '画面', 'この画面',
    '操作', '使えない', 'できない', '出来ない', 'うまくいかない', '上手くいかない', 'よく', '何', 'なに', 'はじめて', '初めて', '最初', 'について',
    'こんにちは', 'こんばんは', 'おはよう', 'ありがとう', 'よろしく', 'すみません', 'はじめまして', 'もしもし',
    'なんで', 'なぜ', 'どうして', '答えろ', '答えて', 'こたえろ', 'こたえて',
    'おかしい', '変になった', '違う', '前と', 'いつもと', 'どこ',
    'さっき', '続き', 'ちょっと', '聞きたい', '相談', '急ぎ', '至急',
    'はい', 'いいえ', 'どういうこと', 'どういう意味', '意味がわからない', 'ご用件', '用件', 'でしょ', '返答して', '返事して', '返事をして', '返信して',
    '惜しい', 'おしい', '残念',
    // 頼み方の言葉（「返答してください」「お願いします」だけの文は、中身がない）
    'ください', '下さい', 'お願いします', 'おねがいします', 'お願い', 'おねがい',
    'どうかしましたか', 'どうしましたか', 'どうかした', 'どうした', 'いつですか', 'いつ',
    // 方言のあいづち・語尾（それだけの文は中身がない。「報告するけん」のように中身があれば答える）
    'じゃけん', 'だけん', 'やけん', 'じゃけ', 'やけ', 'けん', 'じゃん', 'やん', 'だべ', 'やで', 'ばい', 'なぁ', 'なあ',
    '本人', 'あわせて', '方法論', '切り替えし', '切り返し', '切り替え', '切替え', '切替', '切り戻し', '切戻し',
    '構成員', '所属員', '幹部', 'チームメイト', '従業員', '職員', '社員', '作業員', '協力者', 'ワーキンググループ', '業務', 'だれ', '申請',
];

/**
 * 呼びかけだけの文（用件の無い「大変です」など）。faq_normalize_text() でそろえ、記号と空白を除いた形がこれと同じときだけ（用件があれば、ふつうに探す）。
 * 返事は「どういったご用件でしょうか。」（G-25 §2.6。2026-11-17 指示）
 */
const FAQ_OPENER_PHRASES = ['大変です', '大変', 'たいへんです', 'たいへん', '大変なんです', 'たいへんなんです', '大変です大変です', '大変だ', 'もしもし', 'もしもーし'];
/** 「大変」のあとに付いても用件にならない言い方（「大変なのです」「大変でした」「大変困惑」） */
const FAQ_OPENER_TRAILING_WORDS = ['なのです', 'なんです', 'なんですが', 'でした', 'です', 'だった', 'だ', 'な', 'なこと', 'なことに', 'なった', 'になった', '困惑', '困って', '困った', '困ってます', '困っています', 'すぎる', '過ぎる'];

/**
 * 用件がない・もういい、という意味の文（「どういったご用件でしょうか。」に「用件なし」と答えたときなど）。
 * faq_normalize_text() でそろえ、空白を除いた文がこれと同じときだけ。返事は「わかりました。知りたいことがあるときは、いつでも入れてください。」（2026-11-17）
 */
const FAQ_CLOSING_PHRASES = ['用件なし', '用件はない', '用件はないです', '用件はありません', '用はない', '用はないです', '特にない', '特にないです', '特にありません', 'とくにない',
    'なんでもない', 'なんでもないです', '何でもない', '何でもないです', 'なんでもありません', '何でもありません', '大丈夫です', '結構です', 'けっこうです',
    'もういい', 'もういいです', '以上です', 'いいえ大丈夫です', 'ないです', 'ありません'];

/** 会社の決まり（勤務時間・手当など）の言葉。よくある質問では答えられない（課長・確認者に聞く） */
const FAQ_COMPANY_POLICY_WORDS = ['業務時間', '勤務時間', '就業時間', '労働時間', '残業代', '残業手当', '手当', '給料', '給与', '賃金', '時間外'];

/** 会社の決まりの質問か（「報告させるならば業務時間じゃないの？」） */
function faq_is_company_policy_question(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    foreach (FAQ_COMPANY_POLICY_WORDS as $policyWord) {
        if (strpos($normalizedQuery, $policyWord) !== false) {
            return true;
        }
    }
    return false;
}

/** 用件がない・もういい、という意味の文か */
function faq_is_closing(string $queryText): bool
{
    return in_array(str_replace(' ', '', faq_normalize_text($queryText)), FAQ_CLOSING_PHRASES, true);
}

/** 呼びかけだけの文か（「大変」「たいへん」で始まり、そのあとに用件がない） */
function faq_is_opener(string $queryText): bool
{
    $compactText = str_replace(' ', '', faq_normalize_text($queryText));
    // 「課長が大変です」「部下が大変なのです」: 人の主語を外して確かめる。「課長が。。。」のように主語だけで言いかけた文も呼びかけ
    foreach (array_merge(FAQ_SUPERIOR_WORDS, ['部下', '同僚', '管理者', '補佐', '私', '自分', 'わたし']) as $personWord) {
        foreach (['が', 'は'] as $particleText) {
            if ($compactText === $personWord . $particleText) {
                return true;
            }
            if (strpos($compactText, $personWord . $particleText . '大変') === 0 || strpos($compactText, $personWord . $particleText . 'たいへん') === 0) {
                $compactText = substr($compactText, strlen($personWord . $particleText));
                break 2;
            }
        }
    }
    if (in_array($compactText, FAQ_OPENER_PHRASES, true)) {
        return true;
    }
    foreach (['大変', 'たいへん'] as $openerPrefix) {
        if (strpos($compactText, $openerPrefix) !== 0) {
            continue;
        }
        $remainingText = substr($compactText, strlen($openerPrefix));
        $trailingWordList = FAQ_OPENER_TRAILING_WORDS;
        usort($trailingWordList, static function (string $leftWord, string $rightWord): int {
            return strlen($rightWord) <=> strlen($leftWord);
        });
        foreach (array_merge(['大変', 'たいへん'], $trailingWordList) as $trailingWord) {
            $remainingText = str_replace($trailingWord, '', $remainingText);
        }
        return $remainingText === '';
    }
    return false;
}

// ---- 心の不調らしい文（G-25 §2.8。AI は使わない。返事を気づかいの言葉にし、システム管理者・補佐に知らせる） ----
/**
 * 命に関わる言葉（最も急ぐ）。faq_normalize_text() でそろえた形で書く。
 */
const FAQ_DISTRESS_URGENT_WORDS = [
    '死にたい', 'しにたい', '死のう', '自殺', '消えたい', 'きえたい', 'いなくなりたい', '生きていたくない', '生きるのがつらい', '生きるのが辛い',
    '楽になりたい', '終わりにしたい', '死んでしまいたい', '消えてしまいたい', 'いなくなる方法', '死ぬ方法', 'この世から', '去りたい',
    '生きている価値', '生きる価値', '生きている意味', '生きる意味', '目が覚めなければ', '明日が来なくても', '全部終わらせ', '終わらせられたら',
    '自分を傷つけ', '腕を傷つけ', '手首を切', 'リストカット', 'リスカ', 'オーバードーズ', '過量服薬', '大量に飲', '全部飲んで', '飲んでしまいそう',
];
/**
 * 心配な言葉。ふつうの文に紛れる言葉（「写す」の「うつ」など）は入れない。
 */
const FAQ_DISTRESS_CONCERN_WORDS = [
    'つらい', '辛い', 'しんどい', '死んだように', '精神的に', '疲れた', 'つかれた', '疲れ果て', '限界', '眠れない', 'ねむれない', 'やる気が出ない', 'やる気がでない',
    'うつ病', '鬱', '病んで', '落ち込', '不安で', '怖い', 'こわい', '泣きたい', '助けて', 'たすけて', 'パワハラ', 'いじめ', '孤独',
    '逃げたい', '耐えられない', 'たえられない', 'もう無理', 'もうむり', '心が折れ', '気分が落ち', '辞めたい', '会社を辞め', '仕事を辞め',
    'セクハラ', 'モラハラ', 'マタハラ', 'ストーカー', 'つきまと', 'ドメスティック', '暴力を受け',
    '追い出し', '干され', '無視され', '左遷', '嫌がらせ', '仕事を与えられ', '仕事をもらえない', '恫喝', '怒鳴ら', '罵倒', '暴言を', '脅さ', '威圧',
    '涙が', '泣いて', '眠れ', '寝られな', '起きられ', '食欲', '味がしな', '体が重', '鉛のよう', '手がつかな', '手につかな', 'パニック', 'フラッシュバック',
    '悪夢', '不安が強', '不安でたまら', '不安が止まら', '不安が限界', '怖く', '恐怖', '叱責', '休職', '口論', '苦し', '価値がな', '透明人間', '浮き沈み',
    '心療内科', '精神科', 'メンタルが', 'メンタル不調', 'メンタルクリニック', 'メンブレ', '声が聞こえ', '悪口を言う声', '監視され', '確認しないと', 'やめられな', '飲まないと眠', '朝から飲', '毎日お酒', 'お酒がやめられ', 'お酒をボトル', '酒に頼', 'アルコール依存', '燃え尽き',
    '寝ていない', '寝てない', '全部投資',
    '亡くして', '立ち直', 'もうダメ', 'やる気が', '気力が', '孤立', '誰にも相談', '相談できな',
];

/** 心配な言葉を含むが、気持ちのことではない言い方（味の「辛い」など）。faq_normalize_text() でそろえた形で書く */
const FAQ_DISTRESS_NOT_FEELING_PHRASES = ['辛いラーメン', '辛いもの', '辛い物', '辛い料理', '辛いカレー', '辛い食べ物', '辛いお菓子', '辛い味', '辛口'];

/**
 * 心の不調らしい文か。'urgent'（命に関わる言葉）・'concern'（心配な言葉）・''（どちらでもない）。
 */
/** 機能の言葉（「2 段階認証を辞めたい」のように、この言葉と一緒の「辞めたい」は、仕事を辞める話ではない） */
const FAQ_FEATURE_OBJECT_WORDS = ['2段階', '二段階', '認証', 'ログイン', 'パスワード', '設定', 'テーマ', '通知', '機能', 'チャット', 'アプリ', '表示', 'ダーク', 'コード', '入力', 'メール'];
/** 仕事・会社を辞める話の言葉（これがあれば、機能の言葉があっても退職の話として扱う） */
const FAQ_JOB_QUITTING_WORDS = ['仕事を辞め', '会社を辞め', '仕事をやめ', '会社をやめ', '退職', '辞職', '退社', '離職'];

/**
 * 「2 段階認証を辞めたい」のように、機能をやめたいという意味の「辞めたい」を除いた文（そろえた文で受け取り、そろえた文で返す）。
 * 仕事・会社を辞める話の言葉があるときは、何も除かない（2026-11-17。記録で、心の不調と退職の知らせに取り違えていた）
 */
function faq_strip_feature_quitting(string $normalizedQuery): string
{
    foreach (FAQ_JOB_QUITTING_WORDS as $jobQuittingWord) {
        if (strpos($normalizedQuery, $jobQuittingWord) !== false) {
            return $normalizedQuery;
        }
    }
    foreach (FAQ_FEATURE_OBJECT_WORDS as $featureObjectWord) {
        if (strpos($normalizedQuery, $featureObjectWord) !== false) {
            return str_replace(['辞めたい', '辞めたく', '辞め', 'やめたい', 'やめたく', '止めたい'], ' ', $normalizedQuery);
        }
    }
    return $normalizedQuery;
}

/** ほかの人（課長・上司・部下など）が主語のときは、本人の心の不調としない言葉（「課長が休職中になった」） */
const FAQ_DISTRESS_THIRD_PARTY_NEUTRAL_WORDS = ['休職'];
/** ほかの人が主語であることを表す言い方 */
const FAQ_THIRD_PARTY_SUBJECT_PHRASES = ['課長が', '上司が', '部長が', '係長が', '主任が', 'リーダーが', '管理者が', '社長が', '部下が', '同僚が', '先輩が', '後輩が', 'メンバーが'];

function faq_distress_level(string $queryText): string
{
    // 気持ちとは関係のない言い方を先に除く（「辛いラーメン」の辛い は味。「2 段階認証を辞めたい」の辞めたい は機能の話）
    $normalizedQuery = str_replace(FAQ_DISTRESS_NOT_FEELING_PHRASES, ' ', faq_strip_feature_quitting(faq_normalize_text($queryText)));
    foreach (FAQ_THIRD_PARTY_SUBJECT_PHRASES as $thirdPartySubjectPhrase) {
        if (strpos($normalizedQuery, $thirdPartySubjectPhrase) !== false) {
            $normalizedQuery = str_replace(FAQ_DISTRESS_THIRD_PARTY_NEUTRAL_WORDS, ' ', $normalizedQuery);
            break;
        }
    }
    // 「左遷させる」「追い出させる」のように、させる側（人事の側）の言い方は、された側の心の不調にしない
    $normalizedQuery = str_replace(['左遷させ', '左遷する', '左遷し'], ' ', $normalizedQuery);
    foreach (FAQ_DISTRESS_URGENT_WORDS as $distressWord) {
        if (strpos($normalizedQuery, $distressWord) !== false) {
            return 'urgent';
        }
    }
    foreach (FAQ_DISTRESS_CONCERN_WORDS as $distressWord) {
        if (strpos($normalizedQuery, $distressWord) !== false) {
            return 'concern';
        }
    }
    return '';
}

// ---- 命・体の緊急の状態（G-25 §2.12。K-055。119 番を案内する。文は記録しない） ----
/**
 * 急病・けが・事故・誤飲など、命や体に関わる、急いだほうがよい状態の言葉。faq_normalize_text() でそろえた形で書く。
 * 画面の不具合と紛れる言い方（「画面が真っ暗」など）は入れない。
 */
const FAQ_EMERGENCY_WORDS = [
    '倒れた', '倒れて', '倒れま', '倒れこ', '倒れ込', '意識がな', '意識が戻', '反応がな', '反応しな', '呼びかけても', '息ができ', '息が吸', '息苦し', '呼吸が', '窒息', 'けいれん', '痙攣',
    'ひきつけ', 'ガクガク', '出血', '血が止まら', '血が噴', '血が出', '吐血', '冷や汗', '胸が痛', '胸が締め', '胸がチクチク', '胸の痛', '激しく痛', '激痛',
    '頭を強く打', '頭を打', '後頭部を打', 'お腹を強く打', '溺れ', '煙を吸', 'やけど', '火傷', '熱湯', '誤って', '誤飲', '飲み込', '洗剤', '吸い殻',
    'じんましん', '蕁麻疹', 'ハチに刺', '蜂に刺', 'ろれつ', '顔の半分', '腕が上がら', 'めまい', '二重に見え', '腹痛', 'お腹が痛', '高熱', '白目', 'ぐったり',
    '閉じ込め', '救急', '119', '崩れ落ち', '心臓が握', '目の前が真っ暗', '立っていられな', '吐き気', '吐きま', '吐いた', '大量に飲', '全部飲んで',
    'たくさん飲んで', '1箱全部', '1箱飲', '水ぶくれ', '骨折', '煙を', '咳が止まら', '皮膚が', '熱いお', '痛くな', '痛みが', '痛くて',
    'すごく痛', 'ひどく痛', '強く痛', '胸が苦し', '胸が重', '胸の真ん中', '喉に詰ま', 'のどに詰ま', '詰まらせ', '顔色が真っ青', '顔色が悪', '声も出せ', '声が出せ',
    '漂白剤', 'ハイター', '塩素系', '灯油', '農薬', '殺虫剤', 'タバコ', 'たばこ', '灰皿', '電池', '磁石', '嘔吐', 'よだれ', '舐めて', 'なめて',
    '巻き込まれ', '挟まれ', '下敷き', '切断', 'はねられ', '轢かれ', '転落', '冷たくなっ', '息をしていな', '息していな', '呼吸をしていな', '呼吸していな', '脈がな', '体が硬',
];

/**
 * 緊急の言葉のうち、システム・端末の質問にも出るもの（「ボタンが反応しない」「誤って報告を消した」「スマホの電池が切れた」「通信が切断された」）。
 * これだけが当たり、システム・端末の言葉があるときは、緊急としない（自動入力検証で見つかった）
 */
const FAQ_EMERGENCY_AMBIGUOUS_WORDS = ['反応しな', '反応がな', '誤って', '電池', '切断', '磁石'];
/** 上のあいまいな緊急の言葉を、システム・端末の話と見分ける言葉（FAQ_SYSTEM_WORDS に加えて） */
const FAQ_EMERGENCY_SYSTEM_CONTEXT_WORDS = [
    'スマホ', 'スマートフォン', '携帯', 'パソコン', 'pc', 'タブレット', '通信', 'ネット', '回線', '接続', 'wi fi', 'wifi',
    '提出', '送信', '削除', '消し', '押し', '押す', 'クリック', 'タップ', 'コメント', '下書き', '案件', 'ログイン', 'ページ', 'タブ', 'マウス', 'キーボード',
];

/** 命・体の緊急の状態に触れる文か */
function faq_is_emergency(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    $hasClearEmergencyWord = false;
    $hasAmbiguousEmergencyWord = false;
    foreach (FAQ_EMERGENCY_WORDS as $emergencyWord) {
        if (strpos($normalizedQuery, $emergencyWord) === false) {
            continue;
        }
        if (in_array($emergencyWord, FAQ_EMERGENCY_AMBIGUOUS_WORDS, true)) {
            $hasAmbiguousEmergencyWord = true;
        } else {
            $hasClearEmergencyWord = true;
        }
    }
    if ($hasClearEmergencyWord) {
        return true;
    }
    if (!$hasAmbiguousEmergencyWord) {
        return false;
    }
    // あいまいな言葉だけのとき: システム・端末の話なら緊急としない（人のことなら緊急。安全側）
    foreach (array_merge(FAQ_SYSTEM_WORDS, FAQ_EMERGENCY_SYSTEM_CONTEXT_WORDS) as $systemContextWord) {
        if (strpos($normalizedQuery, $systemContextWord) !== false) {
            return false;
        }
    }
    return true;
}

// ---- ID・パスワードを求める質問（G-25 §2.13。K-057。なりすましのおそれ。システム管理者・補佐に知らせる） ----
/** ID・パスワードを求める言い方。faq_normalize_text() でそろえた形で書く（自分のパスワードを忘れた・変えたい、は入れない） */
const FAQ_CREDENTIAL_WORDS = [
    'パスワードがほし', 'パスワードを教え', 'パスワードおしえ', 'パスワード教え', 'パスワードを知り', 'パスワードをくだ', 'パスワードを送', 'パスワードをもら',
    'idとパスワード', 'id パスワード', 'idを教え', 'ログインidを教え', '管理者のパスワード', '前任者のパスワード', '前任のパスワード', '人のパスワード',
    '予備のコードを教え', 'コードを教え', '鍵を教え', '暗号の鍵',
];

/** ID・パスワードなど、ほかの人の認証の情報を求める質問か */
function faq_is_credential_request(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    foreach (FAQ_CREDENTIAL_WORDS as $credentialWord) {
        if (strpos($normalizedQuery, $credentialWord) !== false) {
            return true;
        }
    }
    return false;
}

// ---- 退職に関わる質問（G-25 §2.10。K-054。返事はふつうの答え。システム管理者・補佐に知らせる） ----
/** 退職に関わる言葉。faq_normalize_text() でそろえた形で書く */
const FAQ_RETIREMENT_WORDS = ['退職', '辞め', '辞職', '退社', '離職'];

/** 部下・課員・前任者など、ほかの人のことを聞いている言葉（ほかの人の退職は、手続きや引き継ぎを聞く質問なので、答えを探す） */
const FAQ_SUBORDINATE_WORDS = ['部下', '課員', 'メンバー', '班員', 'スタッフ', '担当', '前任', '後任', '補佐', '同僚', '先輩', '後輩',
    // 上の立場の人が主語（「課長が辞めた」）のときも、自分の退職ではない（「上司に辞めたいと言えない」は自分の退職のまま）
    '課長が', '上司が', '部長が', '係長が', '主任が', 'リーダーが', '管理者が', '社長が'];

/** 部下・課員のことを聞いている質問か */
function faq_is_about_subordinate(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    foreach (FAQ_SUBORDINATE_WORDS as $subordinateWord) {
        if (strpos($normalizedQuery, $subordinateWord) !== false) {
            return true;
        }
    }
    return false;
}

/** 退職に関わる質問か */
function faq_is_retirement(string $queryText): bool
{
    $normalizedQuery = faq_strip_feature_quitting(faq_normalize_text($queryText));   // 「2 段階認証を辞めたい」は退職の話ではない
    foreach (FAQ_RETIREMENT_WORDS as $retirementWord) {
        if (strpos($normalizedQuery, $retirementWord) !== false) {
            return true;
        }
    }
    return false;
}

// ---- 要配慮個人情報に触れる文（G-25 §2.9。K-053。寄り添う返事にし、文は記録しない。管理者には知らせない） ----
/**
 * 要配慮個人情報（病歴・心身の障がい・健康診断や治療・信条・人種・社会的身分・犯罪の経歴や被害など）と、それに近い個人的な事情に触れる言葉。
 * faq_normalize_text() でそろえた形で書く。ふつうの文に紛れる言葉（「システム障害」の「障害」、「がんばる」の「がん」など）は入れない。
 * 命に関わる言葉・心配な言葉・身の安全に関わる被害は、心の不調らしい文（知らせる）で先に見分ける。
 */
const FAQ_SENSITIVE_WORDS = [
    '病気', '入院', '通院', '手術', '持病', '病院', '診断', '治療', '闘病', '癌', 'がん治療', 'がんと', '難病', '体調不良', '体調が悪', '怪我', 'ケガ',
    '感染', 'コロナ', 'インフルエンザ', '健康診断', '健診', '妊娠', '出産', '流産', '不妊', '産休', '育休', '介護', '障がい', '障害者', '身体障', '精神障',
    '発達障', '宗教', '信仰', '人種', '国籍', '民族', '出自', '部落', '差別', '性的指向', '同性愛', '性同一性', 'トランスジェンダー', 'lgbt', '逮捕',
    '前科', '犯罪', '被害届', '警察', '裁判', '起訴', '痴漢', '盗撮',
    '老後', '将来が不安', '将来が心配', '生活が苦し', '借金', '倒産', '破産', 'リストラ', '解雇',
];

/** 要配慮個人情報に触れる文か */
function faq_is_sensitive(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    foreach (FAQ_SENSITIVE_WORDS as $sensitiveWord) {
        if (strpos($normalizedQuery, $sensitiveWord) !== false) {
            return true;
        }
    }
    return false;
}

/** 要配慮個人情報に触れる言葉を除いた文（ほかに知りたいことがあるかを探すため。「入院するので報告が出せない」→「するので報告が出せない」） */
function faq_strip_sensitive_words(string $queryText): string
{
    $remainingText = faq_normalize_text($queryText);
    foreach (FAQ_SENSITIVE_WORDS as $sensitiveWord) {
        $remainingText = str_replace($sensitiveWord, ' ', $remainingText);
    }
    return trim($remainingText);
}

/** 心の不調らしい文と一緒に答えを出す近さの下限（faq_search() の割合） */
const FAQ_DISTRESS_COMPANION_MIN_RATIO = 0.6;

/**
 * 心の不調らしい文・要配慮の文と一緒に出す答え。気持ちの言葉を除いた残りの文に、探す言葉が 2 つ以上あり、
 * はっきり当たるもの（近さ FAQ_DISTRESS_COMPANION_MIN_RATIO 以上）だけ（「仕事がつらい」の「仕事」1 語では出さない）。
 *
 * @param array<int, array<string, mixed>> $faqList
 * @return array<int, array<string, mixed>>
 */
function faq_companion_matches(string $remainingText, array $faqList): array
{
    if (count(faq_search_terms($remainingText)) < 2) {
        return [];
    }
    return array_values(array_filter(faq_search($remainingText, $faqList), static function (array $matchedFaqRow): bool {
        return $matchedFaqRow['match_ratio'] >= FAQ_DISTRESS_COMPANION_MIN_RATIO;
    }));
}

/**
 * 心配な言葉を除いた文（心の不調らしい文で、ほかに知りたいことがあるかを探すため。「助けて、ログインできない」→「、ログインできない」）。
 * 心配な言葉そのもので探すと、チャットの説明など、その言葉を書いた答えに当たってしまう。
 * 「辞めたい」も除く（退職の手続きは示さず、相談するよう促すだけにする。2026-11-16 指示）。
 */
function faq_strip_distress_words(string $queryText): string
{
    $remainingText = faq_normalize_text($queryText);
    foreach (FAQ_DISTRESS_CONCERN_WORDS as $distressWord) {
        $remainingText = str_replace($distressWord, ' ', $remainingText);
    }
    return trim($remainingText);
}

// ---- 不適切な表現（G-25 §2.6。暴言・嫌がらせ。AI は使わない） ----
/**
 * 不適切な言葉。faq_normalize_text() でそろえた形（英字は小文字）で書く。入っていれば、システムの言葉があっても答えない。
 * 誤って当たらないよう、ふつうの文に紛れる言葉（「ばかり」の「ばか」、「カスタム」の「カス」、「早くそう」の「くそ」、「ところすでに」の「ころす」など）は入れない。
 */
const FAQ_ABUSIVE_WORDS = [
    'バカ', '馬鹿', 'アホ', 'あほう', '阿呆', '死ね', '氏ね', '殺す', '消えろ', '黙れ', 'だまれ', '失せろ', 'くたばれ',
    '無能', '役立たず', 'やくたたず', '能無し', '低能', 'ポンコツ', 'ぽんこつ', 'クズ', 'クソ', '糞', 'ボケ', 'ボケが',
    '間抜け', 'マヌケ', 'まぬけ', 'うざい', 'ウザい', 'うぜえ', 'うぜー', 'きもい', 'キモい', '気持ち悪い', 'ふざけるな', 'ふざけんな', 'ふざけんじゃ',
    'なめてる', 'なめんな', '舐めてる', '舐めんな', 'ざけんな', 'fuck', 'shit', 'stupid', 'idiot',
    'お前', 'おまえ', 'てめえ', 'てめぇ', '貴様',
];

/**
 * 人への暴力を書いた言葉（「管理者を殴りたい」）。答えは出さずにカウンセリングを受けることをすすめ、システム管理者・補佐に知らせる（職場の安全のため。2026-11-17 指示）。
 * faq_normalize_text() でそろえた形で書く。「ハチに刺された」などの緊急の文は、先に緊急として扱う
 */
const FAQ_VIOLENCE_WORDS = [
    '殴', 'なぐりたい', 'なぐってやる', '蹴', 'けりたい', 'けとばし', 'けりとばし', 'ボコボコにし', 'ぼこぼこにし', 'ボコる', 'ぼこる', 'ボコって', 'ぼこって', 'フルボッコ', 'しばきたい', 'しばいたる', 'しばいてやる', '刺したい', '刺してやる', '叩きたい', 'たたきたい', '叩いてやる',
    '殺したい', '殺してやる', 'ぶっ殺', 'ころしたい', 'ころしてやる', '襲いたい', '襲ってやる', '痛めつけ', '暴力をふる', '暴力を振る', '危害を加え',
    '火をつけ', '放火', '爆破', '刃物で',
    // 人を追い詰める・どなる（パワハラに当たりうる。どなられた側の「怒鳴られ」は心の不調の心配な言葉で扱う）
    '締め上げ', '締めあげ', 'しめあげ', '怒鳴ってしまっ', '怒鳴った', '怒鳴りたい', '怒鳴ってやる', '怒鳴りつけ', 'どなってしまっ', 'どなりつけ', '手を上げてしまっ', '手をあげてしまっ',
];

/** 上の立場の人を表す言葉（上司からのハラスメントを見分ける） */
const FAQ_SUPERIOR_WORDS = ['上司', '課長', '部長', '係長', '主任', 'リーダー', '先輩', '社長', '役員', '店長', 'マネージャー', '管理職'];
/** ハラスメントを受けたことを表す言葉 */
const FAQ_HARASSMENT_WORDS = ['パワハラ', 'セクハラ', 'モラハラ', 'マタハラ', 'ハラスメント', '怒鳴ら', '恫喝', '罵倒', '暴言', '嫌がらせ', 'いじめ', '無視され', '脅さ', '威圧', '干され', '追い出し'];

/**
 * 上司からのハラスメントを書いた文か（「上司のパワハラがひどい」「課長に怒鳴られた」）。
 * 心の不調の返事で「上司に相談して」とは返さず、ほかの管理職や会社の相談窓口への相談をすすめる（2026-11-17 指示）
 */
function faq_is_harassment_by_superior(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    $hasSuperiorWord = false;
    foreach (FAQ_SUPERIOR_WORDS as $superiorWord) {
        if (strpos($normalizedQuery, $superiorWord) !== false) {
            $hasSuperiorWord = true;
            break;
        }
    }
    if (!$hasSuperiorWord) {
        return false;
    }
    foreach (FAQ_HARASSMENT_WORDS as $harassmentWord) {
        if (strpos($normalizedQuery, $harassmentWord) !== false) {
            return true;
        }
    }
    return false;
}

/** 人への暴力を書いた文か */
function faq_is_violent(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    foreach (FAQ_VIOLENCE_WORDS as $violenceWord) {
        if (strpos($normalizedQuery, $violenceWord) !== false) {
            return true;
        }
    }
    return false;
}

/** 文がそれだけのときに不適切な表現とする、ひらがなの悪口（「ばか」は「エラーばかり」にも出るので、文全体がこれのときだけ） */
const FAQ_WHOLE_INPUT_ABUSIVE_WORDS = ['きも', 'ばか', 'ばーか', 'ばかやろう', 'ばかやろー', 'あほ', 'あほか', 'あほう', 'まぬけ', 'くず', 'かす'];

/** 不適切な表現が入っているか */
function faq_is_abusive(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    if (in_array(str_replace(' ', '', $normalizedQuery), FAQ_WHOLE_INPUT_ABUSIVE_WORDS, true)) {
        return true;
    }
    foreach (FAQ_ABUSIVE_WORDS as $abusiveWord) {
        if (strpos($normalizedQuery, $abusiveWord) !== false) {
            return true;
        }
    }
    return false;
}

// ---- システムに関係のない質問（G-25 §2.6。AI は使わない） ----
/**
 * システムでよく使う言葉（よくある質問の文に無くても、システムの質問とみなす）。faq_normalize_text() でそろえた形で書く。
 */
const FAQ_SYSTEM_WORDS = [
    'エラー', '表示', '真っ白', '固まる', '固まっ', '動かない', '動か', '遅い', '重い', '文字化け', '押せない', '開かない', '保存', '入力', 'ボタン',
    'ブラウザー', 'ブラウザ', '更新', '読み込', '月次', '報告', 'システム', '画面', 'アプリ', 'ログ', 'データ', '消え', '戻す', '戻せ',
    '修正', '訂正', '削除', '登録', '設定', '編集', '変更', '帳票', '余白', 'レイアウト', '添付', '開発者', '概要', '機能', 'チャット', '返答',
    '色彩', '配色', 'テーマ', '部下', '課長代理', '確認者', '課長', '班', '退職', '組織', '役職', '異動', '先月', '翌月', '前月', '今月', '当月',
    '管理者', '権限', 'パスワード', '障害', 'サーバー', '本番環境', '不正アクセス', '侵入', 'ランサムウェア', 'ファイアウォール', 'データベース', 'api',
    '案件', 'プロジェクト', '提出', '未提出', '会話',
    '処理落ち', '遅く', '環境', '左遷', '任命', '方言', '社外情報', '部長', '取締役', '役員', '社長', 'バックドア', '裏口', '問合せ',
    'マルウェア', 'ウイルス', 'ウィルス', '身代金', 'サイバー', '脆弱性', '改ざん', '改竄', 'ハッキング', 'フリーズ', 'クラッシュ', 'ハングアップ', '再編',
];
/**
 * 関係のない質問とみなす境目: 入れた文の言葉のうち、よくある質問の文・システムの言葉に出てくる割合がこれ以下で、
 * いちばん近い質問の近さ（faq_search() の割合）もこれ以下のとき（「野球の結果」のように 1 語だけたまたま当たるものを含める）
 */
const FAQ_UNRELATED_MAX_RATIO = 0.5;
/**
 * 明らかにシステムと関係のない話題の言葉（faq_normalize_text() でそろえた形）。システムの言葉（FAQ_SYSTEM_WORDS）が無ければ、関係のない質問とする。
 * 2 文字の並びで探すため、「ラーメン」が「コメント」に、「Python のコード」が「予備のコード」に当たってしまうのを防ぐ（自動入力検証で見つかった）
 */
/** システムの言葉（「パスワード」など）があっても関係のない話題とする言葉（「社内の Wi-Fi のパスワード」「プリンターが動かない」） */
const FAQ_OFFTOPIC_STRONG_WORDS = ['wi fi', 'wifi', 'プリンター', 'teams', '有給', '人事', '扶養', '住所', '年末調整', '給与'];
const FAQ_OFFTOPIC_WORDS = [
    '天気', '天候', '気温', '雨が', '台風', 'ラーメン', 'レシピ', '料理', 'ランチ', '居酒屋', 'レストラン', 'おすすめの店', 'グルメ',
    '野球', 'サッカー', 'ゴルフ', '競馬', '試合', '株価', '為替', '宝くじ', '占い', '星座', '恋愛', '彼女', '彼氏', '旅行', '観光', '映画', 'ドラマ', 'アニメ', 'ゲーム',
    '人口', '首都', '歴史', '翻訳', 'しりとり', 'なぞなぞ', 'ジョーク', '面白い話', 'python', 'java', 'javascript', 'プログラムを書', 'コードを書', 'プログラミング',
    '社員証', '名刺', '年末調整', '給与明細', '交通費', '経費精算',
];
/** 関係のない質問（不適切な表現を含む）がこの回数続いたら、それからは返事をしない（関係のある質問をするか、分野を選ぶと戻る） */
const FAQ_UNRELATED_IGNORE_COUNT = 5;

/**
 * システムに関係のない質問か。システムの言葉（FAQ_SYSTEM_WORDS）が入っておらず、入れた文の言葉がよくある質問の文（標準の質問を含む）と
 * システムの言葉に半分以下しか出てこず、近い質問も弱い（半分以下）とき。言葉が 1 つも取れないとき（「1+1は」など）も関係のない質問とみなす。
 * 曖昧な質問（faq_is_vague()）かどうかは、先に確かめておく。
 *
 * @param array<int, array<string, mixed>> $faqList 載っているよくある質問
 */
function faq_is_unrelated(string $queryText, array $faqList): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    if (isset(FAQ_WHOLE_INPUT_SYNONYMS[str_replace(' ', '', $normalizedQuery)])) {
        return false;
    }
    foreach (FAQ_OFFTOPIC_STRONG_WORDS as $strongOfftopicWord) {
        if (strpos($normalizedQuery, $strongOfftopicWord) !== false) {
            return true;
        }
    }
    // 「誰が誰なの」のように、誰・だれ のほかに中身の無い文は、関係のない質問（「誰」の言い換えで管理者の答えに当てない）
    // （「あなたは誰」のように、言い換えの表にあるほかの言葉があれば、その言葉で答えを探す）
    $withoutWhoText = str_replace(['誰', 'だれ'], ' ', $normalizedQuery);
    if ($withoutWhoText !== $normalizedQuery && preg_match('/^[\p{Hiragana}ー〜~ ]*$/u', $withoutWhoText) === 1) {
        $hasOtherSynonym = false;
        foreach (array_keys(FAQ_SEARCH_SYNONYMS) as $synonymWord) {
            if (!in_array((string) $synonymWord, ['誰', 'はだれ', 'ってだれ'], true) && faq_text_contains_term($normalizedQuery, (string) $synonymWord)) {
                $hasOtherSynonym = true;
                break;
            }
        }
        if (!$hasOtherSynonym) {
            return true;
        }
    }
    $hasSystemWord = false;
    foreach (FAQ_SYSTEM_WORDS as $systemWord) {
        if (strpos($normalizedQuery, $systemWord) !== false) {
            $hasSystemWord = true;
            break;
        }
    }
    if (!$hasSystemWord) {
        foreach (FAQ_OFFTOPIC_WORDS as $offtopicWord) {
            if (strpos($normalizedQuery, $offtopicWord) !== false) {
                return true;
            }
        }
    }
    // 「上司がクリーントン」のように、人の主語のあとが、よくある質問に出てこないカタカナの言葉だけの文は、関係のない質問
    // （カタカナは 2 文字の並びで探すので、「リー」が「リーダー」に当たるような取り違えが起きるため）
    if (preg_match('/^(上司|課長|部長|係長|主任|社長|部下|同僚|先輩|後輩|管理者)(が|は|って)([\p{Katakana}ー]{3,})$/u', str_replace(' ', '', $normalizedQuery), $personKatakanaMatch) === 1) {
        $katakanaWord = $personKatakanaMatch[3];
        $knownText = implode(' ', FAQ_SYSTEM_WORDS) . ' ' . implode(' ', array_keys(FAQ_SEARCH_SYNONYMS)) . ' ' . implode(' ', FAQ_SEARCH_SYNONYMS);
        foreach ($faqList as $faqRow) {
            $knownText .= ' ' . $faqRow['question'] . ' ' . $faqRow['answer'];
        }
        if (strpos(faq_normalize_text($knownText), $katakanaWord) === false) {
            return true;
        }
    }
    $matchedFaqList = faq_search($queryText, $faqList);
    if ($matchedFaqList !== [] && $matchedFaqList[0]['match_ratio'] > FAQ_UNRELATED_MAX_RATIO) {
        return false;
    }
    if ($hasSystemWord) {
        return false;
    }
    $termList = faq_search_terms($queryText);
    if ($termList === []) {
        return true;
    }
    $corpusText = faq_normalize_text(implode(' ', FAQ_SYSTEM_WORDS) . ' ' . implode(' ', FAQ_CATEGORY_LABELS));
    $faqTextList = array_merge(
        array_map(static function (array $standardEntry): string { return $standardEntry[1] . ' ' . $standardEntry[2]; }, defined('FAQ_STANDARD_ENTRIES') ? FAQ_STANDARD_ENTRIES : []),
        array_map(static function (array $faqRow): string { return $faqRow['question'] . ' ' . $faqRow['answer']; }, $faqList)
    );
    foreach ($faqTextList as $faqText) {
        $corpusText .= ' ' . faq_normalize_text($faqText);
    }
    $foundTermCount = 0;
    foreach ($termList as $termText) {
        if (faq_text_contains_term($corpusText, $termText)) {
            $foundTermCount++;
        }
    }
    return $foundTermCount / count($termList) <= FAQ_UNRELATED_MAX_RATIO;
}

/**
 * 関係のない質問（不適切な表現を含む）が続いた回数を数える（ログインの間だけ。セッションに持つ）。関係のある質問・分野を選んだときは 0 に戻す。
 *
 * @return int 今の続いた回数
 */
function faq_count_unrelated(bool $isUnrelated): int
{
    $_SESSION['faq_unrelated_count'] = $isUnrelated ? (int) ($_SESSION['faq_unrelated_count'] ?? 0) + 1 : 0;
    return (int) $_SESSION['faq_unrelated_count'];
}

// ---- 亡くなったと書かれた質問（お悔やみの言葉を添える。2026-11-16 指示） ----
/** 人が亡くなったことを表す言葉。答えの文には決めて書かず、質問に書かれたときだけ、お悔やみを添える（いなくなった・交代した、だけでは添えない） */
const FAQ_DEATH_WORDS = ['亡くな', '亡くし', '急逝', '他界', '逝去', '死去', '永眠'];

/** 質問に、人が亡くなったことが書かれているか */
function faq_mentions_death(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    foreach (FAQ_DEATH_WORDS as $deathWord) {
        if (strpos($normalizedQuery, $deathWord) !== false) {
            return true;
        }
    }
    return false;
}

/** システム管理者・補佐にだけ見せる分野（管理の手順。ほかの人には、チャット・分野・すべての質問のどこにも出さない） */
const FAQ_STAFF_ONLY_CATEGORIES = ['admin'];

/**
 * その人に見せてよいよくある質問だけにする（管理者・補佐でなければ、管理者・補佐だけの分野を除く）。
 *
 * @param array<int, array<string, mixed>> $faqList
 * @return array<int, array<string, mixed>>
 */
function faq_visible_entries(array $faqList, array $user): array
{
    if (can_manage_system($user)) {
        return $faqList;
    }
    return array_values(array_filter($faqList, static function (array $faqRow): bool {
        return !in_array((string) ($faqRow['category'] ?? ''), FAQ_STAFF_ONLY_CATEGORIES, true);
    }));
}

/**
 * チャットに入れた文の判定（G-25 §2.6。チャット（faq_answer_chat()）と自動入力検証（tools/test/faq_input_check.php）が使う。記録・数えることはしない）。
 * 判定の順: 命・体の緊急の状態（§2.12。119 番を案内） → 心の不調らしい文（§2.8） → 自分の退職（§2.10。寄り添って上司への相談を促す）
 *   → 要配慮個人情報に触れる文（§2.9） → 人への暴力を書いた文 → 不適切な表現 → 呼びかけだけの文 → 曖昧な質問 → 関係のない質問 → 近い質問を探す。
 * 心の不調らしい文は、心配な言葉の段階なら近い質問も探して一緒に返す（「助けて、ログインできない」など）。
 *
 * @param array<int, array<string, mixed>> $faqList 聞いた人に見えるよくある質問
 * @return array{code: string, matches: array<int, array<string, mixed>>, distressLevel: string, noticeLevel: string, harassmentBySuperior: bool}
 *   code: emergency・distress・retirement・sensitive・violence（人への暴力）・abusive・opener（呼びかけだけ）・closing（用件がない）・vague・unrelated・matched・no_match。
 *   noticeLevel: 管理者に知らせる理由（心の不調の段階 → violence（人への暴力） → credential（K-057） → retirement（K-054）。なければ空）
 */
function faq_classify_question(string $questionText, array $faqList): array
{
    $matchedFaqList = [];
    $distressLevel = faq_distress_level($questionText);
    if (faq_is_emergency($questionText)) {
        // 命・体の緊急の状態: 119 番を案内する（心の不調の言葉も入っていれば、知らせる）
        $resultCode = 'emergency';
    } elseif ($distressLevel !== '') {
        $resultCode = 'distress';
        // 心配な言葉で、ほかにはっきり知りたいことがあるときだけ、その答えも出す（「助けて、ログインできない」。「仕事を辞めたい」の「仕事」のような弱い当たりは出さない）
        $matchedFaqList = $distressLevel === 'concern' ? faq_companion_matches(faq_strip_distress_words($questionText), $faqList) : [];
    } elseif (faq_is_retirement($questionText) && !faq_is_about_subordinate($questionText)) {
        // 自分の退職: 手続きは示さず、寄り添って上司（課長）への相談を促す（部下の退職は、下で手続きの答えを探す。どちらも管理者に知らせる）
        $resultCode = 'retirement';
    } elseif (faq_is_sensitive($questionText)) {
        // 要配慮個人情報に触れる文: 寄り添う返事。ほかにはっきり知りたいことがあれば、その答えも出す（「入院するので報告が出せない」）
        $resultCode = 'sensitive';
        $matchedFaqList = faq_companion_matches(faq_strip_sensitive_words($questionText), $faqList);
    } elseif (faq_is_violent($questionText)) {
        // 人への暴力を書いた文: 答えは出さず、カウンセリングを受けることをすすめる（断らない。下でシステム管理者・補佐に知らせる。2026-11-17 指示）
        $resultCode = 'violence';
    } elseif (faq_is_abusive($questionText)) {
        $resultCode = 'abusive';
    } elseif (faq_is_opener($questionText)) {
        // 呼びかけだけ（「大変です」）: 用件を聞き返す。断らない（断った回数に数えない）
        $resultCode = 'opener';
    } elseif (faq_is_closing($questionText)) {
        // 用件がない（「用件なし」「大丈夫です」）: 聞き返さずに受け止める。断らない
        $resultCode = 'closing';
    } elseif (faq_is_company_policy_question($questionText)) {
        // 勤務時間・手当など会社の決まりの質問: よくある質問では答えられない。見つからないとして、課長・確認者への相談・質問として送る案内を出す
        $resultCode = 'no_match';
    } elseif (faq_is_vague($questionText)) {
        $resultCode = 'vague';
    } elseif (faq_is_unrelated($questionText, $faqList)) {
        $resultCode = 'unrelated';
    } else {
        $matchedFaqList = faq_search($questionText, $faqList);
        $resultCode = $matchedFaqList ? 'matched' : 'no_match';
        // 亡くなったと書かれたときだけ、いちばんの答えにお悔やみを添える（答えの文には書かない。逃げた・交代しただけかもしれないので決めつけない）
        if ($matchedFaqList && faq_mentions_death($questionText) && strpos((string) $matchedFaqList[0]['answer'], 'お悔やみ') === false) {
            $matchedFaqList[0]['answer'] = '心よりお悔やみ申し上げます。' . "\n" . $matchedFaqList[0]['answer'];
        }
    }
    // 知らせる理由: 心の不調らしい文の段階 → 人への暴力（violence） → ID・パスワードを求める質問（credential。K-057） → 退職に関わる質問（retirement。K-054）
    $noticeLevel = $distressLevel;
    if ($noticeLevel === '' && faq_is_violent($questionText)) {
        $noticeLevel = 'violence';
    } elseif ($noticeLevel === '' && faq_is_credential_request($questionText)) {
        $noticeLevel = 'credential';
    } elseif ($noticeLevel === '' && !in_array($resultCode, ['abusive', 'violence'], true) && faq_is_retirement($questionText)) {
        $noticeLevel = 'retirement';
    }
    // 上司からのハラスメント: 心の不調の返事を、ほかの管理職・相談窓口への相談をすすめる文にする
    $isHarassmentBySuperior = $resultCode === 'distress' && faq_is_harassment_by_superior($questionText);
    return ['code' => $resultCode, 'matches' => $matchedFaqList, 'distressLevel' => $distressLevel, 'noticeLevel' => $noticeLevel,
            'harassmentBySuperior' => $isHarassmentBySuperior];
}

// ---- 会話（G-25 §2.14。2026-11-17 指示: 複数の発言を一つの会話として扱う） ----
/** 同じ人の発言を 1 つの会話とみなす間隔（秒）。これより空いたら新しい会話にする */
const FAQ_CONVERSATION_GAP_SECONDS = 600;
/** 会話の話題として覚える文の長さ（文字。長い文は先頭だけ） */
const FAQ_CONVERSATION_TOPIC_MAX_LENGTH = 100;

/**
 * 会話の中での判定。今の発言だけでは中身がない（曖昧な質問）とき、同じ会話の直前の話題とつなげて探し、答えが見つかればそれを返す。
 * 話題にするのは、答えた・見つからなかった発言だけ（緊急・心の不調・要配慮・暴力・関係のない質問などの文は話題にしない）。
 * チャット（faq_answer_chat()）と自動入力検証（tools/test/faq_input_check.php）が使う。記録・セッションには触れない。
 *
 * @param array<int, array<string, mixed>> $faqList 聞いた人に見えるよくある質問
 * @return array{classification: array, contextTopic: string, nextTopic: string}
 *   classification: faq_classify_question() と同じ形。contextTopic: 前の話題を補って答えたときの話題（補っていなければ空）。nextTopic: 次の発言で使う話題
 */
function faq_classify_in_conversation(string $questionText, string $previousTopic, array $faqList): array
{
    $classification = faq_classify_question($questionText, $faqList);
    $contextTopic = '';
    // 前の話題そのものの答えを出す（つなげた文で探し直すと、中身のない言葉で弱い答えに当たるため）。
    // 返事への聞き返し（「どういうこと？」「はい？」）には補わない（同じ答えをくり返しても役に立たないため）
    if ($classification['code'] === 'vague' && $previousTopic !== '' && !faq_is_clarification($questionText)) {
        $topicClassification = faq_classify_question($previousTopic, $faqList);
        if ($topicClassification['code'] === 'matched') {
            $classification = $topicClassification;
            $contextTopic = $previousTopic;
        }
    }
    $nextTopic = $previousTopic;
    if ($contextTopic === '' && in_array($classification['code'], ['matched', 'no_match'], true)) {
        $topicCharacterList = preg_split('//u', trim($questionText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $nextTopic = implode('', array_slice($topicCharacterList, 0, FAQ_CONVERSATION_TOPIC_MAX_LENGTH));
    } elseif ($contextTopic === '' && $classification['code'] !== 'vague') {
        // 緊急・心の不調・関係のない質問などのあとは、前の話題を持ち越さない。
        // 呼びかけ（「もしもし」「大変です」）のあとも、話が切り替わるので持ち越さない（古い話題の答えをくり返さないように）
        $nextTopic = '';
    }
    return ['classification' => $classification, 'contextTopic' => $contextTopic, 'nextTopic' => $nextTopic];
}

/**
 * 今の会話（セッションに持つ）。前の発言から FAQ_CONVERSATION_GAP_SECONDS 秒より空いていれば、新しい会話を始める。
 *
 * @return array{id: string, topic: string}
 */
function faq_conversation_current(): array
{
    $conversation = $_SESSION['faq_conversation'] ?? null;
    if (!is_array($conversation) || time() - (int) ($conversation['last_at'] ?? 0) > FAQ_CONVERSATION_GAP_SECONDS) {
        $conversation = ['id' => bin2hex(random_bytes(8)), 'topic' => '', 'last_at' => time()];
    }
    $conversation['last_at'] = time();
    $_SESSION['faq_conversation'] = $conversation;
    return ['id' => (string) $conversation['id'], 'topic' => (string) $conversation['topic']];
}

/** 返事への聞き返し（前の話題を補わない）。faq_normalize_text() でそろえ、空白を除いた文がこれだけでできているとき */
const FAQ_CLARIFICATION_PHRASES = ['どういうこと', 'どういう意味', '意味がわからない', '意味が分からない', 'はい', 'え', 'えっ', 'なに', '何',
    'じゃけん', 'だけん', 'やけん', 'じゃけ', 'やけ', 'けん', 'じゃん', 'やん', 'だべ', 'やで', 'ばい', 'なぁ', 'なあ', 'そうなんよ', 'そうなんですね', 'そうなんですか'];

/** 返事への聞き返しか（「どういうこと？」「はい？」） */
function faq_is_clarification(string $questionText): bool
{
    $compactText = str_replace(' ', '', faq_normalize_text($questionText));
    foreach (FAQ_CLARIFICATION_PHRASES as $clarificationPhrase) {
        $compactText = str_replace($clarificationPhrase, '', $compactText);
    }
    return $compactText === '' && trim(faq_normalize_text($questionText)) !== '';
}

/** 会話を終える（「解決した」を押したとき。次の発言から新しい会話） */
function faq_conversation_reset(): void
{
    unset($_SESSION['faq_conversation']);
}

/** 会話の話題を覚え直す（分野を選んだときは空にする） */
function faq_conversation_set_topic(string $topicText): void
{
    if (isset($_SESSION['faq_conversation']) && is_array($_SESSION['faq_conversation'])) {
        $_SESSION['faq_conversation']['topic'] = $topicText;
    }
}

/** 分野のコードとして正しいか（空の「そのほか」も含む） */
function faq_is_category(string $categoryCode): bool
{
    return array_key_exists($categoryCode, FAQ_CATEGORY_LABELS);
}

/**
 * faq_entries に分野の列が無ければ足す（本番で migrate.php を流す前でも、よくある質問の画面が動くように）。
 */
function faq_ensure_category_column(): void
{
    static $isChecked = false;
    if ($isChecked) {
        return;
    }
    $isChecked = true;
    foreach (db()->query('PRAGMA table_info(faq_entries)')->fetchAll() as $columnInfo) {
        if ($columnInfo['name'] === 'category') {
            return;
        }
    }
    db()->exec("ALTER TABLE faq_entries ADD COLUMN category TEXT NOT NULL DEFAULT ''");
}

/**
 * 曖昧な質問か: 中身のない言い方（FAQ_VAGUE_WORDS）を除くと、探す言葉が残らない。
 */
function faq_is_vague(string $queryText): bool
{
    $normalizedQuery = faq_normalize_text($queryText);
    // 入力の全体が言い換えに当たる言葉（「ワード」「ちゃうちゃう」）は、知りたいことがあるので曖昧とはしない
    if (isset(FAQ_WHOLE_INPUT_SYNONYMS[str_replace(' ', '', $normalizedQuery)])) {
        return false;
    }
    // 「システム系システム」のように、システムという言葉と つなぎの字 だけの文は、知りたいことが分からないので分野を選んでもらう
    if (preg_match('/^((この|その|あの)?(システム|しすてむ|system)|系|の|が|は|と|で|って|[?？!！。、 ])+$/u', $normalizedQuery) === 1) {
        return true;
    }
    // 「誰が誰なの」「だれがだれ」は、曖昧ではなく関係のない質問として扱う（faq_is_unrelated()）
    if (preg_match('/^(誰|だれ)(が|は|と|も)(誰|だれ)/u', str_replace(' ', '', $normalizedQuery)) === 1) {
        return false;
    }
    // 1 文字だけの入力（「は」「わ」）は、打ち間違い・途中で送ったものとみなし、分野を選んでもらう（断らない）
    $compactCharacterList = preg_split('//u', str_replace(' ', '', $normalizedQuery), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($compactCharacterList) === 1) {
        return true;
    }
    $remainingText = $normalizedQuery;
    $vagueWordList = FAQ_VAGUE_WORDS;
    usort($vagueWordList, static function (string $leftWord, string $rightWord): int {
        return strlen($rightWord) <=> strlen($leftWord);   // 長い言い方から除く（「このシステム」を「システム」より先に）
    });
    foreach ($vagueWordList as $vagueWord) {
        $remainingText = str_replace($vagueWord, ' ', $remainingText);
    }
    // 中身のない言い方だけでできた文（「返答して」「どういうこと？」）は、言い換えの表に当たっても曖昧とする
    if ($remainingText !== $normalizedQuery && trim($remainingText) === '') {
        return true;
    }
    // 言い換えの表にある言葉（概要・開発者・添付など）が入っていれば、知りたいことがあるので曖昧とはしない
    foreach (array_keys(FAQ_SEARCH_SYNONYMS) as $synonymWord) {
        if (faq_text_contains_term($normalizedQuery, (string) $synonymWord)) {
            return false;
        }
    }
    // ひらがなだけで、探す言葉が 1 つも取れない文（方言・話し言葉の「いってこうわい」など）は、断らずに分野を選んでもらう。
    // 関係のない話題の言葉（しりとり など）があれば、関係のない質問として扱う
    // （「だれがだれ」のような だれ の問いは、関係のない質問で扱う）
    if (preg_match('/^[\p{Hiragana}ー〜~ ]+$/u', $normalizedQuery) === 1 && faq_search_terms($queryText) === [] && strpos($normalizedQuery, 'だれ') === false) {
        foreach (array_merge(FAQ_OFFTOPIC_WORDS, FAQ_OFFTOPIC_STRONG_WORDS) as $offtopicWord) {
            if (strpos($normalizedQuery, $offtopicWord) !== false) {
                return false;
            }
        }
        return true;
    }
    // 中身のない言い方が入っていないもの（「あなたは誰」など、言葉が取れないだけの文）は曖昧とはしない（関係のない質問で扱う）
    return $remainingText !== $normalizedQuery && faq_search_terms($remainingText) === [];
}

/**
 * 分野ごとの件数（質問のある分野だけ。FAQ_CATEGORY_LABELS の並び）。
 *
 * @param array<int, array<string, mixed>> $faqList
 * @return array<int, array{code: string, label: string, count: int}>
 */
function faq_category_counts(array $faqList): array
{
    $countByCategory = [];
    foreach ($faqList as $faqRow) {
        $categoryCode = faq_is_category((string) ($faqRow['category'] ?? '')) ? (string) ($faqRow['category'] ?? '') : '';
        $countByCategory[$categoryCode] = ($countByCategory[$categoryCode] ?? 0) + 1;
    }
    $categoryCountList = [];
    foreach (FAQ_CATEGORY_LABELS as $categoryCode => $categoryLabel) {
        if (isset($countByCategory[$categoryCode])) {
            $categoryCountList[] = ['code' => (string) $categoryCode, 'label' => $categoryLabel, 'count' => $countByCategory[$categoryCode]];
        }
    }
    return $categoryCountList;
}

/**
 * 分野の質問（並び順）。
 *
 * @param array<int, array<string, mixed>> $faqList
 * @return array<int, array<string, mixed>>
 */
function faq_in_category(array $faqList, string $categoryCode): array
{
    return array_values(array_filter($faqList, static function (array $faqRow) use ($categoryCode): bool {
        $rowCategory = faq_is_category((string) ($faqRow['category'] ?? '')) ? (string) ($faqRow['category'] ?? '') : '';
        return $rowCategory === $categoryCode;
    }));
}

// ---- よくある質問を探す（G-25 §2.1。AI は使わず、文字の並びの一致で近い質問を出す） ----
const FAQ_SEARCH_QUERY_MAX_LENGTH = 200;
/** 出す近い質問の数 */
const FAQ_SEARCH_RESULT_MAX_COUNT = 5;
/** 近いとみなす一致の割合（質問の言葉が「質問」に当たれば 2 点、「答え」だけなら 1 点。満点に対する割合） */
const FAQ_SEARCH_MIN_RATIO = 0.25;

/**
 * 言い換え（入れた言葉 => よくある質問で使う言葉。空白で区切って複数）。入れた質問にあれば、右の言葉でも探す。
 * 左は faq_normalize_text() でそろえた形（英字は小文字・半角）で書く。
 */
const FAQ_SEARCH_SYNONYMS = [
    'スマホ' => 'スマートフォン', '携帯' => 'スマートフォン', 'iphone' => 'スマートフォン', 'android' => 'スマートフォン',
    '機種変' => '買い替え スマートフォン', 'スマホ変え' => '機種変更 買い替え', 'スマホを変え' => '機種変更 買い替え', '課代' => '課長代理 確認者', 'なくし' => '紛失', '無くし' => '紛失', '落とし' => '紛失', '盗' => '紛失', 'パソコン' => 'pc',
    '二段階' => '2段階', '2fa' => '2段階認証', '認証アプリ' => '2段階認証', 'ワンタイム' => '2段階認証 コード',
    'pw' => 'パスワード', 'password' => 'パスワード', 'login' => 'ログイン', 'logon' => 'ログイン', 'sign in' => 'ログイン', 'signin' => 'ログイン',
    'logout' => 'ログアウト', 'logoff' => 'ログアウト', 'sign out' => 'ログアウト', 'signout' => 'ログアウト', 'ログオフ' => 'ログアウト', 'サインアウト' => 'ログアウト',
    'サインオフ' => 'ログアウト', 'サインイン' => 'ログイン', 'ログオン' => 'ログイン', 'sp' => 'スマートフォン', '先月' => '前月', '翌月' => '今月 前月 締め日',
    'ダーク' => 'テーマ', '返答' => '返信 回答', 'インしたい' => 'ログインのしかた ふだん', 'オンしたい' => 'ログインのしかた ふだん', 'ログインできない' => 'ログイン ロック パスワード',
    '締切' => '締め日', '締め切' => '締め日', '期限' => '締め日',
    '修正' => '直す 直せ', '訂正' => '直す 直せ', '変更' => '変える 直す', '編集' => '直す',
    '削除' => '消す 消せ', '取り消' => '消す 戻す',
    'エクスポート' => '出力', 'ダウンロード' => '出力', 'エクセル' => 'csv 出力', 'excel' => 'csv 出力', '印刷' => '印刷 出力',
    '差戻' => '差し戻', '却下' => '差し戻', '承認' => 'チェック 確認',
    '色' => 'テーマ 色', '文字が小さい' => '文字の大きさ', '見にくい' => 'テーマ 見分け',
    '概要' => '概要 目的', '何するもの' => '何をする 概要', '何をするもの' => '概要', '何のシステム' => '概要 目的', 'システムとは' => '概要',
    '目的' => '概要 目的', '開発者' => '開発 問い合わせ先', '作った人' => '開発者', '誰が作' => '開発者', 'だれが作' => '開発者', '誰' => '誰 名前', 'はだれ' => '誰 名前', 'ってだれ' => '誰 名前', '関係者' => '誰 名前 システム管理者', 'パスワードがほし' => '他の人の パスワード 知りたい', 'パスワードを教え' => '他の人の パスワード 知りたい',
    'パスワードを知り' => '他の人の パスワード 知りたい', 'パスワードをくだ' => '他の人の パスワード 知りたい', '権限' => '権限がほしい', '障害' => '止まった 障害', 'ダウン' => '止まった 障害', '停止' => '止まった 障害', 'サーバー' => '止まった 障害', '稼働率' => '止まった 障害', '復旧' => '止まった 障害',
    '本番環境' => '止まった 障害', '不正アクセス' => '不正アクセス 漏れた', '侵入' => '不正アクセス 漏れた', '攻撃' => '不正アクセス 漏れた', 'ランサムウェア' => '不正アクセス 漏れた',
    '奪われ' => '不正アクセス 漏れた', '漏えい' => '不正アクセス 漏れた', '漏洩' => '不正アクセス 漏れた', '乗っ取' => '不正アクセス 漏れた', 'ロックアウト' => '不正アクセス 漏れた', '権限を表示' => '何をする 仕事 役割', '権限を見' => '何をする 仕事 役割', '権限の一覧' => '何をする 仕事 役割', '何ができる' => '何をする 仕事 役割', '権限を与え' => '権限がほしい', '権限を付与' => '権限がほしい', '権限を賦与' => '権限がほしい', '誰もしらない' => '開発担当もいない だれも分からない', '誰も知らない' => '開発担当もいない だれも分からない', 'だれも知らない' => '開発担当もいない だれも分からない', '開発担当も' => '開発担当もいない', '稼働' => 'いつでも使える 使える時間', '管理者になりたい' => '権限がほしい',
    '管理者にしたい' => '権限がほしい', '管理者にして' => '権限がほしい', '前任' => '引き継ぎ 前任 交代', '交代' => '引き継ぎ 交代', '後任' => '引き継ぎ 交代', '担当者' => '誰 名前 システム管理者',
    '補佐は何をする' => '仕事 役割', '管理者は何をする' => '仕事 役割', '補佐の仕事' => '仕事 役割', '管理者の仕事' => '仕事 役割', '出張' => '出張 出せない', '研修' => '出張 研修', '結婚' => '名前が変わった 改姓', '改姓' => '名前が変わった', '旧姓' => '名前が変わった',
    '苗字' => '名前が変わった 改姓', '名字' => '名前が変わった 改姓', '管理者の名前' => '管理者 だれ', '補佐' => '補佐 システム管理者補佐', '管理補佐' => 'システム管理者補佐', '管理者はだれ' => 'システム管理者 だれ', '管理者は誰' => 'システム管理者 だれ', '動かない' => '表示 おかしい 古い', '固まっ' => '表示 おかしい 古い', '問い合わせ先' => '問い合わせ先 開発者',
    '添付' => '添付 ファイル', 'ファイルを付け' => '添付', 'ファイルをつけ' => '添付', '画像' => '添付',
    '余白' => '帳票 余白 レイアウト 印刷', 'レイアウト' => '帳票 レイアウト', '帳票' => '出力 帳票',
    '一般' => '部下', '機能' => '機能 ほしい', '報告できない' => '出せない 提出できない', '出せない' => '提出できない', '問題がある' => '不具合', 'システム障害' => '不具合 システムへの要望', '障害が起き' => '不具合 システムへの要望',
    '返答できません' => 'チャット 返答', '返答できない' => 'チャット 返答', '受け付けない' => 'チャット 返答', '関わること' => 'チャット 返答',
    '色彩' => 'テーマ 色', '配色' => 'テーマ 色', '重い' => '重い 遅い', '遅い' => '重い 遅い', '夜桜' => 'テーマ', 'さくら' => 'テーマ', '若葉' => 'テーマ',
    '部下管理' => '部下 管理 課の管理', '増えた' => '追加', '異動' => '異動 追加', '新しい人' => '部下 追加', '新人' => '部下 追加', '入社' => '部下 追加',
    'ちぐはぐ' => 'チャット 合っていない', '合っていない' => 'チャット 合っていない', '的外れ' => 'チャット 合っていない', '違う答え' => 'チャット 合っていない',
    '退職' => '退職 無効', '他界' => '亡くなった 忌引', '亡くな' => '亡くなった 忌引', '忌引' => '忌引 亡くなった', '葬儀' => '忌引 亡くなった', '逝去' => '忌引 亡くなった', '今度' => '自分', '来月' => '自分', '私が' => '自分', 'わたしが' => '自分', '退職予定' => '自分 退職', '異動予定' => '自分 異動', '部下が退職' => '部下が退職 ユーザー 無効', '部下が辞め' => '部下が退職 ユーザー 無効', '部下が異動' => '部下 異動 ユーザー', '役職変更' => '役職 変わる', '役職が変' => '役職 変わる', '役職が' => '役職 変わる', '役職の' => '役職 変わる', '辞め' => '退職 無効', '辞職' => '退職 無効', '改編' => '組織 改訂 改編', '組織変更' => '組織 改訂', '再編' => '組織 改訂',
    '統合' => '組織 改訂', '分割' => '組織 改訂', '部門' => '課 組織', '部署' => '課 組織', '廃止' => '廃止', '昇格' => '役職', '昇進' => '役職', '他の課' => 'ほかの課', '他課' => 'ほかの課', 'よその課' => 'ほかの課',
    'おくる' => '送る', '送る' => '送る', '送りたい' => '送る', '課長代理' => '課長代理 できること', '代理' => '課長代理 できること', 'リーダー' => '課長代理 確認者',
    // 自動入力検証（tools/test/faq_input_cases.tsv）で見つかった言い換え
    'ログインできません' => 'パスワード 違います', 'しばらく' => 'ロック ロックされた 何回 間違える', '買い替え' => '機種変更 買い替え 認証アプリ', '買い換え' => '機種変更 買い替え 認証アプリ',
    'なくした' => '紛失 盗まれ', 'コピー' => '写したい 過去', '前回' => '過去 前月', '完了にしたい' => '完了にしたい 課長',
    'ダークモード' => '画面の色 テーマ 変えたい', 'ダークテーマ' => '画面の色 テーマ 変えたい', '不具合' => '不具合 要望 伝えたい',
    'プロジェクト' => '案件', 'pj' => '案件',
    // ひらがなで入れたとき
    'ろぐいん' => 'ログイン', 'ろぐいんでき' => 'ログイン パスワード 違います', 'ろぐあうと' => 'ログアウト', 'ぱすわーど' => 'パスワード', 'ほうこく' => '報告', 'しめきり' => '締め日 いつ', 'しめび' => '締め日',
    'にんしょう' => '認証 2段階認証', 'こめんと' => 'コメント', 'ていしゅつ' => '提出', 'すまほ' => 'スマートフォン',
    // 使う端末・場所
    'ipad' => 'スマートフォン 端末 使えるか', 'タブレット' => 'スマートフォン 端末 使えるか', 'mac' => '端末 使えるか ブラウザー', 'linux' => '端末 使えるか ブラウザー',
    'windows' => '端末 使えるか ブラウザー', '自宅' => '端末 使えるか', '家から' => '端末 使えるか', '家の' => '端末 使えるか', '社外から' => '端末 使えるか', '外出先' => '端末 使えるか',
    '在宅' => '端末 使えるか', 'テレワーク' => '端末 使えるか', 'キャッシュ' => '表示 おかしい 古い',
    // 報告の書き方・見る人・知らせ
    '何を書' => '報告に何を書けばよいか 書き方', '書き方' => '報告に何を書けばよいか 書き方', 'どのくらい書' => '報告に何を書けばよいか 書き方', '書くことがない' => '報告に何を書けばよいか 書き方',
    '作業時間' => '報告に何を書けばよいか 書き方', '仕事がなかった' => '報告に何を書けばよいか 書き方', '仕事がない' => '報告に何を書けばよいか 書き方', '顧客' => '客先名 黄色の注意', '客先名' => '客先名 黄色の注意', 'お客様' => '客先名 黄色の注意',
    'だれが見る' => 'だれが見る 見られる人', '誰が見る' => 'だれが見る 見られる人', '以外にも見' => 'だれが見る 見られる人', 'だれが見られ' => 'だれが見る 見られる人', '誰が見られ' => 'だれが見る 見られる人',
    // グレーゾーンの自動入力検証 2 で見つかった言い換え
    '404' => 'ページが見つかりません 権限がありません エラー', '403' => 'ページが見つかりません 権限がありません エラー', 'ページが見つか' => 'ページが見つかりません 権限がありません',
    '権限がありません' => 'ページが見つかりません 権限がありません', '500' => '止まった 障害 エラー', '通信エラー' => '止まった 障害 表示 おかしい', 'エラー' => 'エラー 表示 おかしい',
    '読み込み' => '表示 おかしい 古い', '反応しな' => '表示 おかしい 古い', '他の班' => '他課 情報', '戻るボタン' => '下書き 保存', 'タブを閉じ' => '下書き 保存',
    'ログインid' => 'ログイン id を忘れた', 'idが分から' => 'ログイン id を忘れた', 'idを忘れ' => 'ログイン id を忘れた', 'パスワードの条件' => 'パスワードを変えたい', '何文字' => 'パスワードを変えたい',
    '何回間違え' => 'ロック 何回 間違える', 'コードの有効期限' => '2段階認証 コード どこで見る', 'qr' => 'セットアップキー 時間ベース', '時計' => 'コードが違います 時刻', '締め切りいつ' => '締切 期限', '締め切りはいつ' => '締切 期限',
    '提出できたか' => '報告の状態 提出済み', '出したか分から' => '報告の状態 提出済み', '知らせが行' => 'メールや通知は来るか', '前月分' => '締め日を過ぎても 前月', '先月分' => '締め日を過ぎても 前月',
    '報告を取り下げ' => '提出を取り消したい', '締め日はだれ' => '締め日を変えたい 課長', '締め日は誰' => '締め日を変えたい 課長', '差し戻' => '差し戻 却下',
    '部下の報告' => '課の人の提出状況 未チェックの報告', 'にコメントしたい' => 'コメントのしかた 確認者', '確認者を外' => '確認者を決めたい', '課長になった' => '役職が変わる 昇格',
    '班の分け' => '班ごとに報告を見る 管理の仕方', '確認完了を取り消' => '確認を完了にしたい 課長', '未提出' => '課の人の提出状況', '催促' => '課の人の提出状況',
    'ログインしたまま' => '毎回 2段階認証 聞かれる 覚え', '安全' => 'セキュリティ 暗号化 安全', '暗号' => 'セキュリティ 暗号化 安全',  '勝手に' => '自動で',
    '文字を大き' => '文字を大きくしたい 拡大', 'ズーム' => '文字を大きくしたい 拡大', '拡大' => '文字を大きくしたい 拡大', '押しにくい' => '文字を大きくしたい 拡大', 'ガラケー' => 'スマートフォン 端末 使えるか',
    'チャットの内容' => 'チャットに入れた質問は記録されるか', '記録され' => 'チャットに入れた質問は記録されるか', '人が読' => 'チャットに入れた質問は記録されるか',
    '誰が答え' => 'チャットに入れた質問は記録されるか', 'だれが答え' => 'チャットに入れた質問は記録されるか', '要望はどこ' => 'システムへの要望 伝えたい', '締め日はいつ' => '締切 期限', '締めた' => '締切 期限', 'マニュアル' => '使い方の説明はどこにあるか この画面の使い方', '手順書' => '使い方の説明はどこにあるか この画面の使い方', '説明書' => '使い方の説明はどこにあるか この画面の使い方', 'まにゅある' => '使い方の説明はどこにあるか この画面の使い方', 'ステータス' => '報告の状態 下書き 提出済み 確認完了 意味', '状態管理' => '報告の状態 下書き 提出済み 確認完了 意味', '左遷させ' => '異動 ユーザー 追加 編集', '左遷する' => '異動 ユーザー 追加 編集', '任命' => '確認者 決めたい 役職', '任じ' => '確認者 決めたい 役職', '返答は出来' => 'チャット 返答 何に答える', '返答出来' => 'チャット 返答 何に答える', '返答できない' => 'チャット 返答 何に答える', '返答は出来ません' => 'チャット 返答 何に答える', '本人確認' => '2段階認証 コード ログイン', '社外情報' => '黄色の注意 客先名 社外秘', '顧客情報' => '黄色の注意 客先名', '客先情報' => '黄色の注意 客先名', '個人情報書' => '黄色の注意 客先名 個人名 書かない 可能性', '個人情報を書' => '黄色の注意 客先名 個人名 書かない 可能性', '個人情報を入' => '黄色の注意 客先名 個人名 書かない 可能性', '個人名を書' => '黄色の注意 客先名 個人名 書かない 可能性', '方言' => 'チャット 何に答える 返答', '報告する' => '今月の報告 どこから 作る', '報告したい' => '今月の報告 どこから 作る', '報告します' => '今月の報告 どこから 作る', '報告しよう' => '今月の報告 どこから 作る', '課長が休職' => '課長を変えたい 課を追加', '課長が急な休職' => '課長を変えたい 課を追加', '課長が入院' => '課長を変えたい 課を追加', '課長が不在' => '課長を変えたい 課を追加', '上司が休職' => '課長を変えたい 課を追加', '部長は使え' => '部長 役員 使えるか 役職と役割', '取締役は使え' => '部長 役員 使えるか 役職と役割', '役員は使え' => '部長 役員 使えるか 役職と役割', '社長は使え' => '部長 役員 使えるか 役職と役割', '蚊帳の外' => '部長 役員 使えるか 役職と役割', '課長が逃' => '課長を変えたい 課を追加', '課長が失踪' => '課長を変えたい 課を追加', '課長がいなくな' => '課長を変えたい 課を追加', '課長が辞め' => '課長を変えたい 課を追加', '課長が退職' => '課長を変えたい 課を追加', '課長と連絡が' => '課長を変えたい 課を追加', '上司が逃' => '課長を変えたい 課を追加', '上司がいなくな' => '課長を変えたい 課を追加', '上司が辞め' => '課長を変えたい 課を追加', '上司が退職' => '課長を変えたい 課を追加', '逃亡' => '課長を変えたい 課を追加', '失踪' => '課長を変えたい 課を追加', '管理者がいなくな' => 'システム管理者の権限 引き継ぐ 引き継ぎ', '管理者が急に' => 'システム管理者の権限 引き継ぐ 引き継ぎ', '管理者が辞め' => 'システム管理者の権限 引き継ぐ 引き継ぎ', '管理者が逃' => 'システム管理者の権限 引き継ぐ 引き継ぎ', '連絡方法' => '問い合わせ先 開発者', '連絡先' => '問い合わせ先 開発者', '連絡したい' => '問い合わせ先 開発者', '連絡の方法' => '問い合わせ先 開発者', '連絡のしかた' => '問い合わせ先 開発者', '連絡の仕方' => '問い合わせ先 開発者', '連絡する方法' => '問い合わせ先 開発者', '問い合わせ方法' => '問い合わせ先 開発者', '問い合わせたい' => '問い合わせ先 開発者', '問合せ' => '問い合わせ先 開発者', '問合わせ' => '問い合わせ先 開発者', 'お問い合わせ' => '問い合わせ先 開発者', 'バックドア' => 'データの管理方法 保存場所 機密情報', '裏口' => 'データの管理方法 保存場所 機密情報', '抜け道' => 'データの管理方法 保存場所 機密情報', '隠しコマンド' => 'データの管理方法 保存場所 機密情報', 'セキュリティのしくみ' => 'データの管理方法 保存場所 機密情報', 'セキュリティの仕組み' => 'データの管理方法 保存場所 機密情報', 'データ管理' => 'データの管理方法 保存場所 機密情報', 'データの管理' => 'データの管理方法 保存場所 機密情報', 'どこに保存' => 'データの管理方法 保存場所 機密情報', 'データ保存' => 'データの管理方法 保存場所 機密情報', 'データの保存' => 'データの管理方法 保存場所 機密情報', '保存場所' => 'データの管理方法 保存場所 機密情報', '管理方法' => 'データの管理方法 保存場所 機密情報', 'データは暗号' => 'データの管理方法 保存場所 機密情報', '暗号化の方法' => 'データの管理方法 保存場所 機密情報', '暗号化のしかた' => 'データの管理方法 保存場所 機密情報', '暗号化されて' => 'データの管理方法 保存場所 機密情報', '処理落ち' => '止まった 障害 重い 遅い', '遅く' => '重い 遅い', '遅れる' => '重い 遅い', '動作環境' => 'ブラウザー 端末 使える', '環境は' => 'ブラウザー 端末 使える', '効かない' => '保存できない メンテナンス中 表示 おかしい', 'きかない' => '保存できない メンテナンス中 表示 おかしい', '課長権限' => '課長を変えたい 役職 昇格', '課長の権限' => '課長を変えたい 役職 昇格',
    // 他端末検証（202611171340）の 1 語の入力
    'マルウェア' => '不正アクセス 漏れた 攻撃', 'ウイルス' => '不正アクセス 漏れた 攻撃', 'ウィルス' => '不正アクセス 漏れた 攻撃', '身代金' => '不正アクセス 漏れた 攻撃',
    '脆弱性' => '不正アクセス セキュリティ 安全', 'サイバー' => '不正アクセス 漏れた 攻撃', '改ざん' => '不正アクセス 漏れた', '改竄' => '不正アクセス 漏れた', 'ハッキング' => '不正アクセス 漏れた', 'フィッシング' => '不正アクセス 漏れた',
    'フリーズ' => '止まった 障害', 'クラッシュ' => '止まった 障害', 'ハングアップ' => '止まった 障害', 'アップデート' => '更新のお知らせ', 'バージョンアップ' => '更新のお知らせ',
    '要求' => 'システムへの要望 伝えたい', '要請' => 'システムへの要望 伝えたい', 'リクエスト' => 'システムへの要望 伝えたい', '具申' => 'システムへの要望 伝えたい', '希望' => 'システムへの要望 伝えたい',
    'モバイル' => 'スマートフォン 端末 使えるか', 'ケータイ' => 'スマートフォン 端末 使えるか', '通信端末' => 'スマートフォン 端末 使えるか', 'デバイス' => 'スマートフォン 端末 使えるか',
    'xls' => 'csv 出力', 'カンマ' => 'csv 出力', '事業再編' => '組織 改訂 改編', '組織再編' => '組織 改訂 改編', '組織変更' => '組織 改訂 改編', '要約' => '機能 改善要望 要望 依頼', 'まとめてほしい' => '機能 ほしい 機能がない', '型式' => '課の報告の項目 足したい 変えたい', '形式を' => '課の報告の項目 足したい 変えたい', '様式' => '課の報告の項目 足したい 変えたい', 'フォーマット' => '課の報告の項目 足したい 変えたい', '上司を変え' => '課長を変えたい 課を追加', '上司を替え' => '課長を変えたい 課を追加', '使えなくな' => '止まった 障害', '出力でき' => '出力の画面 pc から行ってください と出る', 'データ出力' => '出力', '会話' => 'チャット 合っていない ちぐはぐ', '開発担当' => '開発者 問い合わせ先', '開発者は' => '開発者 問い合わせ先', '他の人' => '他課 情報',
    'ホウコク' => '報告', 'totp' => '2段階認証 コード', 'otp' => '2段階認証 コード', 'submit' => '提出', 'report' => '報告', 'deadline' => '締め日',
    '通知' => 'メール 通知 お知らせ', '通知を' => 'メールや通知は来るか お知らせの届き方', 'プッシュ' => 'メールや通知は来るか お知らせの届き方', 'メール' => 'メール 通知', '休みだった' => '出張 研修 締め日までに報告を出せない', '休んで' => '出張 研修 締め日までに報告を出せない', 'あなた' => 'チャット 何に答える 返答', 'きみは' => 'チャット 何に答える 返答', '君は' => 'チャット 何に答える 返答',
];

/**
 * 探すために文をそろえる: 全角の英数字・記号を半角に、英字を小文字に、記号と空白を 1 つの空白にする。
 * （mbstring が無いサーバーでも動くよう、mb_ の関数は使わない）
 */
/**
 * 入力の全体がこの言葉のときだけ使う言い換え（ほかの言葉の一部になっていて、ふつうの言い換えでは取り違えるもの。
 * 「ワード」は「パスワード」の一部なので、入力が「ワード」だけのときだけ、出力の形式の質問として探す）。faq_search_terms() で使う
 */
const FAQ_WHOLE_INPUT_SYNONYMS = ['ワード' => 'excel pdf csv 出力', 'word' => 'excel pdf csv 出力',
    // 答えが違う、という言い方（チャットの答えが合っていないときの答え）
    'ちゃうちゃう' => 'チャット 合っていない ちぐはぐ', 'ちゃう' => 'チャット 合っていない ちぐはぐ', 'ちゃうけん' => 'チャット 合っていない ちぐはぐ', 'ちがう' => 'チャット 合っていない ちぐはぐ',
    '違う' => 'チャット 合っていない ちぐはぐ', '違います' => 'チャット 合っていない ちぐはぐ', 'ちがいます' => 'チャット 合っていない ちぐはぐ', 'そうじゃない' => 'チャット 合っていない ちぐはぐ',
    '答えが違う' => 'チャット 合っていない ちぐはぐ'];

/** 半角カナ → 全角カナの対応（濁点・半濁点の付いた 2 文字を先に。mbstring が無くても動くよう、自前の表で変える） */
function faq_halfwidth_kana_map(): array
{
    static $kanaMap = null;
    if ($kanaMap !== null) {
        return $kanaMap;
    }
    $halfwidthList = preg_split('//u', 'ｦｧｨｩｪｫｬｭｮｯｰｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉﾊﾋﾌﾍﾎﾏﾐﾑﾒﾓﾔﾕﾖﾗﾘﾙﾚﾛﾜﾝ｡｢｣､･', -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $fullwidthList = preg_split('//u', 'ヲァィゥェォャュョッーアイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワン。「」、・', -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $kanaMap = [];
    $voicedList = preg_split('//u', 'ガギグゲゴザジズゼゾダヂヅデドバビブベボ', -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (preg_split('//u', 'ｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾊﾋﾌﾍﾎ', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $voicedIndex => $halfwidthCharacter) {
        $kanaMap[$halfwidthCharacter . 'ﾞ'] = $voicedList[$voicedIndex];
    }
    $semiVoicedList = preg_split('//u', 'パピプペポ', -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (preg_split('//u', 'ﾊﾋﾌﾍﾎ', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $semiVoicedIndex => $halfwidthCharacter) {
        $kanaMap[$halfwidthCharacter . 'ﾟ'] = $semiVoicedList[$semiVoicedIndex];
    }
    $kanaMap['ｳﾞ'] = 'ヴ';
    foreach ($halfwidthList as $kanaIndex => $halfwidthCharacter) {
        $kanaMap[$halfwidthCharacter] = $fullwidthList[$kanaIndex];
    }
    $kanaMap['ﾞ'] = '';
    $kanaMap['ﾟ'] = '';
    return $kanaMap;
}

function faq_normalize_text(string $text): string
{
    static $fullwidthToHalfwidthMap = null;
    if ($fullwidthToHalfwidthMap === null) {
        $fullwidthToHalfwidthMap = ["\u{3000}" => ' '];
        for ($codePoint = 0xFF01; $codePoint <= 0xFF5E; $codePoint++) {
            $fullwidthCharacter = chr(0xEF) . chr(0xBC + (($codePoint >> 6) & 0x01)) . chr(0x80 | ($codePoint & 0x3F));
            $fullwidthToHalfwidthMap[$fullwidthCharacter] = chr($codePoint - 0xFEE0);
        }
    }
    $normalizedText = strtolower(strtr(strtr($text, faq_halfwidth_kana_map()), $fullwidthToHalfwidthMap));
    $normalizedText = (string) preg_replace('/[\s\p{P}\p{S}]+/u', ' ', $normalizedText);
    // 日本語の文字の間の空白は詰める（「ログイン　できない」「パスワードを、忘れました」を続けて探す。英数字の間は語の区切りとして残す）
    $normalizedText = (string) preg_replace('/(?<=[^\x00-\x7F]) (?=[^\x00-\x7F])/u', '', $normalizedText);
    return trim($normalizedText);
}

/**
 * そろえた文に、探す言葉があるか。英数字だけの言葉は、前後が英数字でないところだけ（単語として）当てる
 * （「ip」が「iphone」に、「then」が「authenticator」に当たらないように）。日本語はそのまま部分一致。
 */
function faq_text_contains_term(string $normalizedText, string $termText): bool
{
    if (preg_match('/^[a-z0-9]+$/', $termText) === 1) {
        return preg_match('/(?<![a-z0-9])' . preg_quote($termText, '/') . '(?![a-z0-9])/', $normalizedText) === 1;
    }
    return strpos($normalizedText, $termText) !== false;
}

/**
 * 探す言葉を取り出す。英数字はひと続きを 1 語（2 文字以上）。日本語は 2 文字ずつの並び（ひらがなだけの並びは
 * 「ので」「ます」のような言い回しが多いので使わない）。漢字・カタカナが 1 文字だけのときは、その 1 文字。
 *
 * @return string[] 重なりのない言葉の並び
 */
function faq_search_terms(string $queryText): array
{
    $termSet = [];
    // 数字とその単位（「10時」「3日」「2026年」など）と、時刻の飾り（AM・PM・午前・午後）は、探す言葉にしない
    // （「AM10:00 に提出」の「提出」で探せるように。「10時」の「時」が「12 時間」に当たらないように）
    $normalizedQuery = (string) preg_replace('/(?<![a-z])(am|pm)(?![a-z])|午前|午後/u', ' ', faq_normalize_text($queryText));
    $normalizedQuery = (string) preg_replace('/(?<![a-z0-9])[0-9]+(?![a-z0-9])(時間|時|分|秒|日|か月|ヶ月|カ月|月|年|回|件|歳|人)?/u', ' ', $normalizedQuery);
    // 言い換えの表は、数字を除く前の文で見る（「404」「500」も言い換えられるように）
    $synonymSourceQuery = faq_normalize_text($queryText);
    // 面倒・めんどくさい・したくない: ログイン・認証の話なら「毎回 2 段階認証」、報告の話なら手間を減らす答え（変更なし・前の報告を写す）
    $compactSynonymSource = str_replace(' ', '', $synonymSourceQuery);
    if (preg_match('/面倒|めんど|したくない|出したくない|書きたくない|手間/u', $compactSynonymSource) === 1) {
        if (preg_match('/ログイン|認証|コード/u', $compactSynonymSource) === 1) {
            $normalizedQuery .= ' 毎回 2段階認証 聞かれる';
        } elseif (strpos($compactSynonymSource, '報告') !== false) {
            $normalizedQuery .= ' 前月と同じ 変更なし 過去の報告 写したい';
        }
    }
    // マニュアル・手順書・説明書の話は、使い方の説明の答えを探す（「システム運用マニュアル」の「システム」「運用」で管理者の答えに当てない）
    if (preg_match('/マニュアル|まにゅある|手順書|説明書/u', $compactSynonymSource) === 1) {
        $normalizedQuery = str_replace(['システム', '運用'], ' ', $normalizedQuery);
    }
    // 課長・上司がいない（休職・入院・不在・逃げた・辞めた など）は、間に言葉が入っても（「課長が急に休職中になった」）課長を変える答えを探す
    if (preg_match('/(課長|上司)(が|は).{0,12}(休職|入院|不在|いな|逃|失踪|辞め|退職|連絡がつかな|連絡が取れな)/u', str_replace(' ', '', $synonymSourceQuery)) === 1) {
        $normalizedQuery .= ' 課長を変えたい 課を追加';
    }
    // 入力の全体が「ワード」などのときは、その言葉の代わりに言い換えで探す（「パスワード」の答えに当たらないように）
    $wholeInputKey = str_replace(' ', '', $synonymSourceQuery);
    if (isset(FAQ_WHOLE_INPUT_SYNONYMS[$wholeInputKey])) {
        $normalizedQuery = FAQ_WHOLE_INPUT_SYNONYMS[$wholeInputKey];
        $synonymSourceQuery = $normalizedQuery;
    }
    foreach (FAQ_SEARCH_SYNONYMS as $typedWord => $faqWordText) {
        if (faq_text_contains_term($synonymSourceQuery, (string) $typedWord)) {
            $normalizedQuery .= ' ' . $faqWordText;
        }
    }
    preg_match_all('/[a-z0-9]+|[^a-z0-9 ]+/u', $normalizedQuery, $runMatchList);
    foreach ($runMatchList[0] as $runText) {
        if (preg_match('/^[a-z0-9]+$/', $runText) === 1) {
            // 数字だけの言葉（時刻・日付・回数など）は、答えの中の別の数字に当たってしまうので探す言葉にしない（「10:30」が「10 文字以上」に当たらないように）
            if (strlen($runText) >= 2 && preg_match('/^[0-9]+$/', $runText) !== 1) {
                $termSet[$runText] = true;
            }
            continue;
        }
        $characterList = preg_split('//u', $runText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($characterList) === 1) {
            if (preg_match('/^\p{Hiragana}$/u', $runText) !== 1) {
                $termSet[$runText] = true;
            }
            continue;
        }
        for ($characterIndex = 0; $characterIndex < count($characterList) - 1; $characterIndex++) {
            $bigramText = $characterList[$characterIndex] . $characterList[$characterIndex + 1];
            // ひらがなで始まる並び（「の人」「を機」）と、助詞で終わる並び（「日は」「面が」）は、言葉の区切りをまたぐので使わない。
            // 「忘れ」「直せ」のような、漢字に送り仮名が続く並びは使う
            if (preg_match('/^\p{Hiragana}/u', $bigramText) !== 1 && preg_match('/[はがをにのでともへや]$/u', $bigramText) !== 1) {
                $termSet[$bigramText] = true;
            }
        }
    }
    // 数字だけの言葉（"10" など）は、配列のキーにすると整数に変わるので、文字列に戻す（戻さないと strpos() などが止まり「通信エラー」になる）
    return array_map('strval', array_keys($termSet));
}

/**
 * 入れた質問に近い、よくある質問を探す（近い順。同じ近さなら並び順）。
 *
 * @param array<int, array<string, mixed>> $faqList よくある質問（並び順）
 * @return array<int, array<string, mixed>> 近い質問（最大 FAQ_SEARCH_RESULT_MAX_COUNT 件。各行に match_ratio）
 */
function faq_search(string $queryText, array $faqList): array
{
    $termList = faq_search_terms($queryText);
    if ($termList === []) {
        return [];
    }
    $matchedFaqList = [];
    foreach ($faqList as $faqIndex => $faqRow) {
        $normalizedQuestion = faq_normalize_text((string) $faqRow['question']);
        $normalizedAnswer = faq_normalize_text((string) $faqRow['answer']);
        $matchPoint = 0;
        foreach ($termList as $termText) {
            if (faq_text_contains_term($normalizedQuestion, $termText)) {
                $matchPoint += 2;
            } elseif (faq_text_contains_term($normalizedAnswer, $termText)) {
                $matchPoint += 1;
            }
        }
        $matchRatio = $matchPoint / (2 * count($termList));
        if ($matchRatio >= FAQ_SEARCH_MIN_RATIO) {
            // 同じ近さのときの順: 質問の文の言葉のうち、入れた文にもある割合（質問の文そのものを入れたら、その質問が先）
            $questionTermList = faq_search_terms((string) $faqRow['question']);
            $questionOverlapRatio = $questionTermList === [] ? 0 : count(array_intersect($questionTermList, $termList)) / count($questionTermList);
            $matchedFaqList[] = $faqRow + ['match_ratio' => $matchRatio, 'question_overlap_ratio' => $questionOverlapRatio, 'list_index' => $faqIndex];
        }
    }
    usort($matchedFaqList, static function (array $leftRow, array $rightRow): int {
        return [$rightRow['match_ratio'], $rightRow['question_overlap_ratio'], $leftRow['list_index']]
            <=> [$leftRow['match_ratio'], $leftRow['question_overlap_ratio'], $rightRow['list_index']];
    });
    return array_slice($matchedFaqList, 0, FAQ_SEARCH_RESULT_MAX_COUNT);
}

/** 質問の文から、要望・依頼の件名を作る（最初の行。長ければ切って「…」） */
function faq_question_title(string $questionText): string
{
    $firstLineText = trim((string) preg_replace('/\s+/u', ' ', explode("\n", trim($questionText))[0]));
    $characterList = preg_split('//u', $firstLineText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($characterList) <= SUPPORT_REQUEST_TITLE_MAX_LENGTH) {
        return $firstLineText;
    }
    return implode('', array_slice($characterList, 0, SUPPORT_REQUEST_TITLE_MAX_LENGTH - 1)) . '…';
}
