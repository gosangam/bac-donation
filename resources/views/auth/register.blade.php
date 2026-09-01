@extends('layouts.guest')
@section('title', 'Create account')
@section('content')
  <h2 class="text-lg font-semibold text-slate-900 mb-1">Create your account</h2>
  <p class="text-sm text-slate-500 mb-6">You will add your address at checkout.</p>

  <form method="POST" action="{{ route('register') }}" class="space-y-4">
    @csrf
    @foreach ([['name','Full name','text'],['email','Email','email'],['phone','Phone','tel']] as [$field, $label, $type])
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">{{ $label }}</label>
        <input type="{{ $type }}" name="{{ $field }}" value="{{ old($field) }}" required
               class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
      </div>
    @endforeach
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
      <input type="password" name="password" required
             class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
      <p class="text-xs text-slate-500 mt-1">At least 8 characters.</p>
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Confirm password</label>
      <input type="password" name="password_confirmation" required
             class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
    </div>
    <button class="w-full rounded-lg bg-slate-900 text-white py-2.5 text-sm font-medium hover:bg-slate-800">Create account</button>
  </form>

  <p class="mt-6 text-center text-sm text-slate-500">
    Already registered? <a href="{{ route('login') }}" class="text-slate-900 font-medium hover:underline">Sign in</a>
  </p>
@endsection
