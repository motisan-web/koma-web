<?php
/**
 * App configuration loader
 */

require_once __DIR__ . '/data.php';

define('CONFIG_PATH', __DIR__ . '/../data/config.json');

function load_config(): array {
    $defaults = [
        'koma_count'              => 6,
        'koma_duration_minutes'   => 80,
        'max_duration_minutes'    => 100,
        'theme'                   => 'dark',
        'week_goal'              => 30,        // 週の目標コマ数（#T-011。1〜200）
        'week_off_days'          => [4, 5],    // 休みの曜日 0=日〜6=土（#T-011。既定は木・金）
        'hooks' => [
            'koma_start'    => ['url' => '', 'method' => 'GET', 'enabled' => false],
            'koma_complete' => ['url' => '', 'method' => 'GET', 'enabled' => false],
            'koma_80min'    => ['url' => '', 'method' => 'GET', 'enabled' => false],
            'koma_100min'   => ['url' => '', 'method' => 'GET', 'enabled' => false],
            'break_notify'  => ['url' => '', 'method' => 'GET', 'enabled' => false],
        ],
    ];

    $stored = json_read(CONFIG_PATH);
    if ($stored === null) {
        json_write(CONFIG_PATH, $defaults);
        return $defaults;
    }
    // Merge to ensure all keys exist
    $merged = array_replace_recursive($defaults, $stored);
    // 配列は添字ごとに混ざってしまう（[4,5] と [3] が [3,5] になる）ので、保存値で置き換える
    if (isset($stored['week_off_days']) && is_array($stored['week_off_days'])) {
        $merged['week_off_days'] = $stored['week_off_days'];
    }
    return $merged;
}

function save_config(array $config): bool {
    return json_write(CONFIG_PATH, $config);
}
