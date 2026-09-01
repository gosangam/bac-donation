{{-- Inline styles and tables: Outlook ignores <style> blocks and flex. --}}
@php
  $brand = $org['brand_color'];
  $rgb = sscanf($brand, "#%02x%02x%02x");
  $contrast = function ($c) {
      [$r, $g, $b] = array_map(fn ($v) => $v / 255, $c);
      $f = fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
      return 1.05 / (0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b) + 0.05);
  };
  // Brand colours picked for a logo are often too light for 12px body text.
  for ($i = 0; $i < 40 && $contrast($rgb) < 4.5; $i++) {
      $rgb = array_map(fn ($v) => max(0, (int) round($v * 0.92)), $rgb);
  }
  $linkColor = sprintf('#%02x%02x%02x', ...$rgb);
@endphp
<!doctype html>
<html><head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f6f8fa;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fa;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="max-width:600px;background:#ffffff;border:1px solid #d0d7de;border-radius:10px;overflow:hidden;">

  <tr><td style="padding:24px 28px;border-bottom:1px solid #d0d7de;">
    @if ($org['logo_url'])
      {{-- embed() attaches the image and returns a cid, so it renders even where
           remote images are blocked. --}}
      <img src="{{ $message->embed($org['logo_url']) }}" alt="{{ $org['name'] }}"
           style="max-height:72px;max-width:240px;display:block;border:0;">
    @else
      <div style="font:600 20px/1.2 Helvetica,Arial,sans-serif;color:{{ $brand }};">{{ $org['name'] }}</div>
    @endif
  </td></tr>

  <tr><td style="padding:28px 28px 8px;">
    <div style="font:600 19px/1.3 Helvetica,Arial,sans-serif;color:#1f2328;">Thank you, {{ $txn->donor_name }}.</div>
    <div style="margin-top:8px;font:15px/1.6 Helvetica,Arial,sans-serif;color:#57606a;">
      We've received your donation of <strong style="color:#1f2328;">{{ $txn->amount_formatted }}</strong>.
      Your receipt is attached as a PDF.
    </div>
  </td></tr>

  <tr><td style="padding:16px 28px 4px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="background:#f6f8fa;border:1px solid #d0d7de;border-radius:8px;padding:16px 18px;">
      <tr><td>
        <div style="font:600 11px/1.4 Helvetica,Arial,sans-serif;color:#57606a;letter-spacing:.06em;text-transform:uppercase;">Amount received</div>
        <div style="margin-top:4px;font:700 28px/1.2 Helvetica,Arial,sans-serif;color:{{ $brand }};">{{ $txn->amount_formatted }}</div>
      </td></tr>
    </table>
  </td></tr>

  <tr><td style="padding:20px 28px 8px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
      @php
        $rows = array_filter([
          'Receipt No.' => $txn->receipt_no,
          'Date' => optional($txn->paid_at ?? $txn->created_at)->timezone('Asia/Kolkata')->format('d M Y, g:i a').' IST',
          'Purpose' => $txn->purpose,
          'Donation type' => $txn->type === 'subscription' ? 'Recurring donation' : 'One-time donation',
          'Payment method' => $txn->method ?: $txn->gateway_label,
        ]);
      @endphp
      @foreach ($rows as $label => $value)
        <tr>
          <td style="padding:7px 0;color:#57606a;font:14px/1.5 Helvetica,Arial,sans-serif;">{{ $label }}</td>
          <td style="padding:7px 0;color:#1f2328;font:600 14px/1.5 Helvetica,Arial,sans-serif;text-align:right;">{{ $value }}</td>
        </tr>
      @endforeach
    </table>
  </td></tr>

  <tr><td style="padding:16px 28px 24px;">
    <a href="{{ route('transactions.show', $txn) }}"
       style="display:inline-block;background:{{ $brand }};color:#ffffff;text-decoration:none;
              padding:10px 18px;border-radius:8px;font:600 14px Helvetica,Arial,sans-serif;">
      View in your dashboard
    </a>
    <div style="margin-top:18px;font:12px/1.6 Helvetica,Arial,sans-serif;color:#57606a;border-top:1px solid #d0d7de;padding-top:16px;">
      {{ $org['name'] }}@if ($org['address']) · {{ $org['address'] }}@endif<br>
      @if ($org['phone']){{ $org['phone'] }} · @endif
      <a href="mailto:{{ $org['email'] }}" style="color:{{ $linkColor }};">{{ $org['email'] }}</a>
    </div>
    <div style="margin-top:12px;font:11px/1.5 Helvetica,Arial,sans-serif;color:#8c959f;">
      This is a computer-generated receipt and needs no signature.
    </div>
  </td></tr>

</table>
</td></tr>
</table>
</body></html>
