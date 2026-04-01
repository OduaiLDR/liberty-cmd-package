<?php

namespace Cmd\Reports\Console\Commands\ProgramCompletions;

class ReportData
{
    public string $connection = '';
    public bool $dryRun = false;
    public int $totalProcessed = 0;
    public int $totalGraduated = 0;
    public int $crmUpdates = 0;
    public int $notesCreated = 0;
    public int $errorsCount = 0;
    public array $completedClients = [];
    public array $errors = [];
    public string $timestamp = '';

    public function __construct()
    {
        $this->timestamp = now()->format('F j, Y \a\t g:i A T');
    }

    public function setConnection(string $connection): self
    {
        $this->connection = $connection;
        return $this;
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function setStats(int $totalProcessed, int $totalGraduated, int $crmUpdates, int $notesCreated, int $errorsCount): self
    {
        $this->totalProcessed = $totalProcessed;
        $this->totalGraduated = $totalGraduated;
        $this->crmUpdates = $crmUpdates;
        $this->notesCreated = $notesCreated;
        $this->errorsCount = $errorsCount;
        return $this;
    }

    public function setCompletedClients(array $completedClients): self
    {
        $this->completedClients = $completedClients;
        return $this;
    }

    public function setErrors(array $errors): self
    {
        $this->errors = $errors;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'dryRun' => $this->dryRun,
            'timestamp' => $this->timestamp,
            'totalProcessed' => $this->totalProcessed,
            'totalGraduated' => $this->totalGraduated,
            'crmUpdates' => $this->crmUpdates,
            'notesCreated' => $this->notesCreated,
            'errorsCount' => $this->errorsCount,
            'completedClients' => $this->completedClients,
            'errors' => $this->errors,
        ];
    }
}
