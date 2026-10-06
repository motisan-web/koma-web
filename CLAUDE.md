# コマタイマー

## 目的
80分を1コマとした作業単位でタスクを計測・記録するWebタイマーツール。
1日6コマ（8時間）を管理し、統計・hook通知・マークダウン出力ができる。

## 環境
- **PHP 8.2** / XAMPP（仮想ホスト `mp-koma-timer.local`）、本番: Xserver（`main` への push で FTP デプロイ）
- **SQL不使用** — JSONファイルで全データ管理
- **ユーザー**: motiハードコード（多ユーザー対応設計済み、実装は未）
- **iframe対応**: embed.php でヘッダーなし埋め込み可能
- **対象ユーザー**: もちツールズ使用者

## ディレクトリ構成
```
/
├── index.php         # メインタイマーページ（header付き）
├── embed.php         # 埋め込み用（EMBED_MODE=true）
├── stats.php         # 統計ページ（日/週/月/プロジェクト別）
├── settings.php      # Hook・基本設定ページ
├── help.php          # 使い方詳細
├── login.php         # ログイン・初回登録・ログアウト
├── api/
│   ├── timer.php     # タイマー操作API（start/pause/complete/update_meta等）
│   ├── output.php    # マークダウン出力API
│   └── cron_check.php # cron 用。80分・100分の hook を送るだけ
├── includes/
│   ├── header.php / footer.php
│   ├── config.php    # 設定ローダー
│   ├── auth.php      # ログイン（ローカルは不要）
│   ├── koma_stats.php # コマの集計ルール・異常値判定
│   ├── hook.php      # Hook 送信の共通関数
│   ├── data.php      # JSON読み書き（ファイルロック付き）
│   ├── user.php      # ユーザーコンテキスト
│   └── logger.php    # エラーログ（logs/error.log）
├── data/
│   ├── config.json
│   ├── users/moti.json
│   ├── auth.json     # アカウント（git 管理外）
│   └── sessions/YYYY/MM-DD/data.json
├── assets/css/timer.css
└── assets/js/timer.js
```

## Hook イベント
| event | タイミング |
|---|---|
| koma_start | コマ開始 |
| koma_complete | コマ完了（手動） |
| koma_80min | 80分経過 |
| koma_100min | 100分経過（hook 発火のみ。自動完了はしない） |
| break_notify | 完了後10分（break_afterフラグがオンの場合） |

## このプロジェクト固有の注意点
- `data/`・`logs/`・ドキュメント類は `.htaccess` で HTTP から遮断している。公開範囲を変えるときは `.ai-docs/specs/operations.md` を読む。
- コードを変える作業では、最初に `.ai-docs/specs/dev-notes.md` を読む。

## ドキュメント
- 状態と仕様は `.ai-docs/` にある。入口は `.ai-docs/CURRENT.md`、仕様の地図は `.ai-docs/index.md`。
- 運用ルールは `~/.claude/project-docs/RULES.md`（Claude Code ではセッション開始時に自動で読み込まれる）。
- 実装エージェントとして委譲された場合は、指示された `.ai-docs/tasks/<ID>.md` に従う。
  書き込んでよいのはコードと `.ai-docs/inbox/<ID>/` だけで、ほかの `.ai-docs/` のファイルは読むだけにする。
