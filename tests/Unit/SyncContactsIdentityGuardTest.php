<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncContactsData;
use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once dirname(__DIR__, 2) . '/src/Console/Commands/SyncContactsData.php';

class SyncContactsIdentityGuardTest extends TestCase
{
    public function test_cross_company_matches_require_unique_person_identity_and_guard_enrollment_orphans(): void
    {
        $queries = [];
        $connector = $this->getMockBuilder(DBConnector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['querySqlServer'])
            ->getMock();
        $connector->method('querySqlServer')->willReturnCallback(function (string $sql) use (&$queries): array {
            $queries[] = $sql;

            return ['success' => true, 'row_count' => 0, 'data' => []];
        });

        $command = new SyncContactsData();
        $input = new ArrayInput([], $command->getDefinition());
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput()));
        (new \ReflectionMethod($command, 'matchSourceTableToContacts'))
            ->invoke($command, $connector, 'TblContactsPLAW');

        self::assertCount(10, $queries);

        $identityFields = $queries[2];
        self::assertStringContainsString('TblContacts.Email', $identityFields);
        self::assertStringContainsString('TblContacts.Phone', $identityFields);
        self::assertStringContainsString('duplicateContact.LLG_ID <> TblContacts.LLG_ID', $identityFields);
        self::assertStringContainsString('duplicate.LLG_ID <> TblContactsPLAW.LLG_ID', $identityFields);

        $identityRemap = $queries[4];
        self::assertStringContainsString('taken.LLG_ID = TblContactsPLAW.LLG_ID', $identityRemap);
        self::assertStringContainsString('CHARINDEX', $identityRemap);
        self::assertStringContainsString('LEN(', $identityRemap);
        self::assertStringContainsString('duplicateContact.LLG_ID <> TblContacts.LLG_ID', $identityRemap);

        foreach ([$queries[7], $queries[8]] as $orphanEnrollmentQuery) {
            self::assertStringContainsString("NULLIF(LTRIM(RTRIM(COALESCE(e.Client, ''))), '') IS NULL", $orphanEnrollmentQuery);
            self::assertStringContainsString('e.LLG_ID = \'LLG-\' + CAST(src.External_ID AS VARCHAR(50))', $orphanEnrollmentQuery);
            self::assertStringContainsString('duplicate.External_ID = src.External_ID', $orphanEnrollmentQuery);
            self::assertStringContainsString('kept.Email', $orphanEnrollmentQuery);
            self::assertStringContainsString('kept.Phone', $orphanEnrollmentQuery);
        }
    }

    public function test_side_table_agent_is_refreshed_from_the_identity_matched_lt_contact(): void
    {
        $queries = [];
        $connector = $this->getMockBuilder(DBConnector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['querySqlServer'])
            ->getMock();
        $connector->method('querySqlServer')->willReturnCallback(function (string $sql) use (&$queries): array {
            $queries[] = $sql;

            return ['success' => true, 'row_count' => 0, 'data' => []];
        });

        $command = new SyncContactsData();
        $input = new ArrayInput([], $command->getDefinition());
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput()));
        (new \ReflectionMethod($command, 'matchSourceTableToContacts'))
            ->invoke($command, $connector, 'TblContactsLDR');

        self::assertCount(10, $queries, 'The source matching sequence must include LT-agent propagation.');
        $agentUpdate = $queries[6];
        self::assertStringContainsString('SET source.Agent = lt.Agent', $agentUpdate);
        self::assertStringContainsString('INNER JOIN TblContacts AS lt', $agentUpdate);
        self::assertStringContainsString('lt.LLG_ID = source.LLG_ID', $agentUpdate);
        self::assertStringContainsString('source.Client', $agentUpdate);
        self::assertStringContainsString('source.Email', $agentUpdate);
        self::assertStringContainsString('source.Phone', $agentUpdate);
        self::assertStringContainsString("lt.Agent NOT LIKE '% User'", $agentUpdate);
    }
}
