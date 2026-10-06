<?php
/**
 * コマの集計ルール（統計・異常値・今後のバッジで共通に使う）
 */

require_once __DIR__ . '/data.php';

// これ以上の分数のコマは放置などによる異常値とみなす（#T-003）
define('KOMA_ANOMALY_MINUTES', 1000);

const KOMA_DONE_STATUSES = ['completed', 'overtime_max', 'closed', 'auto_closed'];

function koma_is_done(array $k): bool {
    return in_array($k['status'] ?? 'idle', KOMA_DONE_STATUSES, true);
}

function koma_is_anomaly(array $k): bool {
    return koma_is_done($k) && (int)($k['total_seconds'] ?? 0) >= KOMA_ANOMALY_MINUTES * 60;
}

/**
 * 全期間の異常値コマを新しい日付順に返す。
 * 返り値: [['date' => 'Y-m-d', 'koma' => array], ...]
 */
function find_anomaly_komas(): array {
    $result = [];
    foreach (glob(__DIR__ . '/../data/sessions/*/*/data.json') ?: [] as $file) {
        if (!preg_match('#(\d{4})/(\d{2}-\d{2})/data\.json$#', str_replace('\\', '/', $file), $m)) continue;
        $date = $m[1] . '-' . $m[2];
        foreach (load_session($date)['koma'] ?? [] as $k) {
            if (koma_is_anomaly($k)) $result[] = ['date' => $date, 'koma' => $k];
        }
    }
    usort($result, fn($a, $b) => [$b['date'], (int)$a['koma']['id']] <=> [$a['date'], (int)$b['koma']['id']]);
    return $result;
}
