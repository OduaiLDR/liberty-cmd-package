<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsDebtPleteImportPlan;
use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunOptions;
use Modules\Main\SearchSmsRecords\Http\Imports\SmsTenantImportCsv;
use Modules\Main\SearchSmsRecords\Services\SmsTenantImportPlan;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Optional cross-application fixture: both real implementations, no HTTP, SQL or application boot. */
final class SmsDebtPleteImportReceiverContractTest extends TestCase
{
    private string $root;
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        $file = getenv('SMS_IMPORT_RECEIVER_CONTRACT_CLASS');
        if (PHP_VERSION_ID < 80400 || !is_string($file) || !is_file($file) || basename($file) !== 'SmsTenantImportPlan.php') {
            self::markTestSkipped('Supply the reviewed PHP 8.4 receiver class for the cross-application contract check.');
        }
        $csv = dirname($file, 2).'/Http/Imports/SmsTenantImportCsv.php';
        if (!is_file($csv)) self::markTestSkipped('The matching receiver CSV implementation is required.');
        require_once $file; require_once $csv;
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/sms-cross-contract-'.bin2hex(random_bytes(8));
        if (!mkdir($this->root, 0700)) throw new RuntimeException('Unable to create the private fixture root.');
    }

    protected function tearDown(): void
    {
        if (!isset($this->root)) return;
        self::assertMatchesRegularExpression('~/sms-cross-contract-[a-f0-9]{16}$~D', $this->root);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function test_real_exporter_sender_and_receiver_preserve_exact_seals_dates_and_four_column_values(): void
    {
        $identity = ['protocol' => 'debtplete-sms-import-v1', 'origin' => 'https://tenant.example.invalid',
            'tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'client_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
        foreach ([2, 5] as $cap) {
            $directory = $this->root.'/cap-'.$cap.'/runs/'.self::ID;
            $options = SmsRunOptions::normalize(5, $cap, ['T1'], SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
                SmsRunOptions::PLANNED_DATE_VERSION, '2020-01-13', SmsRunOptions::APPROVAL_FILENAME_VERSION);
            $rows = [['Élodie ', '60000', '0555010101', '2020-01-13'], ['=Formula', '60000', '0555010101', '2020-01-13'], ["Two\nLines", '23000', '0555010102', '2020-01-13']];
            $pending = (new SmsRunArtifactBundle)->build($directory, 'SMS Export - 2020-01-13 - ID Pending', $rows, 3, $options);
            $bundle = (new SmsRunArtifactBundle('v2'))->publishApproved($directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0009');
            $original = hash_file('sha256', $bundle['path']);
            $run = (object) ['Request_ID' => self::ID, 'Selection_Policy' => 'prospect-record-v1', 'Status' => 'approved',
                'Target' => 5, 'Selected' => 3, 'Approved_By' => 'fixture@example.invalid',
                'Run_Options_JSON' => json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'Artifact_Manifest_JSON' => json_encode($bundle['manifest'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'File_Path' => $bundle['path'], 'File_Bytes' => $bundle['bytes'], 'File_Sha256' => $bundle['sha256'], 'File_Cleanup_Path' => null];
            foreach (['', '.123456', '.1234560', '.1234567'] as $fraction) {
                $run->Approved_At = '2020-01-06 12:34:56'.$fraction;
                $sender = SmsDebtPleteImportPlan::fromApproved($run, $identity);
                $receiver = SmsTenantImportPlan::fromRequest($sender->payload(), self::ID, $identity['tenant_id'], $identity['client_id']);
                self::assertSame($sender->json(), $receiver->json());
                self::assertSame($sender->sha256(), $receiver->sha256());
                self::assertSame('2020-01-06T12:34:56'.$fraction.'Z', $receiver->payload()['approval']['at']);
                self::assertSame(3, $receiver->rows()); self::assertSame('2020-01-13', $receiver->sendDate());
                $nativeRun = clone $run;
                $nativeRun->Request_ID = strtoupper(self::ID);
                $nativeSender = SmsDebtPleteImportPlan::fromApproved($nativeRun, $identity);
                self::assertSame($sender->json(), $nativeSender->json());
                self::assertSame($receiver->sha256(), $nativeSender->sha256());
                self::assertNotSame($sender->fingerprint(), $nativeSender->fingerprint());
                self::assertSame(strtoupper(self::ID), $nativeRun->Request_ID);
            }
            $zip = null;
            if ($cap === 2) { $zip = new \ZipArchive; self::assertTrue($zip->open($bundle['path'])); }
            $decoded = [];
            try {
                foreach ($bundle['manifest']['parts'] as $part) {
                    $bytes = $zip === null ? file_get_contents($bundle['path']) : $zip->getFromName($part['name']);
                    self::assertSame($part['sha256'], hash('sha256', $bytes));
                    $input = fopen('php://temp', 'w+b'); fwrite($input, $bytes); rewind($input);
                    try {
                        foreach ((new SmsTenantImportCsv)->scan($input, $part['rows'], '2020-01-13') as $chunk) {
                            $decoded = array_merge($decoded, $chunk['rows']);
                        }
                    } finally { fclose($input); }
                }
            } finally { $zip?->close(); }
            self::assertSame(['Élodie ', "'=Formula", "Two\nLines"], array_column($decoded, 'first_name'));
            self::assertSame(['0555010101', '0555010101', '0555010102'], array_column($decoded, 'phone_1'));
            self::assertSame(['60000', '60000', '23000'], array_column($decoded, 'r_checkamt'));
            self::assertSame(['2020-01-13', '2020-01-13', '2020-01-13'], array_column($decoded, 'planned_send_date'));
            self::assertSame($original, hash_file('sha256', $bundle['path']));
        }
    }
}

