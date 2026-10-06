<?php
/**
 * Hook dispatch — timer.php / cron_check.php / settings.php の共通処理
 */

require_once __DIR__ . '/logger.php';

/**
 * 設定された外部 URL へ hook を送る。
 * 返り値: ['skipped' => bool, 'code' => int, 'error' => string, 'body' => string]
 * $timeout はタイマー操作の応答を待たせすぎないよう、呼び出し側で短くできる。
 */
function dispatch_hook(string $event, array $payload, array $config, int $timeout = 5, int $connectTimeout = 3): array {
    $hookCfg = $config['hooks'][$event] ?? null;
    if (!$hookCfg || empty($hookCfg['enabled']) || empty($hookCfg['url'])) {
        return ['skipped' => true, 'code' => 0, 'error' => '', 'body' => ''];
    }

    $url    = $hookCfg['url'];
    $method = strtoupper($hookCfg['method'] ?? 'GET');
    $fullPayload = array_merge($payload, [
        'event'     => $event,
        'user_id'   => $payload['user_id'] ?? 'moti',
        'timestamp' => (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('c'),
    ]);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ];
    if ($method === 'POST') {
        $body = json_encode($fullPayload, JSON_UNESCAPED_UNICODE);
        $opts += [
            CURLOPT_URL        => $url,
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Content-Length: ' . strlen($body)],
        ];
    } else {
        $sep = str_contains($url, '?') ? '&' : '?';
        $opts[CURLOPT_URL] = $url . $sep . http_build_query($fullPayload);
    }

    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error) {
        $error = $error ?: 'curl returned false';
        koma_error('hook dispatch failed', ['event' => $event, 'url' => $url, 'error' => $error]);
    } else {
        koma_info('hook dispatched', ['event' => $event, 'url' => $url, 'method' => $method, 'response_code' => $code]);
    }

    return ['skipped' => false, 'code' => $code, 'error' => $error, 'body' => is_string($response) ? $response : ''];
}

/**
 * コマ1つにつき1回だけ送る hook（koma_80min / koma_100min）。
 * ブラウザ（notify_*）と cron の両方から呼ばれるので、送ったことをコマの hooks_fired に記録して二重送信を防ぐ。
 * $k を書き換えるので、呼び出し側で保存すること。送った場合は true。
 */
function dispatch_koma_hook_once(array &$k, string $event, array $payload, array $config, int $timeout = 5, int $connectTimeout = 3): bool {
    if (!empty($k['hooks_fired'][$event])) return false;
    $k['hooks_fired'][$event] = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('c');
    dispatch_hook($event, $payload, $config, $timeout, $connectTimeout);
    return true;
}
