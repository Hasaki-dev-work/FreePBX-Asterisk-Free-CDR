<?php
// Подключаем конфиг FreePBX
require_once('/etc/freepbx.conf');

// Настройки пагинации
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = isset($_GET['perPage']) ? (int)$_GET['perPage'] : 50;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

// Получаем параметры фильтра
$dateFrom  = isset($_GET['dateFrom']) && $_GET['dateFrom'] !== '' ? $_GET['dateFrom'] : '';
$dateTo    = isset($_GET['dateTo'])   && $_GET['dateTo']   !== '' ? $_GET['dateTo']   : '';
$numberA   = isset($_GET['numberA'])  && $_GET['numberA']  !== '' ? trim($_GET['numberA'])  : '';
$numberB   = isset($_GET['numberB'])  && $_GET['numberB']  !== '' ? trim($_GET['numberB'])  : '';
$numberC   = isset($_GET['numberC'])  && $_GET['numberC']  !== '' ? trim($_GET['numberC'])  : '';
$status    = isset($_GET['status'])   && $_GET['status']   !== '' ? $_GET['status']   : '';
$direction = isset($_GET['direction'])&& $_GET['direction']!== '' ? $_GET['direction']: '';
$export    = isset($_GET['export'])   && $_GET['export']   !== '' ? $_GET['export']   : '';
$filterDid      = isset($_GET['filterDid'])      && $_GET['filterDid']      !== '' ? trim($_GET['filterDid'])      : '';
$filterTransfer = isset($_GET['filterTransfer']) && $_GET['filterTransfer'] !== '' ? trim($_GET['filterTransfer']) : '';

if (empty($dateFrom)) { $dateFrom = date('Y-m-d') . ' 00:00:00'; }
if (empty($dateTo))   { $dateTo   = date('Y-m-d') . ' 23:59:59'; }

// Формируем WHERE clause
$where  = array();
$params = array(':dateFrom' => $dateFrom, ':dateTo' => $dateTo);
$where[] = "calldate >= :dateFrom";
$where[] = "calldate <= :dateTo";

if ($numberA !== '') {
    $where[] = "(src LIKE :numberA OR cnum LIKE :numberA)";
    $params[':numberA'] = "%{$numberA}%";
}
if ($numberB !== '') {
    $where[] = "(dst LIKE :numberB OR did LIKE :numberB)";
    $params[':numberB'] = "%{$numberB}%";
}
if ($status !== '') {
    $where[] = "disposition = :status";
    $params[':status'] = $status;
}

if ($filterDid !== '') {
    $where[] = "did LIKE :filterDid";
    $params[':filterDid'] = "%{$filterDid}%";
}
$whereClause = implode(' AND ', $where);

// ── 1. Получаем ВСЕ CDR-записи за период ────────────────────────────────────
$sql = "SELECT calldate, COALESCE(cnum, src) AS src, dst, billsec, disposition,
               uniqueid, channel, dstchannel, did, duration, linkedid, dcontext, cnum
        FROM asteriskcdrdb.cdr
        WHERE {$whereClause}
        ORDER BY calldate DESC
        LIMIT 15000";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$allCalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Собираем уникальные linkedid
$linkedIds = array();
foreach ($allCalls as $call) {
    if (!empty($call['linkedid'])) {
        $linkedIds[$call['linkedid']] = true;
    }
}
$linkedIdList = array_keys($linkedIds);

// ── 2. МАССОВЫЙ запрос: «кто ответил» (первый ANSWER в CEL) ─────────────────
$answeredMap = array();
if (!empty($linkedIdList)) {
    foreach (array_chunk($linkedIdList, 500) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare(
            "SELECT linkedid, channame, cid_num
             FROM asteriskcdrdb.cel
             WHERE linkedid IN ($ph)
               AND eventtype = 'ANSWER'
             ORDER BY eventtime ASC"
        );
        $stmt->execute($chunk);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $lid = $row['linkedid'];
            if (isset($answeredMap[$lid])) continue;
            $ext = null;
            if (!empty($row['channame']) &&
                preg_match('/(?:SIP|PJSIP|Local)\/([^-@]+)/', $row['channame'], $m)) {
                $p = $m[1];
                if (is_numeric($p) && strlen($p) >= 2 && strlen($p) <= 6) $ext = $p;
            }
            if ($ext === null && !empty($row['cid_num']) &&
                is_numeric($row['cid_num']) &&
                strlen($row['cid_num']) >= 2 && strlen($row['cid_num']) <= 6) {
                $ext = $row['cid_num'];
            }
            if ($ext !== null) $answeredMap[$lid] = $ext;
        }
    }
}

// ── 3. МАССОВЫЙ запрос: события перевода из CEL ──────────────────────────────
//
// Для ATTENDEDTRANSFER Asterisk пишет в extra JSON поле channel2_uniqueid —
// это uniqueid/linkedid нового плеча после перевода. Именно там в CDR лежит
// конечный dst (например 7432).
//
// transferChainMap: linkedid оригинала => child_linkedid (новое плечо)
// transferExtMap:   linkedid оригинала => номер (для blind transfer через exten/extension)

$transferChainMap = array();
$transferExtMap   = array();

if (!empty($linkedIdList)) {
    foreach (array_chunk($linkedIdList, 500) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare(
            "SELECT linkedid, eventtype, exten, extra
             FROM asteriskcdrdb.cel
             WHERE linkedid IN ($ph)
               AND eventtype IN ('ATTENDEDTRANSFER','BLINDTRANSFER')
             ORDER BY eventtime ASC"
        );
        $stmt->execute($chunk);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $lid = $row['linkedid'];
            if (isset($transferChainMap[$lid]) || isset($transferExtMap[$lid])) continue;

            if (!empty($row['extra'])) {
                $extraClean = str_replace('""', '"', $row['extra']);
                $extra = @json_decode($extraClean, true);
                if ($extra) {
                    // ATTENDEDTRANSFER: берём channel2_uniqueid как дочерний linkedid
                    if (!empty($extra['channel2_uniqueid'])) {
                        $transferChainMap[$lid] = $extra['channel2_uniqueid'];
                        continue;
                    }
                    // BLINDTRANSFER: номер назначения может быть в extension
                    if (!empty($extra['extension']) &&
                        preg_match('/^[0-9]{2,6}$/', $extra['extension'])) {
                        $transferExtMap[$lid] = $extra['extension'];
                        continue;
                    }
                }
            }

            // Fallback: exten из строки CEL
            if (!empty($row['exten']) && preg_match('/^[0-9]{2,6}$/', $row['exten'])) {
                $transferExtMap[$lid] = $row['exten'];
            }
        }
    }
}

// ── 4. Разрешаем цепочки: child_linkedid => конечный dst из CDR ──────────────
$transferTargetMap = array();

// Blind transfer — номер уже известен напрямую
foreach ($transferExtMap as $lid => $ext) {
    $transferTargetMap[$lid] = $ext;
}

// Attended transfer — ищем в CDR дочернего linkedid строку с реальным dst
$childLinkedIds = array_unique(array_values($transferChainMap));
if (!empty($childLinkedIds)) {
    foreach (array_chunk($childLinkedIds, 500) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare(
            // Берём dst с наибольшим billsec — это реальный разговор, не транзит
            "SELECT linkedid, dst
             FROM asteriskcdrdb.cdr
             WHERE linkedid IN ($ph)
               AND disposition IN ('ANSWERED','ANSWER')
               AND dst REGEXP '^[0-9]{2,6}$'
             ORDER BY billsec DESC"
        );
        $stmt->execute($chunk);

        $childDstMap = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $clid = $row['linkedid'];
            if (!isset($childDstMap[$clid])) {
                $childDstMap[$clid] = $row['dst'];
            }
        }

        foreach ($transferChainMap as $origLid => $childLid) {
            if (isset($childDstMap[$childLid]) && !isset($transferTargetMap[$origLid])) {
                $transferTargetMap[$origLid] = $childDstMap[$childLid];
            }
        }
    }
}

// ── 5. Определяем направление звонка ────────────────────────────────────────
function determineDirection($src, $dst, $channel, $dcontext, $did) {
    if (strpos($dcontext, 'disa') !== false) return 'inbound';
    if (preg_match('/from-pstn|from-trunk|from-did/i', $dcontext)) return 'inbound';
    if (preg_match('/macro-dialout-trunk/i', $dcontext)) return 'outbound';
    if (strlen($src) >= 2 && strlen($src) <= 6 &&
        strlen($dst) >= 2 && strlen($dst) <= 6) return 'local';
    if (strlen($src) >= 10 && strlen($dst) <= 6) return 'inbound';
    if (strlen($src) <= 6  && strlen($dst) >= 10) return 'outbound';
    return 'inbound';
}

// ── 6. Группировка и обогащение данных ──────────────────────────────────────
$groupedCalls = array();
foreach ($allCalls as $call) {
    if (empty($call['src']) || (strlen($call['src']) < 2 && !is_numeric($call['src']))) continue;

    // Группируем по linkedid — он уникален для каждого звонка.
    // Обрезка uniqueid до точки ненадёжна: два разных звонка в одну секунду
    // могут иметь одинаковый префикс (например 1776155795.17512 и 1776155795.17513).
    $uniqueidBase  = !empty($call['linkedid']) ? $call['linkedid'] : explode('.', $call['uniqueid'])[0];
    $callDirection = determineDirection(
        $call['src'], $call['dst'], $call['channel'], $call['dcontext'], $call['did']
    );

    $lid          = $call['linkedid'];
    $transferInfo = isset($transferTargetMap[$lid]) ? $transferTargetMap[$lid] : 'Нет';

    if (!isset($groupedCalls[$uniqueidBase])) {
        $groupedCalls[$uniqueidBase] = array(
            'calls'            => array(),
            'maxBillsec'       => 0,
            'answered'         => false,
            'finalDisposition' => '',
            'finalCalldate'    => '',
            'direction'        => $callDirection,
            'did'              => !empty($call['did']) ? $call['did'] : '',
            'answered_by'      => '-',
            'transferred_to'   => $transferInfo,
            'earliestCall'     => null, // самая ранняя CDR-запись (заполним после)
        );
    }

    $groupedCalls[$uniqueidBase]['calls'][] = $call;
    // Явно отслеживаем самую раннюю запись по calldate (не полагаемся на порядок сортировки)
    $currentEarliest = $groupedCalls[$uniqueidBase]['earliestCall'];
    if ($currentEarliest === null || strtotime($call['calldate']) < strtotime($currentEarliest['calldate'])) {
        $groupedCalls[$uniqueidBase]['earliestCall'] = $call;
    }

    if ($call['billsec'] > $groupedCalls[$uniqueidBase]['maxBillsec']) {
        $groupedCalls[$uniqueidBase]['maxBillsec'] = $call['billsec'];
    }

    if ($call['disposition'] == 'ANSWERED' || $call['disposition'] == 'ANSWER') {
        $groupedCalls[$uniqueidBase]['answered']         = true;
        $groupedCalls[$uniqueidBase]['finalDisposition'] = $call['disposition'];
        $groupedCalls[$uniqueidBase]['finalCalldate']    = $call['calldate'];

        $answeredBy = isset($answeredMap[$lid]) ? $answeredMap[$lid] : '-';

        if ($answeredBy !== '-' && $answeredBy == $call['src']) {
            $answeredBy = '-';
        }
        if ($answeredBy !== '-' && $callDirection == 'outbound') {
            $answeredBy = '-';
        }
        if ($answeredBy !== '-') {
            $groupedCalls[$uniqueidBase]['answered_by'] = $answeredBy;
        }
    }
}

// ── 7. Батчевый запрос: самая ранняя CDR-запись для каждого linkedid ────────
// Делаем ОДИН запрос вместо N запросов в цикле
$firstRowMap = array(); // linkedid => ['dst','dcontext','calldate','src']
$allLinkedIds = array_keys($groupedCalls);
if (!empty($allLinkedIds)) {
    foreach (array_chunk($allLinkedIds, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $stmtBatch = $db->prepare(
            "SELECT linkedid, dst, dcontext, calldate, src
             FROM asteriskcdrdb.cdr
             WHERE linkedid IN ($ph)
             ORDER BY calldate ASC"
        );
        $stmtBatch->execute($chunk);
        while ($brow = $stmtBatch->fetch(PDO::FETCH_ASSOC)) {
            $lid = $brow['linkedid'];
            // Берём только первую (самую раннюю) запись для каждого linkedid
            if (!isset($firstRowMap[$lid])) {
                $firstRowMap[$lid] = $brow;
            }
        }
    }
}

// ── 8. Формируем итоговый массив ─────────────────────────────────────────────
$calls = array();
foreach ($groupedCalls as $baseId => $group) {
    // Самая ранняя CDR-запись — реальная точка входа звонка (группа/номер)
    $firstCall   = !empty($group['earliestCall']) ? $group['earliestCall'] : $group['calls'][count($group['calls']) - 1];
    $finalStatus = $group['answered'] ? 'ANSWERED' : $firstCall['disposition'];
    if (empty($finalStatus)) $finalStatus = 'NO ANSWER';

    if ($direction !== '' && $group['direction'] != $direction) continue;

    $did         = !empty($group['did']) ? $group['did'] : (!empty($firstCall['did']) ? $firstCall['did'] : '');

    // dst/calldate/src — из батчевого запроса выше (O(1) lookup)
    $destDisplay  = $firstCall['dst'];
    $destDcontext = $firstCall['dcontext'];
    if (isset($firstRowMap[$baseId])) {
        $firstRow = $firstRowMap[$baseId];
        $destDisplay            = $firstRow['dst'];
        $destDcontext           = $firstRow['dcontext'];
        if (!empty($firstRow['calldate'])) $firstCall['calldate'] = $firstRow['calldate'];
        if (!empty($firstRow['src']))      $firstCall['src']      = $firstRow['src'];
    }
    if (preg_match('/ext-group|ringgroup/i', $destDcontext)) {
        $destDisplay .= ' (Группа)';
    }

    $calls[] = array(
        'calldate'       => $firstCall['calldate'], // всегда время первого CDR (время поступления звонка)
        'src'            => $firstCall['src'],
        'dst'            => $firstCall['dst'],
        'did'            => $did,
        'billsec'        => $group['maxBillsec'],
        'disposition'    => $finalStatus,
        'channel'        => $firstCall['channel'],
        'uniqueid'       => str_replace('.', '_', $baseId),
        'linkedid'       => $baseId,
        'direction'      => $group['direction'],
        'dest_display'   => $destDisplay,
        'answered_by'    => $group['answered_by'],
        'transferred_to' => $group['transferred_to'],
        'details'        => $group['calls'],
        'totalStages'    => count($group['calls']),
    );
}

// Фильтрация по Номеру В (Кто ответил)
if ($numberC !== '') {
    $calls = array_filter($calls, function($c) use ($numberC) {
        return strpos($c['answered_by'], $numberC) !== false;
    });
    $calls = array_values($calls);
}

// Фильтрация по переводу
if ($filterTransfer !== '') {
    $calls = array_filter($calls, function($c) use ($filterTransfer) {
        return $c['transferred_to'] !== 'Нет' && strpos($c['transferred_to'], $filterTransfer) !== false;
    });
    $calls = array_values($calls);
}

// Сортировка по дате
usort($calls, function($a, $b) {
    return strtotime($b['calldate']) - strtotime($a['calldate']);
});

// ── 9. ЭКСПОРТ В XLS ─────────────────────────────────────────────────────────
if ($export === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="cdr_report_' . date('Y-m-d_H-i-s') . '.xls"');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"
               xmlns:x="urn:schemas-microsoft-com:office:excel"
               xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<style>td,th{border:1px solid #ccc;padding:5px;font-family:Arial,sans-serif;font-size:10pt;mso-number-format:"\@";}th{background:#f2f2f2;font-weight:bold;}</style>';
    echo '</head><body>';
    echo '<h3 style="font-family:Arial;">Отчёт по звонкам: ' . htmlspecialchars($dateFrom) . ' — ' . htmlspecialchars($dateTo) . '</h3>';
    echo '<table><tr><th>Дата/Время</th><th>Направление</th><th>Звонящий</th><th>DID</th><th>Куда</th><th>Ответил</th><th>Перевод</th><th>Длительность (сек)</th><th>Статус</th></tr>';
    foreach ($calls as $c) {
        $dir = ($c['direction'] == 'inbound') ? 'Входящий' : (($c['direction'] == 'outbound') ? 'Исходящий' : 'Внутренний');
        echo '<tr>';
        echo '<td>' . htmlspecialchars($c['calldate'])       . '</td>';
        echo '<td>' . $dir                                   . '</td>';
        echo '<td>' . htmlspecialchars($c['src'])            . '</td>';
        echo '<td>' . htmlspecialchars($c['did'])            . '</td>';
        echo '<td>' . htmlspecialchars($c['dest_display'])   . '</td>';
        echo '<td>' . htmlspecialchars($c['answered_by'])    . '</td>';
        echo '<td>' . htmlspecialchars($c['transferred_to']) . '</td>';
        echo '<td>' . $c['billsec']                          . '</td>';
        echo '<td>' . htmlspecialchars($c['disposition'])    . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

// ── 10. Пагинация ──────────────────────────────────────────────────────────────
$totalRecords = count($calls);
if ($export !== 'pdf') {
    $totalPages = ceil($totalRecords / $perPage);
    if ($totalPages < 1) $totalPages = 1;
    $calls = array_slice($calls, $offset, $perPage);
} else {
    $totalPages = 1;
    $page       = 1;
}

// ── 11. Вспомогательные функции вывода ───────────────────────────────────────
function getDirectionBadge($direction) {
    switch ($direction) {
        case 'inbound':  return '<span class="badge badge-inbound">⬇ Входящий</span>';
        case 'outbound': return '<span class="badge badge-outbound">⬆ Исходящий</span>';
        case 'local':    return '<span class="badge badge-local">↔ Внутренний</span>';
        default:         return '<span class="badge">Неизвестно</span>';
    }
}

function getStatusBadge($disposition) {
    switch ($disposition) {
        case 'ANSWERED':
        case 'ANSWER':    return '<span class="status-badge status-answered"><span class="dot dot-green"></span>Ответ</span>';
        case 'NO ANSWER': return '<span class="status-badge status-missed"><span class="dot dot-orange"></span>Нет ответа</span>';
        case 'BUSY':      return '<span class="status-badge status-busy"><span class="dot dot-red"></span>Занято</span>';
        case 'FAILED':    return '<span class="status-badge status-failed"><span class="dot dot-red"></span>Ошибка</span>';
        default:          return '<span class="status-badge"><span class="dot dot-gray"></span>' . htmlspecialchars($disposition) . '</span>';
    }
}

function formatDuration($seconds) {
    $s = (int)$seconds;
    if ($s < 60) return $s . ' сек';
    $m = floor($s / 60); $sec = $s % 60;
    if ($m < 60) return sprintf('%02d:%02d', $m, $sec);
    $h = floor($m / 60); $min = $m % 60;
    return sprintf('%02d:%02d:%02d', $h, $min, $sec);
}

function getStageIcon($disposition) {
    switch ($disposition) {
        case 'ANSWERED':
        case 'ANSWER':    return '<span class="stage-icon stage-green">✓</span>';
        case 'NO ANSWER': return '<span class="stage-icon stage-orange">✕</span>';
        case 'BUSY':      return '<span class="stage-icon stage-red">⊘</span>';
        case 'FAILED':    return '<span class="stage-icon stage-red">⚠</span>';
        default:          return '<span class="stage-icon stage-gray">•</span>';
    }
}

function getExportUrl($type) {
    global $_GET;
    $params = $_GET;
    $params['export'] = $type;
    unset($params['page']);
    return 'my_cdr.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Звонки</title>
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<style>
:root{--bg-primary:#fff;--bg-secondary:#f5f5f7;--bg-tertiary:#fafafa;--text-primary:#1d1d1f;--text-secondary:#86868b;--text-tertiary:#afafb2;--accent:#0071e3;--border:rgba(0,0,0,0.08);--shadow-sm:0 1px 3px rgba(0,0,0,0.04);--shadow-md:0 4px 16px rgba(0,0,0,0.06);--shadow-lg:0 8px 32px rgba(0,0,0,0.08);--radius-sm:8px;--radius-md:12px;--radius-lg:16px;--font:-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",Helvetica,Arial,sans-serif;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:var(--font);background:var(--bg-secondary);color:var(--text-primary);line-height:1.47059;font-weight:400;letter-spacing:-0.022em;-webkit-font-smoothing:antialiased;padding:40px 24px;}
.container{max-width:1400px;margin:0 auto;}
.page-header{margin-bottom:32px;}
.page-title{font-size:40px;font-weight:600;letter-spacing:-0.015em;color:var(--text-primary);margin-bottom:8px;}
.page-subtitle{font-size:17px;color:var(--text-secondary);}
.filter-card{background:var(--bg-primary);border-radius:var(--radius-lg);padding:24px 28px;margin-bottom:24px;box-shadow:var(--shadow-md);border:1px solid var(--border);}
.filter-row-1{display:grid;grid-template-columns:1fr 1fr 160px 160px;gap:12px;margin-bottom:12px;}
.filter-row-2{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px;}
.filter-group{min-width:0;}
.filter-label{display:block;font-size:11px;font-weight:500;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:5px;}
.filter-input{width:100%;padding:9px 12px;font-size:13px;font-family:var(--font);color:var(--text-primary);background:var(--bg-tertiary);border:1px solid var(--border);border-radius:var(--radius-sm);outline:none;transition:all 0.2s ease;}
.filter-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,113,227,0.15);background:var(--bg-primary);}
select.filter-input{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2386868b' d='M6 8.5L1 3.5h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px;}
.filter-actions-row{display:flex;align-items:center;justify-content:space-between;gap:8px;padding-top:4px;border-top:1px solid var(--border);}
.filter-actions-left{display:flex;gap:8px;}
.filter-actions-right{display:flex;gap:8px;}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 18px;font-size:13px;font-weight:500;font-family:var(--font);border:none;border-radius:var(--radius-sm);cursor:pointer;transition:all 0.2s ease;text-decoration:none;white-space:nowrap;min-height:36px;}
.btn-primary{background:var(--accent);color:#fff;}.btn-primary:hover{opacity:0.9;}
.btn-secondary{background:var(--bg-tertiary);color:var(--text-secondary);border:1px solid var(--border);}.btn-secondary:hover{background:var(--border);color:var(--text-primary);}
.btn-excel{background:#217346;color:#fff;}.btn-excel:hover{background:#1e6b41;}
.btn-pdf{background:#d93025;color:#fff;}.btn-pdf:hover{background:#c62828;}
.table-card{background:var(--bg-primary);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);border:1px solid var(--border);overflow-x:auto;}
.table-stats{padding:16px 28px;border-bottom:1px solid var(--border);font-size:13px;color:var(--text-secondary);background:var(--bg-tertiary);}
.table-stats strong{color:var(--text-primary);font-weight:600;}
table{width:100%;border-collapse:collapse;min-width:1200px;}
th{padding:14px 20px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-secondary);background:var(--bg-primary);border-bottom:1px solid var(--border);white-space:nowrap;}
td{padding:14px 20px;font-size:13px;color:var(--text-primary);border-bottom:1px solid var(--border);vertical-align:middle;}
tr:hover{background:var(--bg-tertiary);}
tr:last-child td{border-bottom:none;}
.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:500;white-space:nowrap;}
.badge-inbound{background:#e3f2fd;color:#1565c0;}
.badge-outbound{background:#e8f5e9;color:#2e7d32;}
.badge-local{background:#fff3e0;color:#e65100;}
.status-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;font-size:12px;}
.status-answered{color:#1a7f37;background:#e8f5e9;}
.status-missed{color:#b35900;background:#fff3e0;}
.status-busy,.status-failed{color:#c41c2c;background:#ffeaea;}
.dot{width:6px;height:6px;border-radius:50%;display:inline-block;}
.dot-green{background:#34c759;}.dot-orange{background:#ff9500;}.dot-red{background:#ff3b30;}.dot-gray{background:#86868b;}
.expand-btn{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--bg-tertiary);border:1px solid var(--border);cursor:pointer;transition:all 0.2s ease;color:var(--text-secondary);font-size:14px;line-height:1;margin-right:8px;}
.expand-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent);}
.expand-btn.expanded{background:var(--accent);color:#fff;border-color:var(--accent);transform:rotate(90deg);}
.detail-row{display:none;}
.detail-row.show{display:table-row;}
.detail-cell{padding:0 !important;background:var(--bg-tertiary);}
.detail-panel{padding:0;}
.cel-header{display:flex;align-items:baseline;gap:12px;padding:14px 24px 10px;border-bottom:1px solid var(--border);}
.cel-title{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-secondary);}
.cel-meta{font-size:12px;color:var(--text-tertiary);}
.cel-table-wrap{padding:0;}
.cel-loading{padding:20px 24px;font-size:13px;color:var(--text-secondary);display:flex;align-items:center;gap:8px;}
.cel-empty{padding:20px 24px;font-size:13px;color:var(--text-tertiary);}
.cel-error{padding:16px 24px;font-size:13px;color:#c41c2c;}
.cel-spinner{width:14px;height:14px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0;}
@keyframes spin{to{transform:rotate(360deg);}}
.cel-table{width:100%;border-collapse:collapse;font-size:13px;}
.cel-table thead th{padding:8px 24px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-secondary);background:var(--bg-secondary);border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;}
.cel-table thead th:last-child{text-align:right;}
.cel-td-time{padding:9px 24px;white-space:nowrap;color:var(--text-secondary);font-size:12px;width:160px;}
.cel-td-label{padding:9px 24px;color:var(--text-primary);font-weight:400;}
.cel-td-elapsed{padding:9px 24px;text-align:right;white-space:nowrap;color:var(--text-secondary);font-size:12px;font-variant-numeric:tabular-nums;width:90px;}
.cel-row{border-bottom:1px solid var(--border);}
.cel-row:last-child{border-bottom:none;}
.cel-row:hover td{background:rgba(0,0,0,0.015);}
.cel-icon{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;font-size:11px;margin-right:8px;flex-shrink:0;vertical-align:middle;}
.cel-td-label{display:flex;align-items:center;}
/* answer — зелёный фон всей строки */
.cel-answer td{background:#e8f5e9;}
.cel-answer .cel-td-label{color:#1a7f37;font-weight:500;}
.cel-icon-answer{background:#2e7d32;color:#fff;}
/* transfer */
.cel-transfer td{background:#f3e5f5;}
.cel-transfer .cel-td-label{color:#6a1b9a;font-weight:500;}
.cel-icon-transfer{background:#7b1fa2;color:#fff;}
/* inbound */
.cel-icon-inbound{background:#1565c0;color:#fff;}
/* queue */
.cel-icon-queue{background:#e65100;color:#fff;}
/* record */
.cel-icon-record{background:#b71c1c;color:#fff;}
/* hold */
.cel-hold td{background:#fff8e1;}
.cel-hold .cel-td-label{color:#b35900;}
.cel-icon-hold{background:#e65100;color:#fff;}
/* end */
.cel-end .cel-td-label{color:var(--text-secondary);}
.cel-icon-end{background:#546e7a;color:#fff;}
/* other/info */
.cel-icon-other,.cel-icon-info,.cel-icon-ivr{background:#90a4ae;color:#fff;}
.pagination{display:flex;gap:4px;margin-top:20px;padding:20px 28px;border-top:1px solid var(--border);flex-wrap:wrap;}
.page-link{padding:6px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);text-decoration:none;color:var(--text-secondary);font-size:13px;transition:all 0.15s ease;}
.page-link:hover{background:var(--bg-secondary);color:var(--text-primary);}
.page-link.active{background:var(--accent);color:#fff;border-color:var(--accent);}
@media print{
  @page{size:A3 landscape;margin:10mm;}
  body{background:#fff !important;padding:0 !important;}
  .no-print{display:none !important;}
  .container{max-width:100% !important;margin:0 !important;padding:10px;}
  .table-card{box-shadow:none !important;border:none !important;background:transparent !important;}
  table{min-width:100% !important;font-size:9px !important;}
  th,td{padding:5px 6px !important;border-bottom:1px solid #eee !important;}
  th{background:#f5f5f5 !important;color:#333 !important;}
  .badge,.status-badge{border:1px solid #ccc !important;background:#f9f9f9 !important;color:#000 !important;padding:2px 5px !important;font-size:8px !important;}
  .dot{border:1px solid #333 !important;background:transparent !important;}
}
@media(max-width:900px){.filter-row-1{grid-template-columns:1fr 1fr;}.filter-row-2{grid-template-columns:1fr 1fr;}}@media(max-width:768px){body{padding:20px 12px;}.page-title{font-size:28px;}.filter-row-1,.filter-row-2{grid-template-columns:1fr;}.filter-actions-row{flex-direction:column;align-items:stretch;}.filter-actions-left,.filter-actions-right{justify-content:stretch;}.filter-actions-left .btn,.filter-actions-right .btn{flex:1;justify-content:center;}}
</style>
</head>
<body>
<div class="container">

<div class="page-header no-print">
  <h1 class="page-title">Звонки</h1>
  <p class="page-subtitle">Детализация вызовов за выбранный период</p>
</div>

<form method="GET" class="filter-card no-print" autocomplete="off">
  <!-- Ряд 1: даты, направление, статус -->
  <div class="filter-row-1">
    <div class="filter-group">
      <label class="filter-label">Дата от</label>
      <input type="datetime-local" name="dateFrom" class="filter-input"
             value="<?php echo $dateFrom ? str_replace(' ', 'T', $dateFrom) : ''; ?>">
    </div>
    <div class="filter-group">
      <label class="filter-label">Дата до</label>
      <input type="datetime-local" name="dateTo" class="filter-input"
             value="<?php echo $dateTo ? str_replace(' ', 'T', $dateTo) : ''; ?>">
    </div>
    <div class="filter-group filter-group-sm">
      <label class="filter-label">Направление</label>
      <select name="direction" class="filter-input">
        <option value="">Все</option>
        <option value="inbound"  <?php echo $direction === 'inbound'  ? 'selected' : ''; ?>>Входящий</option>
        <option value="outbound" <?php echo $direction === 'outbound' ? 'selected' : ''; ?>>Исходящий</option>
        <option value="local"    <?php echo $direction === 'local'    ? 'selected' : ''; ?>>Внутренний</option>
      </select>
    </div>
    <div class="filter-group filter-group-sm">
      <label class="filter-label">Статус</label>
      <select name="status" class="filter-input">
        <option value="">Все</option>
        <option value="ANSWERED"  <?php echo $status === 'ANSWERED'  ? 'selected' : ''; ?>>Ответ</option>
        <option value="NO ANSWER" <?php echo $status === 'NO ANSWER' ? 'selected' : ''; ?>>Нет ответа</option>
        <option value="BUSY"      <?php echo $status === 'BUSY'      ? 'selected' : ''; ?>>Занято</option>
        <option value="FAILED"    <?php echo $status === 'FAILED'    ? 'selected' : ''; ?>>Ошибка</option>
      </select>
    </div>
  </div>
  <!-- Ряд 2: номера -->
  <div class="filter-row-2">
    <div class="filter-group">
      <label class="filter-label">Номер А (звонящий)</label>
      <input type="text" name="numberA" class="filter-input"
             value="<?php echo htmlspecialchars($numberA); ?>" placeholder="Кто звонил">
    </div>
    <div class="filter-group">
      <label class="filter-label">DID</label>
      <input type="text" name="filterDid" class="filter-input"
             value="<?php echo htmlspecialchars($filterDid); ?>" placeholder="Входящий номер">
    </div>
    <div class="filter-group">
      <label class="filter-label">Номер Б (куда)</label>
      <input type="text" name="numberB" class="filter-input"
             value="<?php echo htmlspecialchars($numberB); ?>" placeholder="Куда звонил">
    </div>
    <div class="filter-group">
      <label class="filter-label">Номер В (кто ответил)</label>
      <input type="text" name="numberC" class="filter-input"
             value="<?php echo htmlspecialchars($numberC); ?>" placeholder="Внутренний номер">
    </div>
    <div class="filter-group">
      <label class="filter-label">Перевод на</label>
      <input type="text" name="filterTransfer" class="filter-input"
             value="<?php echo htmlspecialchars($filterTransfer); ?>" placeholder="Внутренний номер">
    </div>
  </div>
  <!-- Ряд 3: кнопки -->
  <div class="filter-actions-row">
    <div class="filter-actions-left">
      <button type="submit" class="btn btn-primary">🔍 Поиск</button>
      <a href="my_cdr.php" class="btn btn-secondary">🔄 Сброс</a>
    </div>
    <div class="filter-actions-right">
      <a href="<?php echo getExportUrl('xls'); ?>" class="btn btn-excel" title="Скачать все записи в Excel">📊 Экспорт Excel</a>
      <a href="<?php echo getExportUrl('pdf'); ?>" class="btn btn-pdf"   title="Открыть окно печати / Сохранить PDF">📄 Экспорт PDF</a>
    </div>
  </div>
</form>


<div class="table-card">
  <div class="table-stats">
    <?php if ($export === 'pdf'): ?>
      <strong>Отчёт для печати</strong> | Период: <?php echo htmlspecialchars($dateFrom); ?> — <?php echo htmlspecialchars($dateTo); ?> | Записей: <strong><?php echo number_format($totalRecords, 0, '.', ' '); ?></strong>
    <?php else: ?>
      Показано <strong><?php echo count($calls); ?></strong> звонков из <strong><?php echo number_format($totalRecords, 0, '.', ' '); ?></strong>
    <?php endif; ?>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:40px;" class="no-print"></th>
        <th>Направление</th>
        <th>Дата и время</th>
        <th>Кто звонил</th>
        <th>DID</th>
        <th>Куда попал</th>
        <th>Кто ответил</th>
        <th>Перевод</th>
        <th>Разговор</th>
        <th>Статус</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($calls as $call):
        $rowId    = 'row_'    . $call['uniqueid'];
        $detailId = 'detail_' . $call['uniqueid'];
    ?>
      <tr class="main-row" id="<?php echo $rowId; ?>">
        <td class="no-print">
          <button class="expand-btn" onclick="toggleDetail('<?php echo $call['uniqueid']; ?>','<?php echo $call['linkedid']; ?>')">&#9655;</button>
        </td>
        <td><?php echo getDirectionBadge($call['direction']); ?></td>
        <td><?php echo $call['calldate']; ?></td>
        <td><?php echo htmlspecialchars($call['src']); ?></td>
        <td><?php echo htmlspecialchars($call['did']); ?></td>
        <td><?php echo htmlspecialchars($call['dest_display']); ?></td>
        <td><?php echo htmlspecialchars($call['answered_by']); ?></td>
        <td><?php echo htmlspecialchars($call['transferred_to']); ?></td>
        <td><?php echo formatDuration($call['billsec']); ?></td>
        <td><?php echo getStatusBadge($call['disposition']); ?></td>
      </tr>
      <tr class="detail-row" id="<?php echo $detailId; ?>">
        <td colspan="10" class="detail-cell">
          <div class="detail-panel">
            <div class="cel-header">
              <span class="cel-title">Детализация звонка</span>
              <span class="cel-meta"><?php echo htmlspecialchars($call['src']); ?> → <?php echo htmlspecialchars($call['dst']); ?> · <?php echo $call['calldate']; ?></span>
            </div>
            <div class="cel-table-wrap" id="cel_<?php echo $call['uniqueid']; ?>">
              <div class="cel-loading">Загрузка...</div>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1 && $export !== 'pdf'): ?>
  <div class="pagination no-print">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a class="page-link <?php echo $i == $page ? 'active' : ''; ?>"
         href="?<?php echo http_build_query(array_merge($_GET, array('page' => $i))); ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

</div>
<script>
var celLoaded = {};

function toggleDetail(uniqueid, linkedid) {
    var detailRow = document.getElementById('detail_' + uniqueid);
    var btn = detailRow.previousElementSibling.querySelector('.expand-btn');
    var isOpen = detailRow.classList.contains('show');

    if (isOpen) {
        detailRow.classList.remove('show');
        btn.classList.remove('expanded');
        btn.innerHTML = '&#9655;';
        return;
    }

    detailRow.classList.add('show');
    btn.classList.add('expanded');
    btn.innerHTML = '&#8964;';

    if (celLoaded[uniqueid]) return;
    celLoaded[uniqueid] = true;

    var wrap = document.getElementById('cel_' + uniqueid);
    wrap.innerHTML = '<div class="cel-loading"><span class="cel-spinner"></span> Загрузка событий...</div>';

    fetch('cdr_cel_detail.php?linkedid=' + encodeURIComponent(linkedid))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                wrap.innerHTML = '<div class="cel-error">Ошибка: ' + data.error + '</div>';
                return;
            }
            if (!data.events || data.events.length === 0) {
                wrap.innerHTML = '<div class="cel-empty">Событий не найдено</div>';
                return;
            }

            var typeLabels = {
                answer: 'answer', hangup: 'hangup', end: 'end',
                transfer: 'transfer', bridge: 'bridge', hold: 'hold',
                start: 'start', app: 'app', other: 'other'
            };

            var html = '<table class="cel-table">';
            html += '<thead><tr><th>Дата и время</th><th>Событие</th><th>Время с начала</th></tr></thead><tbody>';

            data.events.forEach(function(ev) {
                var type = ev.type || 'other';
                var cls  = 'cel-row cel-' + type;
                var icon = {
                    inbound:  '&#8600;',
                    answer:   '&#10003;',
                    transfer: '&#8644;',
                    queue:    '&#9776;',
                    ivr:      '&#9835;',
                    record:   '&#9679;',
                    hold:     '&#9646;',
                    end:      '&#9632;',
                    info:     '&#8226;',
                    other:    '&#8226;'
                }[type] || '&#8226;';
                var timeCell = ev.time
                    ? '<td class="cel-td-time">' + ev.time + '</td>'
                    : '<td class="cel-td-time"></td>';
                html += '<tr class="' + cls + '">';
                html += timeCell;
                html += '<td class="cel-td-label"><span class="cel-icon cel-icon-' + type + '">' + icon + '</span>' + ev.label + '</td>';
                html += '<td class="cel-td-elapsed">' + (ev.elapsed || '') + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            wrap.innerHTML = html;
        })
        .catch(function(e) {
            wrap.innerHTML = '<div class="cel-error">Ошибка загрузки: ' + e.message + '</div>';
        });
}

window.onload = function() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('export') === 'pdf') {
        setTimeout(function() { window.print(); }, 400);
    }
};
</script>
</body>
</html>