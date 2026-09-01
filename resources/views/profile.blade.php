@extends('layouts.app')
@section('title', 'Profile')
@section('content')
  <div class="max-w-2xl">
    <h1 class="text-2xl font-semibold text-slate-900 mb-1">Your details</h1>
    <p class="text-sm text-slate-500 mb-6">Used to prefill checkout and to print your receipts.</p>

    <form method="POST" action="{{ route('profile.update') }}" class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      @csrf @method('PUT')
      <div class="grid sm:grid-cols-2 gap-4">
        @php
          $fields = [
            ['name', 'Full name', 'text', true], ['phone', 'Phone', 'tel', true],
            ['address_line1', 'Address line 1', 'text', true], ['address_line2', 'Address line 2', 'text', false],
            ['city', 'City', 'text', true], ['state', 'State', 'text', false],
            ['postal_code', 'PIN / ZIP', 'text', true], ['pan', 'PAN', 'text', false],
          ];
        @endphp
        @foreach ($fields as [$field, $label, $type, $required])
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">{{ $label }}</label>
            <input type="{{ $type }}" name="{{ $field }}" value="{{ old($field, $user->$field) }}"
                   @if ($required) required @endif
                   @if ($field === 'pan') style="text-transform:uppercase" maxlength="10" @endif
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
          </div>
        @endforeach
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Country</label>
          <select name="country" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach (['IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'UAE', 'AU' => 'Australia', 'CA' => 'Canada', 'SG' => 'Singapore'] as $code => $label)
              <option value="{{ $code }}" @selected(old('country', $user->country ?? 'IN') === $code)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="pt-2">
        <button class="rounded-lg bg-slate-900 text-white px-5 py-2 text-sm font-medium hover:bg-slate-800">Save</button>
      </div>
      <p class="text-xs text-slate-500">
        Editing these does not change receipts already issued — each receipt keeps the details as they
        were when it was issued.
      </p>
    </form>
  </div>
@endsection
