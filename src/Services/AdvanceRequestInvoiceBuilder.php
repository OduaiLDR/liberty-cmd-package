<?php

namespace Cmd\Reports\Services;

final class AdvanceRequestInvoiceBuilder
{
    private const PAGE_WIDTH = 612.0;
    private const PAGE_HEIGHT = 792.0;

    /** @var array<int, string> */
    private array $objects = [];

    private string $content = '';

    public function __construct(private readonly ?string $assetDirectory = null)
    {
    }

    /**
     * @param array{
     *     program: 'LDR'|'Progress Law',
     *     amount: float|int,
     *     tranche: string,
     *     month: string,
     *     invoice_number: string,
     *     issue_date: string,
     *     sample?: bool
     * } $invoice
     */
    public function build(array $invoice): string
    {
        $config = $this->programConfig($invoice['program']);
        $logoPath = $this->assetPath($config['logo']);

        if (!is_file($logoPath)) {
            throw new \RuntimeException("Advance Request invoice logo not found: {$logoPath}");
        }

        $logoInfo = getimagesize($logoPath);
        if ($logoInfo === false || ($logoInfo['mime'] ?? '') !== 'image/jpeg') {
            throw new \RuntimeException("Advance Request invoice logo must be a JPEG: {$logoPath}");
        }

        $logoBytes = file_get_contents($logoPath);
        if ($logoBytes === false) {
            throw new \RuntimeException("Unable to read Advance Request invoice logo: {$logoPath}");
        }

        $this->objects = [];
        $this->content = '';
        $this->drawInvoice($invoice, $config, (int) $logoInfo[0], (int) $logoInfo[1]);

        $this->objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $this->objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $this->objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
            . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT
            . '] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> /XObject << /Logo 7 0 R >> >> /Contents 4 0 R >>';
        $this->objects[4] = '<< /Length ' . strlen($this->content) . ">>\nstream\n{$this->content}endstream";
        $this->objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $this->objects[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $this->objects[7] = '<< /Type /XObject /Subtype /Image /Width ' . $logoInfo[0]
            . ' /Height ' . $logoInfo[1]
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
            . strlen($logoBytes) . ">>\nstream\n{$logoBytes}\nendstream";
        $this->objects[8] = '<< /Title (' . $this->escape('Advance Invoice - ' . $invoice['program']) . ')'
            . ' /Author (Liberty Debt Relief) /Creator (CMD Advance Request Report) >>';

        return $this->serializePdf();
    }

    /** @return array<string, mixed> */
    private function programConfig(string $program): array
    {
        return match ($program) {
            'LDR' => [
                'display_name' => 'Liberty Debt Relief, LLC',
                'address' => ['333 City Blvd W, 17th Fl', 'Orange, CA 92868'],
                'routing' => '322271627',
                'account' => '219935100',
                'logo' => 'ldr-logo.jpg',
                'accent' => [0.12, 0.51, 0.72],
                'accent_light' => [0.92, 0.97, 0.99],
            ],
            'Progress Law' => [
                'display_name' => 'Progress Law',
                'address' => ['3030 Euclid Ave, Ste LL1', 'Cleveland, OH 44115'],
                'routing' => '044000037',
                'account' => '2908959852',
                'logo' => 'progress-law-logo.jpg',
                'accent' => [0.18, 0.35, 0.43],
                'accent_light' => [0.93, 0.96, 0.97],
            ],
            default => throw new \InvalidArgumentException("Unsupported invoice program: {$program}"),
        };
    }

    private function assetPath(string $filename): string
    {
        $directory = $this->assetDirectory
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'images'
                . DIRECTORY_SEPARATOR . 'advance-request';

        return rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . $filename;
    }

    /** @param array<string, mixed> $invoice @param array<string, mixed> $config */
    private function drawInvoice(array $invoice, array $config, int $logoWidth, int $logoHeight): void
    {
        $accent = $config['accent'];
        $light = $config['accent_light'];
        $amount = '$' . number_format((float) $invoice['amount'], 2);
        $sample = (bool) ($invoice['sample'] ?? false);

        $this->fillColor(0.97, 0.98, 0.99);
        $this->rect(0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, true);
        $this->fillColor(1, 1, 1);
        $this->rect(24, 22, 564, 748, true);
        $this->fillColor(...$accent);
        $this->rect(24, 758, 564, 12, true);

        if ($sample) {
            $this->text(149, 405, 'SAMPLE - FOR REVIEW ONLY', 28, true, [0.90, 0.92, 0.94], 35);
        }

        $maxLogoWidth = $invoice['program'] === 'LDR' ? 78.0 : 66.0;
        $maxLogoHeight = 68.0;
        $scale = min($maxLogoWidth / $logoWidth, $maxLogoHeight / $logoHeight);
        $drawWidth = $logoWidth * $scale;
        $drawHeight = $logoHeight * $scale;
        $this->image(44, 674 + (68 - $drawHeight) / 2, $drawWidth, $drawHeight);

        $this->text(430, 724, 'ADVANCE INVOICE', 20, true, $accent, 0, 'right');
        if ($sample) {
            $this->text(430, 705, 'REVIEW SAMPLE - DO NOT PAY', 8.5, true, [0.70, 0.20, 0.16], 0, 'right');
        }

        $this->text(44, 656, $config['display_name'], 13, true, [0.12, 0.16, 0.20]);
        $this->text(44, 639, $config['address'][0], 9.5, false, [0.34, 0.39, 0.43]);
        $this->text(44, 625, $config['address'][1], 9.5, false, [0.34, 0.39, 0.43]);

        $this->labelValue(422, 665, 'INVOICE NO.', $invoice['invoice_number']);
        $this->labelValue(422, 626, 'ISSUE DATE', $invoice['issue_date']);

        $this->fillColor(...$light);
        $this->rect(44, 535, 524, 68, true);
        $this->fillColor(...$accent);
        $this->rect(44, 535, 7, 68, true);
        $this->text(67, 578, 'AMOUNT DUE', 9, true, [0.36, 0.40, 0.44]);
        $this->text(67, 548, $amount, 24, true, $accent);
        $this->text(546, 570, $invoice['month'], 11, true, [0.17, 0.21, 0.25], 0, 'right');
        $this->text(546, 551, 'Enrollment period', 8.5, false, [0.43, 0.47, 0.51], 0, 'right');
        $this->text(546, 537, 'Terms: Due upon receipt', 8.2, false, [0.43, 0.47, 0.51], 0, 'right');

        $this->text(44, 502, 'PAYMENT REQUESTED FROM', 8.5, true, [0.43, 0.47, 0.51]);
        $this->text(44, 482, 'NexGen Financial', 12, true, [0.14, 0.18, 0.22]);
        $this->text(44, 466, 'Attn: James', 9.5, false, [0.34, 0.39, 0.43]);

        $this->fillColor(...$accent);
        $this->rect(44, 424, 524, 30, true);
        $this->text(57, 435, 'DESCRIPTION', 9, true, [1, 1, 1]);
        $this->text(548, 435, 'AMOUNT', 9, true, [1, 1, 1], 0, 'right');
        $this->fillColor(1, 1, 1);
        $this->rect(44, 370, 524, 54, true);
        $this->strokeColor(0.87, 0.89, 0.91);
        $this->rect(44, 370, 524, 54, false);
        $this->text(57, 399, 'Advance payment toward sale of Tranche ' . $invoice['tranche'], 10, true, [0.18, 0.22, 0.26]);
        $this->text(57, 382, 'For ' . $invoice['month'] . ' enrollments', 9, false, [0.40, 0.44, 0.48]);
        $this->text(548, 391, $amount, 11, true, [0.18, 0.22, 0.26], 0, 'right');

        $this->text(44, 337, 'WIRE INSTRUCTIONS', 11, true, $accent);
        $this->fillColor(...$light);
        $this->rect(44, 224, 524, 96, true);
        $this->text(62, 294, 'BENEFICIARY', 8, true, [0.43, 0.47, 0.51]);
        $this->text(62, 275, $config['display_name'], 10.5, true, [0.15, 0.19, 0.23]);
        $this->text(62, 254, $config['address'][0], 8.7, false, [0.34, 0.39, 0.43]);
        $this->text(62, 239, $config['address'][1], 8.7, false, [0.34, 0.39, 0.43]);
        $this->strokeColor(0.82, 0.86, 0.88);
        $this->line(316, 240, 316, 302);
        $this->text(339, 294, 'ROUTING NUMBER', 8, true, [0.43, 0.47, 0.51]);
        $this->text(339, 275, $config['routing'], 12, true, [0.15, 0.19, 0.23]);
        $this->text(449, 294, 'ACCOUNT NUMBER', 8, true, [0.43, 0.47, 0.51]);
        $this->text(449, 275, $config['account'], 12, true, [0.15, 0.19, 0.23]);
        $this->text(339, 247, 'Wire reference', 8, true, [0.43, 0.47, 0.51]);
        $this->text(418, 247, 'Use invoice no. above', 8.2, false, [0.19, 0.23, 0.27]);
        $this->text(418, 233, 'and Tranche ' . $invoice['tranche'], 8.2, false, [0.19, 0.23, 0.27]);

        $this->fillColor(0.98, 0.98, 0.98);
        $this->rect(44, 167, 524, 38, true);
        $this->text(58, 188, 'Please include the invoice number and tranche in the wire reference.', 8.8, true, [0.30, 0.34, 0.38]);
        $this->text(58, 174, 'Questions or remittance confirmation can be sent by replying to the accompanying email.', 8.2, false, [0.42, 0.46, 0.50]);

        $this->strokeColor(...$accent);
        $this->line(44, 117, 568, 117);
        $this->text(44, 96, 'Thank you', 10, true, $accent);
        $this->text(568, 96, 'Page 1 of 1', 8, false, [0.48, 0.51, 0.54], 0, 'right');
    }

    private function labelValue(float $x, float $y, string $label, string $value): void
    {
        $this->text($x, $y, $label, 7.8, true, [0.48, 0.51, 0.54]);
        $this->text($x, $y - 16, $value, 9.3, true, [0.15, 0.19, 0.23]);
    }

    /** @param array{0:float,1:float,2:float} $color */
    private function text(
        float $x,
        float $y,
        string $text,
        float $size,
        bool $bold,
        array $color,
        float $rotation = 0,
        string $align = 'left'
    ): void {
        if ($align === 'right') {
            $x -= $this->estimateTextWidth($text, $size, $bold);
        }

        $angle = deg2rad($rotation);
        $a = cos($angle);
        $b = sin($angle);
        $c = -sin($angle);
        $d = cos($angle);
        $font = $bold ? 'F2' : 'F1';

        $this->content .= sprintf(
            "BT %.3F %.3F %.3F rg /%s %.2F Tf %.5F %.5F %.5F %.5F %.2F %.2F Tm (%s) Tj ET\n",
            $color[0], $color[1], $color[2], $font, $size, $a, $b, $c, $d, $x, $y, $this->escape($text)
        );
    }

    private function estimateTextWidth(string $text, float $size, bool $bold): float
    {
        return strlen($text) * $size * ($bold ? 0.56 : 0.51);
    }

    private function image(float $x, float $y, float $width, float $height): void
    {
        $this->content .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Logo Do Q\n", $width, $height, $x, $y);
    }

    private function rect(float $x, float $y, float $width, float $height, bool $fill): void
    {
        $this->content .= sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, $y, $width, $height, $fill ? 'f' : 'S');
    }

    private function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->content .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
    }

    private function fillColor(float $red, float $green, float $blue): void
    {
        $this->content .= sprintf("%.3F %.3F %.3F rg\n", $red, $green, $blue);
    }

    private function strokeColor(float $red, float $green, float $blue): void
    {
        $this->content .= sprintf("%.3F %.3F %.3F RG\n", $red, $green, $blue);
    }

    private function escape(string $value): string
    {
        $ascii = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $ascii === false ? $value : $ascii);
    }

    private function serializePdf(): string
    {
        ksort($this->objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];

        foreach ($this->objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($this->objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($number = 1; $number < $count; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R /Info 8 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }
}
