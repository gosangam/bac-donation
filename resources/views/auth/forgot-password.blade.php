@extends('layouts.guest')
@section('title', 'Reset password')
@section('content')
  <h2 class="text-lg font-semibold text-slate-900 mb-1">Reset your password</h2>
  <p class="text-sm text-slate-500 mb-6">We'll email you a link to set a new one.</p>

  <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
    @csrf
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
      <input type="email" name="email" value="{{ old('email') }}" required autofocus
             class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
    </div>
    <button class="w-full rounded-lg bg-slate-900 text-white py-2.5 text-sm font-medium hover:bg-slate-800">Send reset link</button>
  </form>

  <p class="mt-6 text-center text-sm text-slate-500">
    <a href="{{ route('login') }}" class="text-slate-900 font-medium hover:underline">Back to sign in</a>
  </p>
@endsection
