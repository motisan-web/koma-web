# 運用・デプロイ

## 環境
- ローカル: XAMPP の仮想ホスト `mp-koma-timer.local`
- 本番: Xserver（`9jf2.motisan.info`）。`main` への push で GitHub Actions（FTP-Deploy-Action）がデプロイする。
  FTP の認証情報は GitHub Secrets（`STAGING_SERVER` / `STAGING_USERNAME` / `STAGING_PASSWORD`）。
- `logs/error.log`: JSON Lines 形式でエラーを記録する。

## 公開範囲
- `.htaccess` の `RedirectMatch 404` で、`data/`・`logs/`・ドットフォルダ（`.git` `.github` `.claude` `.claude-codex` `.ai-docs`）・
  `.md` `.log` `.yml` などを HTTP から見えなくしている。PHP はファイルシステム経由で読むので影響しない。
- `deploy.yml` の `exclude` でドキュメント類を送らない。ただし FTP 同期は除外したファイルを本番から削除しないため、
  既にある古いファイルは `.htaccess` 側で守る。遮断ルールは削らないこと。
- 新しく公開したくないファイル・フォルダを足したら、`.htaccess` と `exclude` の両方を直し、ローカルで 404 を確認する。

## cron
- `/api/cron_check.php` を2〜5分ごとに実行する想定（未設定 → backlog の T-001）。現状のスクリプトは100分超過で自動完了させるので、設定前に I-012 を片付ける。
