@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
  <h2 class="text-lg font-semibold text-slate-900 mb-1">Sign in</h2>
  <p class="text-sm text-slate-500 mb-6">Access your donations and receipts.</p>

  <form method="POST" action="{{ route('login') }}" class="space-y-4">
    @csrf
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
      <input type="email" name="email" value="{{ old('email') }}" required autofocus
             class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
      <input type="password" name="password" required
             class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-600">
      <input type="checkbox" name="remember" class="rounded border-slate-300"> Remember me
    </label>
    <button class="w-full rounded-lg bg-slate-900 text-white py-2.5 text-sm font-medium hover:bg-slate-800">Sign in</button>
  </form>

  <p class="mt-6 text-center text-sm text-slate-500">
    No account? <a href="{{ route('register') }}" class="text-slate-900 font-medium hover:underline">Create one</a>
  </p>
@endsection
