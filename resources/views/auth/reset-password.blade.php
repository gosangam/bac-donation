@extends('layouts.guest')
@section('title', 'Set password')
@section('content')
  <h2 class="text-2xl font-semibold tracking-[-0.01em] text-ink mb-1">Set your password</h2>
  <p class="text-sm text-stone-500 mb-6">Choose a password to access your donations and receipts.</p>

  <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div>
      <label class="field-label">Email</label>
      <input type="email" name="email" value="{{ old('email', $email) }}" required readonly
             class="w-full rounded-[3px] border border-line bg-stone-50 px-3 py-2.5 text-sm text-stone-600">
    </div>
    <div>
      <label class="field-label">New password</label>
      <input type="password" name="password" required autofocus
             class="field">
      <p class="text-xs text-stone-500 mt-1">At least 8 characters.</p>
    </div>
    <div>
      <label class="field-label">Confirm password</label>
      <input type="password" name="password_confirmation" required
             class="field">
    </div>
    <button class="btn-primary w-full">Set password &amp; sign in</button>
  </form>
@endsection
