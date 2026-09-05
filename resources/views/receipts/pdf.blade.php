{{-- Tables and inline styles throughout: this is printed in-process by Dompdf,
     which lays out tables reliably and supports neither flexbox nor grid.

     DejaVu Sans leads every font stack because it is the only bundled font
     carrying U+20B9, the rupee sign. The PDF core fonts (Helvetica et al) are
     WinAnsi-encoded and render it as "?" — which on an Indian donation receipt
     means the amount is unreadable. Browsers previewing this HTML will not have
     DejaVu Sans and fall through to Helvetica, which is fine there.

     Structure: issuer at the top, donor and donation in the body, the
     no-signature declaration at the foot — the order a receipt is read in. --}}
@php
  // Emitted with {!! !!}: this is a fixed literal defined right here, and
  // escaping it turns the quotes into &#039; in every style attribute.
  $font = "'DejaVu Sans',Helvetica,Arial,sans-serif";
  $brand = $org['brand_color'];

  // Darken the brand colour until small text clears WCAG AA on white; a logo
  // colour is often too light at 9px even when fine for a large figure.
  $rgb = sscanf($brand, "#%02x%02x%02x");
  $contrast = function ($c) {
      [$r, $g, $b] = array_map(fn ($v) => $v / 255, $c);
      $f = fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
      return 1.05 / (0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b) + 0.05);
  };
  for ($i = 0; $i < 40 && $contrast($rgb) < 4.5; $i++) {
      $rgb = array_map(fn ($v) => max(0, (int) round($v * 0.92)), $rgb);
  }
  $ink = sprintf('#%02x%02x%02x', ...$rgb);

  // Everything identifying the issuing organisation.
  $issuer = array_filter([
    'Phone' => $org['phone'],
    'Email' => $org['email'],
    'Website' => $org['website'],
    'PAN' => $org['pan'],
    '80G Registration' => $org['registration_80g'],
  ]);

  // Everything identifying the donor.
  $donor = array_filter([
    'Name' => $txn->donor_name,
    'Email' => $txn->donor_email,
    'Phone' => $txn->donor_phone,
    'Address' => $txn->donor_address,
    'PAN' => $txn->donor_pan,
  ]);

  // Everything identifying the donation itself.
  $donation = array_filter([
    'Purpose' => $txn->purpose,
    'Donation type' => $txn->type === 'subscription' ? 'Recurring donation' : 'One-time donation',
    'Payment method' => $txn->method ?: $txn->gateway_label,
    'Payment ID' => $txn->gateway_payment_id,
  ]);

  $issuedAt = optional($txn->paid_at ?? $txn->created_at)
      ->timezone('Asia/Kolkata')->format('d M Y, g:i a').' IST';

  $label = "padding:3px 0;color:#57606a;font:9px/1.5 {$font};vertical-align:top;width:120px;";
  $value = "padding:3px 0;color:#1f2328;font:600 9px/1.5 {$font};vertical-align:top;";
  $heading = "font:700 8px/1.4 {$font};color:#57606a;letter-spacing:.09em;text-transform:uppercase;";
@endphp
<!doctype html>
<html><head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#ffffff;color:#1f2328;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;">

  {{-- ── Header: who issued this ─────────────────────────────────────────── --}}
  <tr><td style="padding:0 0 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="width:44%;vertical-align:top;padding-right:16px;">
          @if ($org['logo_url'])
            <img src="{{ $org['logo_url'] }}" alt="{{ $org['name'] }}"
                 style="max-height:52px;max-width:170px;display:block;border:0;margin-bottom:8px;">
          @endif
          <div style="font:700 12px/1.35 {!! $font !!};color:{{ $brand }};">{{ $org['name'] }}</div>
          @if ($org['address'])
            <div style="margin-top:3px;font:9px/1.5 {!! $font !!};color:#57606a;">{{ $org['address'] }}</div>
          @endif
        </td>
        <td style="width:56%;vertical-align:top;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            @foreach ($issuer as $key => $val)
              <tr>
                <td style="padding:2px 0;color:#57606a;font:9px/1.5 {!! $font !!};width:110px;">{{ $key }}</td>
                <td style="padding:2px 0;color:#1f2328;font:600 9px/1.5 {!! $font !!};text-align:right;">{{ $val }}</td>
              </tr>
            @endforeach
          </table>
        </td>
      </tr>
    </table>
  </td></tr>

  {{-- ── Title strip: what this document is, and its number ───────────────── --}}
  <tr><td style="padding:10px 0;border-top:2px solid {{ $brand }};border-bottom:1px solid #d0d7de;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="vertical-align:middle;">
          <div style="font:700 12px/1.3 {!! $font !!};color:#1f2328;letter-spacing:.08em;text-transform:uppercase;">Donation Receipt</div>
        </td>
        <td style="vertical-align:middle;text-align:right;">
          <div style="font:9px/1.5 {!! $font !!};color:#57606a;">
            Receipt No. <strong style="color:#1f2328;">{{ $txn->receipt_no ?: $txn->reference }}</strong>
          </div>
          <div style="font:9px/1.5 {!! $font !!};color:#57606a;">Dated {{ $issuedAt }}</div>
        </td>
      </tr>
    </table>
  </td></tr>

  {{-- ── Amount ──────────────────────────────────────────────────────────── --}}
  <tr><td style="padding:14px 0 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="background:#f6f8fa;border:1px solid #d0d7de;border-radius:4px;">
      <tr>
        <td style="padding:10px 14px;vertical-align:middle;">
          <div style="{!! $heading !!}">Amount received</div>
          <div style="margin-top:2px;font:700 20px/1.25 {!! $font !!};color:{{ $brand }};">{{ $txn->amount_formatted }}</div>
        </td>
        <td style="padding:10px 14px;vertical-align:middle;text-align:right;font:9px/1.6 {!! $font !!};color:#57606a;">
          Received with gratitude from<br>
          <strong style="color:#1f2328;font-size:10px;">{{ $txn->donor_name }}</strong>
        </td>
      </tr>
    </table>
  </td></tr>

  {{-- ── Body: the donor ─────────────────────────────────────────────────── --}}
  <tr><td style="padding:18px 0 0;">
    <div style="{!! $heading !!} padding-bottom:5px;border-bottom:1px solid #d0d7de;">Donor details</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;">
      @foreach ($donor as $key => $val)
        <tr>
          <td style="{!! $label !!}">{{ $key }}</td>
          <td style="{!! $value !!}">{{ $val }}</td>
        </tr>
      @endforeach
    </table>
  </td></tr>

  {{-- ── Body: the donation ──────────────────────────────────────────────── --}}
  <tr><td style="padding:16px 0 0;">
    <div style="{!! $heading !!} padding-bottom:5px;border-bottom:1px solid #d0d7de;">Donation details</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;">
      @foreach ($donation as $key => $val)
        <tr>
          <td style="{!! $label !!}">{{ $key }}</td>
          <td style="{!! $value !!}">{{ $val }}</td>
        </tr>
      @endforeach
      <tr>
        <td style="{!! $label !!}">Amount</td>
        <td style="{!! $value !!}">{{ $txn->amount_formatted }}</td>
      </tr>
    </table>
  </td></tr>

  {{-- ── Footer: the declaration ─────────────────────────────────────────── --}}
  <tr><td style="padding:22px 0 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="border-top:1px solid #d0d7de;">
      <tr><td style="padding:10px 0 0;">
        <div style="font:600 9px/1.6 {!! $font !!};color:#1f2328;">
          This is a digitally generated receipt and does not require a signature.
        </div>
        <div style="margin-top:4px;font:8px/1.6 {!! $font !!};color:#8c959f;">
          Issued electronically by {{ $org['name'] }}@if ($org['pan']), PAN {{ $org['pan'] }}@endif.
          @if ($org['registration_80g'])
            Donations are eligible for deduction under Section 80G of the Income Tax Act, 1961,
            vide registration {{ $org['registration_80g'] }}.
          @endif
          Please retain this receipt for your records.
        </div>
        @if ($org['email'] || $org['website'])
          <div style="margin-top:6px;font:8px/1.6 {!! $font !!};color:#57606a;">
            Queries:
            <a href="mailto:{{ $org['email'] }}" style="color:{{ $ink }};">{{ $org['email'] }}</a>
            @if ($org['website'])
              · <a href="{{ $org['website'] }}" style="color:{{ $ink }};">{{ $org['website'] }}</a>
            @endif
          </div>
        @endif
      </td></tr>
    </table>
  </td></tr>

</table>
</body></html>
