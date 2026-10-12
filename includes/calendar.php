<?php
/**
 * 休み・稼働日の日付単位の例外（#T-014）
 * - 保存先は data/calendar.json（git 管理外・HTTP 遮断済み）。形式: {"2026-10-15": "work", "2026-10-17": "off"}
 * - 値は "off"（休み）と "work"（稼働）。曜日の既定（config.json の week_off_days）に対して、日付の値が優先する
 * - 例外を消す（＝既定に戻す）ときはキーを消す
 */

require_once __DIR__ . '/data.php';

define('KOMA_CALENDAR_PATH', __DIR__ . '/../data/calendar.json');
const KOMA_DAY_TYPES = ['off', 'work'];
const KOMA_CALENDAR_MAX_ENTRIES = 5000;   // ファイルが際限なく増えないための上限

function koma_calendar_valid_date(string $date): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** 日付の例外の一覧（日付 => 'off' | 'work'、日付順）。ファイルがない・壊れた値は無視する */
function koma_calendar_load(): array {
    $raw = json_read(KOMA_CALENDAR_PATH);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $date => $type) {
        if (is_string($date) && koma_calendar_valid_date($date) && in_array($type, KOMA_DAY_TYPES, true)) $out[$date] = $type;
    }
    ksort($out);
    return $out;
}

/**
 * 1日の種類を登録する。$type は 'off'（休み）/ 'work'（稼働）/ 'default'（例外を消して曜日の既定に戻す）。
 * 成功時は null、失敗時はエラーメッセージを返す。
 */
function koma_calendar_set(string $date, string $type): ?string {
    if (!koma_calendar_valid_date($date)) return '日付は YYYY-MM-DD で指定してください';
    if ($type !== 'default' && !in_array($type, KOMA_DAY_TYPES, true)) return "type は off / work / default のいずれかです";
    $cal = koma_calendar_load();
    if ($type === 'default') {
        unset($cal[$date]);
    } else {
        if (!isset($cal[$date]) && count($cal) >= KOMA_CALENDAR_MAX_ENTRIES) return '登録できる日数の上限に達しています';
        $cal[$date] = $type;
    }
    ksort($cal);
    return json_write(KOMA_CALENDAR_PATH, $cal) ? null : '保存に失敗しました';
}

/** その日が休みか。日付の例外 → 曜日の既定（$offDays: 0=日〜6=土）の順で判定する。$calendar は koma_calendar_load() の値 */
function koma_day_is_off(string $date, array $offDays, array $calendar): bool {
    if (isset($calendar[$date])) return $calendar[$date] === 'off';
    $dow = (int)(new DateTime($date, new DateTimeZone('Asia/Tokyo')))->format('w');
    return in_array($dow, $offDays, true);
}
