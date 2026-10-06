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
