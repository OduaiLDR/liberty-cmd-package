<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/** Durable, atomic review-only sequences; never reads or reserves production invoice numbers. */
final class ReviewInvoiceNumbers
{
    public function __construct(private readonly string $directory) {}

    public static function fromLocalStorage(): self
    {
        return new self(Storage::disk('local')->path('cmd/review-invoice-numbers'));
    }

    /** A dry/local preview peeks at the next number; an emailed review reserves it permanently. */
    public function next(string $from, string $to, string $invoiceDate, bool $reserve = true): string
    {
        if (!preg_match('/^[A-Z][A-Z0-9]{0,11}$/', $from) || !preg_match('/^[A-Z][A-Z0-9]{0,11}$/', $to)) {
            throw new InvalidArgumentException('Review invoice companies must be uppercase company codes.');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $invoiceDate, $date)
            || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            throw new InvalidArgumentException('Review invoice date must be a valid YYYY-MM-DD date.');
        }
        $pair = $from . '-' . $to;
        $stamp = $date[1] . $date[2] . $date[3];
        $folder = $this->directory . '/' . $pair . '/' . $stamp;
        if ($reserve && !is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new RuntimeException('Cannot create the private review invoice sequence directory.');
        }

        for ($sequence = 1; $sequence <= 99; $sequence++) {
            $suffix = sprintf('%02d', $sequence);
            $number = $pair . '-' . $stamp . '-' . $suffix;
            $path = $folder . '/' . $suffix . '.json';
            if (!$reserve) {
                if (!file_exists($path)) {
                    return $number;
                }
                continue;
            }

            // Exclusive creation is the uniqueness boundary across simultaneous application processes.
            $file = @fopen($path, 'x');
            if ($file === false) {
                if (file_exists($path)) {
                    continue;
                }
                throw new RuntimeException('Cannot reserve a private review invoice number.');
            }
            try {
                $record = json_encode(['number' => $number, 'from' => $from, 'to' => $to,
                    'invoice_date' => $invoiceDate, 'purpose' => 'private-review', 'reserved_at' => gmdate('c')], JSON_THROW_ON_ERROR);
                if (fwrite($file, $record) !== strlen($record) || !fflush($file)) {
                    throw new RuntimeException('Cannot save the private review invoice reservation.');
                }
            } finally {
                fclose($file);
            }
            return $number;
        }

        throw new RuntimeException("All review invoice numbers 01-99 are reserved for {$pair} on {$invoiceDate}.");
    }
}
