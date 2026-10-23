<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/sms-prospect-coverage.php';

final class SmsProspectCoverageConflictTest extends TestCase
{
    public static function contactSequences(): iterable
    {
        foreach ([0, 1, 2] as $existingCount) {
            foreach ([1, 2, 4] as $batchSize) {
                foreach (['phone', 'email', 'both'] as $contactType) {
                    foreach (['alternating' => ['A', 'B', 'A', 'B'],
                        'ending in agreements' => ['A', 'B', 'A', 'A'],
                        'only agreements' => ['A', 'A', 'A', 'A']] as $name => $sequence) {
                        yield "{$existingCount} existing, batch {$batchSize}, {$contactType}, {$name}"
                            => [$existingCount, $batchSize, $contactType, $sequence];
                    }
                }
            }
        }
    }

    #[DataProvider('contactSequences')]
    public function test_conflict_flags_and_counts_are_monotonic_through_the_audit(int $existingCount, int $batchSize, string $contactType, array $sequence): void
    {
        $rows = [];
        foreach ($sequence as $offset => $contact) {
            $rows[] = ['PK' => $offset + 1, 'Bucket' => 17, 'Identity_Valid' => 1,
                'Client' => 'Synthetic Person', 'Address' => '1 Fixture St', 'Zip' => '00123',
                'Phone' => $contactType === 'email' ? null : ($contact === 'A' ? '+1 (202) 555-0101' : '2025550102'),
                'Email' => $contactType === 'phone' ? null : ($contact === 'A' ? ($offset === 0 ? ' Alpha@example.test ' : 'ALPHA@example.test') : 'beta@example.test'),
                'Existing_Count' => $existingCount];
        }
        $source = $this->syntheticSource($rows, $batchSize);
        $journal = new PDO('sqlite::memory:');
        $manifest = ['format' => 1, 'read_only' => true, 'apply_supported' => false,
            'batch_size' => $batchSize, 'source_watermark_pk' => count($rows), 'prospect_watermark_pk' => 20];
        SmsProspectCoverage::initialize($journal, $manifest);
        $state = SmsProspectCoverage::state($journal, $manifest);
        $key = hash('sha256', SmsProspectReconciliation::json([
            $contactType === 'email' ? null : '2025550101', $contactType === 'phone' ? null : 'alpha@example.test',
        ]));
        $evidence = null;

        while (! $state['exhausted']) {
            $state = SmsProspectCoverage::auditBatch($source, $journal, $manifest);
            $seen = $state['source_rows'];
            $conflict = (int) in_array('B', array_slice($sequence, 0, $seen), true);
            $identity = $journal->query('SELECT *,typeof(contact_conflict) AS conflict_type FROM identities')->fetch(PDO::FETCH_ASSOC);

            self::assertSame(1, (int) $journal->query('SELECT COUNT(*) FROM identities')->fetchColumn());
            self::assertSame(1, (int) $identity['id']);
            self::assertSame($seen, (int) $identity['source_rows']);
            self::assertSame($existingCount, (int) $identity['existing_count']);
            self::assertSame($conflict, (int) $identity['contact_conflict'], 'An agreeing contact must never clear a conflict.');
            self::assertSame('integer', $identity['conflict_type']);
            self::assertSame($key, $identity['contact_key']);
            self::assertSame($conflict, $state['counts']['unique_contact_conflicts']);
            self::assertSame($existingCount === 0 ? $conflict : 0, $state['counts']['missing_contact_conflicts']);
            self::assertSame(1, $state['counts']['unique_contact_people']);
            self::assertSame($existingCount > 0 ? 1 : 0, $state['counts']['unique_existing_people']);
            self::assertSame($existingCount === 0 ? 1 : 0, $state['counts']['unique_missing_people']);
            self::assertSame($seen, $state['counts']['valid_contact_rows']);
            self::assertSame($existingCount > 0 ? $seen : 0, $state['counts']['existing_contact_rows']);
            self::assertSame($existingCount === 0 ? $seen : 0, $state['counts']['missing_contact_rows']);
            self::assertSame($state, SmsProspectCoverage::state($journal, $manifest));
            self::assertFalse($source->inTransaction());
            self::assertFalse($journal->inTransaction());

            if ($existingCount > 0) {
                self::assertNull($identity['evidence']);
            } else {
                $evidence ??= $identity['evidence'];
                self::assertSame($evidence, $identity['evidence'], 'Conflicts must not replace the original private evidence.');
                $representative = json_decode(gzdecode($evidence), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(1, $representative['PK']);
                self::assertSame($contactType === 'email' ? null : '2025550101', $representative['Phone']);
                self::assertSame($contactType === 'phone' ? null : 'Alpha@example.test', $representative['Email']);
            }
        }

        self::assertSame(count($rows), $state['source_rows']);
        self::assertSame((int) ceil(count($rows) / $batchSize), $state['batches']);
        $identities = $journal->query('SELECT * FROM identities')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame($state, SmsProspectCoverage::auditBatch($source, $journal, $manifest));
        self::assertSame($identities, $journal->query('SELECT * FROM identities')->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Only the two source SELECT results are synthetic; auditBatch and its SQLite journal are real. */
    private function syntheticSource(array $rows, int $batchSize): PDO
    {
        $source = $this->createMock(PDO::class);
        $transaction = false;
        $source->method('inTransaction')->willReturnCallback(static function () use (&$transaction): bool { return $transaction; });
        $source->method('beginTransaction')->willReturnCallback(static function () use (&$transaction): bool {
            self::assertFalse($transaction);
            return $transaction = true;
        });
        $source->method('rollBack')->willReturnCallback(static function () use (&$transaction): bool {
            self::assertTrue($transaction);
            $transaction = false;
            return true;
        });
        $source->expects(self::never())->method('commit');
        $source->expects(self::never())->method('exec');
        $source->expects(self::never())->method('query');
        $batchCount = (int) ceil(count($rows) / $batchSize);
        $source->expects(self::exactly(2 * $batchCount))->method('prepare')->willReturnCallback(function (string $sql) use ($rows, $batchSize, &$transaction): PDOStatement {
            self::assertTrue($transaction);
            $sourceRows = str_contains($sql, 'WITH Scope AS (');
            self::assertTrue($sourceRows || str_contains($sql, 'WITH Inputs AS ('), 'Unexpected source query.');
            self::assertDoesNotMatchRegularExpression('/\b(?:INSERT|UPDATE|DELETE|MERGE|CREATE|ALTER|DROP|EXEC(?:UTE)?|INTO)\b/i', $sql);
            $statement = $this->createMock(PDOStatement::class);
            $bindings = [];
            $statement->method('bindValue')->willReturnCallback(static function (int $position, mixed $value, int $type) use (&$bindings, $sourceRows): bool {
                self::assertSame($sourceRows ? PDO::PARAM_INT : PDO::PARAM_STR, $type);
                $bindings[$position] = $value;
                return true;
            });
            $statement->expects(self::once())->method('execute')->willReturn(true);
            $statement->expects(self::once())->method('closeCursor')->willReturn(true);
            $statement->expects(self::once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturnCallback(static function () use ($rows, $batchSize, $sql, $sourceRows, &$bindings): array {
                if ($sourceRows) {
                    self::assertStringContainsString("SELECT TOP ({$batchSize})", $sql);
                    return array_slice(array_values(array_filter($rows, static fn (array $row): bool => $row['PK'] > $bindings[1] && $row['PK'] <= $bindings[2])), 0, $batchSize);
                }
                // Every fixture row has exactly the same identity; no SQL Server collation is emulated.
                $inputs = json_decode($bindings[1], true, 512, JSON_THROW_ON_ERROR);
                self::assertContains(1, array_column($inputs, 'Token'), 'Cross-batch comparison must include the saved canonical identity.');
                return array_map(static fn (array $input): array => ['Kind' => $input['Kind'], 'Token' => $input['Token'],
                    'Canonical_ID' => 1, 'Bucket_Valid' => 1], $inputs);
            });
            return $statement;
        });
        return $source;
    }
}
