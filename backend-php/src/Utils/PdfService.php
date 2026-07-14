<?php
declare(strict_types=1);

namespace App\Utils;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PdfService
 * Server-side DomPDF template compiler. Maps {{First_Name}}, {{Company}},
 * {{Ticket_Type}} and a base64 QR string into a markup template, then renders
 * a compact badge/ticket PDF. (Blueprint: src/Utils/dompdf/.)
 */
final class PdfService
{
    private const FORMATS = [
        // [width_mm, height_mm]
        'cr80'    => [54, 85.6],   // plastic badge
        'thermal' => [101.6, 152.4],
        'lanyard' => [100, 140],
        'a4'      => [210, 297],
    ];

    /** Inject variables into a template string. */
    public static function bind(string $template, array $vars): string
    {
        foreach ($vars as $key => $val) {
            $template = str_replace('{{' . $key . '}}', (string) $val, $template);
        }
        return $template;
    }

    /**
     * Render a badge PDF. Returns the absolute file path of the saved PDF.
     */
    public static function renderBadge(array $vars, string $qrToken, string $format = 'cr80', ?string $outDir = null): string
    {
        $size = self::FORMATS[$format] ?? self::FORMATS['cr80'];
        $tplPath = __DIR__ . '/../../templates/badge.html';
        $template = is_file($tplPath) ? file_get_contents($tplPath) : self::fallbackTemplate();

        $vars['QR_DATA_URI'] = QrService::dataUri($qrToken);
        $html = self::bind($template, $vars);

        $options = new Options();
        $options->set('isRemoteEnabled', true);   // allow embedded data URIs / fonts
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        // DomPDF wants points: 1mm ≈ 2.8346 pt
        $dompdf->setPaper([0, 0, $size[0] * 2.8346, $size[1] * 2.8346]);
        $dompdf->render();

        $outDir ??= sys_get_temp_dir();
        if (!is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }
        $file = rtrim($outDir, '/') . '/badge_' . ($vars['REG_ID'] ?? uniqid()) . '.pdf';
        file_put_contents($file, $dompdf->output());
        return $file;
    }

    /**
     * Render a full-page A4 e-ticket (GoWild-style) and return the file path.
     * The QR encodes the same signed JWT used everywhere else, so the staff
     * scanner reads it identically to the compact badge.
     */
    public static function renderTicket(array $vars, string $qrToken, ?string $outDir = null, ?string $filename = null): string
    {
        $tplPath = __DIR__ . '/../../templates/ticket.html';
        $template = is_file($tplPath) ? file_get_contents($tplPath) : self::fallbackTemplate();

        $vars['QR_DATA_URI'] = QrService::dataUri($qrToken);
        $vars += [
            'Status_Label' => '',
            'Age_Notice'   => 'To enter the event, you must be at least 18 years old.',
            'Price'        => '—',
        ];
        $html = self::bind($template, $vars);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $outDir ??= sys_get_temp_dir();
        if (!is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }

        // Filename matters because PHPMailer uses the file's basename when
        // attaching it to outgoing emails. Caller passes a friendly name like
        // "Folie-Electronic-Ticket-42.pdf" so guests see that in Gmail.
        $name = $filename ?: ('ticket_' . ($vars['REG_ID'] ?? uniqid()) . '.pdf');
        $file = rtrim($outDir, '/') . '/' . $name;
        file_put_contents($file, $dompdf->output());
        return $file;
    }

    /**
     * Render a landscape A4 certificate of attendance. Returns the file path.
     */
    public static function renderCertificate(array $vars, ?string $outDir = null): string
    {
        $tplPath = __DIR__ . '/../../templates/certificate.html';
        $template = is_file($tplPath) ? file_get_contents($tplPath) : '<h1>{{Attendee_Name}} — {{Event_Title}}</h1>';
        $html = self::bind($template, $vars);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $outDir ??= sys_get_temp_dir();
        if (!is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }
        $file = rtrim($outDir, '/') . '/certificate_' . ($vars['REG_ID'] ?? uniqid()) . '.pdf';
        file_put_contents($file, $dompdf->output());
        return $file;
    }

    /**
     * Render a tabular registrations report PDF (A4 portrait). Returns its bytes.
     */
    public static function renderReport(string $eventTitle, array $headers, array $rows, ?array $summary = null): string
    {
        $head = '';
        foreach ($headers as $h) {
            $head .= '<th>' . htmlspecialchars((string) $h) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($row as $cell) {
                $body .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
            }
            $body .= '</tr>';
        }

        $count = count($rows);
        $date  = date('Y-m-d H:i');
        $title = htmlspecialchars($eventTitle);

        $summaryBlock = '';
        if ($summary) {
            $cap = ($summary['capacity'] ?? 0) > 0 ? (int) $summary['capacity'] : 'Unlimited';
            $summaryBlock = '<table class="summary">'
                . '<tr>'
                . '<td><b>Total</b><br>' . (int) ($summary['total'] ?? 0) . '</td>'
                . '<td><b>Checked in</b><br>' . (int) ($summary['checked_in'] ?? 0) . '</td>'
                . '<td><b>Pending</b><br>' . (int) ($summary['pending'] ?? 0) . '</td>'
                . '<td><b>Attendance</b><br>' . (float) ($summary['attendance'] ?? 0) . '%</td>'
                . '<td><b>Capacity</b><br>' . $cap . '</td>'
                . '</tr></table>';
        }

        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
  body { font-family:'DejaVu Sans',sans-serif; font-size:10px; color:#1a1d23; }
  .head { border-bottom:3px solid #EC2024; padding-bottom:10px; margin-bottom:14px; }
  .head h1 { font-size:18px; margin:0 0 2px; }
  .head .meta { font-size:11px; color:#666; }
  .summary { width:100%; border-collapse:collapse; margin-bottom:16px;
             background:#fafafa; border:1px solid #e5e7eb; }
  .summary td { padding:10px 12px; border-right:1px solid #e5e7eb; font-size:11px;
                width:20%; }
  .summary td:last-child { border-right:none; }
  .summary b { font-size:9px; color:#666; text-transform:uppercase; letter-spacing:.5px; }
  table.data { width:100%; border-collapse:collapse; }
  table.data th { background:#EC2024; color:#fff; text-align:left; padding:6px 7px; font-size:9px; }
  table.data td { padding:5px 7px; border-bottom:1px solid #e5e7eb; }
  table.data tr:nth-child(even) td { background:#fafafa; }
</style></head><body>
  <div class="head">
    <h1>Registrations Report — {$title}</h1>
    <div class="meta">{$count} registrations · Generated {$date}</div>
  </div>
  {$summaryBlock}
  <table class="data"><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>
</body></html>
HTML;

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        return $dompdf->output();
    }

    private static function fallbackTemplate(): string
    {
        return <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<style>
  body{margin:0;font-family:'DejaVu Sans',sans-serif;}
  .badge{text-align:center;padding:10px;}
  .name{font-size:18px;font-weight:bold;margin-top:6px;}
  .meta{font-size:11px;color:#444;}
  .tier{display:inline-block;margin-top:4px;padding:2px 8px;border-radius:8px;
        background:#EC2024;color:#fff;font-size:10px;}
  img{width:120px;height:120px;}
</style></head><body>
  <div class="badge">
    <div class="meta">{{Event_Title}}</div>
    <div class="name">{{First_Name}} {{Last_Name}}</div>
    <div class="meta">{{Company}}</div>
    <span class="tier">{{Ticket_Type}}</span>
    <div><img src="{{QR_DATA_URI}}" alt="QR"></div>
    <div class="meta">#{{REG_ID}}</div>
  </div>
</body></html>
HTML;
    }
}
