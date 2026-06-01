<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Support\PmodTransactionMatcher;
use PHPUnit\Framework\TestCase;

class PmodTransactionMatcherTest extends TestCase
{
    public function testFindUniqueByDateAndAmountReturnsOnlyMatchingTransaction(): void
    {
        $match = PmodTransactionMatcher::findUniqueByDateAndAmount([
            [
                'process_date' => '2024-11-25',
                'amount' => '250.50',
                'draft_id' => 'draft-123',
            ],
            [
                'process_date' => '2024-12-01',
                'amount' => '300.00',
                'draft_id' => 'draft-999',
            ],
        ], '11/25/2024', '$250.50');

        $this->assertNotNull($match);
        $this->assertSame('draft-123', $match['draft_id']);
    }

    public function testFindUniqueByDateAndAmountReturnsNullWhenAmbiguous(): void
    {
        $match = PmodTransactionMatcher::findUniqueByDateAndAmount([
            [
                'process_date' => '2024-11-25',
                'amount' => '250.50',
                'draft_id' => 'draft-123',
            ],
            [
                'process_date' => '2024-11-25',
                'amount' => '250.50',
                'draft_id' => 'draft-124',
            ],
        ], '2024-11-25', '250.50');

        $this->assertNull($match);
    }

    public function testExtractAuthoritativeDraftIdUsesExplicitDraftFields(): void
    {
        $this->assertSame('draft-123', PmodTransactionMatcher::extractAuthoritativeDraftId([
            'draft_id' => 'draft-123',
            'id' => 'crm-transaction-555',
        ]));
    }

    public function testExtractAuthoritativeDraftIdDoesNotTreatGenericIdAsDraftId(): void
    {
        $this->assertNull(PmodTransactionMatcher::extractAuthoritativeDraftId([
            'id' => 'crm-transaction-555',
            'amount' => '250.50',
        ]));
    }
}
