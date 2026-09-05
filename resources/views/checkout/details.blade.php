@extends('layouts.app')
@section('title', 'Your details')
@section('content')
  <div class="max-w-3xl">
    <h1 class="text-2xl font-semibold text-slate-900 mb-1">Your details</h1>
    <p class="text-sm text-slate-500 mb-8">
      We need these before taking payment — they go on your 80G receipt and are passed to the payment
      provider so you are not asked twice.
      @guest
        You don't need an account: we'll create one for this email afterwards so your receipts are
        always available.
      @endguest
    </p>

    <div class="bg-white rounded-xl border border-slate-200 p-5 mb-6 flex items-center justify-between gap-4">
      <div>
        <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">
          {{ $plan ? 'Recurring plan' : 'One-off donation' }}
        </div>
        <div class="text-xl font-semibold text-slate-900 mt-1">
          {{ \App\Support\Money::format($amount, $currency) }}
          @if ($plan)<span class="text-sm font-normal text-slate-500">/ {{ strtolower($plan->cadence) }}</span>@endif
        </div>
        @if ($plan)<div class="text-sm text-slate-600 mt-0.5">{{ $plan->name }}</div>@endif
      </div>
      <a href="{{ route('checkout.choose') }}" class="text-sm text-slate-500 hover:text-slate-900 underline shrink-0">Change</a>
    </div>

    <form method="POST" action="{{ route('checkout.start') }}" class="space-y-6">
      @csrf
      <input type="hidden" name="kind" value="{{ $kind }}">
      <input type="hidden" name="plan" value="{{ $plan?->slug }}">
      <input type="hidden" name="amount" value="{{ $amount }}">
      <input type="hidden" name="currency" value="{{ $currency }}">

      <section class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="font-semibold text-slate-900 mb-4">Contact</h2>
        <div class="grid sm:grid-cols-2 gap-4">
          @php
            $fields = [
              ['name', 'Full name', 'text', $user->name, true],
              ['email', 'Email', 'email', $user->email, true],
              ['phone', 'Phone', 'tel', $user->phone, true],
              ['pan', 'PAN (optional)', 'text', $user->pan, false],
            ];
          @endphp
          @foreach ($fields as [$field, $label, $type, $value, $required])
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">{{ $label }}</label>
              <input type="{{ $type }}" name="{{ $field }}" value="{{ old($field, $value) }}"
                     @if ($required) required @endif
                     @if ($field === 'pan') style="text-transform:uppercase" maxlength="10" placeholder="ABCDE1234F" @endif
                     class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
              @if ($field === 'pan')
                <p class="text-xs text-slate-500 mt-1">Needed only if you want to claim 80G.</p>
              @endif
            </div>
          @endforeach
        </div>
      </section>

      <section class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="font-semibold text-slate-900 mb-4">Address</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Address line 1</label>
            <input type="text" name="address_line1" value="{{ old('address_line1', $user->address_line1) }}" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Address line 2 <span class="text-slate-400">(optional)</span></label>
            <input type="text" name="address_line2" value="{{ old('address_line2', $user->address_line2) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
          </div>
          <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @php
              $addr = [
                ['city', 'City', $user->city, true],
                ['state', 'State', $user->state, false],
                ['postal_code', 'PIN / ZIP', $user->postal_code, true],
              ];
            @endphp
            @foreach ($addr as [$field, $label, $value, $required])
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">{{ $label }}</label>
                <input type="text" name="{{ $field }}" value="{{ old($field, $value) }}" @if ($required) required @endif
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
              </div>
            @endforeach
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Country</label>
              <select name="country" required
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
                @foreach (['IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'UAE', 'AU' => 'Australia', 'CA' => 'Canada', 'SG' => 'Singapore'] as $code => $label)
                  <option value="{{ $code }}" @selected(old('country', $user->country ?? 'IN') === $code)>{{ $label }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Purpose <span class="text-slate-400">(optional)</span></label>
            <input type="text" name="purpose" value="{{ old('purpose', $plan?->name) }}" maxlength="120"
                   placeholder="e.g. Cow feed fund"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
            <p class="text-xs text-slate-500 mt-1">Printed on your receipt.</p>
          </div>
        </div>
      </section>

      <section class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="font-semibold text-slate-900 mb-1">Payment method</h2>
        <p class="text-sm text-slate-500 mb-4">
          Only methods that can take {{ $currency }} are shown.
        </p>

        @if (empty($gateways))
          <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @if ($plan)
              {{-- Either no gateway takes this currency, or none of them has this
                   plan created in it. Both are admin setup, not donor error. --}}
              {{ $plan->name }} can't be taken in {{ $currency }} yet. An administrator
              needs to create this plan in a gateway that supports {{ $currency }}.
              <a href="{{ route('checkout.choose') }}" class="underline font-medium">Choose another plan</a>.
            @else
              No payment method is configured for {{ $currency }} yet. An administrator needs to add API
              keys before donations can be taken.
            @endif
          </div>
        @else
          <div class="grid sm:grid-cols-3 gap-3">
            @foreach ($gateways as $key => $gateway)
              <label class="relative flex items-center gap-3 rounded-lg border border-slate-300 px-4 py-3 cursor-pointer hover:border-slate-900 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50">
                <input type="radio" name="gateway" value="{{ $key }}" required @checked($loop->first)
                       class="text-slate-900 focus:ring-slate-400">
                <span class="text-sm font-medium text-slate-900">{{ $gateway->displayName() }}</span>
              </label>
            @endforeach
          </div>
        @endif
      </section>

      <div class="flex items-center gap-3">
        <button @disabled(empty($gateways))
                class="rounded-lg bg-brand text-white px-6 py-2.5 text-sm font-medium hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed">
          Continue to payment
        </button>
        <span class="text-xs text-slate-500">You will not be charged until you confirm on the next screen.</span>
      </div>
    </form>
  </div>
@endsection
