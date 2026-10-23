<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Jobs\SmsRunPublicationJob;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunEmailWorkflow;
use Cmd\Reports\Services\SmsRunPublicationWorkflow;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Default read-only. Actual publishing always requires explicit operator confirmation. */
final class SmsRunPublicationCommand extends Command
{
    protected $signature = 'sms:run-publish {request-id : Approved SMS run UUID}
        {--action=status : status, publish, queue or prepare-email}
        {--actor= : Operator email recorded by the publication ledger}
        {--confirm-publish : Confirm uploading this approved run under its configured sharing policy}';

    protected $description = 'Inspect or explicitly publish approved CSV parts and prepare their dynamic-recipient email.';

    public function handle(SmsRunPublicationWorkflow $publications): int
    {
        $id = (string) $this->argument('request-id');
        $action = (string) $this->option('action');
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)
            || ! in_array($action, ['status', 'publish', 'queue', 'prepare-email'], true)) {
            $this->error('Use a lower-case SMS UUID and action status, publish, queue or prepare-email.');
            return self::FAILURE;
        }
        try {
            if ($action === 'status') {
                $status = $publications->status($id);
            } else {
                $actor = (string) $this->option('actor');
                if (! $this->option('confirm-publish') || $actor !== trim($actor)
                    || strlen($actor) > 255 || ! filter_var($actor, FILTER_VALIDATE_EMAIL)
                    || preg_match('/[\x00-\x20\x7f]/', $actor)) {
                    throw new RuntimeException('An explicit operator and publication confirmation are required.');
                }
                if ($action === 'queue') {
                    $publications->assertPublishable($id);
                    (new SmsExportQueue)->assertReady();
                    SmsRunPublicationJob::dispatch($id, $actor);
                    $this->info('SMS publication job queued. No email send or DebtPlete upload was requested.');
                    return self::SUCCESS;
                }
                // Rechecks remote objects/policy even after a completed publication.
                // The publication lock is released before the separate email workflow.
                $status = $publications->publish($id, $actor);
                if ($action === 'prepare-email') {
                    $email = app(SmsRunEmailWorkflow::class)->prepare($id, $publications->links($id), $actor);
                    $this->info('SMS email prepared: '.$email['status']);
                    $this->line('Prepared payload SHA-256: '.$email['payload_sha256']);
                }
            }
            if ($status === null) {
                $this->info('No tracked SMS publication is available. Check activation, schema and the approved destination policy.');
            } else {
                $this->info('SMS file publication: '.$status['status']);
                $this->line('CSV parts: '.$status['files_done'].' / '.$status['files_total']);
            }
            $this->line('No email was sent and no DebtPlete records were imported by this action.');
            return self::SUCCESS;
        } catch (Throwable) {
            // Capability links, folder paths, provider responses and credentials stay private.
            $this->error('SMS publication action did not complete. Check its saved progress and reviewed destination policy before retrying. No email send was requested.');
            return self::FAILURE;
        }
    }
}
