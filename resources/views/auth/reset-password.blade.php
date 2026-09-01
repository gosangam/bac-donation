@extends('layouts.guest')
@section('title', 'Set password')
@section('content')
  <h2 class="text-lg font-semibold text-slate-900 mb-1">Set your password</h2>
  <p class="text-sm text-slate-500 mb-6">Choose a password to access your donations and receipts.</p>

  <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
      <input type="email" name="email" value="{{ old('email', $email) }}" required readonly
             class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">New password</label>
      <input type="password" name="password" required autofocus
             class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
      <p class="text-xs text-slate-500 mt-1">At least 8 characters.</p>
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Confirm password</label>
      <input type="password" name="password_confirmation" required
             class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
    </div>
    <button class="w-full rounded-lg bg-slate-900 text-white py-2.5 text-sm font-medium hover:bg-slate-800">Set password &amp; sign in</button>
  </form>
@endsection
