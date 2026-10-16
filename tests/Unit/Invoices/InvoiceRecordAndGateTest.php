<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Cmd\Reports\Invoices\InvoiceRecordWriter;
use Cmd\Reports\Invoices\InvoiceRegistry;
use Cmd\Reports\Invoices\InvoiceRegistryConfig;
use Cmd\Reports\Invoices\InvoiceRegistryException;
use Cmd\Reports\Invoices\InvoiceRegistryGate;
use Cmd\Reports\Invoices\InvoiceStoreTransientException;
use Cmd\Reports\Invoices\S3InvoiceObjectStore;
use PHPUnit\Framework\TestCase;

final class InvoiceRecordAndGateTest extends TestCase
{
    private function files(): array
    {
        return [
            ['name' => 'LT-PL-20261015-01.pdf', 'kind' => 'pdf', 'bytes' => '%PDF-fake', 'contentType' => 'application/pdf'],
            ['name' => 'LT-PL-20261015-01-backup.xlsx', 'kind' => 'backup', 'bytes' => 'PKfake', 'contentType' => 'application/octet-stream'],
        ];
    }

    private function fields(): array
    {
        return ['amount' => 5500.0, 'invoiceDate' => '2026-10-15', 'status' => 'sent', 'generator' => 'lt-pl', 'period' => 'September 2026'];
    }

    public function test_record_json_matches_the_page_schema_and_layout(): void
    {
        $store = new FakeInvoiceStore();
        $record = (new InvoiceRecordWriter($store))->write('LT-PL', 'LT-PL-20261015-01', $this->fields(), $this->files());
        $folder = 'lending-tower/progress-law/2026/10/LT-PL-20261015-01/';
        self::assertSame([$folder . 'LT-PL-20261015-01.pdf', $folder . 'LT-PL-20261015-01-backup.xlsx', $folder . 'record.json'], array_keys($store->objects));
        $onDisk = json_decode($store->objects[$folder . 'record.json'], true);
        self::assertEquals($record, $onDisk);
        self::assertEquals(['LT-PL-20261015-01', 'lending-tower', 'progress-law', 5500.0, 'USD', '2026-10-15', 'sent', 1], [
            $onDisk['invoiceNumber'], $onDisk['company'], $onDisk['type'], $onDisk['amount'], $onDisk['currency'], $onDisk['invoiceDate'], $onDisk['status'], $onDisk['revision'],
        ]);
        self::assertSame(['name' => 'LT-PL-20261015-01.pdf', 'kind' => 'pdf', 'key' => $folder . 'LT-PL-20261015-01.pdf', 'sha256' => hash('sha256', '%PDF-fake'), 'bytes' => 9], $onDisk['files'][0]);
        self::assertSame(['system', 'lt-pl', 'September 2026'], [$onDisk['source'], $onDisk['generator'], $onDisk['period']]);
    }

    public function test_invalid_records_and_overwrites_are_refused(): void
    {
        $writer = new InvoiceRecordWriter($store = new FakeInvoiceStore());
        foreach ([['status' => 'paid'], ['amount' => 'x'], ['invoiceDate' => '10/15/2026']] as $bad) {
            try {
                $writer->write('LT-PL', 'LT-PL-20261015-01', $bad + $this->fields(), $this->files());
                self::fail('accepted ' . json_encode($bad));
            } catch (InvoiceRegistryException) {
            }
        }
        self::assertSame([], $store->objects, 'nothing is written for an invalid record');
        $writer->write('LT-PL', 'LT-PL-20261015-01', $this->fields(), $this->files());
        $this->expectException(InvoiceRegistryException::class);
        $writer->write('LT-PL', 'LT-PL-20261015-01', $this->fields(), $this->files());
    }

    public function test_number_must_belong_to_the_series(): void
    {
        $this->expectException(InvoiceRegistryException::class);
        (new InvoiceRecordWriter(new FakeInvoiceStore()))->write('LT-PL', 'LT-LDR-20261015-01', $this->fields(), $this->files());
    }

    public function test_s3_adapter_sends_if_none_match_and_maps_412_and_409(): void
    {
        $sent = [];
        $mock = new MockHandler();
        $mock->append(function (Command $cmd) use (&$sent) { $sent[] = $cmd->toArray(); return new Result([]); });
        $mock->append(new AwsException('Precondition Failed', new Command('PutObject'), ['response' => new \GuzzleHttp\Psr7\Response(412), 'code' => 'PreconditionFailed']));
        $mock->append(new AwsException('Conflict', new Command('PutObject'), ['response' => new \GuzzleHttp\Psr7\Response(409), 'code' => 'ConditionalRequestConflict']));
        $mock->append(new AwsException('Denied', new Command('PutObject'), ['response' => new \GuzzleHttp\Psr7\Response(403), 'code' => 'AccessDenied']));
        $client = new S3Client(['version' => 'latest', 'region' => 'us-east-2', 'credentials' => ['key' => 'fake', 'secret' => 'fake'], 'handler' => $mock]);
        $store = new S3InvoiceObjectStore($client, 'ff-development-invoices');

        self::assertTrue($store->putIfAbsent('_reservations/LT-PL/20261015/01.json', '{}', 'application/json'));
        self::assertSame('*', $sent[0]['IfNoneMatch']);
        self::assertSame(['ff-development-invoices', '_reservations/LT-PL/20261015/01.json', 'AES256'], [$sent[0]['Bucket'], $sent[0]['Key'], $sent[0]['ServerSideEncryption']]);
        self::assertFalse($store->putIfAbsent('k', '{}', 'application/json'), '412 means taken');
        try {
            $store->putIfAbsent('k', '{}', 'application/json');
            self::fail('409 should be transient');
        } catch (InvoiceStoreTransientException) {
        }
        $this->expectException(InvoiceRegistryException::class);
        $store->putIfAbsent('k', '{}', 'application/json');
    }

    private function cfg(array $env): InvoiceRegistryConfig
    {
        return InvoiceRegistryConfig::fromReader(static fn (string $k) => $env[$k] ?? null);
    }

    public function test_s3_adapter_reads_back_an_object_maps_404_to_null_and_refuses_when_unreadable(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['Body' => \GuzzleHttp\Psr7\Utils::streamFor('PKfake')]));
        $mock->append(new AwsException('Missing', new Command('GetObject'), ['response' => new \GuzzleHttp\Psr7\Response(404), 'code' => 'NoSuchKey']));
        $mock->append(new AwsException('Denied', new Command('GetObject'), ['response' => new \GuzzleHttp\Psr7\Response(403), 'code' => 'AccessDenied']));
        $store = new S3InvoiceObjectStore(new S3Client(['version' => 'latest', 'region' => 'us-east-2', 'credentials' => ['key' => 'fake', 'secret' => 'fake'], 'handler' => $mock]), 'ff-development-invoices');
        self::assertSame('PKfake', $store->get('k'));
        self::assertNull($store->get('k'));
        $this->expectException(InvoiceRegistryException::class);
        $store->get('k');
    }

    private function halfFinishedAttempt(FakeInvoiceStore $store, string $workbook): string
    {
        $folder = 'lending-tower/progress-law/2026/10/LT-PL-20261015-01/';
        $store->transientFailures = [$folder . 'record.json'];
        $files = $this->files();
        $files[1]['bytes'] = $workbook;
        try {
            (new InvoiceRecordWriter($store))->write('LT-PL', 'LT-PL-20261015-01', ['amount' => 100.0] + $this->fields(), $files);
            self::fail('the first attempt should have died on record.json');
        } catch (\Cmd\Reports\Invoices\InvoiceStoreTransientException) {
        }
        self::assertArrayNotHasKey($folder . 'record.json', $store->objects);

        return $folder;
    }

    public function test_retry_with_a_different_workbook_never_records_a_checksum_for_the_wrong_file(): void
    {
        $store = new FakeInvoiceStore();
        $folder = $this->halfFinishedAttempt($store, 'PK-workbook-100-dollars');
        $files = $this->files();
        $files[1]['bytes'] = 'PK-workbook-200-dollars';
        try {
            (new InvoiceRecordWriter($store))->write('LT-PL', 'LT-PL-20261015-01', ['amount' => 200.0] + $this->fields(), $files);
            self::fail('a retry whose workbook differs from the stored one must fail loudly');
        } catch (InvoiceRegistryException $e) {
            self::assertStringContainsString('already holds a different file', $e->getMessage());
            self::assertStringContainsString(hash('sha256', 'PK-workbook-100-dollars'), $e->getMessage());
        }
        self::assertArrayNotHasKey($folder . 'record.json', $store->objects, 'no record for the mismatched retry');
        self::assertSame('PK-workbook-100-dollars', $store->objects[$folder . 'LT-PL-20261015-01-backup.xlsx'], 'the stored file is never overwritten');
    }

    public function test_retry_with_the_same_files_completes_and_the_checksums_match_what_is_stored(): void
    {
        $store = new FakeInvoiceStore();
        $folder = $this->halfFinishedAttempt($store, 'PK-workbook-100-dollars');
        $files = $this->files();
        $files[1]['bytes'] = 'PK-workbook-100-dollars';
        $record = (new InvoiceRecordWriter($store))->write('LT-PL', 'LT-PL-20261015-01', ['amount' => 100.0] + $this->fields(), $files);
        foreach ($record['files'] as $entry) {
            self::assertSame(hash('sha256', $store->objects[$entry['key']]), $entry['sha256'], $entry['name']);
            self::assertSame(strlen($store->objects[$entry['key']]), $entry['bytes']);
        }
        self::assertEquals(100.0, json_decode($store->objects[$folder . 'record.json'], true)['amount']);
    }

    public function test_gate_reports_a_mismatched_retry_as_not_recorded_and_the_generator_carries_on(): void
    {
        $store = new FakeInvoiceStore();
        $this->halfFinishedAttempt($store, 'PK-old');
        $alerts = [];
        $gate = new InvoiceRegistryGate(new InvoiceRegistry($store), static function (string $m) use (&$alerts): void { $alerts[] = $m; });
        $files = $this->files();
        $files[1]['bytes'] = 'PK-new';
        self::assertFalse($gate->record('LT-PL', 'LT-PL-20261015-01', ['amount' => 200.0] + $this->fields(), $files), 'false = keep invoicing, nothing recorded');
        self::assertCount(1, $alerts);
        self::assertStringContainsString('different file', $alerts[0]);
    }

    public function test_gate_is_null_when_off_and_never_throws_when_s3_fails(): void
    {
        $alerts = [];
        $alert = static function (string $m, array $c) use (&$alerts): void { $alerts[] = $m; };
        $on = ['INVOICE_ENABLED' => 'true', 'INVOICE_S3_BUCKET' => 'b', 'INVOICE_LT_PL_ENABLED' => 'true'];

        self::assertNull(InvoiceRegistryGate::forGenerator('INVOICE_LT_PL_ENABLED', $this->cfg([]), fn () => new FakeInvoiceStore(), $alert));
        self::assertNull(InvoiceRegistryGate::forGenerator('INVOICE_LT_PL_ENABLED', $this->cfg(['INVOICE_ENABLED' => 'true', 'INVOICE_S3_BUCKET' => 'b']), fn () => new FakeInvoiceStore(), $alert));
        self::assertSame([], $alerts, 'off is silent');

        $store = new FakeInvoiceStore();
        $gate = InvoiceRegistryGate::forGenerator('INVOICE_LT_PL_ENABLED', $this->cfg($on), fn () => $store, $alert);
        self::assertSame('LT-PL-20261015-01', $gate->reserve('LT-PL', '2026-10-15'));
        self::assertTrue($gate->record('LT-PL', 'LT-PL-20261015-01', $this->fields(), $this->files()));

        $store->failAlways = true;
        self::assertNull($gate->reserve('LT-PL', '2026-10-15'));
        self::assertFalse($gate->record('LT-PL', 'LT-PL-20261015-02', $this->fields(), $this->files()));
        self::assertCount(2, $alerts);
        self::assertStringContainsString('using the OLD number', $alerts[0]);
        self::assertStringContainsString('NOT recorded', $alerts[1]);

        $alerts = [];
        self::assertNull(InvoiceRegistryGate::forGenerator('INVOICE_LT_PL_ENABLED', $this->cfg($on), function () { throw new \RuntimeException('no credentials'); }, $alert));
        self::assertStringContainsString('continuing WITHOUT it', $alerts[0]);
    }
}
