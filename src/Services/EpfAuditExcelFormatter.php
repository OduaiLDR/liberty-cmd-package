<?php

namespace Cmd\Reports\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EpfAuditExcelFormatter
{
    /**
     * Build a 3-sheet workbook (EPFs, Advances, Summary) and return the absolute path.
     *
     * @param array<int, array<string, mixed>> $epfs
     * @param array<int, array<string, mixed>> $advances
     * @param array<int, array<string, mixed>> $summary
     * @param array<int, string> $epfCols
     * @param array<int, string> $advCols
     * @param array<int, string> $sumCols
     */
    public function buildWorkbook(
        array $epfs,
        array $advances,
        array $summary,
        array $epfCols,
        array $advCols,
        array $sumCols,
        string $cutoff
    ): string {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->addSheet($spreadsheet, 'EPFs',     $epfCols, $epfs);
        $this->addSheet($spreadsheet, 'Advances', $advCols, $advances);
        $this->addSheet($spreadsheet, 'Summary',  $sumCols, $summary);

        $spreadsheet->setActiveSheetIndex(0);
        $spreadsheet->getActiveSheet()->setSelectedCells('A1');

        $filename = 'epf_audit_' . date('Ymd_His') . '.xlsx';
        $path = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return $path;
    }

    /**
     * @param array<int, string> $cols
     * @param array<int, array<string, mixed>> $rows
     */
    private function addSheet(Spreadsheet $book, string $title, array $cols, array $rows): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle($title);
        $sheet->setShowGridlines(false);

        $sheet->fromArray($cols, null, 'A1');

        if (!empty($rows)) {
            $data = [];
            foreach ($rows as $row) {
                $data[] = array_values($row);
            }
            $sheet->fromArray($data, null, 'A2');
        }

        $lastColLetter = $this->columnLetter(count($cols));
        $lastRow = max(1, count($rows) + 1);

        $this->styleHeader($sheet, "A1:{$lastColLetter}1");

        if ($lastRow > 1) {
            $sheet->getStyle("A2:{$lastColLetter}{$lastRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
        }

        $sheet->freezePane('A2');

        for ($i = 1; $i <= count($cols); $i++) {
            $sheet->getColumnDimension($this->columnLetter($i))->setAutoSize(true);
        }
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D9E1F2');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = (int) (($index - $mod) / 26);
        }
        return $letter;
    }
}
