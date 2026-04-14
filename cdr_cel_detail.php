<?php
require_once('/etc/freepbx.conf');
header('Content-Type: application/json; charset=utf-8');

$linkedid = isset($_GET['linkedid']) ? trim($_GET['linkedid']) : '';
if (empty($linkedid)) {
    echo json_encode(['error' => 'No linkedid']);
    exit;
}

try {
    // 1. Находим все связанные linkedid (включая дочерние после перевода)
    $stmt = $db->prepare(
        "SELECT DISTINCT linkedid FROM asteriskcdrdb.cdr
         WHERE linkedid = :lid OR uniqueid = :lid2 LIMIT 20"
    );
    $stmt->execute([':lid' => $linkedid, ':lid2' => $linkedid]);
    $linkedIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'linkedid');

    // Дочерние linkedid через ATTENDEDTRANSFER extra
    $stmt2 = $db->prepare(
        "SELECT extra FROM asteriskcdrdb.cel
         WHERE linkedid = :lid AND eventtype = 'ATTENDEDTRANSFER' LIMIT 5"
    );
    $stmt2->execute([':lid' => $linkedid]);
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['extra'])) {
            $extra = @json_decode(str_replace('""', '"', $row['extra']), true);
            if (!empty($extra['channel2_uniqueid'])) {
                $linkedIds[] = $extra['channel2_uniqueid'];
            }
        }
    }
    $linkedIds = array_unique($linkedIds);

    // 2. DID из CDR
    $stmtDid = $db->prepare(
        "SELECT did FROM asteriskcdrdb.cdr WHERE linkedid = :lid AND did != '' LIMIT 1"
    );
    $stmtDid->execute([':lid' => $linkedid]);
    $didRow   = $stmtDid->fetch(PDO::FETCH_ASSOC);
    $didValue = $didRow ? $didRow['did'] : '';

    // 3. Получаем все CEL события
    $ph   = implode(',', array_fill(0, count($linkedIds), '?'));
    $stmt = $db->prepare(
        "SELECT eventtime, eventtype, cid_name, cid_num, exten, context,
                channame, appname, appdata, linkedid
         FROM asteriskcdrdb.cel
         WHERE linkedid IN ($ph)
         ORDER BY eventtime ASC, id ASC"
    );
    $stmt->execute($linkedIds);
    $celRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Извлекаем короткий номер/имя из строки CEL
    function getWho($row) {
        $cnum = !empty($row['cid_num'])  ? $row['cid_num']  : '';
        $cnam = !empty($row['cid_name']) ? $row['cid_name'] : '';
        $chan  = !empty($row['channame']) ? $row['channame'] : '';

        // Имя + номер если они разные
        if (!empty($cnam) && !empty($cnum) && $cnam !== $cnum) {
            return "{$cnam} ({$cnum})";
        }
        if (!empty($cnum)) return $cnum;

        // Из канала
        if (preg_match('/(?:SIP|PJSIP)\/([^-@;]+)/', $chan, $m)) return $m[1];
        return '';
    }

    function isInternal($num) {
        return preg_match('/^[0-9]{2,6}$/', $num);
    }

    // 5. Строим список значимых событий, устраняя дубликаты
    $events    = [];
    $startTime = null;

    // Трекеры для дедупликации
    $answeredSet   = []; // уже показанные "Ответил: X"
    $hangupSet     = []; // уже показанные "Завершение: X"
    $bridgeSet     = []; // уже показанные "В разговоре: X"
    $transferDone  = false;
    $firstInbound  = false;

    foreach ($celRows as $row) {
        $ev   = $row['eventtype'];
        $who  = getWho($row);
        $cnum = !empty($row['cid_num']) ? $row['cid_num'] : '';
        $cnam = !empty($row['cid_name']) ? $row['cid_name'] : '';
        $exten = !empty($row['exten']) ? $row['exten'] : '';
        $ctx   = !empty($row['context']) ? $row['context'] : '';
        $app   = !empty($row['appname']) ? $row['appname'] : '';

        $ts = strtotime($row['eventtime']);
        if ($startTime === null) $startTime = $ts;
        $elapsed = $ts - $startTime;
        $timeStr = sprintf('%02d:%02d', floor($elapsed/60), $elapsed%60);
        $datetime = date('Y-m-d H:i:s', $ts);

        $label = null;
        $type  = 'info';

        switch ($ev) {

            // ── Входящий звонок (только первый CHAN_START от внешнего) ──────
            case 'CHAN_START':
                if (!$firstInbound && preg_match('/from-pstn|from-trunk|from-did/i', $ctx)) {
                    $firstInbound = true;
                    $label = "Входящий звонок от {$cnum}" . ($didValue ? " на {$didValue}" : "");
                    $type  = 'inbound';
                }
                // Внутренние CHAN_START не показываем — слишком много шума
                break;

            // ── Голосовое меню / IVR ─────────────────────────────────────
            case 'APP_START':
                if ($app === 'Background' || $app === 'Read') {
                    $label = "Голосовое меню";
                    $type  = 'ivr';
                } elseif ($app === 'Queue' && !empty($exten) && $exten !== 's') {
                    $label = "Очередь: {$exten}";
                    $type  = 'queue';
                } elseif ($app === 'MixMonitor') {
                    $label = "Запись разговора начата";
                    $type  = 'record';
                }
                break;

            // ── Вызов конкретного внутреннего номера ─────────────────────
            // Показываем только если dst — внутренний номер-сотрудник
            // и это событие из группового/очередного контекста
            case 'ANSWER':
                // Ответ внутреннего сотрудника — дедупликация по номеру
                if (!empty($who) && !isset($answeredSet[$cnum])) {
                    // Пропускаем ответ самого внешнего звонящего как "ответившего"
                    if (!isInternal($cnum) && $cnum !== '') break;
                    $answeredSet[$cnum] = true;
                    $label = "Ответил: {$who}";
                    $type  = 'answer';
                }
                break;

            // ── Перевод ───────────────────────────────────────────────────
            case 'ATTENDEDTRANSFER':
                if (!$transferDone) {
                    $transferDone = true;
                    $extra = [];
                    if (!empty($row['extra'])) {
                        $extra = @json_decode(str_replace('""', '"', $row['extra']), true) ?: [];
                    }
                    $label = "Консультативный перевод" . ($who ? " от {$who}" : "");
                    $type  = 'transfer';
                }
                break;

            case 'BLINDTRANSFER':
                if (!$transferDone) {
                    $transferDone = true;
                    $target = ($exten && $exten !== 's') ? " на {$exten}" : "";
                    $label  = "Слепой перевод" . ($who ? " от {$who}" : "") . $target;
                    $type   = 'transfer';
                }
                break;

            // ── Завершение — только внутренние, дедупликация ─────────────
            case 'HANGUP':
                // Показываем завершение только внешнего звонящего и только раз
                if (!isInternal($cnum) && !isset($hangupSet[$cnum])) {
                    $hangupSet[$cnum] = true;
                    // Пропускаем — будет показано через LINKEDID_END
                }
                break;

            // ── Конец звонка ──────────────────────────────────────────────
            case 'LINKEDID_END':
                // Показываем только один раз (для корневого linkedid)
                if ($row['linkedid'] === $linkedid) {
                    // Определяем инициатора завершения
                    $initiator = '';
                    if (!isInternal($cnum) && !empty($cnum)) {
                        $initiator = 'клиент';
                    } elseif (!empty($who)) {
                        $initiator = "оператор ({$who})";
                    }
                    if ($initiator) {
                        $events[] = [
                            'time' => $datetime, 'elapsed' => $timeStr,
                            'label' => "Инициатор завершения: {$initiator}", 'type' => 'end'
                        ];
                    }
                    $label = "Завершение звонка";
                    $type  = 'end';
                }
                break;

            case 'HOLD':
                $label = "Удержание" . ($who ? ": {$who}" : "");
                $type  = 'hold';
                break;

            case 'UNHOLD':
                $label = "Снятие с удержания" . ($who ? ": {$who}" : "");
                $type  = 'hold';
                break;
        }

        if ($label !== null) {
            $events[] = [
                'time'    => $datetime,
                'elapsed' => $timeStr,
                'label'   => $label,
                'type'    => $type,
            ];
        }
    }

    // 6. Добавляем в начало строки "Вызов: XXXX" из CDR (кого вызывали в группе/очереди)
    // Берём первый dst из CDR — это группа или очередь
    $stmtDst = $db->prepare(
        "SELECT dst, dcontext FROM asteriskcdrdb.cdr
         WHERE linkedid = :lid ORDER BY calldate ASC LIMIT 1"
    );
    $stmtDst->execute([':lid' => $linkedid]);
    $dstRow = $stmtDst->fetch(PDO::FETCH_ASSOC);
    if ($dstRow && !empty($dstRow['dst'])) {
        $dstCtx = $dstRow['dcontext'];
        $dstNum = $dstRow['dst'];
        if (preg_match('/ext-group|ringgroup/i', $dstCtx)) {
            $groupEvent = ['time' => '', 'elapsed' => '', 'label' => "Группа звонка: {$dstNum}", 'type' => 'queue'];
            array_splice($events, 1, 0, [$groupEvent]);
        }
    }

    echo json_encode(['events' => $events], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}