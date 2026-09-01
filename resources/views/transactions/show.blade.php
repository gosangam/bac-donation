@extends('layouts.app')
@section('title', 'Transaction')
@section('content')
  <div class="max-w-2xl">
    @auth
      <a href="{{ route('transactions.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← All transactions</a>
    @endauth

    <div class="bg-white rounded-xl border border-slate-200 mt-4">
      <div class="px-6 py-5 border-b border-slate-200 flex items-start justify-between gap-4">
        <div>
          <div class="text-2xl font-semibold text-slate-900">{{ $txn->amount_formatted }}</div>
          <div class="text-sm text-slate-500 mt-1">{{ $txn->purpose }}</div>
        </div>
        @include('partials.status-badge', ['status' => $txn->status])
      </div>

      <dl class="px-6 py-5 space-y-3 text-sm">
        @php
          $rows = [
            'Receipt no.' => $txn->receipt_no ?? 'Issued once payment clears',
            'Reference' => $txn->reference,
            'Date' => $txn->created_at->format('d M Y, g:i a'),
            'Paid at' => $txn->paid_at?->format('d M Y, g:i a') ?? '—',
            'Type' => $txn->type === 'subscription' ? 'Automatic renewal' : 'One-off donation',
            'Method' => $txn->method ?? $txn->gateway_label,
            'Gateway' => $txn->gateway_label,
            'Gateway payment id' => $txn->gateway_payment_id ?? '—',
            'Donor' => $txn->donor_name,
            'Email' => $txn->donor_email,
            'Phone' => $txn->donor_phone ?? '—',
            'Address' => $txn->donor_address ?? '—',
            'PAN' => $txn->donor_pan ?? '—',
          ];
        @endphp
        @foreach ($rows as $label => $value)
          <div class="flex justify-between gap-6">
            <dt class="text-slate-500 shrink-0">{{ $label }}</dt>
            <dd class="text-slate-900 text-right break-all">{{ $value }}</dd>
          </div>
        @endforeach
      </dl>

      @guest
        @if ($txn->isPaid())
          <div class="px-6 py-4 border-t border-slate-200 bg-emerald-50">
            <p class="text-sm text-emerald-900">
              Your receipt has been emailed to <strong>{{ $txn->donor_email }}</strong>. We're also
              setting up an account for that address so you can find your receipts later — watch for
              an email asking you to choose a password.
            </p>
          </div>
        @endif
      @endguest

      <div class="px-6 py-4 border-t border-slate-200 flex items-center gap-3">
        @if ($txn->isPaid())
          <a href="{{ route('transactions.receipt', $txn) }}"
             class="rounded-lg bg-slate-900 text-white px-4 py-2 text-sm font-medium hover:bg-slate-800">Download PDF receipt</a>
          <a href="{{ route('transactions.receipt.preview', $txn) }}" target="_blank"
             class="text-sm text-slate-600 hover:text-slate-900 underline">Preview</a>
        @else
          <div class="text-sm text-slate-500">
            <p>
              A receipt is issued once the payment clears. We checked with
              {{ $txn->gateway_label }}
              @if ($txn->gateway_synced_at) {{ $txn->gateway_synced_at->diffForHumans() }} @endif
              and it has not confirmed this payment yet.
            </p>
            @if ($txn->gateway_sync_error)
              <p class="mt-2 text-amber-700">
                We couldn't reach {{ $txn->gateway_label }} just now, so this may be out of date.
              </p>
            @endif
            <div class="mt-3">
              <a href="{{ route('transactions.show', $txn) }}"
                 class="inline-block rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Check again
              </a>
            </div>
          </div>
        @endif
      </div>
    </div>
  </div>
@endsection
