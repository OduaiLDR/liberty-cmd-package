<?php

namespace Cmd\Reports\Console\Commands\LendingUSAStatusReport;

class ReportData
{
    public string $connection = 'ALL';
    public string $timestamp;
    public bool $dryRun = false;
    
    public int $totalProcessed = 0;
    public int $totalChanges = 0;
    public int $crmUpdates = 0;
    public int $noChange = 0;
    public int $errors = 0;
    
    public array $fundedClients = [];
    public array $declinedClients = [];
    public array $newClients = [];
    public array $changedRecords = [];
    public array $statusBreakdown = [];
    public array $errorList = [];

    public function __construct()
    {
        $this->timestamp = now()->format('F j, Y \a\t g:i A T');
    }

    public function setConnection(string $connection): self
    {
        $this->connection = strtoupper($connection);
        return $this;
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function setStats(int $processed, int $changes, int $crmUpdates, int $noChange, int $errors): self
    {
        $this->totalProcessed = $processed;
        $this->totalChanges = $changes;
        $this->crmUpdates = $crmUpdates;
        $this->noChange = $noChange;
        $this->errors = $errors;
        return $this;
    }

    public function setFundedClients(array $clients): self
    {
        $this->fundedClients = $clients;
        return $this;
    }

    public function setDeclinedClients(array $clients): self
    {
        $this->declinedClients = $clients;
        return $this;
    }

    public function setNewClients(array $clients): self
    {
        $this->newClients = $clients;
        return $this;
    }

    public function setChangedRecords(array $records): self
    {
        $this->changedRecords = $records;
        return $this;
    }

    public function setStatusBreakdown(array $breakdown): self
    {
        $this->statusBreakdown = $breakdown;
        return $this;
    }

    public function addError(string $cid, string $message): self
    {
        $this->errorList[] = ['cid' => $cid, 'message' => $message];
        return $this;
    }

    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'timestamp' => $this->timestamp,
            'dryRun' => $this->dryRun,
            'totalProcessed' => $this->totalProcessed,
            'totalChanges' => $this->totalChanges,
            'crmUpdates' => $this->crmUpdates,
            'noChange' => $this->noChange,
            'errorsCount' => $this->errors,
            'fundedClients' => $this->fundedClients,
            'declinedClients' => $this->declinedClients,
            'newClients' => $this->newClients,
            'changedRecords' => $this->changedRecords,
            'statusBreakdown' => $this->statusBreakdown,
            'errors' => $this->errorList,
        ];
    }
}
