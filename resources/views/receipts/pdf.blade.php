{{-- Tables and inline styles throughout: this is printed in-process by Dompdf,
     and kept deliberately close to the emailed receipt so a donor sees one
     consistent document.

     DejaVu Sans leads every font stack because it is the only bundled font
     carrying U+20B9, the rupee sign. The PDF core fonts (Helvetica et al) are
     WinAnsi-encoded and render it as "?" — which on an Indian donation receipt
     means the amount is unreadable. Browsers previewing this HTML will not have
     DejaVu Sans and fall through to Helvetica, which is fine there. --}}
@php
  $brand = $org['brand_color'];
  // Darken the brand colour until small text clears WCAG AA on white; a logo
  // colour is often too light at 12px even when fine for a large figure.
  $rgb = sscanf($brand, "#%02x%02x%02x");
  $contrast = function ($c) {
      [$r, $g, $b] = array_map(fn ($v) => $v / 255, $c);
      $f = fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
      return 1.05 / (0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b) + 0.05);
  };
  for ($i = 0; $i < 40 && $contrast($rgb) < 4.5; $i++) {
      $rgb = array_map(fn ($v) => max(0, (int) round($v * 0.92)), $rgb);
  }
  $linkColor = sprintf('#%02x%02x%02x', ...$rgb);

  $rows = array_filter([
    'Receipt No.' => $txn->receipt_no,
    'Date' => optional($txn->paid_at ?? $txn->created_at)->timezone('Asia/Kolkata')->format('d M Y, g:i a').' IST',
    'Purpose' => $txn->purpose,
    'Donation type' => $txn->type === 'subscription' ? 'Recurring donation' : 'One-time donation',
    'Payment method' => $txn->method ?: $txn->gateway_label,
    'Payment ID' => $txn->gateway_payment_id,
    'Donor' => $txn->donor_name,
    'Email' => $txn->donor_email,
    'Phone' => $txn->donor_phone,
    'Address' => $txn->donor_address,
    'Donor PAN' => $txn->donor_pan,
  ]);

  $legal = array_filter([
    'PAN' => $org['pan'],
    '80G Registration' => $org['registration_80g'],
  ]);
@endphp
<!doctype html>
<html><head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#ffffff;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:100%;background:#ffffff;">

  <tr><td style="padding:0 0 20px;border-bottom:1px solid #d0d7de;">
    @if ($org['logo_url'])
      <img src="{{ $org['logo_url'] }}" alt="{{ $org['name'] }}"
           style="max-height:72px;max-width:240px;display:block;border:0;">
    @else
      <div style="font:600 20px/1.2 'DejaVu Sans',Helvetica,Arial,sans-serif;color:{{ $brand }};">{{ $org['name'] }}</div>
    @endif
  </td></tr>

  <tr><td style="padding:24px 0 8px;">
    <div style="font:700 13px/1.3 'DejaVu Sans',Helvetica,Arial,sans-serif;color:#57606a;letter-spacing:.08em;text-transform:uppercase;margin-bottom:14px;">Donation Receipt</div>
    <div style="font:600 19px/1.3 'DejaVu Sans',Helvetica,Arial,sans-serif;color:#1f2328;">Thank you, {{ $txn->donor_name }}.</div>
    <div style="margin-top:8px;font:15px/1.6 'DejaVu Sans',Helvetica,Arial,sans-serif;color:#57606a;">
      We've received your donation of
      <strong style="color:#1f2328;">{{ $txn->amount_formatted }}</strong>. This document is your receipt.
    </div>
  </td></tr>

  <tr><td style="padding:16px 0 4px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="background:#f6f8fa;border:1px solid #d0d7de;border-radius:8px;padding:16px 18px;">
      <tr><td>
        <div style="font:600 11px/1.4 'DejaVu Sans',Helvetica,Arial,sans-serif;color:#57606a;letter-spacing:.06em;text-transform:uppercase;">Amount received</div>
        <div style="margin-top:4px;font:700 28px/1.2 'DejaVu Sans',Helvetica,Arial,sans-serif;color:{{ $brand }};">{{ $txn->amount_formatted }}</div>
      </td></tr>
    </table>
  </td></tr>

  <tr><td style="padding:20px 0 8px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
      @foreach ($rows as $label => $value)
        <tr>
          <td style="padding:7px 0;color:#57606a;font:14px/1.5 'DejaVu Sans',Helvetica,Arial,sans-serif;">{{ $label }}</td>
          <td style="padding:7px 0;color:#1f2328;font:600 14px/1.5 'DejaVu Sans',Helvetica,Arial,sans-serif;text-align:right;">{{ $value }}</td>
        </tr>
      @endforeach
    </table>
  </td></tr>

  <tr><td style="padding:16px 0 0;">
    @if ($legal)
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
             style="border-top:1px solid #d0d7de;padding-top:16px;">
        @foreach ($legal as $label => $value)
          <tr>
            <td style="padding:7px 0;color:#57606a;font:14px/1.5 'DejaVu Sans',Helvetica,Arial,sans-serif;">{{ $label }}</td>
            <td style="padding:7px 0;color:#1f2328;font:600 14px/1.5 'DejaVu Sans',Helvetica,Arial,sans-serif;text-align:right;">{{ $value }}</td>
          </tr>
        @endforeach
      </table>
    @endif
    <div style="margin-top:14px;font:12px/1.6 'DejaVu Sans',Helvetica,Arial,sans-serif;color:#57606a;">
      {{ $org['name'] }}@if ($org['address']) · {{ $org['address'] }}@endif<br>
      @if ($org['phone']){{ $org['phone'] }} · @endif
      <a href="mailto:{{ $org['email'] }}" style="color:{{ $linkColor }};">{{ $org['email'] }}</a>
      @if ($org['website']) · <a href="{{ $org['website'] }}" style="color:{{ $linkColor }};">{{ $org['website'] }}</a>@endif
    </div>
    <div style="margin-top:12px;font:11px/1.5 'DejaVu Sans',Helvetica,Arial,sans-serif;color:#8c959f;">
      This is a computer-generated receipt and needs no signature.
    </div>
  </td></tr>

</table>
</td></tr>
</table>
</body></html>
