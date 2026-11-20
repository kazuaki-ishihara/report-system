<?php

declare(strict_types=1);

// ====================================================================
// G-26 通勤定期代の精算（詳細設計書 G26_expenses.md。K-063）
// 交通費・通勤定期代精算書を、各自が Web で作り、総務の様式の Excel（.xlsx）で受け取る。月次報告とは別の機能。
// 受け付けるのは通勤定期だけ。通勤実費・業務交通費などは別のシステムで受け付けるので、ここでは登録できない。
// - 見る・直す・消す・印刷するのは、作った本人だけ（管理者・補佐・課長も見られない）。承認の流れはない。
// - 精算書の中身はまとめて暗号化して 1 列に入れる（expense_sheets.data_enc）。記録（access_logs）にも残さない。
// ====================================================================

/** 1 枚の精算書に書ける明細の行の数（元の様式は 1 ページ 13 行 × 2 ページ） */
const EXPENSE_MAX_ROWS = 26;
/** 印刷で 1 ページに出す明細の行の数（元の様式と同じ） */
const EXPENSE_ROWS_PER_PAGE = 13;
/** 金額の上限（円） */
const EXPENSE_MAX_AMOUNT = 9999999;

const EXPENSE_PAYMENT_METHODS = ['transfer' => '振込', 'cash' => '現金'];
const EXPENSE_OFFICES = ['大阪', '東京'];
/** 様式の種別（様式の選択肢と同じ）。このシステムで受け付けるのは EXPENSE_ACCEPTED_KIND だけ */
const EXPENSE_KINDS = ['通勤定期', '通勤実費', '業務交通費', 'その他'];
/** 受け付ける種別（通勤定期だけ。ほかの種別は別のシステムで受け付ける） */
const EXPENSE_ACCEPTED_KIND = '通勤定期';
const EXPENSE_TRANSPORTS = ['ＪＲ', '私鉄・地下鉄', 'バス', 'タクシー', 'その他'];
const EXPENSE_TRIPS = ['片道', '往復'];

/** 明細の 1 行の列（キー => 表示名） */
const EXPENSE_ROW_COLUMNS = [
    'start_date' => '月／日（から）',
    'end_date'   => '月／日（まで）',
    'kind'       => '種別',
    'purpose'    => '業務・行先',
    'transport'  => '交通手段',
    'route'      => '発～(経由)～着',
    'trip'       => '片道往復',
    'amount'     => '金額',
];

/** 暗号化の付加データ（ほかの人の行に写しても読めないよう、持ち主の ID を入れる） */
function expense_aad(int $userId): string
{
    return 'expense_sheets:' . $userId;
}

/** 空の明細の行 */
function expense_empty_row(): array
{
    return array_fill_keys(array_keys(EXPENSE_ROW_COLUMNS), '');
}

/**
 * 自分の精算書を 1 枚読む（ほかの人の精算書・無いものは null）
 *
 * @return array{id: int, user_id: int, created_at: string, updated_at: string, data: array<string, mixed>}|null
 */
function expense_sheet_of_owner(int $sheetId, int $ownerUserId): ?array
{
    $sheetRow = db_one('SELECT id, user_id, data_enc, created_at, updated_at FROM expense_sheets WHERE id = ? AND user_id = ?', [$sheetId, $ownerUserId]);
    if ($sheetRow === null) {
        return null;
    }
    return [
        'id'         => (int) $sheetRow['id'],
        'user_id'    => (int) $sheetRow['user_id'],
        'created_at' => (string) $sheetRow['created_at'],
        'updated_at' => (string) $sheetRow['updated_at'],
        'data'       => expense_decode((string) $sheetRow['data_enc'], $ownerUserId),
    ];
}

/**
 * 自分の精算書の一覧（新しい順）。中身は一覧に出す分だけ復号する
 *
 * @return array<int, array{id: int, updated_at: string, data: array<string, mixed>}>
 */
function expense_sheets_of_owner(int $ownerUserId, int $limit = 100): array
{
    $sheetList = [];
    foreach (db_all('SELECT id, data_enc, updated_at FROM expense_sheets WHERE user_id = ? ORDER BY id DESC LIMIT ?', [$ownerUserId, $limit]) as $sheetRow) {
        $sheetList[] = [
            'id'         => (int) $sheetRow['id'],
            'updated_at' => (string) $sheetRow['updated_at'],
            'data'       => expense_decode((string) $sheetRow['data_enc'], $ownerUserId),
        ];
    }
    return $sheetList;
}

/** 暗号を解いて、精算書の中身にする（解けないときは空の中身に「読めない」の印を付ける） */
function expense_decode(string $encryptedText, int $ownerUserId): array
{
    $plainText = dec($encryptedText, expense_aad($ownerUserId));
    $decodedData = $plainText === null ? null : json_decode($plainText, true);
    if (!is_array($decodedData)) {
        return expense_normalize([]) + ['unreadable' => true];
    }
    return expense_normalize($decodedData);
}

/** 足りないキーを補い、型をそろえる（古い形・壊れた形でも画面が動くように） */
function expense_normalize(array $sheetData): array
{
    $normalizedRowList = [];
    foreach ((isset($sheetData['rows']) && is_array($sheetData['rows'])) ? $sheetData['rows'] : [] as $rowData) {
        if (!is_array($rowData)) {
            continue;
        }
        $normalizedRow = expense_empty_row();
        foreach (array_keys(EXPENSE_ROW_COLUMNS) as $columnKey) {
            $normalizedRow[$columnKey] = isset($rowData[$columnKey]) ? (string) $rowData[$columnKey] : '';
        }
        $normalizedRowList[] = $normalizedRow;
    }
    return [
        'submitted_on'    => (string) ($sheetData['submitted_on'] ?? ''),
        'payment_method'  => (string) ($sheetData['payment_method'] ?? 'transfer'),
        'employee_number' => (string) ($sheetData['employee_number'] ?? ''),
        'office'          => (string) ($sheetData['office'] ?? ''),
        'department_name' => (string) ($sheetData['department_name'] ?? ''),
        'full_name'       => (string) ($sheetData['full_name'] ?? ''),
        'advance_amount'  => (string) ($sheetData['advance_amount'] ?? ''),
        'rows'            => $normalizedRowList,
    ];
}

/** 明細の金額の合計（小計）・仮払い・合計（小計 − 仮払い） */
function expense_totals(array $sheetData): array
{
    $subtotalAmount = 0;
    foreach ($sheetData['rows'] as $rowData) {
        if (preg_match('/\A\d+\z/', (string) $rowData['amount']) === 1) {
            $subtotalAmount += (int) $rowData['amount'];
        }
    }
    $advanceAmount = preg_match('/\A\d+\z/', (string) $sheetData['advance_amount']) === 1 ? (int) $sheetData['advance_amount'] : 0;
    return ['subtotal' => $subtotalAmount, 'advance' => $advanceAmount, 'total' => $subtotalAmount - $advanceAmount];
}

/** 新しく作るときの中身。上の欄は、前に作った精算書（無ければ利用者の情報）から入れる */
function expense_new_sheet_data(array $user): array
{
    $latestSheetList = expense_sheets_of_owner((int) $user['id'], 1);
    $previousData = $latestSheetList && !isset($latestSheetList[0]['data']['unreadable']) ? $latestSheetList[0]['data'] : null;
    $departmentName = '';
    if (!empty($user['department_id'])) {
        $departmentName = (string) db_val('SELECT name FROM departments WHERE id = ?', [(int) $user['department_id']]);
    }
    return expense_normalize([
        'submitted_on'    => date('Y-m-d'),
        'payment_method'  => $previousData['payment_method'] ?? 'transfer',
        'employee_number' => $previousData['employee_number'] ?? '',
        'office'          => $previousData['office'] ?? '',
        'department_name' => $previousData['department_name'] ?? $departmentName,
        'full_name'       => $previousData['full_name'] ?? (string) ($user['display_name'] ?? ''),
        'advance_amount'  => '',
        'rows'            => [],
    ]);
}

/** 送られた値を精算書の中身にする（確かめる前の形。空の行は捨てる） */
function expense_data_from_post(): array
{
    $postedRowList = [];
    $rawRowList = $_POST['rows'] ?? [];
    if (is_array($rawRowList)) {
        foreach ($rawRowList as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }
            $postedRow = expense_empty_row();
            $isEmptyRow = true;
            foreach (array_keys(EXPENSE_ROW_COLUMNS) as $columnKey) {
                $cellText = isset($rawRow[$columnKey]) && is_string($rawRow[$columnKey]) ? trim($rawRow[$columnKey]) : '';
                if ($columnKey === 'amount') {
                    $cellText = expense_amount_text($cellText);
                }
                $postedRow[$columnKey] = $cellText;
                if ($cellText !== '' && $columnKey !== 'trip' && $columnKey !== 'kind') {
                    $isEmptyRow = false;
                }
            }
            if (!$isEmptyRow) {
                if ($postedRow['kind'] === '') {
                    $postedRow['kind'] = EXPENSE_ACCEPTED_KIND;   // 種別は通勤定期だけなので、入れなくてよい
                }
                $postedRowList[] = $postedRow;
            }
        }
    }
    return expense_normalize([
        'submitted_on'    => post_str('submitted_on'),
        'payment_method'  => post_str('payment_method'),
        'employee_number' => post_str('employee_number'),
        'office'          => post_str('office'),
        'department_name' => post_str('department_name'),
        'full_name'       => post_str('full_name'),
        'advance_amount'  => expense_amount_text(post_str('advance_amount')),
        'rows'            => $postedRowList,
    ]);
}

/** 金額の書き方をそろえる（全角の数字・カンマ・「円」を取り除く） */
function expense_amount_text(string $amountText): string
{
    $amountText = function_exists('mb_convert_kana') ? mb_convert_kana($amountText, 'n', 'UTF-8') : $amountText;
    return str_replace([',', '，', '円', ' ', '　'], '', $amountText);
}

/** 日付（YYYY-MM-DD）として正しいか */
function expense_is_valid_date(string $dateText): bool
{
    if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $dateText, $dateParts) !== 1) {
        return false;
    }
    return checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]);
}

/**
 * 精算書の中身を確かめる。誤りの文の一覧を返す（空なら保存できる）
 *
 * @return string[]
 */
function expense_validate(array $sheetData): array
{
    $errorTextList = [];
    if (!expense_is_valid_date($sheetData['submitted_on'])) {
        $errorTextList[] = msg('提出日を正しく入れてください');
    }
    if (!isset(EXPENSE_PAYMENT_METHODS[$sheetData['payment_method']])) {
        $errorTextList[] = msg('精算方法を選んでください');
    }
    validate_text($sheetData['employee_number'], msg('社員番号'), 20, true, $errorTextList);
    if (!in_array($sheetData['office'], EXPENSE_OFFICES, true)) {
        $errorTextList[] = msg('所属を選んでください');
    }
    validate_text($sheetData['department_name'], msg('部署'), 40, true, $errorTextList);
    validate_text($sheetData['full_name'], msg('氏名'), 40, true, $errorTextList);
    if ($sheetData['advance_amount'] !== '' && (preg_match('/\A\d+\z/', $sheetData['advance_amount']) !== 1 || (int) $sheetData['advance_amount'] > EXPENSE_MAX_AMOUNT)) {
        $errorTextList[] = msg('仮払いは 0 から {max} までの円の数で入れてください', ['max' => number_format(EXPENSE_MAX_AMOUNT)]);
    }
    if (!$sheetData['rows']) {
        $errorTextList[] = msg('明細を 1 行以上入れてください');
    }
    if (count($sheetData['rows']) > EXPENSE_MAX_ROWS) {
        $errorTextList[] = msg('明細は {max} 行までです', ['max' => EXPENSE_MAX_ROWS]);
    }
    foreach ($sheetData['rows'] as $rowIndex => $rowData) {
        $rowLabel = msg('明細の {number} 行目', ['number' => $rowIndex + 1]);
        if (!expense_is_valid_date($rowData['start_date'])) {
            $errorTextList[] = msg('{row}: 月／日を正しく入れてください', ['row' => $rowLabel]);
        }
        if ($rowData['end_date'] !== '' && (!expense_is_valid_date($rowData['end_date']) || $rowData['end_date'] < $rowData['start_date'])) {
            $errorTextList[] = msg('{row}: 終わりの月／日は、始めの月／日と同じか、あとの日にしてください', ['row' => $rowLabel]);
        }
        if ($rowData['kind'] !== EXPENSE_ACCEPTED_KIND) {
            $errorTextList[] = msg('{row}: 通勤定期のほか（通勤実費・業務交通費など）は、このシステムでは受け付けません。別のシステムで申請してください', ['row' => $rowLabel]);
        }
        if (str_len($rowData['purpose']) > 60 || has_control_chars($rowData['purpose'])) {
            $errorTextList[] = msg('{row}: 業務・行先は 60 文字以内にしてください', ['row' => $rowLabel]);
        }
        if (!in_array($rowData['transport'], EXPENSE_TRANSPORTS, true)) {
            $errorTextList[] = msg('{row}: 交通手段を選んでください', ['row' => $rowLabel]);
        }
        if ($rowData['route'] === '' || str_len($rowData['route']) > 80 || has_control_chars($rowData['route'])) {
            $errorTextList[] = msg('{row}: 発～(経由)～着を 80 文字以内で入れてください', ['row' => $rowLabel]);
        }
        if (!in_array($rowData['trip'], EXPENSE_TRIPS, true)) {
            $errorTextList[] = msg('{row}: 片道か往復を選んでください', ['row' => $rowLabel]);
        }
        if (preg_match('/\A\d+\z/', $rowData['amount']) !== 1 || (int) $rowData['amount'] > EXPENSE_MAX_AMOUNT) {
            $errorTextList[] = msg('{row}: 金額は 0 から {max} までの円の数で入れてください', ['row' => $rowLabel, 'max' => number_format(EXPENSE_MAX_AMOUNT)]);
        }
    }
    return $errorTextList;
}

/** 保存する（新しく作るときは $sheetId = null）。保存した精算書の ID を返す */
function expense_save(?int $sheetId, int $ownerUserId, array $sheetData): int
{
    $encryptedText = enc((string) json_encode($sheetData, JSON_UNESCAPED_UNICODE), expense_aad($ownerUserId));
    if ($sheetId === null) {
        db_exec('INSERT INTO expense_sheets (user_id, data_enc, created_at, updated_at) VALUES (?, ?, ?, ?)', [$ownerUserId, $encryptedText, now(), now()]);
        return (int) db()->lastInsertId();
    }
    db_exec('UPDATE expense_sheets SET data_enc = ?, updated_at = ? WHERE id = ? AND user_id = ?', [$encryptedText, now(), $sheetId, $ownerUserId]);
    return $sheetId;
}

// ---- 精算書の xlsx（元の様式 app/templates/expense_sheet.xlsx に値を入れて渡す。K-063） ----------------

/** 元の様式（総務の「交通費・通勤定期代精算書」の Excel をそのまま入れたもの。様式が変わったら差し替える） */
const EXPENSE_TEMPLATE_PATH = APP_DIR . '/templates/expense_sheet.xlsx';
const EXPENSE_TEMPLATE_SHEET_PATH = 'xl/worksheets/sheet1.xml';

/**
 * 様式の 1 枚目のシート（精算書）の、値を入れる行。ページ（No.1・No.2）ごとに、上の欄・明細の最初の行・小計・仮払い・合計の行。
 * 明細の 1 行は A（月／日 から）・B（まで）・C（種別）・D（業務・行先。D:H を結合）・I（交通手段。I:L）・M（発～着。M:U）・V（片道往復）・W（金額。W:X）
 */
const EXPENSE_TEMPLATE_PAGES = [
    ['header_row' => 3, 'person_row' => 5, 'first_line_row' => 8, 'subtotal_row' => 21, 'advance_row' => 22, 'total_row' => 23],
    ['header_row' => 34, 'person_row' => 36, 'first_line_row' => 39, 'subtotal_row' => 52, 'advance_row' => 53, 'total_row' => 54],
];

/**
 * zip（xlsx）の中のファイルを読む（zip の拡張がない環境でも動くよう、自前で読む。格納と deflate だけ）
 *
 * @return array<string, string> 中の場所 => 中身（並びは zip の中の順）
 */
function zip_read_entries(string $zipBytes): array
{
    $endPosition = strrpos($zipBytes, "PK\x05\x06");
    if ($endPosition === false) {
        throw new RuntimeException('zip: end of central directory not found');
    }
    $endRecord = unpack('Vsignature/vdisk/vstartDisk/ventriesOnDisk/ventries/VdirectorySize/VdirectoryOffset', substr($zipBytes, $endPosition, 20));
    $entryByName = [];
    $centralPosition = (int) $endRecord['directoryOffset'];
    for ($entryIndex = 0; $entryIndex < (int) $endRecord['entries']; $entryIndex++) {
        $centralHeader = unpack('Vsignature/vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/VcompressedSize/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/VlocalOffset',
            substr($zipBytes, $centralPosition, 46));
        if ((int) $centralHeader['signature'] !== 0x02014b50) {
            throw new RuntimeException('zip: broken central directory');
        }
        $entryName = substr($zipBytes, $centralPosition + 46, (int) $centralHeader['nameLength']);
        $localHeader = unpack('Vsignature/vneeded/vflags/vmethod/vtime/vdate/Vcrc/VcompressedSize/Vsize/vnameLength/vextraLength',
            substr($zipBytes, (int) $centralHeader['localOffset'], 30));
        $dataPosition = (int) $centralHeader['localOffset'] + 30 + (int) $localHeader['nameLength'] + (int) $localHeader['extraLength'];
        $storedBytes = substr($zipBytes, $dataPosition, (int) $centralHeader['compressedSize']);
        if ((int) $centralHeader['method'] === 0) {
            $entryBytes = $storedBytes;
        } elseif ((int) $centralHeader['method'] === 8) {
            $entryBytes = gzinflate($storedBytes);
            if ($entryBytes === false) {
                throw new RuntimeException('zip: inflate failed: ' . $entryName);
            }
        } else {
            throw new RuntimeException('zip: unsupported method: ' . $entryName);
        }
        // CRC は 16 進の文字で比べる（32 ビットの PHP では 0xFFFFFFFF が整数に収まらず、数で比べると止まるため）
        if (hash('crc32b', $entryBytes) !== bin2hex(strrev(substr($zipBytes, $centralPosition + 16, 4)))) {
            throw new RuntimeException('zip: crc mismatch: ' . $entryName);
        }
        $entryByName[$entryName] = $entryBytes;
        $centralPosition += 46 + (int) $centralHeader['nameLength'] + (int) $centralHeader['extraLength'] + (int) $centralHeader['commentLength'];
    }
    return $entryByName;
}

/** Excel の日付の数（1900 年方式。1899-12-30 からの日数） */
function expense_excel_date_number(string $dateText): int
{
    $baseDate = new DateTimeImmutable('1899-12-30');
    return (int) $baseDate->diff(new DateTimeImmutable($dateText))->days;
}

/**
 * シートのセルに値を入れる（書式（罫線・表示形式）はセルに付いたまま残す。式は残して、計算した値だけを入れ直す）
 *
 * @param string|int|null $cellValue 文字は文字のセル、数は数のセル、null・空は空にする
 */
function expense_xlsx_set_cell(DOMDocument $sheetDocument, DOMXPath $sheetXPath, string $cellReference, $cellValue): void
{
    $mainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $cellNodeList = $sheetXPath->query('//m:sheetData/m:row/m:c[@r="' . $cellReference . '"]');
    if ($cellNodeList === false || $cellNodeList->length === 0) {
        throw new RuntimeException('xlsx: cell not found in template: ' . $cellReference);
    }
    $cellElement = $cellNodeList->item(0);
    $hasFormula = false;
    foreach (iterator_to_array($cellElement->childNodes) as $childNode) {
        if ($childNode instanceof DOMElement && $childNode->localName === 'f') {
            $hasFormula = true;
            continue;
        }
        $cellElement->removeChild($childNode);
    }
    $cellElement->removeAttribute('t');
    if ($cellValue === null || $cellValue === '') {
        return;
    }
    if (is_int($cellValue)) {
        $cellElement->appendChild($sheetDocument->createElementNS($mainNamespace, 'v', (string) $cellValue));
        return;
    }
    if ($hasFormula) {
        throw new RuntimeException('xlsx: text into formula cell: ' . $cellReference);
    }
    $cellElement->setAttribute('t', 'inlineStr');
    $inlineStringElement = $sheetDocument->createElementNS($mainNamespace, 'is');
    $textElement = $sheetDocument->createElementNS($mainNamespace, 't');
    $textElement->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    $textElement->appendChild($sheetDocument->createTextNode((string) $cellValue));
    $inlineStringElement->appendChild($textElement);
    $cellElement->appendChild($inlineStringElement);
}

/** 精算書の中身を、元の様式の xlsx に入れて返す（1 枚目 13 行・2 枚目 13 行。仮払いは 1 枚目に入れる） */
function expense_xlsx(array $sheetData): string
{
    $entryByName = zip_read_entries((string) file_get_contents(EXPENSE_TEMPLATE_PATH));
    if (!isset($entryByName[EXPENSE_TEMPLATE_SHEET_PATH], $entryByName['xl/workbook.xml'])) {
        throw new RuntimeException('xlsx: template sheet not found');
    }
    $sheetDocument = new DOMDocument();
    $sheetDocument->preserveWhiteSpace = true;
    if (!$sheetDocument->loadXML($entryByName[EXPENSE_TEMPLATE_SHEET_PATH])) {
        throw new RuntimeException('xlsx: template sheet broken');
    }
    $sheetXPath = new DOMXPath($sheetDocument);
    $sheetXPath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $submittedParts = explode('-', $sheetData['submitted_on']);
    $employeeNumber = preg_match('/\A\d{1,9}\z/', $sheetData['employee_number']) === 1 ? (int) $sheetData['employee_number'] : $sheetData['employee_number'];
    $pageRowLists = array_chunk($sheetData['rows'], EXPENSE_ROWS_PER_PAGE);
    $advanceAmount = expense_totals($sheetData)['advance'];
    foreach (EXPENSE_TEMPLATE_PAGES as $pageIndex => $pageCells) {
        $pageRowList = $pageRowLists[$pageIndex] ?? [];
        if ($pageIndex > 0 && !$pageRowList) {
            break;   // 2 枚目は、明細が 13 行を超えたときだけ書く
        }
        $headerRow = $pageCells['header_row'];
        $personRow = $pageCells['person_row'];
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'C' . $headerRow, EXPENSE_PAYMENT_METHODS[$sheetData['payment_method']] ?? '');
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'F' . $headerRow, (int) $submittedParts[0]);
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'H' . $headerRow, (int) $submittedParts[1]);
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'J' . $headerRow, (int) $submittedParts[2]);
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'C' . $personRow, $employeeNumber);
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'F' . $personRow, $sheetData['office']);
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'I' . $personRow, $sheetData['department_name']);
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'O' . $personRow, $sheetData['full_name']);
        $pageSubtotal = 0;
        foreach ($pageRowList as $lineIndex => $rowData) {
            $lineRow = $pageCells['first_line_row'] + $lineIndex;
            expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'A' . $lineRow, expense_excel_date_number($rowData['start_date']));
            expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'B' . $lineRow, $rowData['end_date'] !== '' ? expense_excel_date_number($rowData['end_date']) : null);
            expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'C' . $lineRow, $rowData['kind']);
            expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'D' . $lineRow, $rowData['purpose']);
            expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'I' . $lineRow, $rowData['transport']);
            expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'M' . $lineRow, $rowData['route']);
            expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'V' . $lineRow, $rowData['trip']);
            expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'W' . $lineRow, (int) $rowData['amount']);
            $pageSubtotal += (int) $rowData['amount'];
        }
        $pageAdvance = $pageIndex === 0 ? $advanceAmount : 0;
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'W' . $pageCells['advance_row'], $pageAdvance > 0 ? $pageAdvance : null);
        // 小計・合計の式は様式のまま。開いたときに計算し直すが、計算しないソフトのために値も入れておく
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'W' . $pageCells['subtotal_row'], $pageSubtotal);
        expense_xlsx_set_cell($sheetDocument, $sheetXPath, 'W' . $pageCells['total_row'], $pageSubtotal - $pageAdvance);
    }
    $entryByName[EXPENSE_TEMPLATE_SHEET_PATH] = (string) $sheetDocument->saveXML();
    // 開いたときに式を計算し直す
    $entryByName['xl/workbook.xml'] = (string) preg_replace('/<calcPr\b(?![^>]*fullCalcOnLoad)/', '<calcPr fullCalcOnLoad="1"', $entryByName['xl/workbook.xml'], 1);
    return zip_store($entryByName);
}

/** ダウンロードのファイル名（例: 交通費通勤定期代精算書_2026-11-20.xlsx） */
function expense_xlsx_file_name(array $sheetData): string
{
    return '交通費通勤定期代精算書_' . ($sheetData['submitted_on'] !== '' ? $sheetData['submitted_on'] : date('Y-m-d')) . '.xlsx';
}
