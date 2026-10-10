<?php

/**
 * HTML出力用のエスケープ
 */
function h($value)
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

