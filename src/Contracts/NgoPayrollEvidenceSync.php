<?php

declare(strict_types=1);

namespace Cmd\Reports\Contracts;

use Cmd\Reports\Services\DBConnector;

interface NgoPayrollEvidenceSync
{
    /** Owns its evidence transactions; called only after legacy summaries commit, without an ambient transaction. */
    public function sync(
        DBConnector $sqlServer,
        DBConnector $snowflake,
        string $source,
        string $period,
        ?string $payoutDate
    ): void;
}
