<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title') · {{ config('payments.org.name') }}</title>
  @include('partials.theme-head')
</head>
<body class="min-h-full bg-white font-sans text-ink antialiased">
  <div class="min-h-full flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <a href="{{ route('checkout.choose') }}">
          <img src="{{ config('payments.org.logo_url') }}" alt="" class="h-16 w-16 mx-auto rounded object-contain">
          <h1 class="mt-4 text-xl font-semibold tracking-tight">{{ config('payments.org.name') }}</h1>
        </a>
      </div>
      <div class="card p-7 shadow-[0_20px_50px_-30px_rgba(19,42,19,.35)]">
        @include('partials.flash')
        @yield('content')
      </div>
    </div>
  </div>
</body>
</html>
