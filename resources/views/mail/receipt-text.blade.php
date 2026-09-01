{{ $org['name'] }} — Donation Receipt

Thank you, {{ $txn->donor_name }}.
We've received your donation of {{ $txn->amount_formatted }}.
Your receipt is attached as a PDF.

Receipt No.: {{ $txn->receipt_no }}
Date: {{ optional($txn->paid_at ?? $txn->created_at)->timezone('Asia/Kolkata')->format('d M Y, g:i a') }} IST
@if ($txn->purpose)Purpose: {{ $txn->purpose }}
@endif
Donation type: {{ $txn->type === 'subscription' ? 'Recurring donation' : 'One-time donation' }}
Payment method: {{ $txn->method ?: $txn->gateway_label }}

View in your dashboard: {{ route('transactions.show', $txn) }}

{{ $org['name'] }}@if ($org['address']) | {{ $org['address'] }}@endif

@if ($org['phone']){{ $org['phone'] }} | @endif{{ $org['email'] }}
This is a computer-generated receipt and needs no signature.
