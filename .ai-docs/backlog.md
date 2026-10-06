次のID: T-010 / I-016

## issue
- [ ] I-015 何日も前の「実行中」コマが閉じられず、今までの経過が丸ごとコマ数に入る（例: 2026-06-01 コマ2 で 2,294.2 コマ）。原因: 自動中止（auto_close_old_komas）が get_state でしか動かず、どの画面も get_state を呼んでいない。さらに対象が90日前まで

## todo
- [ ] T-001 Xserver で cron ジョブを設定する（`api/cron_check.php` を CLI で2〜5分ごと。用途は hook 発火のみ）
