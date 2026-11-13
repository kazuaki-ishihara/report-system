<?php

declare(strict_types=1);

// ====================================================================
// 更新のお知らせ（便利機能 A。G-24）・よくある質問（便利機能 J。G-25）・画面ごとの使い方（便利機能 I）
// ====================================================================

const NOTICE_TITLE_MAX_LENGTH = 60;
const NOTICE_BODY_MAX_LENGTH = 1000;
const FAQ_QUESTION_MAX_LENGTH = 200;
const FAQ_ANSWER_MAX_LENGTH = 2000;
const FAQ_MAX_COUNT = 100;

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
            return msg('自分が課長の課の、締め日・管理の仕方（課全体／班ごと）・確認者（課代・リーダー）・チェックする人・項目管理者・班を決めます。');
        case 'admin_home':
            return msg('管理の仕事ごとの入口と、今の状態です。目立つ札は、対応が要るものです。管理者・補佐には、バックアップの状態とメンテナンスの切り替えも出ます。');
        case 'admin_field_options':
            return msg('共通の選択式の項目の選択肢を、この課でだけ、表示名を変える・出さない・足すことができます（IT 系以外の課のため）。ほかの課には影響しません。');
        case 'admin_field_preview':
            return msg('報告の作成の画面が、今の項目でどう見えるかを確かめます。ここでは保存されません。');
        case 'admin_org':
            return msg('課の追加・名前・課長、ユーザーの追加と所属、注意の言葉、組織の一括の入れ替え（CSV）を行います。毎年の組織改訂は「一括（CSV）」で、事前に予告がある改訂は日を予約できます。課の中のこと（締め日・課代・リーダー・班など）は、その課の課長が「課の管理」で決めます。');
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
            return msg('使い方でよく聞かれることと答えです。見つからないときは、課長・確認者を通して「要望・依頼」で質問してください。');
        default:
            return '';
    }
}
