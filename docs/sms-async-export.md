# SMS export rollout

The CMD API now queues SMS selection and export work. The app polls JSON status endpoints. Export files are private S3 objects; a ready status returns a 15-minute download URL, so CSV/ZIP bytes do not pass through the 30-second CMD proxy.

Before enabling exports on CMD runner:

1. Keep `oduaildr/liberty-cmd-package` on `dev`. Publish `reports-sms-migrations` and apply the two new `2026_10_07_*` migrations to `sqlsrv`. Do not rerun or drop the existing SMS tracking migration.
2. Configure `CMD_SMS_EXPORT_BUCKET` (private bucket) and optionally `CMD_SMS_EXPORT_PREFIX` and `AWS_DEFAULT_REGION`. The CMD runner's IAM identity needs `s3:PutObject` and `s3:GetObject` for that prefix; S3 HeadObject uses the GetObject permission. The AWS SDK must be installed in the host. The package does not modify Composer.
3. Keep the existing `tu-export` worker running. This job allows one attempt and up to 7,000 seconds. CMD runner must use its database queue with `DB_QUEUE_RETRY_AFTER` above 7,000 seconds, and the worker timeout must exceed the job timeout. The API fails closed when the queue reservation is too short. Otherwise the status can remain queued or show a worker failure. Avoid running another export for the same drops until reconciliation.
4. Run `php artisan optimize:clear` after changing config. Check `GET /api/cmd/mail-drop-export-report/preview?page=1`, then queue a small selection and export through the app before a large production request.

`POST /api/cmd/mail-drop-export-report/selection` accepts a UUID `request_id` and either `target` or `drop_pks`; it returns 202. Poll `GET /api/cmd/mail-drop-export-report/selection-status?request_id=...` for `ready`, `drops`, `total`, and `shortfall`. `POST /api/cmd/mail-drop-export-report/export` accepts a UUID `request_id`, `target`, and optional `drop_pks`; it returns 202. Poll `GET /api/cmd/mail-drop-export-report/export-status?request_id=...` until `ready` and open `download_url` directly. Reusing the same request ID with different inputs is rejected. Requests and download links are scoped to the CMD user's email.

An export uploads and verifies its S3 artifact before the SQL transaction records SMS drop use. A failed generation or upload rolls back SMS tracking. If tracking exists but a file cannot be verified, status is `needs_reconciliation`; do not automatically export those drops again. Retain the S3 objects until the business sets a retention policy, since deleting one would remove the only durable copy of a recorded export.
