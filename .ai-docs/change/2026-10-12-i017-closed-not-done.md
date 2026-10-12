# I-017 中止したコマを完了扱いにしない

ID: I-017
日付: 2026-10-12
バージョン: 1.1.1

## 判断・意図
- 中止（`closed` / `auto_closed`）は「完了」ではなく、経過した実分数を数えるだけにする。
- 「終わった（経過時間が `total_seconds` で確定している）」と「完了した」を分けた。
  - PHP: `koma_is_done()` を `koma_is_ended()`（completed / overtime_max / closed / auto_closed）に改名し、`koma_is_completed()`（completed / overtime_max）を追加。
  - JS: `isDone()`（終わった）は据え置き、`isCompleted()`（完了）を追加。
- 変えたのは「完了」の意味の箇所だけ: `koma_value()` / `komaValue()` の80分への切り上げ、`api/output.php` の `[x]`、`stats.php` の「完了したコマ」個数。
- 経過時間は中止も `total_seconds`。異常値判定（1000分以上は0）も変更なし。前日のコマは前日に結び付いたまま。

## 現状維持にしたもの
- 状態表示・ボタン制御（`isDone`）、中止コマの事後編集（`edit_koma`）、`round_to_100min` と reset の条件、履歴エリアのコピー対象（中止も含む）、統計の「中止したコマ」割合。
- `round_to_100min` は api/timer.php 側で completed / closed / auto_closed を対象にしているまま。

## 影響
- 中止の20分超〜80分未満のコマ数が下がる（例: 50分 1.0 → 0.625）。実績・トップのLv・カレンダー・統計はすべて `koma_value()` 経由で自動的にそろう。
- 既存データの書き換えなし。
