<?php
/**
 * Shared letterhead rendering for proposal_print.php / invoice_print.php.
 * One template_style setting drives three distinct visual skins from the
 * same data — logo, colours, and footer text all come from Settings, so
 * "customising the template" never means editing PHP.
 */

const PRINT_TEMPLATE_STYLES = ['classic', 'modern', 'minimal'];

function print_template_style(array $s): string
{
    $style = $s['template_style'] ?? 'classic';
    return in_array($style, PRINT_TEMPLATE_STYLES, true) ? $style : 'classic';
}

/** CSS shared by all three skins, plus the per-skin overrides for the
 *  letterhead band itself. Callers still set --brand-primary/--brand-accent. */
function print_template_css(string $style): string
{
    $shared = '
      *{box-sizing:border-box}
      body{font-family:"Segoe UI",system-ui,sans-serif;color:#1A2540;max-width:760px;margin:0 auto;padding:40px 30px}
      .doc-title{font-size:1.1rem;font-weight:800;color:var(--brand-primary);margin-bottom:4px}
      .pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;background:#E9EEF7;color:var(--brand-primary);text-transform:capitalize}
      .grid2{display:flex;justify-content:space-between;gap:24px;margin:20px 0;font-size:.84rem;flex-wrap:wrap}
      table{width:100%;border-collapse:collapse;margin-top:10px;font-size:.86rem}
      th{text-align:left;background:#F4F6FA;padding:10px;font-size:.72rem;text-transform:uppercase;color:#5A6B87}
      td{padding:10px;border-bottom:1px solid #E2E8F0}
      tfoot td{font-weight:800;color:var(--brand-primary);border-top:2px solid var(--brand-primary);border-bottom:none}
      .totals{width:280px;margin-left:auto;margin-top:12px;font-size:.88rem}
      .totals div{display:flex;justify-content:space-between;padding:6px 0}
      .totals .grand{font-weight:800;color:var(--brand-primary);border-top:2px solid var(--brand-primary);font-size:1rem;padding-top:10px}
      .bank-box{margin-top:30px;background:#F4F6FA;border-radius:10px;padding:16px 18px;font-size:.82rem}
      .bank-box strong{color:var(--brand-primary)}
      .footer-note{margin-top:28px;font-size:.8rem;color:#5A6B87;white-space:pre-wrap;border-top:1px solid #E2E8F0;padding-top:14px}
      .intro{margin:16px 0;line-height:1.6;font-size:.88rem;white-space:pre-wrap}
      .print-bar{margin-top:30px;text-align:center}
      .print-bar button{padding:10px 20px;border-radius:8px;border:none;background:var(--brand-accent);font-weight:700;cursor:pointer}
      @media print{.print-bar{display:none}}
      @media(max-width:640px){
        body{padding:24px 16px}
        .grid2{flex-direction:column;gap:10px}
        .grid2>div{text-align:left!important}
        table,thead,tbody,tfoot{display:block;width:100%}
        thead{display:none}
        tr{display:block;margin-bottom:12px;border:1px solid #E2E8F0;border-radius:8px;padding:6px}
        td{display:flex;justify-content:space-between;gap:10px;border-bottom:1px solid #F4F6FA;text-align:right}
        td:first-child{font-weight:700;text-align:left}
        td:before{content:attr(data-label);font-weight:700;color:#5A6B87;text-align:left}
        tfoot tr{border:none}
        .totals{width:100%}
      }
    ';

    $letterheads = [
        'classic' => '
          .letterhead{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:4px solid var(--brand-accent);padding-bottom:18px;margin-bottom:26px;flex-wrap:wrap}
          .letterhead h1{color:var(--brand-primary);font-size:1.4rem;margin-bottom:2px}
          .letterhead .meta{text-align:right;font-size:.8rem;color:#5A6B87;line-height:1.5}
          .letterhead .logo{max-height:56px;max-width:180px;object-fit:contain}
          @media(max-width:640px){.letterhead{flex-direction:column}.letterhead .meta{text-align:left}}
        ',
        'modern' => '
          body{padding-top:0}
          .letterhead{display:flex;justify-content:space-between;align-items:center;gap:16px;background:var(--brand-primary);color:#fff;margin:0 -30px 26px;padding:26px 30px;flex-wrap:wrap}
          .letterhead h1{color:#fff;font-size:1.5rem;margin-bottom:2px}
          .letterhead .meta{text-align:right;font-size:.8rem;color:rgba(255,255,255,.75);line-height:1.5}
          .letterhead .logo{max-height:60px;max-width:180px;object-fit:contain;background:#fff;border-radius:8px;padding:6px}
          .doc-title{border-left:4px solid var(--brand-accent);padding-left:10px}
          @media(max-width:640px){.letterhead{margin:0 -16px 20px;padding:20px 16px;flex-direction:column;align-items:flex-start}.letterhead .meta{text-align:left}}
        ',
        'minimal' => '
          .letterhead{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:1px solid #E2E8F0;padding-bottom:16px;margin-bottom:26px;flex-wrap:wrap}
          .letterhead h1{color:#1A2540;font-size:1.25rem;font-weight:600;margin-bottom:2px}
          .letterhead .meta{text-align:right;font-size:.78rem;color:#5A6B87;line-height:1.5}
          .letterhead .logo{max-height:48px;max-width:160px;object-fit:contain}
          .doc-title{text-transform:uppercase;letter-spacing:.06em;font-size:.95rem;border-bottom:2px solid var(--brand-accent);display:inline-block;padding-bottom:4px}
          @media(max-width:640px){.letterhead{flex-direction:column}.letterhead .meta{text-align:left}}
        ',
    ];

    return $shared . ($letterheads[$style] ?? $letterheads['classic']);
}

function render_letterhead(array $s): string
{
    // proposal_print.php / invoice_print.php live at the app root, same as
    // the stored company_logo path (e.g. "assets/branding/logo-xxx.png") —
    // no path prefix needed.
    $logo = !empty($s['company_logo']) ? htmlspecialchars($s['company_logo']) : null;
    $name = htmlspecialchars($s['company_name'] ?? 'Elevate SJC');
    $tagline = htmlspecialchars($s['tagline'] ?? '');
    $address = htmlspecialchars($s['company_address'] ?? '');
    $phone = htmlspecialchars($s['company_phone'] ?? '');
    $email = htmlspecialchars($s['company_email'] ?? '');
    $vat = htmlspecialchars($s['vat_number'] ?? '');

    $logoHtml = $logo ? "<img class=\"logo\" src=\"{$logo}\" alt=\"{$name} logo\"/>" : "<h1>{$name}</h1>";
    $vatLine = $vat !== '' ? "<br/>VAT No: {$vat}" : '';

    return <<<HTML
      <div class="letterhead">
        <div>
          {$logoHtml}
          <div style="font-size:.78rem;opacity:.85">{$tagline}</div>
        </div>
        <div class="meta">
          {$address}<br/>
          {$phone} · {$email}
          {$vatLine}
        </div>
      </div>
    HTML;
}

function render_footer_note(?string $note): string
{
    if (!$note || trim($note) === '') {
        return '';
    }
    return '<div class="footer-note">' . nl2br(htmlspecialchars($note)) . '</div>';
}
