<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\SettlementApprovalEmail;

use Cmd\Reports\SettlementApprovalEmail\{ApprovalEmail, DatabaseApprovalLedger};
use Illuminate\Database\Capsule\Manager;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseApprovalLedgerTest extends TestCase
{
    private function connection(): \Illuminate\Database\Connection
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db = $capsule->getConnection();
        $previous = Facade::getFacadeApplication();
        $app = new Container();
        $app->instance('db.schema', $db->getSchemaBuilder());
        Facade::clearResolvedInstance('db.schema');
        Facade::setFacadeApplication($app);
        try {
            $migration = require dirname(__DIR__, 3) . '/database/settlement-approval-email-migrations/2026_10_19_000001_create_settlement_approval_email_jobs.php';
            $migration->up();
        } finally {
            Facade::clearResolvedInstance('db.schema');
            Facade::setFacadeApplication($previous);
        }
        return $db;
    }

    public function test_database_lease_excludes_other_workers_and_fences_stale_saves(): void
    {
        $db = $this->connection();
        $first = new DatabaseApprovalLedger($db); $second = new DatabaseApprovalLedger($db);
        $email = new ApprovalEmail('LDR', '123', '456', 'Bank', '0012');
        self::assertSame('ready', $first->claim($email)['stage']);
        self::assertNull($second->claim($email));
        $first->save($email, ['stage' => 'status_attempting', 'target_status' => '7']);
        $db->table(DatabaseApprovalLedger::TABLE)->where('job_key', $email->key())->update(['lease_until' => '2000-01-01 00:00:00']);
        self::assertSame('status_attempting', $second->claim($email)['stage']);
        try { $first->save($email, ['stage' => 'completed']); self::fail('Expected stale writer rejection.'); }
        catch (RuntimeException) { self::assertTrue(true); }
        $first->release($email);
        $second->save($email, ['stage' => 'status_updated']);
        $second->release($email);
        self::assertSame('status_updated', $first->claim($email)['stage']);
    }

    public function test_conflicting_customer_identity_is_rejected_and_companies_are_separate(): void
    {
        $db = $this->connection(); $ledger = new DatabaseApprovalLedger($db);
        $a = new ApprovalEmail('LDR', '123', '456', 'Bank', '0012');
        $ledger->claim($a); $ledger->release($a);
        self::assertNotNull($ledger->claim(new ApprovalEmail('PLAW', '123', '456', 'Bank', '0012')));
        $this->expectException(RuntimeException::class);
        $ledger->claim(new ApprovalEmail('LDR', '999', '456', 'Bank', '0012'));
    }
}
