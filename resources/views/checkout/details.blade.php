@extends('layouts.app')
@section('title', 'Your details')
@section('content')
  <div class="max-w-3xl mx-auto">
    <span class="eyebrow">Step 2 of 3</span>
    <h1 class="mt-4 text-3xl sm:text-4xl font-semibold tracking-[-0.02em] mb-3">Your details</h1>
    <p class="text-[15px] leading-relaxed text-stone-600 mb-10">
      We need these before taking payment — they go on your 80G receipt and are passed to the payment
      provider so you are not asked twice.
      @guest
        You don't need an account: we'll create one for this email afterwards so your receipts are
        always available.
      @endguest
    </p>

    <div class="rounded-md bg-forest text-white p-6 mb-8 flex items-center justify-between gap-4">
      <div>
        <div class="text-xs uppercase tracking-wider text-lime font-semibold">
          {{ $plan ? 'Recurring plan' : 'One-time contribution' }}
        </div>
        <div class="text-3xl font-semibold tracking-[-0.02em] mt-2">
          {{ \App\Support\Money::format($amount, $currency) }}
          @if ($plan)<span class="text-base font-normal text-white/60">/ {{ strtolower($plan->cadence) }}</span>@endif
        </div>
        @if ($plan)<div class="text-sm text-white/70 mt-1">{{ $plan->name }}</div>@endif
      </div>
      <a href="{{ route('checkout.choose') }}#give" class="text-sm text-white/70 hover:text-white underline underline-offset-4 shrink-0">Change</a>
    </div>

    <form method="POST" action="{{ route('checkout.start') }}" class="space-y-6">
      @csrf
      <input type="hidden" name="kind" value="{{ $kind }}">
      <input type="hidden" name="plan" value="{{ $plan?->slug }}">
      <input type="hidden" name="amount" value="{{ $amount }}">
      <input type="hidden" name="currency" value="{{ $currency }}">

      <section class="card p-6 sm:p-7">
        <h2 class="text-lg font-semibold text-forest mb-5">Contact</h2>
        <div class="grid sm:grid-cols-2 gap-4">
          @php
            $fields = [
              ['name', 'Full name', 'text', $user->name],
              ['email', 'Email', 'email', $user->email],
              ['phone', 'Phone', 'tel', $user->phone],
            ];
          @endphp
          @foreach ($fields as [$field, $label, $type, $value])
            <div>
              <label class="field-label">{{ $label }}</label>
              <input type="{{ $type }}" name="{{ $field }}" value="{{ old($field, $value) }}" required
                     class="field">
              @error($field)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
          @endforeach
        </div>
      </section>

      {{-- Identity proof. Required for rupee donations because Form 10BD
           reporting files the donor's ID against the receipt; a foreign-currency
           donation is not reported that way, so it is offered but not demanded. --}}
      @php
        $needsId = $currency === 'INR';
        $idType = old('id_type', $user->id_type);
        $idNumber = old('id_number', $user->id_number);
      @endphp
      <section class="card p-6 sm:p-7">
        <h2 class="text-lg font-semibold text-forest mb-1">
          Identity proof
          @unless ($needsId)<span class="text-sm font-normal text-stone-400">(optional)</span>@endunless
        </h2>
        <p class="text-xs text-stone-500 mb-4">
          @if ($needsId)
            Required for donations in rupees, so your 80G receipt can be reported to the Income Tax Department.
          @else
            Only needed if you intend to claim a deduction under Indian tax law.
          @endif
        </p>

        <div class="grid sm:grid-cols-2 gap-4">
          @include('partials.identity-proof', [
            'type' => $idType,
            'number' => $idNumber,
            'required' => $needsId,
            'label' => 'Type',
          ])
        </div>
      </section>

      <section class="card p-6 sm:p-7">
        <h2 class="text-lg font-semibold text-forest mb-5">Address</h2>
        <div class="space-y-4">
          <div>
            <label class="field-label">Address line 1</label>
            <input type="text" name="address_line1" value="{{ old('address_line1', $user->address_line1) }}" required
                   class="field">
          </div>
          <div>
            <label class="field-label">Address line 2 <span class="text-stone-400">(optional)</span></label>
            <input type="text" name="address_line2" value="{{ old('address_line2', $user->address_line2) }}"
                   class="field">
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
                <label class="field-label">{{ $label }}</label>
                <input type="text" name="{{ $field }}" value="{{ old($field, $value) }}" @if ($required) required @endif
                       class="field">
              </div>
            @endforeach
            <div>
              <label class="field-label">Country</label>
              <select name="country" required
                      class="field">
                @foreach (['IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'UAE', 'AU' => 'Australia', 'CA' => 'Canada', 'SG' => 'Singapore'] as $code => $label)
                  <option value="{{ $code }}" @selected(old('country', $user->country ?? 'IN') === $code)>{{ $label }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div>
            <label class="field-label">Purpose <span class="text-stone-400">(optional)</span></label>
            <input type="text" name="purpose" value="{{ old('purpose', $plan?->name) }}" maxlength="120"
                   placeholder="e.g. Dog rescue fund"
                   class="field">
            <p class="text-xs text-stone-500 mt-1">Printed on your receipt.</p>
          </div>
        </div>
      </section>

      <section class="card p-6 sm:p-7">
        <h2 class="text-lg font-semibold text-forest mb-1">Payment method</h2>
        <p class="text-sm text-stone-500 mb-4">
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
              <label class="relative flex items-center gap-3 rounded-[3px] border border-line bg-white px-4 py-3.5 cursor-pointer hover:border-forest has-[:checked]:border-forest has-[:checked]:bg-lime">
                <input type="radio" name="gateway" value="{{ $key }}" required @checked($loop->first)
                       class="accent-moss">
                <span class="text-sm font-medium text-forest">{{ $gateway->displayName() }}</span>
              </label>
            @endforeach
          </div>
        @endif
      </section>

      <div class="flex flex-col sm:flex-row sm:items-center gap-4 pt-2">
        <button @disabled(empty($gateways))
                class="btn-primary">
          Continue to payment
        </button>
        <span class="text-sm text-stone-500">You will not be charged until you confirm on the next screen.</span>
      </div>
    </form>
  </div>

@endsection
