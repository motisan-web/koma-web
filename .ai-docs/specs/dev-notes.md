# 実装上の約束事・既知の仕様

コードを変える作業の前に読む。

## API（`api/timer.php`）
- 全アクションは `date` パラメータを受け付ける（省略時は今日）。前日コマ操作時は JS が `date` を付けてリクエストする。
- スロット上限は `SLOT_MAX = 20`（config.json の `koma_count` とは別）。
- `auto_close_old_komas()` は `get_state` 呼び出し時に毎回走る（2日以上前のみ対象、軽量）。
- `set_theme` / `get_state` は slot バリデーション前に処理する（slot 不要なアクション）。
- `reset` アクションは `segments` が空のコマ専用。開始済みコマには適用不可。
- `round_to_100min` アクションは完了済み + 100分超過コマのみ対象。
- `edit_koma` アクション（統計の日別表示から呼ぶ）は完了扱いのコマ（completed / closed / auto_closed）のみ対象。時間は合計分数（0〜999の整数）で受け取り、`total_seconds` と `overtime_seconds` を書き換える。segments は変えない。最初の編集時だけ元の値を `original_total_seconds` に残し、`edited_at` を記録する。
- `set_slot_count` は JS から `CFG.today` を受け取る（深夜をまたいだとき翌日に書き込まないため）。

## Hook
- 送信は `includes/hook.php` の `dispatch_hook()` に一本化。timer.php は応答を待たせないようタイムアウト3秒（接続2秒）で直接呼ぶ。設定画面のテスト送信は「有効」の有無に関係なく送る。
- `koma_80min` / `koma_100min` はコマ1つにつき1回だけ。`dispatch_koma_hook_once()` が送信済みをコマの `hooks_fired`（イベント名 → 送信時刻）に記録し、ブラウザの `notify_*` と cron のどちらが先でも二重に送らない。hook が無効でも「送信済み」として記録される。

## データ
- コマのステータス全種: `idle` / `running` / `paused` / `overtime` / `completed` / `closed` / `auto_closed`
  - `overtime_max` は廃止（既存データの互換性のためステータス定義は残る）。
  - 100分自動完了は廃止済み。100分経過時は `koma_100min` hook の発火のみ（cron_check.php も状態を変えない）。
- `closed`・`auto_closed` は統計・マークダウン出力でも「完了扱い」（チェックボックス `[x]`）。
- 完了扱いで `total_seconds` が1000分以上のコマは異常値（`includes/koma_stats.php` の `KOMA_ANOMALY_MINUTES`）。統計画面の全タブ上部に全期間の一覧を出し、日別表示で行を強調する。集計ルールは `includes/koma_stats.php` に集める。
- コマ数の換算は `koma_value()`: 分数 ÷ 80。完了扱いで20分超〜80分未満は80分扱い、20分以下は実分数。作業中・一時停止中は segments から今の時刻までの実分数。0分と異常値は0。JS（`timer.js` の `komaValue()`）にも同じ規則があるので、変えるときは両方直す。
- `koma_daily_all()` は全セッションを読んで日別のコマ数とプロジェクト別のコマ数を返す（トップ表示のたびに走る）。
- `KOMA_DAY_TIERS`（6 フルデイ / 8 オーバードライブ / 10 ハイパードライブ / 12 リミットブレイク）が1日のコマ数による実績の段階。カレンダーの色の段階（0 / 〜3 / 〜6 / 6+ / 8+ / 10+）もこれにそろえる。
- `koma_streak($daily, $today, $min)` は $min コマ以上の日の連続。今日がまだ満たしていなければ昨日までを数える。トップの「連続」は1コマ以上、「フルデイ連続」は6コマ以上。
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
- 履歴エリアは過去14日・最大40件の完了コマを表示。「コピー」は、未実行かつ作業内容・プロジェクトが空（保存前の入力欄も含む）の先頭スロットに転記する。入力済みの未実行コマは上書きしない。
- トップ（index.php、埋め込み以外）の Lv は累計コマ数 ÷ 6 の切り捨て。PHP は今日以外の累計（`homeStats.pastTotal`）と連続記録・カレンダーの日別値を `CFG.homeStats` で渡し、今日の分は JS が `komaState` から毎秒計算して見出し「M/DD（N.Nコマ）」・Lv・累計を更新する（`updateSummary()`）。前日の未完了コマは今日の分に含めない。
- カレンダーは折りたたみ（`.fold`、初期は閉じる）で、初めて開いたときに描く。履歴も同じ `.fold` の仕組みで開閉する。
- ツールチップは `data-tip` 属性に入れた HTML を出す（`initTooltip()`）。innerHTML で入るので、利用者の入力を入れるときは必ずエスケープする。

## テーマ
- `data/config.json` の `theme` フィールドに保存（`"dark"` / `"light"`）。
- `api/timer.php` の `set_theme` アクションで保存（slot バリデーション前に配置）。
- 全ページの `<html data-theme="...">` を PHP が config から出力。
