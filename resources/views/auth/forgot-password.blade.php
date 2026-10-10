@extends('layouts.guest')
@section('title', 'Reset password')
@section('content')
  <h2 class="text-2xl font-semibold tracking-[-0.01em] text-forest mb-1">Reset your password</h2>
  <p class="text-sm text-stone-500 mb-6">We'll email you a link to set a new one.</p>

  <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
    @csrf
    <div>
      <label class="field-label">Email</label>
      <input type="email" name="email" value="{{ old('email') }}" required autofocus
             class="field">
    </div>
    <button class="btn-primary w-full">Send reset link</button>
  </form>

  <p class="mt-6 text-center text-sm text-stone-500">
    <a href="{{ route('login') }}" class="text-forest font-medium hover:underline">Back to sign in</a>
  </p>
@endsection
