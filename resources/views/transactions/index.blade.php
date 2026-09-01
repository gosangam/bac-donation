@extends('layouts.app')
@section('title', 'Transactions')
@section('content')
  <div class="flex items-center justify-between gap-4 mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Transactions</h1>
      <p class="text-sm text-slate-500 mt-1">One-off gifts and automatic renewals, with receipts.</p>
    </div>
  </div>

  <form method="GET" class="flex flex-wrap gap-2 mb-4">
    <select name="type" onchange="this.form.submit()"
            class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
      <option value="">All types</option>
      <option value="one_off" @selected(($filters['type'] ?? '') === 'one_off')>One-off</option>
      <option value="subscription" @selected(($filters['type'] ?? '') === 'subscription')>Auto-debit</option>
    </select>
    <select name="status" onchange="this.form.submit()"
            class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
      <option value="">All statuses</option>
      @foreach (['paid', 'pending', 'failed', 'cancelled'] as $status)
        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
      @endforeach
    </select>
    @if (array_filter($filters))
      <a href="{{ route('transactions.index') }}" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-900">Clear</a>
    @endif
  </form>

  {{-- Cards on a phone: a horizontally scrolled table hides the amount, status
       and receipt link, which are the only things a donor came here for. --}}
  <div class="sm:hidden space-y-3">
    @forelse ($transactions as $txn)
      <div class="bg-white rounded-xl border border-slate-200 p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-semibold text-slate-900">{{ $txn->amount_formatted }}</div>
            <div class="text-xs text-slate-500 mt-0.5">
              {{ $txn->created_at->format('d M Y') }} ·
              {{ $txn->type === 'subscription' ? 'Auto-debit' : 'One-off' }}
            </div>
          </div>
          @include('partials.status-badge', ['status' => $txn->status])
        </div>
        <div class="text-xs text-slate-500 mt-2 break-all">
          {{ $txn->receipt_no ?? 'Receipt issued once paid' }} · {{ $txn->method ?? $txn->gateway_label }}
        </div>
        <div class="flex items-center gap-4 mt-3 text-sm">
          <a href="{{ route('transactions.show', $txn) }}" class="text-slate-600 underline">View</a>
          @if ($txn->isPaid())
            <a href="{{ route('transactions.receipt', $txn) }}" class="text-slate-900 font-medium underline">Download PDF</a>
          @endif
        </div>
      </div>
    @empty
      <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
        No transactions yet.
        <a href="{{ route('checkout.choose') }}" class="text-slate-900 font-medium hover:underline">Make a donation</a>.
      </div>
    @endforelse
  </div>

  <div class="hidden sm:block bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr>
            <th class="text-left font-medium px-4 py-3">Date</th>
            <th class="text-left font-medium px-4 py-3">Receipt</th>
            <th class="text-left font-medium px-4 py-3">Type</th>
            <th class="text-left font-medium px-4 py-3">Method</th>
            <th class="text-right font-medium px-4 py-3">Amount</th>
            <th class="text-left font-medium px-4 py-3">Status</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          @forelse ($transactions as $txn)
            <tr class="border-t border-slate-100 hover:bg-slate-50">
              <td class="px-4 py-3 whitespace-nowrap">{{ $txn->created_at->format('d M Y') }}</td>
              <td class="px-4 py-3 font-mono text-xs">{{ $txn->receipt_no ?? '—' }}</td>
              <td class="px-4 py-3">{{ $txn->type === 'subscription' ? 'Auto-debit' : 'One-off' }}</td>
              <td class="px-4 py-3 text-slate-600">{{ $txn->method ?? $txn->gateway_label }}</td>
              <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ $txn->amount_formatted }}</td>
              <td class="px-4 py-3">@include('partials.status-badge', ['status' => $txn->status])</td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <a href="{{ route('transactions.show', $txn) }}" class="text-slate-600 hover:text-slate-900 underline">View</a>
                @if ($txn->isPaid())
                  <a href="{{ route('transactions.receipt', $txn) }}" class="ml-3 text-slate-900 font-medium hover:underline">PDF</a>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">
              No transactions yet.
              <a href="{{ route('checkout.choose') }}" class="text-slate-900 font-medium hover:underline">Make a donation</a>.
            </td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-4">{{ $transactions->links() }}</div>
@endsection
