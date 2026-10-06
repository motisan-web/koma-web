# リポジトリ・ディレクトリを koma-web に改名

ID: T-000（改名作業。backlog 外）

- GitHub リポジトリ `motisan-web/mp-koma-timer` → `motisan-web/koma-web`（ユーザーの指示）。旧 URL は GitHub が転送する。Actions の Secrets はリポジトリに付いたまま引き継がれる。
- ローカルのディレクトリ `mp-koma-timer.local` → `koma-web.local`、仮想ホスト `mp-koma-timer.local` → `koma-web.local`。
  同じフォルダを指していた `git7.local` の仮想ホストも、パスだけ新しいフォルダに直した。
- このプロジェクトの project id とプロジェクトラベルは `koma-web`。CLAUDE.md に記載した。
- 過去の change ログ内の旧名は当時の記録として残す。
