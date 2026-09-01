@extends('layouts.app')
@section('title', 'All transactions')
@section('content')
  <h1 class="text-2xl font-semibold text-slate-900 mb-6">All transactions</h1>

  <form method="GET" class="flex flex-wrap gap-2 mb-4">
    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email, receipt, payment id"
           class="rounded-lg border border-slate-300 px-3 py-2 text-sm min-w-64 flex-1">
    @foreach ([['gateway', ['razorpay', 'stripe', 'paypal']], ['status', ['paid', 'pending', 'failed', 'cancelled']], ['type', ['one_off', 'subscription']]] as [$name, $options])
      <select name="{{ $name }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">All {{ $name }}</option>
        @foreach ($options as $option)
          <option value="{{ $option }}" @selected(($filters[$name] ?? '') === $option)>{{ ucfirst(str_replace('_', '-', $option)) }}</option>
        @endforeach
      </select>
    @endforeach
    <button class="rounded-lg bg-slate-900 text-white px-4 py-2 text-sm font-medium">Filter</button>
    @if (array_filter($filters))
      <a href="{{ route('admin.transactions') }}" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-900">Clear</a>
    @endif
  </form>

  <div class="sm:hidden space-y-3">
    @forelse ($transactions as $txn)
      <div class="bg-white rounded-xl border border-slate-200 p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-medium text-slate-900 truncate">{{ $txn->donor_name }}</div>
            <div class="text-xs text-slate-500 truncate">{{ $txn->donor_email }}</div>
          </div>
          <div class="text-right shrink-0">
            <div class="font-semibold text-slate-900">{{ $txn->amount_formatted }}</div>
            @include('partials.status-badge', ['status' => $txn->status])
          </div>
        </div>
        <div class="text-xs text-slate-500 mt-2 break-all">
          {{ $txn->created_at->format('d M Y') }} · {{ $txn->gateway_label }} ·
          {{ $txn->type === 'subscription' ? 'Auto' : 'One-off' }} ·
          {{ $txn->receipt_no ?? 'no receipt' }}
        </div>
        @if ($txn->isPaid())
          <div class="flex items-center gap-4 mt-3 text-sm">
            <a href="{{ route('admin.receipt', $txn) }}" class="text-slate-900 font-medium underline">PDF</a>
            <form method="POST" action="{{ route('admin.receipt.resend', $txn) }}">@csrf
              <button class="text-slate-600 underline">Resend</button>
            </form>
          </div>
        @endif
      </div>
    @empty
      <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
        No transactions match.
      </div>
    @endforelse
  </div>

  <div class="hidden sm:block bg-white rounded-xl border border-slate-200 overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600">
        <tr>
          @foreach (['Date', 'Donor', 'Receipt', 'Gateway', 'Type', 'Amount', 'Status', 'Receipt email', ''] as $heading)
            <th class="text-left font-medium px-4 py-3 {{ $heading === 'Amount' ? 'text-right' : '' }}">{{ $heading }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @forelse ($transactions as $txn)
          <tr class="border-t border-slate-100 hover:bg-slate-50">
            <td class="px-4 py-3 whitespace-nowrap">{{ $txn->created_at->format('d M Y') }}</td>
            <td class="px-4 py-3">
              <div class="font-medium text-slate-900">{{ $txn->donor_name }}</div>
              <div class="text-xs text-slate-500">{{ $txn->donor_email }}</div>
            </td>
            <td class="px-4 py-3 font-mono text-xs">{{ $txn->receipt_no ?? '—' }}</td>
            <td class="px-4 py-3">{{ $txn->gateway_label }}</td>
            <td class="px-4 py-3">{{ $txn->type === 'subscription' ? 'Auto' : 'One-off' }}</td>
            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ $txn->amount_formatted }}</td>
            <td class="px-4 py-3">@include('partials.status-badge', ['status' => $txn->status])</td>
            <td class="px-4 py-3">
              @if (! $txn->isPaid())
                <span class="text-slate-400 text-xs">—</span>
              @elseif ($txn->receipt_emailed_at)
                <span class="text-xs text-emerald-700">Sent {{ $txn->receipt_emailed_at->format('d M, g:i a') }}</span>
              @elseif ($txn->receipt_email_error)
                <span class="text-xs text-red-700" title="{{ $txn->receipt_email_error }}">Failed</span>
              @else
                <span class="text-xs text-amber-700">Queued</span>
              @endif
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              @if ($txn->isPaid())
                <a href="{{ route('admin.receipt', $txn) }}" class="text-slate-900 font-medium hover:underline">PDF</a>
                <form method="POST" action="{{ route('admin.receipt.resend', $txn) }}" class="inline">
                  @csrf
                  <button class="ml-3 text-slate-600 hover:text-slate-900 underline">Resend</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="9" class="px-4 py-12 text-center text-slate-500">No transactions match.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-4">{{ $transactions->links() }}</div>
@endsection
