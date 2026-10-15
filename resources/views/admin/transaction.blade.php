@extends('layouts.app')
@section('title', 'Transaction '.($txn->receipt_no ?? $txn->reference))
@section('content')
  <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('admin.transactions') }}"
     class="text-sm text-slate-500 hover:text-slate-900">← Back</a>

  @include('admin.partials.donor-emails')

  {{-- Summary --}}
  <div class="bg-white rounded-xl border border-slate-200 mt-4 p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <div class="text-sm text-slate-500">
          {{ $txn->type === 'subscription' ? 'Recurring donation' : 'One-time contribution' }} · {{ $txn->gateway_label }}
        </div>
        <div class="mt-1 flex items-center gap-3">
          <h1 class="text-3xl font-semibold text-slate-900">{{ $txn->amount_formatted }}</h1>
          @include('partials.status-badge', ['status' => $txn->status])
        </div>
        <div class="text-sm text-slate-500 mt-1 font-mono">{{ $txn->receipt_no ?? 'No receipt yet' }}</div>
      </div>
      @if ($txn->isPaid())
        <div class="flex items-center gap-4 text-sm">
          @include('admin.partials.receipt-download', ['txn' => $txn])
          <form method="POST" action="{{ route('admin.receipt.resend', $txn) }}">
            @csrf
            <button class="text-slate-600 hover:text-slate-900 underline">Resend receipt</button>
          </form>
        </div>
      @endif
    </div>
  </div>

  @php
    $dash = '—';
    $sections = [
      'Payment' => [
        'Reference' => $txn->reference,
        'Receipt no.' => $txn->receipt_no ?? $dash,
        'Amount' => $txn->amount_formatted,
        'Currency' => $txn->currency,
        'Status' => ucfirst($txn->status),
        'Type' => $txn->type === 'subscription' ? 'Recurring donation' : 'One-time contribution',
        'Purpose' => $txn->purpose ?? $dash,
        'Gateway' => $txn->gateway_label,
        'Payment method' => $txn->method ?? $dash,
        'Gateway payment id' => $txn->gateway_payment_id ?? $dash,
        'Gateway order id' => $txn->gateway_order_id ?? $dash,
      ],
      'Timeline' => [
        'Created' => $txn->created_at->format('d M Y, g:i:s a'),
        'Paid' => $txn->paid_at?->format('d M Y, g:i:s a') ?? $dash,
        'Linked to account' => $txn->linked_at?->format('d M Y, g:i:s a') ?? $dash,
        'Last synced with gateway' => $txn->gateway_synced_at?->format('d M Y, g:i:s a') ?? $dash,
        'Sync error' => $txn->gateway_sync_error ?? $dash,
        'Receipt emailed' => $txn->receipt_emailed_at?->format('d M Y, g:i:s a')
            ?? ($txn->receipt_email_error ? 'Failed: '.$txn->receipt_email_error : ($txn->isPaid() ? 'Queued' : $dash)),
      ],
    ];
  @endphp

  <div class="grid lg:grid-cols-2 gap-6 mt-6">
    {{-- Donor, as recorded on the receipt --}}
    <section class="bg-white rounded-xl border border-slate-200">
      <h2 class="px-5 py-3 border-b border-slate-100 font-semibold text-slate-900">Donor (as on the receipt)</h2>
      <dl class="px-5 py-4 space-y-3 text-sm">
        @foreach (['Name' => $txn->donor_name, 'Email' => $txn->donor_email, 'Phone' => $txn->donor_phone ?? $dash, 'Address' => $txn->donor_address ?? $dash] as $label => $value)
          <div class="flex justify-between gap-6">
            <dt class="text-slate-500 shrink-0">{{ $label }}</dt>
            <dd class="text-slate-900 text-right break-words min-w-0">{{ $value }}</dd>
          </div>
        @endforeach
        <div class="flex justify-between gap-6">
          <dt class="text-slate-500 shrink-0">{{ \App\Support\IdentityProof::label($txn->donor_id_type) ?? 'Identity proof' }}</dt>
          <dd class="text-slate-900 text-right">
            @if ($maskedId)
              <span class="font-mono" data-id-value>{{ $maskedId }}</span>
              <button type="button" class="ml-2 text-xs text-primary underline" data-reveal-id
                      data-url="{{ route('admin.transactions.reveal-id', $txn) }}"
                      data-masked="{{ $maskedId }}">Reveal</button>
            @else
              {{ $dash }}
            @endif
          </dd>
        </div>
      </dl>
    </section>

    {{-- Dedication and message --}}
    <section class="bg-white rounded-xl border border-slate-200">
      <h2 class="px-5 py-3 border-b border-slate-100 font-semibold text-slate-900">Dedication &amp; message</h2>
      <dl class="px-5 py-4 space-y-3 text-sm">
        <div class="flex justify-between gap-6">
          <dt class="text-slate-500 shrink-0">Dedication</dt>
          <dd class="text-slate-900 text-right">{{ $txn->dedication ?? 'None' }}</dd>
        </div>
        <div>
          <dt class="text-slate-500">Donor's message</dt>
          <dd class="mt-1 text-slate-900 whitespace-pre-line break-words">{{ $txn->donor_message ?? 'No message.' }}</dd>
        </div>
      </dl>
    </section>

    @foreach ($sections as $heading => $rows)
      <section class="bg-white rounded-xl border border-slate-200">
        <h2 class="px-5 py-3 border-b border-slate-100 font-semibold text-slate-900">{{ $heading }}</h2>
        <dl class="px-5 py-4 space-y-3 text-sm">
          @foreach ($rows as $label => $value)
            <div class="flex justify-between gap-6">
              <dt class="text-slate-500 shrink-0">{{ $label }}</dt>
              <dd class="text-slate-900 text-right break-all min-w-0">{{ $value }}</dd>
            </div>
          @endforeach
        </dl>
      </section>
    @endforeach

    {{-- Account and subscription --}}
    <section class="bg-white rounded-xl border border-slate-200">
      <h2 class="px-5 py-3 border-b border-slate-100 font-semibold text-slate-900">Account &amp; subscription</h2>
      <dl class="px-5 py-4 space-y-3 text-sm">
        <div class="flex justify-between gap-6">
          <dt class="text-slate-500 shrink-0">Donor account</dt>
          <dd class="text-right">
            <div class="flex items-center justify-end gap-1">
              @if ($txn->user)
                <a href="{{ route('admin.users.show', $txn->user) }}" class="text-slate-900 hover:underline">{{ $txn->user->email }}</a>
              @else
                <span class="text-slate-400">Not linked</span>
              @endif
              @include('admin.partials.owner-toggle', ['target' => 'owner-txn', 'current' => $txn->user])
            </div>
            @include('admin.partials.owner-form', [
              'id' => 'owner-txn',
              'action' => route('admin.transactions.owner', $txn),
              'current' => $txn->user,
            ])
          </dd>
        </div>
        <div class="flex justify-between gap-6">
          <dt class="text-slate-500 shrink-0">Subscription</dt>
          <dd class="text-slate-900 text-right">
            @if ($txn->subscription)
              {{ $txn->subscription->plan?->name }} · {{ ucfirst($txn->subscription->status) }}
              <div class="text-xs text-slate-500 font-mono break-all">{{ $txn->subscription->gateway_subscription_id }}</div>
            @else
              {{ $dash }}
            @endif
          </dd>
        </div>
      </dl>
    </section>
  </div>

  {{-- Raw gateway data, for when the summary above is not enough. --}}
  @if ($txn->gateway_payload)
    <details class="bg-white rounded-xl border border-slate-200 mt-6">
      <summary class="px-5 py-3 cursor-pointer font-semibold text-slate-900">Gateway payload</summary>
      <pre class="px-5 pb-5 text-xs text-slate-700 overflow-x-auto">{{ json_encode($txn->gateway_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
  @endif

  @push('scripts')
  <script>
  (function () {
    var button = document.querySelector('[data-reveal-id]');
    if (!button) return;

    var value = document.querySelector('[data-id-value]');
    var revealed = false;

    button.addEventListener('click', function () {
      if (revealed) {
        value.textContent = button.dataset.masked;
        button.textContent = 'Reveal';
        revealed = false;
        return;
      }

      button.disabled = true;
      fetch(button.dataset.url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' },
      })
        .then(function (response) {
          if (!response.ok) throw new Error(response.status);
          return response.json();
        })
        .then(function (data) {
          value.textContent = data.number;
          button.textContent = 'Hide';
          revealed = true;
        })
        .catch(function () { button.textContent = 'Could not reveal — retry'; })
        .finally(function () { button.disabled = false; });
    });
  })();
  </script>
  @endpush
@endsection
