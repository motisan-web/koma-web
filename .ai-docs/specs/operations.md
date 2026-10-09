# 運用・デプロイ

## 環境
- ローカル: XAMPP の仮想ホスト `koma-web.local`（`C:/xampp/htdocs/github/koma-web.local`）
- GitHub: `motisan-web/koma-web`（旧 `mp-koma-timer`。GitHub の転送があるので旧 URL でも届く）
- 本番: Xserver（`9jf2.motisan.info`）。`main` への push で GitHub Actions（FTP-Deploy-Action）がデプロイする。
  FTP の認証情報は GitHub Secrets（`STAGING_SERVER` / `STAGING_USERNAME` / `STAGING_PASSWORD`）。
- `logs/error.log`: JSON Lines 形式でエラーを記録する。

## 公開範囲
- `.htaccess` の `RedirectMatch 404` で、`data/`・`logs/`・ドットフォルダ（`.git` `.github` `.claude` `.claude-codex` `.ai-docs`）・
  `.md` `.log` `.yml` `.pj` などを HTTP から見えなくしている。PHP はファイルシステム経由で読むので影響しない。
- `deploy.yml` の `exclude` でドキュメント類を送らない。ただし FTP 同期は除外したファイルを本番から削除しないため、
  既にある古いファイルは `.htaccess` 側で守る。遮断ルールは削らないこと。
- 新しく公開したくないファイル・フォルダを足したら、`.htaccess` と `exclude` の両方を直し、ローカルで 404 を確認する。
- `mock/`（見た目モック）はローカル専用。deploy の exclude に入れ、`.htaccess` でもホスト名が .local 以外なら 404 にしている。

## cron
- `api/cron_check.php` を CLI から2〜5分ごとに実行する想定（未設定 → backlog の T-001）。役割はブラウザを閉じていても `koma_80min` / `koma_100min` を送ることだけで、コマの状態は変えない。

## ログイン
- `includes/auth.php`。ページは `auth_require_page()`（未ログインなら `/login.php` へ）、API は `auth_require_api()`（401）で守る。新しいページ・API を足したら必ずどちらかを呼ぶ。
- アカウントは `data/auth.json`（git 管理外）。1件もないときだけ `/login.php` が初回登録フォームになる。デプロイ直後は本人がすぐ登録すること（先に誰かが開くと登録されてしまう）。
- ローカル判定は「REMOTE_ADDR がループバック」かつ「ホスト名が .local / localhost」。CLI（cron）も認証なしで動く。
- セッションは `data/php_sessions/` に保存し、30日有効。Cookie は HttpOnly・SameSite=Lax（Lax なのでフォーム POST の CSRF は Cookie が送られず防げる。login.php のフォームは念のためトークンも確認）。
- 5回続けて失敗すると、そのアカウントを15分ロックする。パスワードを忘れたら `data/auth.json` をサーバー上で消すと初回登録からやり直せる。

## プロジェクトの目印
- ルートの `koma-web@koma-web.local.pj` は、ローカルでプロジェクトを探すための検索用マーカー（中身は空）。`.htaccess` で `.pj` を 404 にしているので、本番に送られても見えない。

## リリース（バージョン管理）
- セマンティックバージョニング。現在のバージョンは `includes/version.php` の `KOMA_VERSION`（フッターに表示）。最初の正式版が 1.0.0、現在 1.1.0（旧 v2.0.0 表記は廃止）。
- リリース手順: ① `includes/version.php` を上げる ② ルートの `CHANGELOG.md` の先頭に版を追記する ③ コミットして `vX.Y.Z` のタグを打つ。
- 機能追加は MINOR、バグ修正だけなら PATCH、データ形式の非互換な変更は MAJOR を上げる。
