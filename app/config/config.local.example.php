<?php

// ====================================================================
// ローカル検証環境の設定のひな形（このファイル自体は読み込まれない）
//
// 使い方: このファイルを config.local.php という名前で写して使う。
//   Linux / macOS: cp app/config/config.local.example.php app/config/config.local.php
//   Windows      : copy app\config\config.local.example.php app\config\config.local.php
//
// 【注意】
// - ローカル検証環境の専用。本番（さくらのレンタルサーバー）には絶対に置かない。
//   https => false で Cookie の Secure が外れ、dev_skip_2fa => true で 2 段階認証が飛ばされ、
//   allow_dev_seed => true で dev_seed.php が DB を消して作り直せるようになる。
// - config.local.php はリポジトリに入れない（.gitignore 済み。verify_all.py も入っていないことを確かめる）。
//   設定を変えたいときは、このひな形を直してコミットする。
// - verify_all.py は、検証用の DB（app/data/verify.sqlite）を使い、2 段階認証を飛ばさない設定で動く（環境変数 MR_VERIFY_DB）。
//   dev_skip_2fa を切り替える必要はなく、画面を見ている DB（テーマ・ログイン）にも触れない。
// ====================================================================

return [
    'base_path'      => '',                                  // 公開側の URL の先頭（ローカルでは空）
    'https'          => false,                               // ローカルは http
    'allow_dev_seed' => true,                                // tools/test/dev_seed.php を使えるようにする
    'db_path'        => dirname(__DIR__) . '/data/dev.sqlite', // テスト用の DB（app/data/ はリポジトリに入れない）
    'dev_skip_2fa'   => true,                                // 画面を見るため 2 段階認証を飛ばす（自動検証は自動で飛ばさない）
];
