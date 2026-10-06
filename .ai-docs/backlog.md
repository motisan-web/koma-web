次のID: T-002 / I-013

## issue
- [ ] I-012 `api/cron_check.php` が今も100分超過コマを自動完了させる（I-004 の「自動完了廃止」と矛盾）。T-001 で cron を設定する前に、hook 発火のみにするか cron 自体を不要にするか決める

## todo
- [ ] T-001 Xserver デプロイ後に cron ジョブを設定する（`/api/cron_check.php` を2〜5分ごとに実行）— I-012 の方針が決まるまで保留
