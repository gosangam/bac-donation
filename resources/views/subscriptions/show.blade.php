@extends('layouts.app')
@section('title', 'Subscription')
@section('content')
  <div class="max-w-2xl">
    <a href="{{ route('subscriptions.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← All subscriptions</a>

    <div class="bg-white rounded-xl border border-slate-200 mt-4 p-6">
      <div class="flex items-center gap-3">
        <h1 class="text-xl font-semibold text-slate-900">{{ $subscription->plan->name }}</h1>
        @include('partials.status-badge', ['status' => $subscription->status])
      </div>
      <div class="text-sm text-slate-500 mt-1">
        {{ $subscription->plan->amount_formatted }} · {{ $subscription->plan->cadence }}
        · via {{ ucfirst($subscription->gateway) }}
      </div>
    </div>

    <h2 class="font-semibold text-slate-900 mt-8 mb-3">Charges</h2>
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      @forelse ($transactions as $txn)
        <div class="px-5 py-4 border-b border-slate-100 last:border-0 flex items-center justify-between gap-4">
          <div>
            <div class="font-medium text-slate-900">{{ $txn->amount_formatted }}</div>
            <div class="text-xs text-slate-500 mt-0.5">
              {{ $txn->created_at->format('d M Y') }} · {{ $txn->receipt_no ?? 'no receipt yet' }}
            </div>
          </div>
          <div class="flex items-center gap-3">
            @include('partials.status-badge', ['status' => $txn->status])
            @if ($txn->isPaid())
              <a href="{{ route('transactions.receipt', $txn) }}" class="text-sm text-slate-900 font-medium hover:underline">PDF</a>
            @endif
          </div>
        </div>
      @empty
        <p class="px-5 py-8 text-sm text-slate-500 text-center">
          No charges yet. The first one appears once the provider confirms it.
        </p>
      @endforelse
    </div>
  </div>
@endsection
