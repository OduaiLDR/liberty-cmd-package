<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

final class ProspectListCsvFiles
{
    /** @var resource|null */
    private $stream = null;

    private int $rowsInFile = 0;

    private int $fileCount = 0;

    private bool $uploadAttempted = false;

    /** @param list<string> $headers */
    public function __construct(
        private FilesystemAdapter $disk,
        private string $key,
        private array $headers,
        private ?int $rowsPerFile,
    ) {}

    public function write(array $row): void
    {
        if ($this->stream === null) {
            $this->open();
        }
        if (fputcsv($this->stream, $row, escape: '') === false) {
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
    }

    private function open(): void
    {
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Unable to create a temporary prospect list.');
        }
        $this->stream = $stream;
        $this->rowsInFile = 0;
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
        $this->fileCount++;
        $this->close();
    }
}
