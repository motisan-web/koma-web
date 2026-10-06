# 実装上の約束事・既知の仕様

コードを変える作業の前に読む。

## API（`api/timer.php`）
- 全アクションは `date` パラメータを受け付ける（省略時は今日）。前日コマ操作時は JS が `date` を付けてリクエストする。
- スロット上限は `SLOT_MAX = 20`（config.json の `koma_count` とは別）。
- 2日以上前の作業中・一時停止中のコマは `koma_auto_close_stale()`（`includes/koma_stats.php`）が自動中止（`auto_closed`）にする。全期間が対象で、トップ・統計の表示時と `get_state` で走る。開いたままの区間は今の時刻で閉じるので、実行中のまま放置したコマは異常値になり、統計の日別表示で時間を直す。
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
- 1000分以上のコマは異常値（完了扱いは `total_seconds`、作業中・一時停止中は今までの経過で判定）（`includes/koma_stats.php` の `KOMA_ANOMALY_MINUTES`）。統計画面の全タブ上部に全期間の一覧を出し、日別表示で行を強調する。集計ルールは `includes/koma_stats.php` に集める。
- コマ数の換算は `koma_value()`: 分数 ÷ 80。完了扱いで20分超〜80分未満は80分扱い、20分以下は実分数。作業中・一時停止中は segments から今の時刻までの実分数。0分と異常値は0。JS（`timer.js` の `komaValue()`）にも同じ規則があるので、変えるときは両方直す。
- `koma_daily_all()` は全セッションを読んで日別のコマ数とプロジェクト別のコマ数を返す（トップ表示のたびに走る）。
- `KOMA_DAY_TIERS`（6 フルデイ / 8 オーバードライブ / 10 ハイパードライブ / 12 リミットブレイク）が1日のコマ数による実績の段階。カレンダーの色の段階（0 / 〜3 / 〜6 / 6+ / 8+ / 10+）もこれにそろえる。
- `koma_streak($daily, $today, $min)` は $min コマ以上の日の連続。今日がまだ満たしていなければ昨日までを数える。トップの「連続」は1コマ以上、「フルデイ連続」は6コマ以上。
- `break_notify` hook はコマ完了時に即発火（「10分後に叩く」遅延は hook 受け取り側で実装する想定）。
- project_id 履歴は datalist 補完のみ（サーバーサイド fetch 補完なし）。
- JSON エンコードは `JSON_UNESCAPED_UNICODE` で統一。

## 実績（`includes/achievements.php`）
- `achievements_build($daily, $today)` が全期間を判定する。同じ実績は何度でも獲得でき、獲得1回を「イベント」として id を振る（例: `full:2026-04-29`、`week30:<週の日曜>`、`month120:2026-04`、`total:3`、`proj:<project_id>:2`、`streak7:<届いた日>`）。id の形を変えると開封済みの記録とずれるので変えない。
- 種類: 今日（6 / 8 / 10 / 12コマ）、期間（ウィークリー30・マンスリー120・皆勤＝日〜土の7日すべて1コマ以上）、累計（100コマごと・100時間ごと・職人＝同じプロジェクトで50コマごと）、連続（1コマ以上の日が7日・30日続くたび、フルデイが3日続くたび）。累計時間はコマ数 × 80分で数える。
- 職人は直近7日にコマ数のあるプロジェクトだけ一覧・進捗に出す（獲得の判定と宝箱には隠れたものも含む）。
- 宝箱: 開封したイベントの id だけを `data/users/<id>_achievements.json`（git 管理外）に保存する。ファイルがない＝一度も開けていないときは、過去分をまとめた大きな宝箱1つとして出す。`open_achievements` アクションはサーバー側で判定し直した未開封分をすべて開封済みにする（クライアントから id は受け取らない）。
- 実績の進捗はページ表示時の値を `CFG.homeStats.progress` で渡し、JS が「今日のコマ数の増分 × `live`」を足して毎秒並べ直す。今日の実績（6 / 8 / 10 / 12）の進捗は JS が `dayTiers` から作る。

## 統計（`stats.php` / `assets/js/stats.js`）
- タブ: 日別（PHP で描く。事後編集・マークダウン出力あり）/ 期間 / プロジェクト / 時間帯 / 集中度 / 記録。日別以外は PHP が `koma_stats_records()` で全コマを1コマ1行（`d s p n st m v a seg`）にして `window.STATS` に渡し、`stats.js` が集計してグラフを描く。データが増えて重くなったら、ここを日別の集計に置き換える。
- URL: `?tab=period&unit=week|month&offset=N`（N 期間前）、`?tab=project&p=<project_id>` で単体プロジェクト。旧 URL の `?tab=week` / `?tab=month` は「期間」に寄せる。
- コマ数はトップと同じ `koma_value()`（`v`）。分数を使う集計（長さ・時間帯・作業時間）は異常値を除き、長さと時間帯は作業中のコマも除く。
- 週は日曜始まり。進行中の期間の「前の期間比」は、前の期間も同じ日数までで比べる。
- `seg` はその日の0時からの分数。0時をまたいだ分は時間帯のヒートマップで翌日に入れる。4時より前に始めたコマは前の晩の続きとみなし「始業」に数えない。
- プロジェクトの色は全期間の上位5件に固定で割り当て（表示中の順位で塗り直さない）、ほかは「その他」の灰色。配色はダーク・ライトそれぞれ色覚多様性の検証を通したもの（`--p1`〜`--p5`）。量の色は緑の単色ランプ（`--seq0`〜`--seq5`）。
- 日別のサマリーは「コマ数」（`koma_value` の合計）と「完了したコマ」（個数）を並べる。

## お知らせ（トップのサイドバー）
- 常に出す: 0時までの残り時間とコマ数（80分換算）、次の今日の実績まで（締め切り: 今日 0:00）、ウィークリー30まで（締め切り: 土曜 24:00）。JS の `renderInfo()` が今日のコマ数の変化と30秒ごとの時計で更新する。
- 異常値があるときだけ件数と先頭3件を出し、統計の日別表示（`stats.php?tab=day&date=...#koma-row-N`）へリンクする。
- 未開封の実績があるときだけ宝箱を最後に出す。
- 既存の `.notice`（統計・設定の注意書き）とクラス名がぶつかるので、お知らせの行は `.info-row` を使う。

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

## フッター
- `includes/footer.php` にプロジェクトラベル `pj:koma-web` を出す（サイト側でどのプロジェクトに紐づくか一目でわかるようにするため）。プロジェクト ID を変えたらここも直す。埋め込み（embed.php）では出ない。

## テーマ
- `data/config.json` の `theme` フィールドに保存（`"dark"` / `"light"`）。
- `api/timer.php` の `set_theme` アクションで保存（slot バリデーション前に配置）。
- 全ページの `<html data-theme="...">` を PHP が config から出力。
