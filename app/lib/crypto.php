<?php

declare(strict_types=1);

// ====================================================================
// 暗号化（詳細設計書 共通編 §8）
// AES-256-GCM。保存の形: v1:Base64(IV 12 + タグ 16 + 暗号文)
// 付加データ（AAD）に行の識別を入れ、別の行へ移し替えた暗号文は復号できない。
// ====================================================================

const CRYPTO_CIPHER = 'aes-256-gcm';
const CRYPTO_VERSION = 'v1';   // はじめの鍵の版

/**
 * 今の鍵の版。鍵の入れ替え（app/bin/rotate_key.php）が、全件の暗号化し直しと同じトランザクションで app_state に書く。
 * 一度も入れ替えていなければ（表がまだないときも）はじめの版。
 */
function crypto_version(): string
{
    if (!isset($GLOBALS['CRYPTO_CURRENT_VERSION'])) {
        $storedVersion = db_val("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'app_state'") === null
            ? null : db_val("SELECT value FROM app_state WHERE name = 'crypto_version'");
        $version = $storedVersion === null ? CRYPTO_VERSION : (string) $storedVersion;
        if (preg_match('/\Av[1-9][0-9]{0,5}\z/', $version) !== 1) {
            throw new RuntimeException('encryption key version is invalid');
        }
        $GLOBALS['CRYPTO_CURRENT_VERSION'] = $version;
    }
    return $GLOBALS['CRYPTO_CURRENT_VERSION'];
}

/** 覚えている鍵の版を忘れる（入れ替えのあとに読み直す） */
function crypto_version_forget(): void
{
    unset($GLOBALS['CRYPTO_CURRENT_VERSION']);
}

function crypto_key(?string $version = null): string
{
    $version = $version ?? crypto_version();
    static $keys = [];
    if (isset($keys[$version])) {
        return $keys[$version];
    }
    $file = cfg('key_dir') . '/field_' . $version . '.key';
    if (!is_file($file)) {
        throw new RuntimeException('encryption key not found: ' . $version);
    }
    $raw = base64_decode(trim((string) file_get_contents($file)), true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('encryption key is invalid: ' . $version);
    }
    $keys[$version] = $raw;
    return $raw;
}

/** 鍵を作る（app/bin/gen_key.php から呼ぶ。既にあれば作らない） */
function crypto_generate_key(?string $version = null): string
{
    $version = $version ?? crypto_version();
    $dir = cfg('key_dir');
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $file = $dir . '/field_' . $version . '.key';
    if (is_file($file)) {
        return $file;
    }
    file_put_contents($file, base64_encode(random_bytes(32)) . "\n", LOCK_EX);
    @chmod($file, 0600);
    return $file;
}

function enc(string $plain, string $aad, ?string $version = null): string
{
    $version = $version ?? crypto_version();
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, CRYPTO_CIPHER, crypto_key($version), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if ($ct === false) {
        throw new RuntimeException('encrypt failed');
    }
    return $version . ':' . base64_encode($iv . $tag . $ct);
}

/** 復号できなければ null（呼び出し側で「この内容は表示できません」と出す） */
function dec(string $blob, string $aad): ?string
{
    if ($blob === '') {
        return '';
    }
    $pos = strpos($blob, ':');
    if ($pos === false) {
        return null;
    }
    $version = substr($blob, 0, $pos);
    $raw = base64_decode(substr($blob, $pos + 1), true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    try {
        $key = crypto_key($version);
    } catch (RuntimeException $e) {
        app_log('decrypt: ' . $e->getMessage());
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $plain = openssl_decrypt($ct, CRYPTO_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
    if ($plain === false) {
        app_log('decrypt failed aad=' . $aad);
        return null;
    }
    return $plain;
}
