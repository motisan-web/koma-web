# 実装上の約束事・既知の仕様

コードを変える作業の前に読む。

## API（`api/timer.php`）
- 全アクションは `date` パラメータを受け付ける（省略時は今日）。前日コマ操作時は JS が `date` を付けてリクエストする。
- スロット上限は `SLOT_MAX = 20`（config.json の `koma_count` とは別）。
- `auto_close_old_komas()` は `get_state` 呼び出し時に毎回走る（2日以上前のみ対象、軽量）。
- `set_theme` / `get_state` は slot バリデーション前に処理する（slot 不要なアクション）。
- `reset` アクションは `segments` が空のコマ専用。開始済みコマには適用不可。
- `round_to_100min` アクションは完了済み + 100分超過コマのみ対象。
- `set_slot_count` は JS から `CFG.today` を受け取る（深夜をまたいだとき翌日に書き込まないため）。

## Hook
- 送信は `includes/hook.php` の `dispatch_hook()` に一本化。timer.php は応答を待たせないようタイムアウト3秒（接続2秒）で直接呼ぶ。設定画面のテスト送信は「有効」の有無に関係なく送る。
- `koma_80min` / `koma_100min` はコマ1つにつき1回だけ。`dispatch_koma_hook_once()` が送信済みをコマの `hooks_fired`（イベント名 → 送信時刻）に記録し、ブラウザの `notify_*` と cron のどちらが先でも二重に送らない。hook が無効でも「送信済み」として記録される。

## データ
- コマのステータス全種: `idle` / `running` / `paused` / `overtime` / `completed` / `closed` / `auto_closed`
  - `overtime_max` は廃止（既存データの互換性のためステータス定義は残る）。
  - 100分自動完了は廃止済み。100分経過時は `koma_100min` hook の発火のみ（cron_check.php も状態を変えない）。
- `closed`・`auto_closed` は統計・マークダウン出力でも「完了扱い」（チェックボックス `[x]`）。
- `break_notify` hook はコマ完了時に即発火（「10分後に叩く」遅延は hook 受け取り側で実装する想定）。
- project_id 履歴は datalist 補完のみ（サーバーサイド fetch 補完なし）。
- JSON エンコードは `JSON_UNESCAPED_UNICODE` で統一。

## フロントエンド（`assets/js/timer.js`）
- `CFG.serverNow`: PHP がレンダリング時のサーバー時刻（ms）を出力。`_clockOffset = CFG.serverNow - Date.now()` でブラウザとのズレを補正し `liveElapsed` に使う。
- `prevKey(date, slot)` は `"YYYYMMDD s スロット番号"` 形式（例: `"20260418s7"`）— CSS セレクター用。
- `CFG.komaCount` はページロード時に PHP 側の `$renderSlotCount` で初期化。動的追加のたびに JS で更新される。
- 前日コマエリアは JS で動的生成（PHP は `prevIncomplete` 配列を CFG に渡すだけ）。前日未完了コマの `total_seconds` は PHP で再計算して渡す。
- 「リセット」ボタン（`btn-reset-{slot}`）は `completed` + `segments.length === 0` のときのみ表示。
- 「100分に丸める」ボタン（`btn-round-{slot}`）は `done && elapsed > maxDurationSec` のときのみ表示。
- 履歴エリアは過去14日・最大40件の完了コマを表示。「コピー」は、未開始かつ作業内容・プロジェクトが空（保存前の入力欄も含む）の先頭スロットに転記する。入力済みの未開始コマは上書きしない。

## テーマ
- `data/config.json` の `theme` フィールドに保存（`"dark"` / `"light"`）。
- `api/timer.php` の `set_theme` アクションで保存（slot バリデーション前に配置）。
- 全ページの `<html data-theme="...">` を PHP が config から出力。
