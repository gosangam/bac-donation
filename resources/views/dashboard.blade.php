@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
  <div class="flex items-start justify-between gap-4 mb-8">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Namaste, {{ $user->name }}</h1>
      <p class="text-sm text-slate-500 mt-1">Thank you for supporting the animals of Braj.</p>
    </div>
    <a href="{{ route('checkout.choose') }}"
       class="rounded-lg bg-brand text-white px-4 py-2.5 text-sm font-medium hover:opacity-90 shrink-0">Give now</a>
  </div>

  @unless ($user->hasCompleteDonorProfile())
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      Your address is incomplete. We collect it at checkout, but
      <a href="{{ route('profile') }}" class="font-medium underline">adding it now</a> makes giving quicker.
    </div>
  @endunless

  <div class="grid sm:grid-cols-3 gap-4 mb-8">
    @php
      $cards = [
        ['Total given', \App\Support\Money::format($totalGiven, $currency), 'across all completed payments'],
        ['Active subscriptions', $activeSubscriptions->count(), 'recurring donations running'],
        ['Transactions', $user->transactions()->count(), 'including auto-debits'],
      ];
    @endphp
    @foreach ($cards as [$label, $value, $hint])
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">{{ $label }}</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ $value }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ $hint }}</div>
      </div>
    @endforeach
  </div>

  <div class="grid lg:grid-cols-2 gap-6">
    <section class="bg-white rounded-xl border border-slate-200">
      <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
        <h2 class="font-semibold text-slate-900">Active subscriptions</h2>
        <a href="{{ route('subscriptions.index') }}" class="text-sm text-slate-500 hover:text-slate-900">All</a>
      </div>
      @forelse ($activeSubscriptions as $subscription)
        <div class="px-5 py-4 border-b border-slate-100 last:border-0 flex items-center justify-between gap-4">
          <div>
            <div class="font-medium text-slate-900">{{ $subscription->plan->name }}</div>
            <div class="text-xs text-slate-500 mt-0.5">
              {{ $subscription->plan->amount_formatted }} · {{ $subscription->plan->cadence }}
              · via {{ ucfirst($subscription->gateway) }}
            </div>
          </div>
          @include('partials.status-badge', ['status' => $subscription->status])
        </div>
      @empty
        <p class="px-5 py-8 text-sm text-slate-500 text-center">
          No recurring donations yet.
          <a href="{{ route('checkout.choose') }}" class="text-slate-900 font-medium hover:underline">Start one</a>.
        </p>
      @endforelse
    </section>

    <section class="bg-white rounded-xl border border-slate-200">
      <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
        <h2 class="font-semibold text-slate-900">Recent transactions</h2>
        <a href="{{ route('transactions.index') }}" class="text-sm text-slate-500 hover:text-slate-900">All</a>
      </div>
      @forelse ($recentTransactions as $txn)
        <div class="px-5 py-4 border-b border-slate-100 last:border-0 flex items-center justify-between gap-4">
          <div class="min-w-0">
            <div class="font-medium text-slate-900">{{ $txn->amount_formatted }}</div>
            <div class="text-xs text-slate-500 mt-0.5 truncate">
              {{ $txn->created_at->format('d M Y') }} · {{ $txn->gateway_label }}
              · {{ $txn->type === 'subscription' ? 'Auto-debit' : 'One-off' }}
            </div>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            @include('partials.status-badge', ['status' => $txn->status])
            @if ($txn->isPaid())
              <a href="{{ route('transactions.receipt', $txn) }}" class="text-xs text-slate-600 hover:text-slate-900 underline">PDF</a>
            @endif
          </div>
        </div>
      @empty
        <p class="px-5 py-8 text-sm text-slate-500 text-center">Nothing yet.</p>
      @endforelse
    </section>
  </div>
@endsection
