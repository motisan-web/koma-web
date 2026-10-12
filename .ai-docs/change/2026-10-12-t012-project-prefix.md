# T-012 プロジェクト入力の `#project/` を省略できるようにする

ID: T-012
日付: 2026-10-12
バージョン: 1.3.0

## 判断・意図
- すべてのプロジェクトが `#project/` 始まりなので、入力欄では接頭辞を固定表示し、続きだけ入力する。
- 保存値は従来どおり `#project/xxx`。履歴・統計・マークダウン出力・hook は保存値のまま変わらない。既存データの変換は不要。
- 空欄は空のまま保存する（`#project/` だけの保存はしない）。
- 二重にしない規則: 先頭の `#project/` を（重なっていても）すべて外してから1つだけ付ける。PHP（`koma_project_normalize()`）に正規化を1つにまとめ、`update_meta` と `edit_koma` の両方から呼ぶ。JS は `KomaProject`（`assets/js/project-id.js`）に1つにまとめ、トップと統計で共有する。
- 入力欄に `#project/` 付きが貼られたら、document の capture 付き input リスナーが入力欄から接頭辞を外す（各入力欄の保存処理より先に値が直る）。個別の入力欄ごとにバインドせず、動的追加コマ・前日コマにも自動で効く。
- datalist の候補は接頭辞を除いた続きだけにした（入力欄が続きだけなので、選んだ値がそのまま入る）。履歴のデータ自体は保存値のまま（`data/users/*.json` は変更なし）。
- 履歴テーブルの「プロジェクト」列（保存値を表示）と、その「コピー」は、保存値を入力欄の続きに変換して転記する。

## 影響
- 変更: `api/timer.php`、`includes/koma_stats.php`、`index.php`、`stats.php`、`assets/js/timer.js`、`assets/js/project-id.js`（新規）、`assets/css/timer.css`（`.project-field*`）。
- 長さ制限は従来どおり100文字（接頭辞込みで切る）。
