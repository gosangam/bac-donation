@extends('layouts.app')
@section('title', 'Give')
@section('content')
  <h1 class="text-2xl font-semibold text-slate-900 mb-1">Make a donation</h1>
  <p class="text-sm text-slate-500 mb-8">Give once, or set up a monthly gift that renews automatically.</p>

  @guest
    <div class="mb-8 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
      No account needed. We'll email your receipt and set up an account afterwards so you can find it
      again — or <a href="{{ route('login') }}" class="text-slate-900 font-medium hover:underline">sign in</a>
      if you already have one.
    </div>
  @endguest

  <section class="mb-10">
    <h2 class="font-semibold text-slate-900 mb-3">Recurring plans</h2>
    @if ($plans->isEmpty())
      <div class="bg-white rounded-xl border border-slate-200 p-6 text-sm text-slate-500">
        No plans are set up yet. An administrator can add them under Admin → Plans.
      </div>
    @else
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($plans as $plan)
          <form method="GET" action="{{ route('checkout.details') }}"
                class="bg-white rounded-xl border border-slate-200 p-5 flex flex-col hover:border-slate-300">
            <input type="hidden" name="kind" value="plan">
            <input type="hidden" name="plan" value="{{ $plan->slug }}">
            <div class="font-semibold text-slate-900">{{ $plan->name }}</div>
            <div class="text-2xl font-semibold text-brand mt-2">{{ $plan->amount_formatted }}</div>
            <div class="text-xs text-slate-500 mt-1">{{ $plan->cadence }}</div>
            @if ($plan->description)
              <p class="text-sm text-slate-600 mt-3 flex-1">{{ $plan->description }}</p>
            @else
              <div class="flex-1"></div>
            @endif
            <button class="mt-4 w-full rounded-lg bg-slate-900 text-white py-2 text-sm font-medium hover:bg-slate-800">Choose</button>
          </form>
        @endforeach
      </div>
    @endif
  </section>

  <section>
    <h2 class="font-semibold text-slate-900 mb-3">One-off donation</h2>
    <form method="GET" action="{{ route('checkout.details') }}"
          class="bg-white rounded-xl border border-slate-200 p-5 max-w-lg">
      <input type="hidden" name="kind" value="one_off">
      <input type="hidden" name="currency" value="{{ $currency }}">

      <div class="flex flex-wrap gap-2 mb-4">
        @foreach ($presets as $preset)
          <button type="submit" name="amount" value="{{ \App\Support\Money::toDecimal($preset, $currency) }}"
                  class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:border-slate-900 hover:bg-slate-50">
            {{ \App\Support\Money::format($preset, $currency) }}
          </button>
        @endforeach
      </div>

      <label class="block text-sm font-medium text-slate-700 mb-1">Or enter an amount</label>
      <div class="flex gap-2">
        <input type="number" name="amount" step="0.01" min="1" placeholder="1000"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
        <button class="rounded-lg bg-slate-900 text-white px-5 py-2 text-sm font-medium hover:bg-slate-800">Continue</button>
      </div>
    </form>
  </section>
@endsection
