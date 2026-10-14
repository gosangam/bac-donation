@extends('layouts.app')
@section('title', $user->exists ? 'Edit '.$user->name : 'New account')
@section('content')
  <a href="{{ $user->exists ? route('admin.users.show', $user) : route('admin.users') }}"
     class="text-sm text-slate-500 hover:text-slate-900">← {{ $user->exists ? $user->name : 'All donors' }}</a>

  <div class="max-w-2xl mt-4">
    <h1 class="text-2xl font-semibold text-slate-900 mb-1">{{ $user->exists ? 'Edit account' : 'New account' }}</h1>
    <p class="text-sm text-slate-500 mb-6">
      Only name and email are required. Receipts already issued keep the details they were issued with.
    </p>

    <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}"
          class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      @csrf
      @if ($user->exists) @method('PUT') @endif

      <div class="grid sm:grid-cols-2 gap-4">
        @php
          $fields = [
            ['name', 'Full name', 'text', true], ['email', 'Email', 'email', true],
            ['phone', 'Phone', 'tel', false], ['address_line1', 'Address line 1', 'text', false],
            ['address_line2', 'Address line 2', 'text', false], ['city', 'City', 'text', false],
            ['state', 'State', 'text', false], ['postal_code', 'PIN / ZIP', 'text', false],
          ];
        @endphp
        @foreach ($fields as [$field, $label, $type, $required])
          <div>
            <label for="{{ $field }}" class="block text-sm font-medium text-slate-700 mb-1">{{ $label }}</label>
            <input id="{{ $field }}" type="{{ $type }}" name="{{ $field }}" value="{{ old($field, $user->$field) }}"
                   @if ($required) required @endif
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
          </div>
        @endforeach
        <div>
          <label for="country" class="block text-sm font-medium text-slate-700 mb-1">Country</label>
          <select id="country" name="country" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">—</option>
            @foreach (['IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'UAE', 'AU' => 'Australia', 'CA' => 'Canada', 'SG' => 'Singapore'] as $code => $label)
              <option value="{{ $code }}" @selected(old('country', $user->country ?? 'IN') === $code)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        @include('partials.identity-proof', [
          'type' => old('id_type', $user->id_type),
          'number' => old('id_number', $user->id_number),
          'required' => false,
        ])
        <div class="sm:col-span-2">
          <label for="password" class="block text-sm font-medium text-slate-700 mb-1">
            Password <span class="text-slate-400">({{ $user->exists ? 'leave blank to keep the current one' : 'optional' }})</span>
          </label>
          <input id="password" type="password" name="password" autocomplete="new-password" minlength="8"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
        </div>
      </div>

      <div class="space-y-2 pt-1">
        <label class="flex items-start gap-2 text-sm text-slate-700">
          <input type="hidden" name="send_invite" value="0">
          <input type="checkbox" name="send_invite" value="1" class="mt-0.5"
                 @checked(old('send_invite', ! $user->exists))>
          <span>Email a link to set their password <span class="text-slate-400">— the same email a new guest donor gets</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm text-slate-700">
          <input type="hidden" name="is_admin" value="0">
          <input type="checkbox" name="is_admin" value="1" class="mt-0.5"
                 @checked(old('is_admin', $user->is_admin))
                 @disabled($user->exists && $user->is(auth()->user()))>
          <span>Admin <span class="text-slate-400">— can open this admin area, and can still donate like anyone else</span></span>
        </label>
        @if ($user->exists && $user->is(auth()->user()))
          {{-- A disabled checkbox is not submitted, so restate it. --}}
          <input type="hidden" name="is_admin" value="1">
          <p class="text-xs text-slate-500 pl-6">You cannot remove your own admin access.</p>
        @endif
      </div>

      <div class="pt-2">
        <button class="rounded-lg bg-primary text-white px-5 py-2 text-sm font-medium hover:bg-primary-dark">
          {{ $user->exists ? 'Save changes' : 'Create account' }}
        </button>
      </div>
    </form>
  </div>
@endsection
