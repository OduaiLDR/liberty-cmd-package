<?php

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Renders one Lending Tower invoice to PDF bytes.
 *
 * Purely presentational: every figure arrives already formatted by the command, so the page can
 * only show numbers the command itself printed. The layout is built from tables because dompdf
 * implements CSS 2.1 and has no flexbox or grid.
 *
 * It follows the house style of the branded PDFs the web app already produces (incentive letters:
 * logo top left, a rule beneath it, a solid table-header band with white text, zebra rows), in
 * Lending Tower's colours as measured from the official wordmark.
 *
 * Expected $invoice keys (all strings unless noted):
 *   number, issue_date, due_date, terms, period
 *   from:     {name, lines: list<string>, email}
 *   bill_to:  {name, lines: list<string>}
 *   columns:  {quantity, rate}                 header labels for the two middle columns
 *   lines:    list<{description, detail, quantity, rate, amount}>
 *   totals:   list<{label, amount, detail?}>   rows above the total due; may be empty
 *   total_due
 *   notes:    list<string>
 *   logo_data_uri: ?string                     data:image/...;base64,... or null for a text wordmark
 */
final class InvoicePdf
{
    /** Slate of "LENDING" in the wordmark. */
    private const BRAND_DARK = '#2f383d';

    /** Green of "TOWER" and the circle in the wordmark. */
    private const BRAND_GREEN = '#bcdb90';

    public function __construct(private readonly string $workDir)
    {
    }

    /** @param array<string, mixed> $invoice */
    public function render(array $invoice): string
    {
        $dompdf = $this->dompdf();
        $dompdf->loadHtml($this->html($invoice), 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $pdf = $dompdf->output();

        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('dompdf did not produce a PDF.');
        }

        return $pdf;
    }

    /** @param array<string, mixed> $invoice */
    public function html(array $invoice): string
    {
        $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $dark = self::BRAND_DARK;
        $green = self::BRAND_GREEN;

        $from = $invoice['from'];
        $billTo = $invoice['bill_to'];

        $brand = empty($invoice['logo_data_uri'])
            ? '<div class="wordmark">' . $e(strtoupper($from['name'])) . '</div>'
            : '<img class="logo" src="' . $e($invoice['logo_data_uri']) . '" alt="' . $e($from['name']) . '">';

        $fromLines = implode('<br>', array_map($e, $from['lines'] ?? []));
        $billToLines = implode('<br>', array_map($e, $billTo['lines'] ?? []));

        $meta = '';
        foreach ([
            'Invoice no.' => $invoice['number'],
            'Invoice date' => $invoice['issue_date'],
            'Due date' => $invoice['due_date'],
            'Terms' => $invoice['terms'],
        ] as $label => $value) {
            $meta .= '<tr><td class="meta-label">' . $e($label) . '</td><td class="meta-value">' . $e($value) . '</td></tr>';
        }

        $rows = '';
        // Widths repeat the header band's, which is a separate table so its background can be one piece.
        foreach ($invoice['lines'] as $line) {
            $rows .= '<tr>'
                . '<td style="width: 50%;"><div class="item">' . $e($line['description']) . '</div>'
                . ($line['detail'] !== '' ? '<div class="detail">' . $e($line['detail']) . '</div>' : '')
                . '</td>'
                . '<td class="num" style="width: 20%;">' . $e($line['quantity']) . '</td>'
                . '<td class="num" style="width: 12%;">' . $e($line['rate']) . '</td>'
                . '<td class="num" style="width: 18%;">' . $e($line['amount']) . '</td>'
                . '</tr>';
        }

        $totals = '';
        foreach ($invoice['totals'] as $total) {
            $totals .= '<tr><td class="total-label totals-label">' . $e($total['label'])
                . (! empty($total['detail']) ? '<div class="detail">' . $e($total['detail']) . '</div>' : '')
                . '</td><td class="num total-amount">' . $e($total['amount']) . '</td></tr>';
        }

        $notes = '';
        foreach ($invoice['notes'] as $note) {
            $notes .= '<p>' . $e($note) . '</p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice {$e($invoice['number'])}</title>
<style>
    @page { margin: 46px 54px 54px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: {$dark}; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .wordmark { font-size: 19pt; font-weight: bold; letter-spacing: 2px; color: {$dark}; }
    .logo { width: 200px; }
    .title { font-size: 26pt; font-weight: bold; letter-spacing: 3px; color: {$dark}; text-align: right; }
    .meta { margin-top: 10px; }
    .meta td { padding: 2px 0; font-size: 9.5pt; }
    .meta-label { color: #6b7378; text-align: right; padding-right: 14px; width: 58%; }
    .meta-value { text-align: right; font-weight: bold; white-space: nowrap; }
    .rule { border-top: 3px solid {$green}; margin-top: 16px; }
    .label { font-size: 7.5pt; font-weight: bold; letter-spacing: 1.5px; color: #6b7378; text-transform: uppercase; margin-bottom: 5px; }
    .party { font-size: 9.5pt; color: #4a5358; }
    .party strong { font-size: 11pt; color: {$dark}; }
    /* Bands are one background behind transparent cells. Painting each cell separately leaves
       hairline seams at the joins in most PDF viewers. */
    .band { background: {$dark}; margin-top: 28px; }
    .band th { color: #ffffff; font-size: 7.5pt; font-weight: bold; letter-spacing: 1.2px;
               text-transform: uppercase; text-align: left; padding: 8px; }
    .items td { padding: 11px 8px; border-bottom: 1px solid #e3e7e1; }
    .band th.num, .num { text-align: right; white-space: nowrap; }
    .item { font-weight: bold; font-size: 10.5pt; }
    .detail { font-size: 8.5pt; color: #6b7378; margin-top: 3px; font-weight: normal; }
    .totals { margin-top: 4px; }
    .totals td { padding: 7px 8px; }
    .total-label { color: #4a5358; }
    .total-amount { font-size: 10.5pt; }
    .due { background: {$green}; margin-top: 2px; }
    .due td { color: {$dark}; font-size: 13.5pt; font-weight: bold; padding: 10px 8px; }
    .totals-label { width: 62%; }
    .notes { margin-top: 40px; padding-top: 10px; border-top: 1px solid #e3e7e1; font-size: 8.5pt; color: #6b7378; }
    .notes p { margin: 0 0 4px; }
</style>
</head>
<body>

<table>
    <tr>
        <td style="width: 55%;">{$brand}</td>
        <td style="width: 45%;">
            <div class="title">INVOICE</div>
            <table class="meta">{$meta}</table>
        </td>
    </tr>
</table>

<div class="rule"></div>

<table style="margin-top: 18px;">
    <tr>
        <td style="width: 36%;">
            <div class="label">From</div>
            <div class="party"><strong>{$e($from['name'])}</strong><br>{$fromLines}<br>{$e($from['email'])}</div>
        </td>
        <td style="width: 36%;">
            <div class="label">Bill to</div>
            <div class="party"><strong>{$e($billTo['name'])}</strong><br>{$billToLines}</div>
        </td>
        <td style="width: 28%; text-align: right;">
            <div class="label">Billing period</div>
            <div class="party"><strong>{$e($invoice['period'])}</strong></div>
        </td>
    </tr>
</table>

<div class="band">
    <table>
        <tr>
            <th style="width: 50%;">Description</th>
            <th class="num" style="width: 20%;">{$e($invoice['columns']['quantity'])}</th>
            <th class="num" style="width: 12%;">{$e($invoice['columns']['rate'])}</th>
            <th class="num" style="width: 18%;">Amount</th>
        </tr>
    </table>
</div>
<table class="items">{$rows}</table>

<table class="totals">
    <tr>
        <td style="width: 42%;"></td>
        <td style="width: 58%; padding: 0;">
            <table>
                {$totals}
            </table>
            <div class="due">
                <table><tr><td class="totals-label">Total due</td><td class="num">{$e($invoice['total_due'])}</td></tr></table>
            </div>
        </td>
    </tr>
</table>

<div class="notes">{$notes}</div>

</body>
</html>
HTML;
    }

    private function dompdf(): Dompdf
    {
        if (! is_dir($this->workDir) && ! @mkdir($this->workDir, 0775, true) && ! is_dir($this->workDir)) {
            throw new RuntimeException("Cannot create the PDF work directory {$this->workDir}.");
        }

        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('tempDir', $this->workDir);
        $options->set('fontCache', $this->workDir);

        return new Dompdf($options);
    }
}
