@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
  <h2 class="text-2xl font-semibold tracking-[-0.01em] text-ink mb-1">Sign in</h2>
  <p class="text-sm text-stone-500 mb-6">Access your donations and receipts.</p>

  <form method="POST" action="{{ route('login') }}" class="space-y-4">
    @csrf
    <div>
      <label class="field-label">Email</label>
      <input type="email" name="email" value="{{ old('email') }}" required autofocus
             class="field">
    </div>
    <div>
      <label class="field-label">Password</label>
      <input type="password" name="password" required
             class="field">
    </div>
    <label class="flex items-center gap-2 text-sm text-stone-600">
      <input type="checkbox" name="remember" class="rounded border-line accent-primary"> Remember me
    </label>
    <button class="btn-primary w-full">Sign in</button>
  </form>

  <p class="mt-6 text-center text-sm text-stone-500">
    No account? <a href="{{ route('register') }}" class="text-ink font-medium hover:underline">Create one</a>
    · <a href="{{ route('password.request') }}" class="text-ink font-medium hover:underline">Forgot password</a>
  </p>
  <p class="mt-3 text-center text-sm text-stone-500">
    You don't need an account to give —
    <a href="{{ route('checkout.choose') }}" class="text-ink font-medium hover:underline">donate now</a>.
  </p>
@endsection
