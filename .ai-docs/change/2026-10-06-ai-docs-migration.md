# ドキュメント管理を .ai-docs 形式に移行

ID: T-000（移行作業。backlog 外）

## 判断
- `.claude-codex/` を `git mv` で `.ai-docs/` に移した。旧 change ログはファイル名・本文とも変えていない（本文中の `.claude-codex` 表記も当時の記録として残す）。
- 旧 CURRENT.md の「リリース内容・追加修正」の表は捨てた（git と change/ にある）。「注意事項・既知の仕様」は `specs/dev-notes.md`、インフラは `specs/operations.md` へ。
- ルートの `koma_web_design.md`（将来構想）は今の動作を表さないので `archive/` へ。
- CLAUDE.md の食い違いを直した: 仮想ホスト名（git7.local → mp-koma-timer.local）、Hook 表の「100分自動完了」（I-004 で廃止済み）。
- 移行中に `api/cron_check.php` が自動完了を残していることに気づき、I-012 として登録。T-001（cron 設定）は保留にした。
- feat 用の `F-` ID は新形式にないので、今後の新機能は todo（`T-`）で登録する。
