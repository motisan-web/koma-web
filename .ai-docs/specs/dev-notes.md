# 実装上の約束事・既知の仕様

コードを変える作業の前に読む。

## API（`api/timer.php`）
- 全アクションは `date` パラメータを受け付ける（省略時は今日）。前日コマ操作時は JS が `date` を付けてリクエストする。
- スロット上限は `SLOT_MAX = 20`（config.json の `koma_count` とは別）。
- 2日以上前の作業中・一時停止中のコマは `koma_auto_close_stale()`（`includes/koma_stats.php`）が自動中止（`auto_closed`）にする。全期間が対象で、トップ・統計の表示時と `get_state` で走る。開いたままの区間は今の時刻で閉じるので、実行中のまま放置したコマは異常値になり、統計の日別表示で時間を直す。
- `set_theme` / `get_state` は slot バリデーション前に処理する（slot 不要なアクション）。
- `reset` アクションは `segments` が空のコマ専用。開始済みコマには適用不可。
- `round_to_100min` アクションは完了済み + 100分超過コマのみ対象。
- `edit_koma` アクション（統計の日別表示から呼ぶ）は終わったコマ（completed / closed / auto_closed）のみ対象。時間は合計分数（0〜999の整数）で受け取り、`total_seconds` と `overtime_seconds` を書き換える。segments は変えない。最初の編集時だけ元の値を `original_total_seconds` に残し、`edited_at` を記録する。
- `set_slot_count` は JS から `CFG.today` を受け取る（深夜をまたいだとき翌日に書き込まないため）。

## Claude 用 API
- `api/claude.php`（Bearer トークン認証。読み取りと、コマの作業内容・プロジェクト・静音タグの修正だけ）の仕様は `specs/claude-api.md`。プロジェクトIDの正規化は `koma_project_normalize()` を通す。

## Hook
- 送信は `includes/hook.php` の `dispatch_hook()` に一本化。timer.php は応答を待たせないようタイムアウト3秒（接続2秒）で直接呼ぶ。設定画面のテスト送信は「有効」の有無に関係なく送る。
- `koma_80min` / `koma_100min` はコマ1つにつき1回だけ。`dispatch_koma_hook_once()` が送信済みをコマの `hooks_fired`（イベント名 → 送信時刻）に記録し、ブラウザの `notify_*` と cron のどちらが先でも二重に送らない。hook が無効でも「送信済み」として記録される。

## データ
- コマのステータス全種: `idle` / `running` / `paused` / `overtime` / `completed` / `closed` / `auto_closed`
  - `overtime_max` は廃止（既存データの互換性のためステータス定義は残る）。
  - 100分自動完了は廃止済み。100分経過時は `koma_100min` hook の発火のみ（cron_check.php も状態を変えない）。
- 「終わったコマ」（`koma_is_ended()`: completed / overtime_max / closed / auto_closed）は経過時間を `total_seconds` で確定させる。「完了したコマ」（`koma_is_completed()`: completed / overtime_max）だけが `[x]`・80分への切り上げ・統計の「完了したコマ」個数の対象。中止（`closed` / `auto_closed`）は実分数を数えるだけで、完了扱いにしない（#I-017）。JS は `isDone()`（終わった）と `isCompleted()`（完了）に分けてある。
- 1000分以上のコマは異常値（完了扱いは `total_seconds`、作業中・一時停止中は今までの経過で判定）（`includes/koma_stats.php` の `KOMA_ANOMALY_MINUTES`）。統計画面の全タブ上部に全期間の一覧を出し、日別表示で行を強調する。集計ルールは `includes/koma_stats.php` に集める。
- コマ数の換算は `koma_value()`: 分数 ÷ 80。完了（completed / overtime_max）で20分超〜80分未満は80分扱い、20分以下は実分数。中止（closed / auto_closed）は切り上げず常に実分数（`total_seconds`）。作業中・一時停止中は segments から今の時刻までの実分数。0分と異常値は0。JS（`timer.js` の `komaValue()`）にも同じ規則があるので、変えるときは両方直す。
- `koma_daily_all()` は全セッションを読んで日別のコマ数とプロジェクト別のコマ数を返す（トップ表示のたびに走る）。
- `KOMA_DAY_TIERS`（6 フルデイ / 8 オーバードライブ / 10 ハイパードライブ / 12 リミットブレイク）が1日のコマ数による実績の段階。カレンダーの色の段階（0 / 〜3 / 〜6 / 6+ / 8+ / 10+）もこれにそろえる。
- `koma_streak($daily, $today, $min)` は $min コマ以上の日の連続。今日がまだ満たしていなければ昨日までを数える。トップの「連続」は1コマ以上、「フルデイ連続」は6コマ以上。
- `break_notify` hook はコマ完了時に即発火（「10分後に叩く」遅延は hook 受け取り側で実装する想定）。
- project_id 履歴は datalist 補完のみ（サーバーサイド fetch 補完なし）。履歴は保存値（`#project/xxx`）のまま持ち、datalist の候補には接頭辞を除いた続きだけを出す。
- プロジェクトID（#T-012）: 保存値は `#project/xxx` か空。入力欄（トップのコマカード・前日コマ・動的追加コマ・統計の事後編集）は `#project/` を固定表示の接頭辞ラベルにして続きだけ入れる。正規化は `koma_project_normalize()`（`includes/koma_stats.php`）の1つで、空・`#project/` だけは空、`#project/` で始まらなければ補う、重なっていれば1つにする、100文字まで。`update_meta` / `edit_koma` はこれを通して保存する。JS 側は `KomaProject.full()` / `suffix()`（`assets/js/project-id.js`。stats.php でも読み込む）で同じ規則を持つので、変えるときは両方直す。入力・貼り付けで `#project/` 付きが来たら `project-id.js` の capture 付き input リスナーが入力欄から外す。履歴の表示・統計・マークダウン出力は保存値のまま。
- JSON エンコードは `JSON_UNESCAPED_UNICODE` で統一。

## 実績（`includes/achievements.php`）
- `achievements_build($daily, $today)` が全期間を判定する。同じ実績は何度でも獲得でき、獲得1回を「イベント」として id を振る（例: `full:2026-04-29`、`week30:<週の日曜>`、`month120:2026-04`、`total:3`、`proj:<project_id>:2`、`streak7:<届いた日>`）。id の形を変えると開封済みの記録とずれるので変えない。
- 種類: 今日（6 / 8 / 10 / 12コマ）、期間（ウィークリー30・マンスリー120・皆勤＝日〜土の7日すべて1コマ以上）、累計（100コマごと・100時間ごと・職人＝同じプロジェクトで50コマごと）、連続（1コマ以上の日が7日・30日続くたび、フルデイが3日続くたび）。累計時間はコマ数 × 80分で数える。
- 職人は直近7日にコマ数のあるプロジェクトだけ一覧・進捗に出す（獲得の判定と宝箱には隠れたものも含む）。
- 宝箱: 開封したイベントの id だけを `data/users/<id>_achievements.json`（git 管理外）に保存する。ファイルがない＝一度も開けていないときは、過去分をまとめた大きな宝箱1つとして出す。`open_achievements` アクションはサーバー側で判定し直した未開封分をすべて開封済みにする（クライアントから id は受け取らない）。
- 実績の進捗はページ表示時の値を `CFG.homeStats.progress` で渡し、JS が「今日のコマ数の増分 × `live`」を足して毎秒並べ直す。今日の実績（6 / 8 / 10 / 12）の進捗は JS が `dayTiers` から作る。

## マークダウン出力（`api/output.php`）
- 統計の日別表示と、トップのサイドバー「コマデータをコピー」（今日・昨日・一昨日。`timer.js` の `initCopyMarkdown()`）が同じ API を使う。
- 最終行: `合計作業時間 N分(YYYY/n/j) コマ数 X.X silentコマ Y.Y`。コマ数は `koma_value()` の合計、silentコマは作業内容に `KOMA_SILENT_TAGS`（`@無音` `@音楽` `@ブラウンノイズ`）を含むコマの `koma_value()` 合計（`koma_is_silent()`）。定義の出どころは Obsidian の `08-claude/frontmatter-daily.md`。
- http の仮想ホストでは `navigator.clipboard` が使えないので、`copyText()` が `execCommand('copy')` に切り替える。

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

## 週の状況（トップのサイドバー先頭。#T-011）
- 週は日曜始まり。目標コマ数（デフォルト30）と休みの曜日（デフォルト木・金）を `settings.php` で変える。設定キーは `data/config.json` の `week_goal`（整数 1〜200）と `week_off_days`（0=日〜6=土の整数の配列。7日すべて休みは不可）。キーがなければデフォルトで動く。範囲外・不正値は `koma_week_goal()` / `koma_week_off_days()` でデフォルトに戻る（設定画面は保存せずエラー表示）。
- 稼働日・休みは、その週（日曜〜土曜）の各日について「日付の例外（`data/calendar.json`。#T-014）→ 曜日の既定（`week_off_days`）」の順で決める。1日の目標 = 週の目標 ÷ その週の稼働日数（30 ÷ 5 = 6）。その週に稼働日が0なら1日の目標は0（ゼロ除算しない）で、パネルは「今週は休み」を出す（必要ペースは出さない）。次週以降の例外は今週の表示に影響しない（今週の日付だけを見る）。過去の週も日付の例外をそのまま使う。
- 「今週 X / 目標（あと Y）」: X は日曜〜今日のコマ数の合計（`koma_daily_all()` / `koma_value()` と同じ数え方）。Y = max(0, 目標 − X)。到達したら「達成」。
- ペース: 昨日までの目標累計 = 1日の目標 × 日曜〜昨日の稼働日数。差 = X − 目標累計。正なら「進み」、負なら「遅れ」、小数1桁で0なら「ちょうど」。休みの日（例外で休みにした日を含む）に作業した分は目標累計に足さず X にだけ入る（前倒し）。昨日までの稼働日数・残り稼働日数も、日付の例外込みで数える。
- 未達のときは「残り稼働日（今日が稼働日なら今日を含む。休みなら含めない）で1日あたり (目標 − X) ÷ 残り稼働日 コマ必要」を出す。残り稼働日が0なら「今週の稼働日は終わりました」。
- PHP と JS の分担: `koma_week_status()`（`includes/koma_stats.php`。日付の例外は `koma_calendar_load()` から読む）が今日を除く今週の合計（`pastWeekTotal`）・その週の稼働日数（`workDays`）・昨日までの稼働日数（`elapsedWorkDays`）・残り稼働日数（`remainWorkDays`）・今日が休みか（`todayOff`）・1日の目標（`perDay`）などを `CFG.homeStats.week` で渡す。JS は休みの判定を持たず、これらの値だけを使う。JS の `renderWeek()` / `weekStatus()`（`timer.js`）が今日の分（`todayKoma()`）を足して `updateSummary()` から毎秒描き直す。前日の未完了コマは今日の分に含めない。**差・必要ペースの規則は PHP と JS（`weekStatus()`）の両方にあるので、変えるときは両方直す。**
- お知らせの「ウィークリー30まで」（`info-week`。実績の判定。目標は30固定・締め切り土曜）とは別物。週の目標を設定で変えても、ウィークリー30の実績条件は変わらない。表示は `.week-stat*` クラス（`.info-row` はお知らせ専用）。
## カレンダー（休み・稼働日の登録。#T-014）
- 日付単位の例外は `data/calendar.json`（git 管理外・HTTP 遮断済み）。形式 `{"2026-10-15": "work", "2026-10-17": "off"}`。値は `off`（休み）/ `work`（稼働）で、曜日の既定（`week_off_days`）より日付の値が優先する。例外を消す＝キーを消す（既定に戻る）。読み書きは `includes/calendar.php`（`koma_calendar_load()` / `koma_calendar_set()` / `koma_day_is_off()`）。登録できる件数は5000まで。
- 人間の入力は専用ページ `calendar.php`（ヘッダーのナビ「カレンダー」。トップのカレンダーとは別）。月表示で日付をクリックすると 既定 → 休み → 稼働 → 既定 と切り替わり、`api/timer.php` の `set_day_type`（`date` / `type`: `off` / `work` / `default`。slot 不要でログイン認証・既存の流儀）で保存する。見た目は、稼働（既定）= 無地、休み（曜日の既定）= 灰色・点線、休み（日付で登録）= 赤、稼働（日付で登録）= 緑。ラベル文字も付けて色だけに頼らない。
- Claude からは `api/claude.php` の `calendar`（取得）/ `set_day_type`（登録・解除）。仕様は `specs/claude-api.md`。

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
- 0時を過ぎたら（日本時間の日付が `CFG.today` と変わったら）トップを再読み込みする（`initDayChange()`）。30秒ごと・タブに戻ったとき・入力欄を離れたときに確かめ、入力中は待つ。実行中のコマは前日のセッションに残り、再読み込み後は「前日の未完了コマ」に出る。
- ツールチップは `data-tip` 属性に入れた HTML を出す（`initTooltip()`）。innerHTML で入るので、利用者の入力を入れるときは必ずエスケープする。

## フッター
- `includes/footer.php` にプロジェクトラベル `pj:koma-web` を出す（サイト側でどのプロジェクトに紐づくか一目でわかるようにするため）。プロジェクト ID を変えたらここも直す。埋め込み（embed.php）では出ない。

## テーマ
- `data/config.json` の `theme` フィールドに保存（`"dark"` / `"light"`）。
- `api/timer.php` の `set_theme` アクションで保存（slot バリデーション前に配置）。
- 全ページの `<html data-theme="...">` を PHP が config から出力。
