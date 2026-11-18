<?php

declare(strict_types=1);

// ====================================================================
// 2 段階認証（TOTP。RFC 6238: HMAC-SHA1、30 秒、6 桁）
// ====================================================================

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($bin);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function base32_decode(string $b32): ?string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[\s=-]/', '', $b32));
    $bits = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $p = strpos($alphabet, $b32[$i]);
        if ($p === false) {
            return null;
        }
        $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_code(string $secretBin, int $step): string
{
    $msg = pack('N*', 0) . pack('N*', $step);
    $hash = hash_hmac('sha1', $msg, $secretBin, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string) ($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * コードを確かめる。前後 1 区間まで許す。
 * $lastStep 以下の区間は使用済みとして断る（同じコードの 2 回目を防ぐ）。
 * 一致した区間を返す。一致しなければ null。
 */
function totp_verify(string $secretBin, string $code, ?int $lastStep, ?int $time = null): ?int
{
    if (preg_match('/^\d{6}$/', $code) !== 1) {
        return null;
    }
    $now = intdiv($time ?? time(), TOTP_PERIOD);
    for ($d = -1; $d <= 1; $d++) {
        $step = $now + $d;
        if ($lastStep !== null && $step <= $lastStep) {
            continue;
        }
        if (hash_equals(totp_code($secretBin, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_uri(string $secretB32, string $loginId): string
{
    $issuer = '月次報告';
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $loginId)
        . '?secret=' . $secretB32
        . '&issuer=' . rawurlencode($issuer)
        . '&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}
