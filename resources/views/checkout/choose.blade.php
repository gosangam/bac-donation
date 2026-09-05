@extends('layouts.app')
@section('title', 'Give')
@section('content')
  @guest
    <div class="text-center max-w-2xl mx-auto mb-10">
      <h1 class="text-2xl sm:text-3xl md:text-4xl font-semibold text-slate-900 tracking-tight">
        Care for the animals of Braj
      </h1>
      <p class="mt-3 text-sm sm:text-base text-slate-600">
        Every gift feeds and treats rescued cows and street animals in Vrindavan. Give once, or
        monthly — <strong class="text-slate-900">no account needed</strong>. Your 80G receipt is
        emailed straight away.
      </p>
      <p class="mt-3 text-sm text-slate-500">
        Already given before?
        <a href="{{ route('login') }}" class="text-slate-900 font-medium hover:underline">Sign in</a>
        to see your receipts.
      </p>
    </div>
  @endguest

  @auth
    <div class="flex items-start justify-between gap-4 mb-8">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Make a donation</h1>
        <p class="text-sm text-slate-500 mt-1">Give once, or set up a monthly gift that renews automatically.</p>
      </div>
      <a href="{{ route('dashboard') }}"
         class="text-sm text-slate-600 hover:text-slate-900 underline shrink-0">Your dashboard</a>
    </div>
  @endauth

  <div class="flex justify-center sm:justify-end mb-6">
    <form method="POST" action="{{ route('checkout.currency') }}"
          class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5" role="group"
          aria-label="Giving currency">
      @csrf
      @foreach (\App\Support\Currency::supported() as $code)
        <button name="currency" value="{{ $code }}"
                @if ($code === $currency) aria-current="true" @endif
                class="rounded-md px-3 py-1.5 text-sm font-medium transition
                       {{ $code === $currency
                          ? 'bg-slate-900 text-white'
                          : 'text-slate-600 hover:text-slate-900' }}">
          {{ $code === 'INR' ? '₹ INR' : '$ USD' }}
        </button>
      @endforeach
    </form>
  </div>

  <section class="mb-10">
    <h2 class="font-semibold text-slate-900 mb-3">Monthly giving</h2>
    @if ($plans->isEmpty())
      <div class="bg-white rounded-xl border border-slate-200 p-6 text-sm text-slate-500">
        @auth
          No plans are priced in {{ $currency }} yet. An administrator can add a
          {{ $currency }} amount under Admin → Plans.
        @else
          Monthly giving isn't available in {{ $currency }} just now — a one-off
          donation below works perfectly.
        @endauth
      </div>
    @else
      <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ($plans as $plan)
          <form method="GET" action="{{ route('checkout.details') }}"
                class="bg-white rounded-xl border border-slate-200 p-5 flex flex-col hover:border-slate-300">
            <input type="hidden" name="kind" value="plan">
            <input type="hidden" name="plan" value="{{ $plan->slug }}">
            <input type="hidden" name="currency" value="{{ $currency }}">
            <div class="font-semibold text-slate-900">{{ $plan->name }}</div>
            <div class="text-2xl font-semibold text-brand mt-2">{{ $plan->amountFormattedIn($currency) }}</div>
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
          class="bg-white rounded-xl border border-slate-200 p-4 sm:p-5 w-full max-w-lg">
      <input type="hidden" name="kind" value="one_off">
      <input type="hidden" name="currency" value="{{ $currency }}">

      <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-2 mb-4">
        @foreach ($presets as $preset)
          <button type="submit" name="amount" value="{{ \App\Support\Money::toDecimal($preset, $currency) }}"
                  class="rounded-lg border border-slate-300 px-3 sm:px-4 py-2 text-sm font-medium hover:border-slate-900 hover:bg-slate-50">
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
