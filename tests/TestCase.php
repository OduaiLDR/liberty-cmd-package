<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests;

use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Enums\PmodCompany;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setTenantContext();
        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->setTenantContext();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function setTenantContext(array $context = []): void
    {
        $GLOBALS['__cmd_reports_test_tenant'] = $context;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function makeWorkItem(array $overrides = []): PmodWorkItem
    {
        $data = array_merge([
            'requestId' => 'req-123',
            'tenantId' => 'lt',
            'source' => 'test',
            'company' => PmodCompany::LDR,
            'actionType' => PmodActionType::SKIP_PAYMENT,
            'requestedBy' => 'System Admin',
            'contactId' => 'contact-123',
            'idempotencyKey' => 'idem-123',
            'receivedAt' => '2026-04-20T10:00:00+00:00',
            'rawPayload' => ['raw' => true],
            'normalizedPayload' => ['normalized' => true],
            'settlementIds' => [],
            'originalDates' => [],
            'targetDates' => [],
            'amounts' => [],
            'amount' => null,
            'increaseAmount' => null,
            'totalAmount' => null,
            'frequency' => null,
            'attachments' => [],
            'paymentChange' => [],
            'bankingUpdate' => [],
            'creditorChange' => [],
            'sponsorUpdate' => [],
            'executionOptions' => [],
            'dryRun' => false,
        ], $overrides);

        return new PmodWorkItem(
            requestId: $data['requestId'],
            tenantId: $data['tenantId'],
            source: $data['source'],
            company: $data['company'],
            actionType: $data['actionType'],
            requestedBy: $data['requestedBy'],
            contactId: $data['contactId'],
            idempotencyKey: $data['idempotencyKey'],
            receivedAt: $data['receivedAt'],
            rawPayload: $data['rawPayload'],
            normalizedPayload: $data['normalizedPayload'],
            settlementIds: $data['settlementIds'],
            originalDates: $data['originalDates'],
            targetDates: $data['targetDates'],
            amounts: $data['amounts'],
            amount: $data['amount'],
            increaseAmount: $data['increaseAmount'],
            totalAmount: $data['totalAmount'],
            frequency: $data['frequency'],
            attachments: $data['attachments'],
            paymentChange: $data['paymentChange'],
            bankingUpdate: $data['bankingUpdate'],
            creditorChange: $data['creditorChange'],
            sponsorUpdate: $data['sponsorUpdate'],
            executionOptions: $data['executionOptions'],
            dryRun: $data['dryRun'],
        );
    }
}
