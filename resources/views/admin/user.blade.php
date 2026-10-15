@extends('layouts.app')
@section('title', $donor->name)
@section('content')
  <a href="{{ route('admin.users') }}" class="text-sm text-slate-500 hover:text-slate-900">← All donors</a>

  <div class="bg-white rounded-xl border border-slate-200 mt-4 p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ $donor->name }}</h1>
        <div class="text-sm text-slate-500 mt-1">
          {{ $donor->email }}@if ($donor->phone) · {{ $donor->phone }}@endif
        </div>
        @if ($donor->full_address)
          <div class="text-sm text-slate-500 mt-1">{{ $donor->full_address }}</div>
        @endif
        @if ($donor->id_number)
          {{-- Aadhaar is shown masked; see IdentityProof::forDisplay(). --}}
          <div class="text-sm text-slate-500 mt-1">{{ \App\Support\IdentityProof::describe($donor->id_type, $donor->id_number) }}</div>
        @endif
      </div>
      <div class="sm:text-right">
        @forelse ($totals as $total)
          <div class="text-xl font-semibold text-slate-900">
            {{ \App\Support\Money::format($total->total, $total->currency) }}
          </div>
          <div class="text-xs text-slate-500">{{ $total->count }} payments in {{ $total->currency }}</div>
        @empty
          <div class="text-xl font-semibold text-slate-400">Nothing given yet</div>
        @endforelse
        <div class="text-xs text-slate-500 mt-2">Joined {{ $donor->created_at->format('d M Y') }}</div>
        <a href="{{ route('admin.users.edit', $donor) }}"
           class="inline-block mt-3 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit account</a>
      </div>
    </div>
  </div>

  <div class="flex flex-wrap items-end justify-between gap-3 mt-8 mb-3">
    <h2 class="font-semibold text-slate-900">Subscriptions</h2>
    @include('admin.partials.attach-form', [
        'action' => route('admin.users.subscriptions.attach', $donor),
        'name' => 'subscription',
        'placeholder' => 'Gateway subscription id (sub_…, I-…)',
        'hint' => 'Its past payments move here too.',
    ])
  </div>
  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    @forelse ($subscriptions as $subscription)
      <div class="px-5 py-4 border-b border-slate-100 last:border-0 flex flex-wrap items-center justify-between gap-4">
        <div>
          <div class="font-medium text-slate-900">{{ $subscription->plan->name }}</div>
          <div class="text-xs text-slate-500 mt-0.5">
            {{ $subscription->plan->amount_formatted }} · {{ $subscription->plan->cadence }}
            · {{ ucfirst($subscription->gateway) }}
            @if ($subscription->started_at) · since {{ $subscription->started_at->format('d M Y') }} @endif
          </div>
          @if ($subscription->gateway_subscription_id)
            <div class="text-xs text-slate-400 font-mono mt-0.5">{{ $subscription->gateway_subscription_id }}</div>
          @endif
        </div>
        @include('partials.status-badge', ['status' => $subscription->status])
      </div>
    @empty
      <p class="px-5 py-8 text-sm text-slate-500 text-center">No subscriptions.</p>
    @endforelse
  </div>

  <div class="flex flex-wrap items-end justify-between gap-3 mt-8 mb-3">
    <h2 class="font-semibold text-slate-900">Transactions</h2>
    @include('admin.partials.attach-form', [
        'action' => route('admin.users.transactions.attach', $donor),
        'name' => 'transaction',
        'placeholder' => 'Receipt no., reference or payment id',
        'hint' => null,
    ])
  </div>
  <div class="sm:hidden space-y-3">
    @forelse ($transactions as $txn)
      <div class="bg-white rounded-xl border border-slate-200 p-4 cursor-pointer hover:border-slate-300"
           data-href="{{ route('admin.transactions.show', $txn) }}">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="font-semibold text-slate-900">{{ $txn->amount_formatted }}</div>
            <div class="text-xs text-slate-500 mt-0.5">
              {{ $txn->created_at->format('d M Y') }} ·
              {{ $txn->type === 'subscription' ? 'Auto-debit' : 'One-time' }} · {{ $txn->gateway_label }}
            </div>
          </div>
          @include('partials.status-badge', ['status' => $txn->status])
        </div>
        <a href="{{ route('admin.transactions.show', $txn) }}" class="block text-xs text-slate-500 mt-2 break-all underline">{{ $txn->receipt_no ?? 'details' }}</a>
        @if ($txn->isPaid())
          <div class="mt-3 text-sm">@include('admin.partials.receipt-download', ['txn' => $txn])</div>
        @endif
      </div>
    @empty
      <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
        No transactions.
      </div>
    @endforelse
  </div>

  <div class="hidden sm:block bg-white rounded-xl border border-slate-200 overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600">
        <tr>
          @foreach (['Date', 'Receipt', 'Type', 'Gateway', 'Amount', 'Status', ''] as $heading)
            <th class="text-left font-medium px-4 py-3 {{ $heading === 'Amount' ? 'text-right' : '' }}">{{ $heading }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @forelse ($transactions as $txn)
          <tr class="border-t border-slate-100 hover:bg-slate-50 cursor-pointer"
              data-href="{{ route('admin.transactions.show', $txn) }}">
            <td class="px-4 py-3 whitespace-nowrap">{{ $txn->created_at->format('d M Y') }}</td>
            <td class="px-4 py-3 font-mono text-xs">
              <a href="{{ route('admin.transactions.show', $txn) }}" class="hover:underline">{{ $txn->receipt_no ?? 'View' }}</a>
            </td>
            <td class="px-4 py-3">{{ $txn->type === 'subscription' ? 'Auto-debit' : 'One-time' }}</td>
            <td class="px-4 py-3">{{ $txn->gateway_label }}</td>
            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ $txn->amount_formatted }}</td>
            <td class="px-4 py-3">@include('partials.status-badge', ['status' => $txn->status])</td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              @if ($txn->isPaid())
                @include('admin.partials.receipt-download', ['txn' => $txn])
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">No transactions.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="mt-4">{{ $transactions->links() }}</div>
  @include('admin.partials.row-links')
@endsection
