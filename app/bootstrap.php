<?php

declare(strict_types=1);

// ====================================================================
// すべての入り口が最初に読む（詳細設計書 共通編 §3）
// PHP 7.4 で動く書き方にする（match、nullsafe、str_contains などは使わない）
// ====================================================================

define('APP_DIR', __DIR__);

$GLOBALS['APP_CONFIG'] = require APP_DIR . '/config/config.php';

date_default_timezone_set('Asia/Tokyo');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

require_once APP_DIR . '/lib/util.php';
require_once APP_DIR . '/lib/html.php';
require_once APP_DIR . '/lib/db.php';
require_once APP_DIR . '/lib/schema.php';
require_once APP_DIR . '/lib/crypto.php';
require_once APP_DIR . '/lib/csrf.php';
require_once APP_DIR . '/lib/request_key.php';
require_once APP_DIR . '/lib/audit_log.php';
require_once APP_DIR . '/lib/totp.php';
require_once APP_DIR . '/lib/auth.php';
require_once APP_DIR . '/lib/session.php';
require_once APP_DIR . '/lib/perm.php';
require_once APP_DIR . '/lib/report.php';
require_once APP_DIR . '/lib/admin.php';
require_once APP_DIR . '/lib/form.php';

app_init_error_handling();
