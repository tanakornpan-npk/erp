<?php
/**
 * Backend API for Interactive Executive Dashboard (PHP Version)
 * ระบบติดตามและรายงานผลการจัดซื้อจัดจ้างงบลงทุนเงินแผ่นดิน ปี 2569
 * มหาวิทยาลัยเกษตรศาสตร์ วิทยาเขตเฉลิมพระเกียรติ จังหวัดสกลนคร
 *
 * ดึงข้อมูลจาก Google Sheets แบบ Real-time พร้อม Fallback ไปยัง mock-data.json
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');
date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/auth.php';

if (PHP_SAPI !== 'cli' || !empty($_SERVER['HTTP_X_ENFORCE_AUTH'])) {
    $currentUser = getAuthenticatedUser();
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode([
            'ok'           => false,
            'unauthorized' => true,
            'message'      => 'กรุณาเข้าสู่ระบบก่อนใช้งาน Dashboard',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

const SPREADSHEET_ID = '1Xo-wAMivk54boKXb7MkAtq7QA1-6C1K2zCme32Ugjf0';
const SHEET_NAME = 'DATA';
const APP_VERSION = '7.1.0-web';
const APP_TITLE = 'ระบบติดตามและรายงานผลการจัดซื้อจัดจ้างงบลงทุนเงินแผ่นดิน ปี 2569';
const STALE_DAYS = 30;
const AUTO_REFRESH_SECONDS = 300;

$startedAt = microtime(true);

$headerAliases = [
    'owner'          => ['ผู้รับผิดชอบ'],
    'sequence'       => ['ลำดับ', 'เลขที่'],
    'item'           => ['รายการ', 'รายการจัดซื้อจัดจ้าง'],
    'currentStatus'  => ['สถานะงานปัจจุบัน', 'สถานะปัจจุบัน'],
    'department'     => ['ส่วนงาน', 'หน่วยงาน'],
    'budget'         => ['งบประมาณที่ได้รับ', 'วงเงินงบประมาณ', 'งบประมาณ'],
    'summaryStatus'  => ['สรุปสถานะการดำเนินการ', 'สรุปสถานะ'],
    'stage'          => ['สถานะการดำเนินการ', 'ขั้นตอนการดำเนินการ'],
    'recommendation' => ['Action Recommendation', 'ข้อเสนอแนะ', 'ข้อเสนอแนะเพื่อดำเนินการ'],
    'method'         => ['จัดซื้อจัดจ้างวิธี', 'วิธีจัดซื้อจัดจ้าง'],
    'contracted'     => ['ราคา/ซื้อจ้างทำสัญญาแล้ว', 'ราคาซื้อจ้างทำสัญญาแล้ว', 'วงเงินตามสัญญา'],
    'remaining'      => ['งบประมาณ /คงเหลือ', 'งบประมาณ/คงเหลือ', 'งบประมาณคงเหลือ'],
];

$milestoneHeaders = [
    'จองงบประมาณ',
    'ร่างขอบเขตของงาน (TOR)',
    'รายงานขอซื้อ/ขอจ้าง',
    'ประกาศร่างเอกสารประกวดราคา',
    'ประกาศประกวดราคา',
    'พิจารณาผลประกวดราคา',
    'ประกาศผู้ชนะการเสนอราคา',
    'จัดทำสัญญาซื้อขาย/จ้าง',
    'การบริหารสัญญา',
    'สัญญาครบกำหนด',
    'ส่งมอบของ/งาน',
    'คณะกรรมการดำเนินการตรวจรับพัสดุ',
    'ส่งเอกสารเบิกจ่ายเงิน',
];

try {
    $csvUrl = 'https://docs.google.com/spreadsheets/d/' . SPREADSHEET_ID . '/export?format=csv&sheet=' . urlencode(SHEET_NAME);
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'follow_location' => 1,
            'header' => "User-Agent: Mozilla/5.0\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);

    $csvContent = @file_get_contents($csvUrl, false, $ctx);
    $cacheFile = __DIR__ . '/data/sheet-cache.csv';

    if ($csvContent !== false && strlen($csvContent) > 50) {
        if (!is_dir(__DIR__ . '/data')) {
            @mkdir(__DIR__ . '/data', 0777, true);
        }
        @file_put_contents($cacheFile, $csvContent);
    } elseif (file_exists($cacheFile)) {
        $csvContent = file_get_contents($cacheFile);
    } else {
        throw new Exception('ไม่สามารถเชื่อมต่อ Google Sheets และไม่พบข้อมูลสำรองในเครื่อง');
    }

    $rows = parseCsvContent($csvContent);
    if (count($rows) < 1) {
        throw new Exception('ไม่พบข้อมูลในชีต');
    }

    $headers = array_map('cleanText', $rows[0]);
    $headerMap = buildHeaderMap($headers);
    assertRequiredHeaders($headerMap, $headerAliases);

    $milestoneIndexes = [];
    foreach ($milestoneHeaders as $mHeader) {
        $idx = findHeaderIndex($headerMap, [$mHeader]);
        if ($idx > -1) {
            $milestoneIndexes[] = ['label' => $mHeader, 'index' => $idx];
        }
    }

    $today = new DateTimeImmutable('today');
    $records = [];
    $skippedBlankRows = 0;

    for ($i = 1; $i < count($rows); $i++) {
        $displayRow = $rows[$i];
        $sourceRow = 1 + $i;

        if (isBlankDataRow($displayRow)) {
            $skippedBlankRows++;
            continue;
        }

        $records[] = buildRecord($displayRow, $sourceRow, $headerMap, $headerAliases, $milestoneIndexes, $today);
    }

    $response = [
        'ok'                 => true,
        'appTitle'           => APP_TITLE,
        'appVersion'         => APP_VERSION,
        'spreadsheetTitle'   => 'Google Sheet (' . SHEET_NAME . ')',
        'sheetName'          => SHEET_NAME,
        'sourceUrl'          => 'https://docs.google.com/spreadsheets/d/' . SPREADSHEET_ID . '/edit?usp=sharing',
        'generatedAt'        => date('d/m/Y H:i:s'),
        'generatedAtIso'     => date('c'),
        'elapsedMs'          => round((microtime(true) - $startedAt) * 1000),
        'autoRefreshSeconds' => AUTO_REFRESH_SECONDS,
        'staleDaysThreshold' => STALE_DAYS,
        'sourceRowCount'     => max(0, count($rows) - 1),
        'skippedBlankRows'   => $skippedBlankRows,
        'records'            => $records,
        'options'            => buildFilterOptions($records),
        'qualitySummary'     => buildQualitySummary($records),
        'headers'            => $headers,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'ok'          => false,
        'message'     => $e->getMessage(),
        'generatedAt' => date('d/m/Y H:i:s'),
        'elapsedMs'   => round((microtime(true) - $startedAt) * 1000),
    ], JSON_UNESCAPED_UNICODE);
}

// ============================================================================
// Helper Functions
// ============================================================================

function parseCsvContent(string $csv): array {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    $rows = [];
    while (($data = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $rows[] = $data;
    }
    fclose($stream);
    return $rows;
}

function cleanText($value): string {
    if ($value === null) return '';
    $str = preg_replace('/[\r\n\t]+/u', ' ', (string)$value);
    $str = preg_replace('/\s{2,}/u', ' ', $str);
    return trim($str);
}

function thaiDigitsToArabic(string $value): string {
    $thai = ['๐','๑','๒','๓','๔','๕','๖','๗','๘','๙'];
    $arab = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($thai, $arab, $value);
}

function normalizeHeader(string $value): string {
    $str = thaiDigitsToArabic(cleanText($value));
    $str = mb_strtolower($str, 'UTF-8');
    $str = preg_replace('/[\s\-_\/().]+/u', '', $str);
    $str = preg_replace('/[“”"\'`]/u', '', $str);
    return $str;
}

function buildHeaderMap(array $headers): array {
    $map = [];
    foreach ($headers as $idx => $h) {
        $norm = normalizeHeader($h);
        if ($norm !== '' && !array_key_exists($norm, $map)) {
            $map[$norm] = $idx;
        }
    }
    return $map;
}

function findHeaderIndex(array $headerMap, array $aliases): int {
    foreach ($aliases as $alias) {
        $key = normalizeHeader($alias);
        if (array_key_exists($key, $headerMap)) {
            return $headerMap[$key];
        }
    }
    return -1;
}

function assertRequiredHeaders(array $headerMap, array $headerAliases): void {
    $required = [
        'ผู้รับผิดชอบ'       => $headerAliases['owner'],
        'รายการ'             => $headerAliases['item'],
        'ส่วนงาน'            => $headerAliases['department'],
        'งบประมาณที่ได้รับ'  => $headerAliases['budget'],
        'สถานะการดำเนินการ' => $headerAliases['stage'],
    ];
    $missing = [];
    foreach ($required as $label => $aliases) {
        if (findHeaderIndex($headerMap, $aliases) === -1) {
            $missing[] = $label;
        }
    }
    if (!empty($missing)) {
        throw new Exception('ไม่พบหัวคอลัมน์ที่จำเป็น: ' . implode(', ', $missing));
    }
}

function isBlankDataRow(array $row): bool {
    foreach ($row as $cell) {
        if (cleanText($cell) !== '') return false;
    }
    return true;
}

function getDisplayField(array $row, array $headerMap, array $aliases): string {
    $idx = findHeaderIndex($headerMap, $aliases);
    return ($idx > -1 && isset($row[$idx])) ? cleanText($row[$idx]) : '';
}

function parseNumber($displayValue): ?float {
    $text = thaiDigitsToArabic((string)$displayValue);
    $text = preg_replace('/[\s,฿]|บาท/u', '', $text);
    if ($text === '') return null;
    $isNegative = preg_match('/^\(.*\)$/', $text);
    $normalized = preg_replace('/[()]/', '', $text);
    if (!is_numeric($normalized)) return null;
    $val = (float)$normalized;
    return $isNegative ? -$val : $val;
}

function getNumberField(array $row, array $headerMap, array $aliases): ?float {
    $idx = findHeaderIndex($headerMap, $aliases);
    if ($idx === -1 || !isset($row[$idx])) return null;
    return parseNumber($row[$idx]);
}

function extractDates(string $displayValue): array {
    $dates = [];
    $text = thaiDigitsToArabic(cleanText($displayValue));
    if (preg_match_all('/(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $year = (int)$m[3];
            if ($year < 100) $year += 2500;
            if ($year >= 2400) $year -= 543;
            if (checkdate($month, $day, $year)) {
                $dt = DateTimeImmutable::createFromFormat('!Y-n-j', "$year-$month-$day");
                if ($dt) $dates[] = $dt;
            }
        }
    }
    usort($dates, fn($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());
    return $dates;
}

function hasSuspiciousDate(string $displayValue, array $dates): bool {
    $text = thaiDigitsToArabic(cleanText($displayValue));
    $dateLike = (bool)preg_match('/\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}/', $text);
    if ($dateLike && empty($dates)) return true;
    foreach ($dates as $d) {
        $y = (int)$d->format('Y');
        if ($y < 2020 || $y > 2100) return true;
    }
    return false;
}

function buildRecord(array $displayRow, int $sourceRow, array $headerMap, array $headerAliases, array $milestoneIndexes, DateTimeImmutable $today): array {
    $owner          = getDisplayField($displayRow, $headerMap, $headerAliases['owner']);
    $sequence       = getDisplayField($displayRow, $headerMap, $headerAliases['sequence']);
    $item           = getDisplayField($displayRow, $headerMap, $headerAliases['item']);
    $currentStatus  = getDisplayField($displayRow, $headerMap, $headerAliases['currentStatus']);
    $department     = getDisplayField($displayRow, $headerMap, $headerAliases['department']);
    $summaryStatus  = getDisplayField($displayRow, $headerMap, $headerAliases['summaryStatus']);
    $stage          = getDisplayField($displayRow, $headerMap, $headerAliases['stage']);
    $recommendation = getDisplayField($displayRow, $headerMap, $headerAliases['recommendation']);
    $method         = getDisplayField($displayRow, $headerMap, $headerAliases['method']);

    $budget         = getNumberField($displayRow, $headerMap, $headerAliases['budget']);
    $contracted     = getNumberField($displayRow, $headerMap, $headerAliases['contracted']);
    $statedRemaining = getNumberField($displayRow, $headerMap, $headerAliases['remaining']);
    $calcRemaining  = max(0.0, ($budget ?? 0.0) - ($contracted ?? 0.0));
    $remaining      = $statedRemaining !== null ? $statedRemaining : $calcRemaining;

    $milestones = [];
    $validDates = [];
    foreach ($milestoneIndexes as $ms) {
        $val = isset($displayRow[$ms['index']]) ? cleanText($displayRow[$ms['index']]) : '';
        if ($val === '') continue;
        $dates = extractDates($val);
        $lastDate = !empty($dates) ? $dates[count($dates) - 1] : null;
        if ($lastDate && $ms['label'] !== 'สัญญาครบกำหนด') {
            $y = (int)$lastDate->format('Y');
            if ($y >= 2020 && $y <= 2100) {
                $validDates[] = $lastDate;
            }
        }
        $milestones[] = [
            'label'             => $ms['label'],
            'value'             => $val,
            'validDateIso'      => $lastDate ? $lastDate->format('c') : null,
            'hasSuspiciousDate' => hasSuspiciousDate($val, $dates),
        ];
    }

    $latestDate = null;
    if (!empty($validDates)) {
        usort($validDates, fn($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());
        $latestDate = $validDates[count($validDates) - 1];
    }

    $latestUpdateIso = $latestDate ? $latestDate->format('c') : null;
    $latestUpdate    = $latestDate ? $latestDate->format('d/m/Y') : '';
    $staleDays       = $latestDate ? max(0, (int)floor(($today->getTimestamp() - $latestDate->getTimestamp()) / 86400)) : null;

    $complete = isComplete($summaryStatus, $stage);
    $progress = calculateProgress($stage, $complete);

    $issues = [];
    if ($owner === '') $issues[] = 'ไม่ระบุผู้รับผิดชอบ';
    if ($item === '') $issues[] = 'ไม่ระบุรายการ';
    if ($department === '') $issues[] = 'ไม่ระบุส่วนงาน';
    if ($budget === null || $budget <= 0) $issues[] = 'งบประมาณไม่ถูกต้องหรือไม่ระบุ';
    if ($summaryStatus === '') $issues[] = 'ไม่ระบุสรุปสถานะ';
    if ($budget !== null && $contracted !== null && $contracted > $budget + 1) {
        $issues[] = 'วงเงินตามสัญญาสูงกว่างบประมาณ';
    }
    if ($budget !== null && $contracted !== null && $remaining !== null && abs($calcRemaining - $remaining) > 1.01) {
        $issues[] = 'ยอดงบประมาณคงเหลือไม่สัมพันธ์กับงบประมาณและวงเงินสัญญา';
    }
    foreach ($milestones as $m) {
        if ($m['hasSuspiciousDate']) {
            $issues[] = 'พบรูปแบบวันที่ที่ควรตรวจสอบ';
            break;
        }
    }

    $riskLevel = 'normal';
    if ($complete) {
        $riskLevel = 'complete';
    } elseif ($recommendation !== '') {
        $riskLevel = preg_match('/(ความเสี่ยงสูง|วิกฤต|เร่งด่วนมาก)/u', $recommendation) ? 'high' : 'medium';
    }

    $trackingStatus = $complete ? 'เสร็จแล้ว' : ($recommendation !== '' ? $recommendation : 'ปกติ');

    return [
        'id'              => (string)$sourceRow,
        'sourceRow'       => $sourceRow,
        'sequence'        => $sequence !== '' ? $sequence : (string)($sourceRow - 1),
        'owner'           => $owner !== '' ? $owner : 'ไม่ระบุ',
        'item'            => $item !== '' ? $item : 'ไม่ระบุรายการ',
        'currentStatus'   => $currentStatus !== '' ? $currentStatus : 'ไม่ระบุ',
        'department'      => $department !== '' ? $department : 'ไม่ระบุ',
        'budget'          => round($budget ?? 0.0, 2),
        'summaryStatus'   => $summaryStatus !== '' ? $summaryStatus : 'ไม่ระบุ',
        'stage'           => $stage !== '' ? $stage : 'ไม่ระบุ',
        'recommendation'  => $recommendation,
        'method'          => $method !== '' ? $method : 'ไม่ระบุ',
        'contracted'      => round($contracted ?? 0.0, 2),
        'remaining'       => round($remaining ?? 0.0, 2),
        'progress'        => $progress,
        'complete'        => $complete,
        'latestUpdate'    => $latestUpdate,
        'latestUpdateIso' => $latestUpdateIso,
        'staleDays'       => $staleDays,
        'attention'       => !$complete && ($recommendation !== ''),
        'riskLevel'       => $riskLevel,
        'trackingStatus'  => $trackingStatus,
        'dataIssues'      => $issues,
        'milestones'      => $milestones,
    ];
}

function isComplete(string $summaryStatus, string $stage): bool {
    $text = mb_strtolower(cleanText($summaryStatus . ' ' . $stage), 'UTF-8');
    return (bool)preg_match('/(เสร็จ|เบิกจ่ายเงินแล้ว|ส่งเอกสารเบิกจ่ายเงิน)/u', $text);
}

function calculateProgress(string $stage, bool $complete): int {
    if ($complete) return 100;
    $norm = normalizeHeader($stage);
    $stages = [
        ['aliases' => ['จองงบประมาณ'], 'value' => 8],
        ['aliases' => ['ร่างขอบเขตของงานtor', 'ร่างขอบเขตของงาน'], 'value' => 15],
        ['aliases' => ['รายงานขอซื้อขอจ้าง'], 'value' => 25],
        ['aliases' => ['ประกาศร่างเอกสารประกวดราคา'], 'value' => 35],
        ['aliases' => ['ประกาศประกวดราคา'], 'value' => 45],
        ['aliases' => ['พิจารณาผลประกวดราคา'], 'value' => 55],
        ['aliases' => ['ประกาศผู้ชนะการเสนอราคา'], 'value' => 65],
        ['aliases' => ['จัดทำสัญญาซื้อขายจ้าง'], 'value' => 75],
        ['aliases' => ['การบริหารสัญญา', 'สัญญาครบกำหนด'], 'value' => 82],
        ['aliases' => ['ส่งมอบของงาน'], 'value' => 90],
        ['aliases' => ['คณะกรรมการดำเนินการตรวจรับพัสดุ', 'ตรวจรับพัสดุ'], 'value' => 95],
        ['aliases' => ['ส่งเอกสารเบิกจ่ายเงิน'], 'value' => 100],
    ];
    foreach ($stages as $s) {
        foreach ($s['aliases'] as $alias) {
            if (mb_strpos($norm, $alias) !== false) {
                return $s['value'];
            }
        }
    }
    return 0;
}

function uniqueSorted(array $values): array {
    $seen = [];
    $result = [];
    foreach ($values as $v) {
        $clean = cleanText($v);
        if ($clean === '' || isset($seen[$clean])) continue;
        $seen[$clean] = true;
        $result[] = $clean;
    }
    sort($result, SORT_LOCALE_STRING);
    return $result;
}

function buildFilterOptions(array $records): array {
    return [
        'owners'          => uniqueSorted(array_column($records, 'owner')),
        'departments'     => uniqueSorted(array_column($records, 'department')),
        'summaryStatuses' => uniqueSorted(array_column($records, 'summaryStatus')),
        'stages'          => uniqueSorted(array_column($records, 'stage')),
        'methods'         => uniqueSorted(array_column($records, 'method')),
    ];
}

function buildQualitySummary(array $records): array {
    $withIssues = 0;
    $suspiciousDates = 0;
    $missingLatest = 0;
    $highRisk = 0;
    $mediumRisk = 0;

    foreach ($records as $r) {
        if (!empty($r['dataIssues'])) $withIssues++;
        if (in_array('พบรูปแบบวันที่ที่ควรตรวจสอบ', $r['dataIssues'], true)) $suspiciousDates++;
        if (empty($r['latestUpdateIso'])) $missingLatest++;
        if ($r['riskLevel'] === 'high') $highRisk++;
        if ($r['riskLevel'] === 'medium') $mediumRisk++;
    }

    return [
        'recordsWithIssues'   => $withIssues,
        'suspiciousDates'     => $suspiciousDates,
        'missingLatestUpdate' => $missingLatest,
        'highRisk'            => $highRisk,
        'mediumRisk'          => $mediumRisk,
    ];
}
