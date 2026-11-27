<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Cmd\Reports\Repositories\ProspectListExportFiles;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

final class ProspectListCsvFiles
{
    /** @var resource|null */
    private $stream = null;
    /** @var resource|null */
    private $membership = null;

    private int $rowsInFile = 0;

    private int $fileCount = 0;

    private bool $uploadAttempted = false;
    private string $campaignId;

    /** @param list<string> $headers */
    public function __construct(
        private FilesystemAdapter $disk,
        private string $key,
        private array $headers,
        private ?int $rowsPerFile,
        private ?string $mailerId = null,
        private ?int $campaignColumnIndex = null,
    ) {}

    public function write(array $row, int $prospectId): void
    {
        if ($this->stream === null) {
            $this->open();
        }
        if ($this->campaignColumnIndex !== null) {
            $row[$this->campaignColumnIndex] = $this->campaignId;
        }
        $membershipLine = $prospectId."\n";
        if (fputcsv($this->stream, $row, escape: '') === false
            || fwrite($this->membership, $membershipLine) !== strlen($membershipLine)) {
            throw new RuntimeException('Unable to write the prospect list.');
        }

        $this->rowsInFile++;
        if ($this->rowsInFile === $this->rowsPerFile) {
            $this->upload();
        }
    }

    public function finish(bool $allowEmpty): int
    {
        if ($allowEmpty && $this->fileCount === 0 && $this->stream === null) {
            $this->open();
        }
        if ($this->stream !== null) {
            $this->upload();
        }

        return $this->fileCount;
    }

    public function discard(): void
    {
        if (! $this->uploadAttempted) {
            return;
        }
        $this->disk->deleteDirectory(ProspectListRuns::membershipDirectory($this->key));
        if ($this->rowsPerFile === null) {
            $this->disk->delete(ProspectListRuns::artifact($this->key));
        } else {
            $this->disk->deleteDirectory(ProspectListRuns::artifactDirectory($this->key));
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
        if ($this->membership !== null) {
            fclose($this->membership);
            $this->membership = null;
        }
    }

    private function open(): void
    {
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Unable to create a temporary prospect list.');
        }
        $this->stream = $stream;
        $membership = tmpfile();
        if ($membership === false) {
            throw new RuntimeException('Unable to track the campaign prospect IDs.');
        }
        $this->membership = $membership;
        $this->rowsInFile = 0;
        $this->campaignId = ProspectListExportFiles::reserve($this->key, $this->fileCount + 1, $this->mailerId);
        if (fputcsv($this->stream, $this->headers, escape: '') === false) {
            throw new RuntimeException('Unable to write the prospect list headers.');
        }
    }

    private function upload(): void
    {
        $part = $this->rowsPerFile === null ? null : $this->fileCount + 1;
        $path = ProspectListRuns::artifact($this->key, $part);
        rewind($this->stream);
        $this->uploadAttempted = true;
        if (! $this->disk->put($path, $this->stream, ['visibility' => 'private', 'ContentType' => 'text/csv; charset=UTF-8'])) {
            throw new RuntimeException('Unable to store the prospect list.');
        }
        rewind($this->membership);
        $historyPath = ProspectListRuns::membershipDirectory($this->key).'/'.$this->campaignId.'.ids';
        if (! $this->disk->put($historyPath, $this->membership, ['visibility' => 'private', 'ContentType' => 'text/plain'])) {
            throw new RuntimeException('Unable to store the campaign prospect IDs.');
        }
        ProspectListExportFiles::recordRows($this->key, $this->fileCount + 1, $this->rowsInFile);
        $this->fileCount++;
        $this->close();
    }
}
