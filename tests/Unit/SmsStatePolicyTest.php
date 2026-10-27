<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsProspectLoadRepository;
use Cmd\Reports\Services\SmsStatePolicy;
use Cmd\Reports\Services\SmsPhoneCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmsStatePolicyTest extends TestCase
{
    public static function blockedStates(): array
    {
        return array_map(static fn (string $state): array => [$state], ['CO', 'OR', 'RI', 'WI', 'WV']);
    }

    #[DataProvider('blockedStates')]
    public function test_exact_five_states_are_blocked_after_safe_normalization(string $state): void
    {
        self::assertSame('blocked_state', SmsStatePolicy::holdReason($state));
        self::assertSame('blocked_state', SmsStatePolicy::holdReason(strtolower($state)));
        self::assertSame('blocked_state', SmsStatePolicy::holdReason(" {$state} "));
    }

    public function test_allowed_controls_and_unknown_states_are_distinguished(): void
    {
        self::assertNull(SmsStatePolicy::holdReason('CA'));
        self::assertNull(SmsStatePolicy::holdReason(' ny '));
        self::assertNull(SmsStatePolicy::holdReason('DC'));
        self::assertSame('missing_state', SmsStatePolicy::holdReason(null));
        self::assertSame('missing_state', SmsStatePolicy::holdReason('  '));
        self::assertSame('invalid_state', SmsStatePolicy::holdReason('Colorado'));
        self::assertSame('invalid_state', SmsStatePolicy::holdReason('C1'));
        self::assertSame('invalid_state', SmsStatePolicy::holdReason(['CA']));
        self::assertSame('unknown_state', SmsStatePolicy::holdReason('ZZ'));
        self::assertSame('unknown_state', SmsStatePolicy::holdReason('PR'));
        self::assertSame('CO', SmsStatePolicy::normalize(' co '));
        self::assertSame('ZZ', SmsStatePolicy::normalize('zz'));
    }

    public function test_sql_predicates_are_fixed_server_policy_not_caller_input(): void
    {
        $sqlServer = SmsStatePolicy::sqlPredicate('p.State', 'sqlsrv');
        self::assertStringContainsString('UPPER(LTRIM(RTRIM(p.State))) COLLATE Latin1_General_100_BIN2 IN', $sqlServer);
        self::assertStringContainsString("'CA'", $sqlServer);
        self::assertStringNotContainsString("'CO'", $sqlServer);

        $sqlite = SmsStatePolicy::sqlPredicate('TblProspects.State', 'sqlite');
        self::assertStringContainsString('UPPER(TRIM(TblProspects.State)) IN', $sqlite);
        self::assertStringContainsString("'DC'", $sqlite);
        self::assertStringNotContainsString("'WV'", $sqlite);
    }

    public function test_sql_column_expression_is_internal_and_rejects_unsafe_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SmsStatePolicy::sqlPredicate('State) OR 1=1 --', 'sqlsrv');
    }

    public function test_legacy_phone_count_sql_holds_the_whole_mixed_state_identity(): void
    {
        $sql = (new SmsPhoneCounter)->sql('SELECT External_ID,Phone FROM fixture', 'SELECT Phone FROM contacts');
        self::assertStringContainsString('LeadStates AS', $sql);
        self::assertStringContainsString('States_Allowed = 1 AND State_Count = 1', $sql);
        self::assertStringContainsString('Latin1_General_100_BIN2 IN', $sql);
        self::assertStringNotContainsString('SmsStatePolicy::', $sql);
    }

    public function test_operator_preflight_is_read_only_and_bound_to_the_same_policy(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/sms-performance/state-policy-preflight.sql');
        self::assertIsString($sql);
        self::assertStringContainsString('sms-state-exclusion-v1', $sql);
        self::assertStringContainsString("IN ('CO','OR','RI','WI','WV')", $sql);
        self::assertStringContainsString('unknown_state', $sql);
        self::assertStringNotContainsString('UPDATE ', $sql);
        self::assertStringNotContainsString('DELETE ', $sql);
        self::assertStringNotContainsString('INSERT ', $sql);
        self::assertStringNotContainsString('ALTER ', $sql);
    }

    public function test_drop_admission_ranks_every_state_before_allowing_one_identity(): void
    {
        $method = new \ReflectionMethod(SmsProspectLoadRepository::class, 'sourceSql');
        $sql = $method->invoke(new SmsProspectLoadRepository);
        self::assertStringContainsString('RankedPeople AS', $sql);
        self::assertStringContainsString('MIN(CASE WHEN s.State IS NOT NULL', $sql);
        self::assertStringContainsString('All_States_Allowed = 1', $sql);
        self::assertStringContainsString('Min_State = Max_State', $sql);
    }

    public function test_final_state_guard_locks_every_current_sql_server_prospect_row(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Services/SmsFrozenEligibility.php');
        self::assertStringContainsString('FROM TblProspects p WITH (UPDLOCK,HOLDLOCK)', $source);
        self::assertStringContainsString('x WITH (HOLDLOCK) ON x.Prospect_PK=p.PK', $source);
    }
}
