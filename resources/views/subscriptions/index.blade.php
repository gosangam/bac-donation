@extends('layouts.app')
@section('title', 'Subscriptions')
@section('content')
  <h1 class="text-2xl font-semibold text-slate-900 mb-1">Subscriptions</h1>
  <p class="text-sm text-slate-500 mb-6">Recurring donations. Cancelling stops all future charges.</p>

  <div class="space-y-4">
    @forelse ($subscriptions as $subscription)
      <div class="bg-white rounded-xl border border-slate-200 p-5 flex flex-wrap items-center justify-between gap-4">
        <div>
          <div class="flex items-center gap-3">
            <span class="font-semibold text-slate-900">{{ $subscription->plan->name }}</span>
            @include('partials.status-badge', ['status' => $subscription->status])
          </div>
          <div class="text-sm text-slate-500 mt-1">
            {{ $subscription->plan->amount_formatted }} · {{ $subscription->plan->cadence }}
            · via {{ ucfirst($subscription->gateway) }}
            @if ($subscription->started_at) · since {{ $subscription->started_at->format('d M Y') }} @endif
          </div>
        </div>
        <div class="flex items-center gap-3">
          <a href="{{ route('subscriptions.show', $subscription) }}"
             class="text-sm text-slate-600 hover:text-slate-900 underline">Details</a>
          @if ($subscription->isActive())
            <form method="POST" action="{{ route('subscriptions.cancel', $subscription) }}"
                  onsubmit="return confirm('Cancel this recurring donation? You will not be charged again.')">
              @csrf
              <button class="rounded-lg border border-red-300 text-red-700 px-3 py-1.5 text-sm hover:bg-red-50">Cancel</button>
            </form>
          @endif
        </div>
      </div>
    @empty
      <div class="bg-white rounded-xl border border-slate-200 p-12 text-center">
        <p class="text-slate-500">No recurring donations yet.</p>
        <a href="{{ route('checkout.choose') }}"
           class="inline-block mt-4 rounded-lg bg-brand text-white px-4 py-2 text-sm font-medium hover:opacity-90">Start one</a>
      </div>
    @endforelse
  </div>
@endsection
