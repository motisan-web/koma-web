# Claude 用 API（`api/claude.php`）

Claude（スキル `~/.claude/skills/koma-web/SKILL.md`）がコマのデータを読み取り、記録の中身を直すための API。ブラウザのログイン・CSRF とは独立している。

## 認証
- `Authorization: Bearer <token>` だけで認証する。`auth_require_api()` は使わない。未発行・不一致・ヘッダーなしはすべて 401（`{"ok":false,"error":"unauthorized"}`）。
- トークンは `data/claude_token.json`（git 管理外。`.htaccess` で `/data/` は HTTP から遮断済み）に **SHA-256 のハッシュと発行日時だけ**を保存する。平文は保存しない。照合は `hash_equals`（`includes/claude_token.php`）。
- 有効なトークンは1つだけ。発行・再発行・失効は設定画面（`settings.php` の「Claude 連携」）で行う。再発行すると前のトークンは使えなくなる。平文は発行直後のレスポンスに1回だけ出る（`Cache-Control: no-store`）。発行・失効の POST は CSRF トークン（`auth_check_csrf`）を確認する。
- トークンはログ（`logs/`）にもレスポンスにも出さない。`.htaccess` の `SetEnvIf Authorization` で、FastCGI でも `Authorization` ヘッダーが PHP に届く。
- スキル側のトークンは `~/.claude/koma-web.env`（`KOMA_BASE` / `KOMA_TOKEN`）。リポジトリには書かない。

## 読み取り（GET `?action=`）
| action | パラメータ | 内容 |
|---|---|---|
| `day` | `date`（省略で今日） | コマ一覧（`slot` / `name` / `project_id` / `status` / `minutes` / `koma` / `silent` / `anomaly` / `started_at` / `completed_at`）と `koma` / `silent_koma` / `minutes` |
| `daily` | `from` / `to`（省略で今週の日曜〜今日。最大366日） | 日別のコマ数と合計 |
| `week` | `date`（その日を含む週。省略で今週） | 日曜〜土曜の日別コマ数、`total`、`goal` |
| `week_status` | なし | `koma_week_status()` + 今日の分（`koma_week_progress()`）。`done` / `rest` / `reached` / `target_until_yesterday` / `diff` / `pace` / `need_per_day` ほか |
| `calendar` | `from` / `to`（省略で今月。最大366日） | 日ごとの休み（`off`。日付の例外 → 曜日の既定）と `exception`（`off` / `work` / `null`＝曜日の既定のまま）。`off_days` は曜日の既定 |
| `stats` | `from` / `to`（省略で直近30日。最大366日）、`project` | `koma` / `silent_koma` / `minutes` / `count` / `active_days`、`by_project`（コマ数の多い順）、`by_day` |

- コマ数は `koma_value()`、分数は `koma_seconds()`（異常値は数えない）。トップ・統計と同じ規則。読み取りはデータを書き換えない（自動中止も走らせない。セッションファイルがなければ作らない）。
- `day` / `week` の各日には `off` と `off_exception` が付く。`week_status` には `today_off` / `off_week`（今週の稼働日が0）も付く。
- `week_status` の差・必要ペースは `koma_week_progress()`（`includes/koma_stats.php`）。トップの JS `weekStatus()` と同じ規則なので、変えるときは両方直す。

## 更新（POST JSON `{"action":"update_koma", ...}`）
- `date`（必須）・`slot`（1〜20）で既存のコマを指す。コマがなければ 404（新規作成はしない）。
- 更新できるのは `name`（200文字まで・trim）、`project_id`（`koma_project_normalize()`。100文字まで・`#project/` を補う・空文字で空）、`silent`（`無音` / `音楽` / `ブラウンノイズ`。既存の静音タグを外して作業内容の最後に ` @タグ` を付ける。空文字で外す。`koma_set_silent_tag()`）の3つだけ。
- 上記以外のフィールド（時間・ステータス・移動先のスロット/日付など）が1つでもあると 400。タイマー操作（開始・一時停止・完了・中止）、時間の編集、コマの新規作成・移動は API にない。
- 保存は `load_session()` / `save_session()`（ファイルロック付き）。`project_id` を変えたら `push_project_history()` で履歴にも入れる（`timer.php` の `update_meta` と同じ）。

## 休み・稼働日の登録（POST JSON `{"action":"set_day_type", "date":..., "type":...}`）
- `type` は `off`（休み）/ `work`（稼働）/ `default`（日付の例外を消して曜日の既定に戻す）。`data/calendar.json` に書く（`koma_calendar_set()`。人間用の `api/timer.php` の `set_day_type` と同じ関数）。
- `date` と `type` 以外のキーは 400。コマの更新（`update_koma`）とは別アクションで、コマのデータには触れない。
- 登録するとトップの週の状況（稼働日数・1日の目標・遅れ/進み）に反映される。
