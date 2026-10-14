@extends('layouts.guest')
@section('title', 'Create account')
@section('content')
  <h2 class="text-2xl font-semibold tracking-[-0.01em] text-ink mb-1">Create your account</h2>
  <p class="text-sm text-stone-500 mb-6">You will add your address at checkout.</p>

  <form method="POST" action="{{ route('register') }}" class="space-y-4">
    @csrf
    @foreach ([['name','Full name','text'],['email','Email','email'],['phone','Phone','tel']] as [$field, $label, $type])
      <div>
        <label class="field-label">{{ $label }}</label>
        <input type="{{ $type }}" name="{{ $field }}" value="{{ old($field) }}" required
               class="field">
      </div>
    @endforeach
    <div>
      <label class="field-label">Password</label>
      <input type="password" name="password" required
             class="field">
      <p class="text-xs text-stone-500 mt-1">At least 8 characters.</p>
    </div>
    <div>
      <label class="field-label">Confirm password</label>
      <input type="password" name="password_confirmation" required
             class="field">
    </div>
    <button class="btn-primary w-full">Create account</button>
  </form>

  <p class="mt-6 text-center text-sm text-stone-500">
    Already registered? <a href="{{ route('login') }}" class="text-ink font-medium hover:underline">Sign in</a>
  </p>
@endsection
