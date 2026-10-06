<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\FivetranDeletedRowFilter;
use PHPUnit\Framework\TestCase;

require_once getenv('DEBT_TEST_FILTER') ?: dirname(__DIR__, 2) . '/src/Services/FivetranDeletedRowFilter.php';

class FivetranDeletedRowFilterTest extends TestCase
{
    public function test_filters_marked_sources_inside_the_relation_to_preserve_left_join_rows(): void
    {
        $sql = 'SELECT c.ID FROM CONTACTS c LEFT JOIN DPP_DATA.READER.TRANSACTIONS AS t ON t.CONTACT_ID = c.ID';

        $result = FivetranDeletedRowFilter::apply($sql, ['CONTACTS', 'TRANSACTIONS']);

        self::assertSame(['CONTACTS', 'TRANSACTIONS'], $result['tables']);
        self::assertStringContainsString(
            'FROM (SELECT * FROM CONTACTS WHERE (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL)) c',
            $result['sql']
        );
        self::assertStringContainsString(
            'LEFT JOIN (SELECT * FROM DPP_DATA.READER.TRANSACTIONS WHERE (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL)) AS t ON',
            $result['sql']
        );
    }

    public function test_adds_an_alias_when_the_original_relation_has_none(): void
    {
        $result = FivetranDeletedRowFilter::apply('SELECT COUNT(*) FROM DEBTS WHERE ENROLLED = 1', ['DEBTS']);

        self::assertStringContainsString(
            'FROM (SELECT * FROM DEBTS WHERE (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL)) AS DEBTS WHERE',
            $result['sql']
        );
    }

    public function test_leaves_tables_without_the_marker_unchanged(): void
    {
        $sql = 'SELECT * FROM CONTACTS c JOIN LEGACY_LOOKUP l ON l.ID = c.ID';

        $result = FivetranDeletedRowFilter::apply($sql, ['CONTACTS']);

        self::assertSame(['CONTACTS'], $result['tables']);
        self::assertStringContainsString('JOIN LEGACY_LOOKUP l ON', $result['sql']);
        self::assertStringNotContainsString('_FIVETRAN_DELETED', substr($result['sql'], strpos($result['sql'], 'JOIN LEGACY_LOOKUP')));
    }

    public function test_handles_ctes_without_changing_the_cte_name(): void
    {
        $sql = 'WITH page AS (SELECT ID FROM CONTACTS WHERE ID > 0) SELECT * FROM page p';

        $result = FivetranDeletedRowFilter::apply($sql, ['CONTACTS']);

        self::assertStringContainsString('FROM (SELECT * FROM CONTACTS WHERE (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL)) AS CONTACTS WHERE ID > 0', $result['sql']);
        self::assertStringContainsString('FROM page p', $result['sql']);
    }

    public function test_returns_query_unchanged_when_metadata_has_no_marked_tables(): void
    {
        $sql = 'SELECT * FROM CONTACTS';

        self::assertSame(['sql' => $sql, 'tables' => []], FivetranDeletedRowFilter::apply($sql, []));
    }
}
