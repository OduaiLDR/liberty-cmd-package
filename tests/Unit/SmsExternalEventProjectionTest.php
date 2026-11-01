<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Cmd\Reports\Services\SmsExternalArtifact;
use Cmd\Reports\Services\SmsExternalEventProjection;
use Cmd\Reports\Services\SmsFeedbackService;
use Cmd\Reports\Services\SmsRecordPolicy;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Only in-memory SQLite and a new owned temporary directory; no providers or shared databases. */
final class SmsExternalEventProjectionTest extends TestCase
{
    private const ID = '12345678-1234-4234-8234-123456789abc';
    private Container $previous;
    private $db;
    private string $root;
    private SmsRunService $service;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance(); $container = new Container; Container::setInstance($container);
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/sms-external-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $container->instance('config', new Repository(['sms-exports' => ['local_root' => $this->root,
            'record_workflow_enabled' => true,'durable_availability_enabled' => true],'database' => ['default' => 'sqlsrv']]));
        $capsule = new Manager($container); $capsule->addConnection(['driver' => 'sqlite','database' => ':memory:'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv'); $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container); $this->db = $capsule->getConnection('sqlsrv');
        $this->db->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $t) => $t->string('Phone'));
        foreach (['2026_10_12_000001_create_sms_prospects_tables.php','2026_10_19_000001_complete_sms_workflow.php',
            '2026_10_20_000001_create_sms_availability_summary.php','2026_10_20_000003_add_sms_record_approval.php',
            '2026_10_22_000001_add_sms_run_file_options.php'] as $file) (require dirname(__DIR__, 2).'/database/sms-migrations/'.$file)->up();
        $this->db->getSchemaBuilder()->create('TblSMSExternalEvents', function (Blueprint $t): void {
            $t->uuid('Request_ID')->primary();
            foreach (['Policy_Version','State','Business_Date','Occurred_At_UTC','Started_At_UTC','Updated_At_UTC','Completed_At_UTC','Event_Key','Registered_By'] as $field) $t->string($field);
            foreach (['Occurred_Precision','Expected_Rows','Staged_Rows','Applied_Rows'] as $field) $t->integer($field);
            foreach (['Original_Artifacts_JSON','Reported_Submitter','Error_Code'] as $field) $t->text($field)->nullable();
        });
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => self::ID,'Target' => 3,'Selected' => 3,'Status' => 'external_sent',
            'Selection_Policy' => SmsRecordPolicy::EXTERNAL,'Created_By' => 'operator@example.invalid','Created_At' => '2026-10-24 18:00:00',
            'Updated_At' => '2026-10-24 18:00:00','Sent_At' => '2026-10-21 18:11:15']);
        $this->db->table('TblSMSExternalEvents')->insert(['Request_ID' => self::ID,'Policy_Version' => SmsRecordPolicy::EXTERNAL,
            'State' => 'completed','Business_Date' => '2026-10-21','Occurred_At_UTC' => '2026-10-21 18:11:15','Occurred_Precision' => 0,
            'Started_At_UTC' => '2026-10-24 18:00:00','Updated_At_UTC' => '2026-10-24 18:01:00','Completed_At_UTC' => '2026-10-24 18:01:00',
            'Event_Key' => 'jude-original-sms-2026-10-21','Registered_By' => 'operator@example.invalid','Expected_Rows' => 3,'Staged_Rows' => 3,'Applied_Rows' => 3]);
        foreach (range(1, 3) as $i) {
            $pk = $this->db->table('TblProspects')->insertGetId(['Phone' => '000555010'.$i,'Client' => 'Fixture Person','Address' => 'Fixture '.$i,
                'Opt_Out' => $i === 1 ? 1 : 0,'Invalid_Phone' => $i === 2 ? 1 : 0]);
            $this->db->table('TblSMSHistory')->insert(['Request_ID' => self::ID,'Prospect_PK' => $pk,'Phone' => '000555010'.$i,'Export_Date' => '2026-10-21 18:11:15']);
        }
        $this->service = new class(new SmsProspectExportRepository) extends SmsRunService {
            protected function dispatch(string $requestId, string $job): void { throw new \RuntimeException('No ordinary job may be dispatched.'); }
        };
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        self::assertMatchesRegularExpression('~/sms-external-test-[a-f0-9]{16}$~D', $this->root);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($this->root); Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    public function test_external_history_is_honest_and_unknown_tiers_remain_in_totals(): void
    {
        $run = $this->service->show(self::ID);
        self::assertNull($run->options); self::assertNull($run->debt); self::assertNull($run->approved_at);
        self::assertNull($run->never_texted); self::assertFalse($run->can_correct_send);
        self::assertSame('2026-10-21T18:11:15Z', $run->external_event['occurred_at']);
        self::assertSame(3, $this->service->history()['items'][0]->selected);
        $details = $this->service->details(self::ID);
        self::assertSame(3, $details['total']['records']); self::assertSame(3, $details['unknown_tier']['records']);
        self::assertSame(0, array_sum(array_column($details['tiers'], 'records')));
        self::assertSame(1, $details['total']['opt_out']); self::assertSame(1, $details['total']['invalid']);
        self::assertNull($details['total']['contacted']);
    }

    public function test_all_ordinary_actions_and_direct_repositories_deny_external_without_changes(): void
    {
        $legacy = new SmsProspectExportRepository; $record = new SmsRecordExportRepository;
        $actions = [
            fn () => $this->service->start(self::ID, 3, 'operator@example.invalid'), fn () => $this->service->prepare(self::ID),
            fn () => $this->service->requestFile(self::ID), fn () => $this->service->buildFile(self::ID),
            fn () => $this->service->requestSent(self::ID, 'actor'), fn () => $this->service->markSent(self::ID),
            fn () => $this->service->requestApprove(self::ID, 'actor'), fn () => $this->service->markApproved(self::ID),
            fn () => $this->service->reviewOnly(self::ID, 'actor'), fn () => $this->service->requestNotSent(self::ID, 'actor', 'Synthetic correction reason'),
            fn () => $this->service->reverseSent(self::ID), fn () => $this->service->discard(self::ID),
            fn () => $this->service->cleanupDeletedFile(self::ID), fn () => $this->service->retry(self::ID),
            fn () => $this->service->fail(self::ID, 'prepare', 'synthetic'), fn () => $legacy->select(self::ID, 3),
            fn () => $legacy->lookupDebts(self::ID), fn () => $legacy->assignDrops(self::ID, Carbon::parse('2026-10-21')),
            fn () => $legacy->writeCsv(self::ID, $this->root.'/unexpected.csv'), fn () => $legacy->markSent(self::ID, Carbon::now()),
            fn () => $legacy->releaseReview(self::ID), fn () => $legacy->reverseSent(self::ID), fn () => $legacy->discard(self::ID),
            fn () => $record->select(self::ID, 3), fn () => $record->assertFrozen(self::ID, 3),
            fn () => (new SmsFeedbackService)->import(self::ID, 0, [['phone' => '0005550101','status' => 'opt_out']], 'actor'),
        ];
        $before = $this->db->table('TblProspects')->orderBy('PK')->get()->toJson();
        foreach ($actions as $action) {
            try { $action(); self::fail('Ordinary action must be refused.'); }
            catch (HttpException $error) { self::assertSame(409, $error->getStatusCode()); }
        }
        self::assertSame($before, $this->db->table('TblProspects')->orderBy('PK')->get()->toJson());
        self::assertSame(3, $this->db->table('TblSMSHistory')->count()); self::assertSame(0, $this->db->table('TblSMSFeedbackImports')->count());
        config(['sms-exports.record_workflow_enabled' => false]);
        try { $legacy->select(self::ID, 3); self::fail('A disabled creation flag cannot reinterpret external history.'); }
        catch (HttpException $error) { self::assertSame(409, $error->getStatusCode()); }
        $this->db->table('TblSMSRuns')->update(['Selection_Policy' => 'unknown-policy']);
        try { $legacy->discard(self::ID); self::fail('Unknown policy cannot fall through to legacy.'); }
        catch (HttpException $error) { self::assertSame(409, $error->getStatusCode()); }
    }

    public function test_original_zip_contains_exact_nine_column_bytes_and_rejects_tampering(): void
    {
        $run = $this->db->table('TblSMSRuns')->first(); $root = SmsExternalArtifact::root($run); mkdir($root, 0700, true);
        $parts = [];
        foreach (range(1, 3) as $i) {
            $name = 'SMS_Data_Part'.$i.'.csv'; $path = $root.'/'.$name; $stream = fopen($path, 'xb');
            fputcsv($stream, SmsExternalArtifact::HEADER, ',', '"', ''); fputcsv($stream, ['Fixture','Fixture '.$i,'13500','000555010'.$i,'','','','','2026-10-21'], ',', '"', ''); fclose($stream);
            $parts[] = ['part' => $i,'name' => $name,'rows' => 1,'bytes' => filesize($path),'sha256' => hash_file('sha256', $path),'private_relative_path' => $name];
        }
        $download = SmsExternalArtifact::buildOriginalZip($root, $parts, 'Historical originals.zip');
        $manifest = ['version' => 1,'csv_format' => SmsExternalArtifact::FORMAT,'header' => SmsExternalArtifact::HEADER,'parts' => $parts,
            'pk_list' => ['name' => 'selected_TblProspects_PKs.csv','rows' => 3,'bytes' => 1,'sha256' => str_repeat('a', 64),'private_relative_path' => 'selected_TblProspects_PKs.csv'], 'download' => $download];
        $this->db->table('TblSMSExternalEvents')->update(['Original_Artifacts_JSON' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
        $this->db->table('TblSMSRuns')->update(['File_Path' => $root.'/'.$download['name'],'File_Bytes' => $download['bytes'],'File_Sha256' => $download['sha256'],'File_At' => '2026-10-24 18:00:00']);
        self::assertSame($root.'/'.$download['name'], $this->service->filePath(self::ID));
        $view = $this->service->show(self::ID); self::assertSame(3, $view->file['parts']); self::assertSame(SmsExternalArtifact::FORMAT, $view->file['csv_format']);
        $zip = new \ZipArchive; self::assertTrue($zip->open($root.'/'.$download['name']) === true); self::assertSame(3, $zip->numFiles);
        foreach ($parts as $part) self::assertSame($part['sha256'], hash('sha256', $zip->getFromName($part['name']))); $zip->close();
        self::assertSame($download, SmsExternalArtifact::buildOriginalZip($root, $parts, $download['name']), 'Replay retains the same ZIP bytes.');
        file_put_contents($root.'/'.$download['name'], 'corrupted synthetic zip');
        try { $this->service->filePath(self::ID); self::fail('Changed ZIP must be rejected.'); } catch (\RuntimeException) { self::assertTrue(true); }
        self::assertSame('corrupted synthetic zip', file_get_contents($root.'/'.$download['name']));
    }

    public function test_external_registering_failed_looking_and_unknown_policies_never_enter_normal_steps(): void
    {
        foreach (['external_registering','file_ready','failed'] as $status) {
            $this->db->table('TblSMSRuns')->update(['Status' => $status,'Failed_Step' => 'select']);
            foreach ([fn () => $this->service->retry(self::ID), fn () => $this->service->requestFile(self::ID),
                fn () => $this->service->reviewOnly(self::ID, 'actor'), fn () => $this->service->discard(self::ID)] as $action) {
                try { $action(); self::fail('External policy cannot enter a normal step.'); }
                catch (HttpException $error) { self::assertSame(409, $error->getStatusCode()); }
            }
        }
        $this->db->table('TblSMSRuns')->update(['Selection_Policy' => 'unexpected-policy']);
        try { $this->service->show(self::ID); self::fail('Unknown policy is not displayed as legacy.'); }
        catch (HttpException $error) { self::assertSame(409, $error->getStatusCode()); }
        self::assertSame(0, $this->db->table('TblProspectsExport')->count());
        self::assertSame(3, $this->db->table('TblSMSHistory')->count());
    }

    public function test_inconsistent_external_completion_receipts_fail_closed_without_reinterpreting_history(): void
    {
        foreach ([['Applied_Rows' => 2], ['Occurred_Precision' => 6], ['Business_Date' => '2026-10-20'], ['Occurred_At_UTC' => '2026-10-21 18:11:15.123']] as $changed) {
            $header = $this->db->table('TblSMSExternalEvents')->first();
            $this->db->table('TblSMSExternalEvents')->update($changed);
            try { $this->service->show(self::ID); self::fail('Changed external completion must be refused.'); }
            catch (HttpException $error) { self::assertSame(409, $error->getStatusCode()); }
            $this->db->table('TblSMSExternalEvents')->update((array) $header);
        }
        self::assertSame(3, $this->db->table('TblSMSHistory')->count());
    }
}
