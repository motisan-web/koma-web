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
- `api/cron_check.php` を CLI から2〜5分ごとに実行する想定（未設定 → backlog の T-001）。役割はブラウザを閉じていても `koma_80min` / `koma_100min` を送ることだけで、コマの状態は変えない。

## ログイン
- `includes/auth.php`。ページは `auth_require_page()`（未ログインなら `/login.php` へ）、API は `auth_require_api()`（401）で守る。新しいページ・API を足したら必ずどちらかを呼ぶ。
- アカウントは `data/auth.json`（git 管理外）。1件もないときだけ `/login.php` が初回登録フォームになる。デプロイ直後は本人がすぐ登録すること（先に誰かが開くと登録されてしまう）。
- ローカル判定は「REMOTE_ADDR がループバック」かつ「ホスト名が .local / localhost」。CLI（cron）も認証なしで動く。
- セッションは `data/php_sessions/` に保存し、30日有効。Cookie は HttpOnly・SameSite=Lax（Lax なのでフォーム POST の CSRF は Cookie が送られず防げる。login.php のフォームは念のためトークンも確認）。
- 5回続けて失敗すると、そのアカウントを15分ロックする。パスワードを忘れたら `data/auth.json` をサーバー上で消すと初回登録からやり直せる。
