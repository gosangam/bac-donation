<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Dashboard') · {{ config('payments.org.name') }}</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: { colors: { brand: '{{ config('payments.org.brand_color') }}' } } } }
  </script>
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">
<div class="min-h-full">
  @guest
  <nav class="bg-white border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 flex items-center justify-between h-16">
      <a href="{{ route('checkout.choose') }}" class="flex items-center gap-2">
        <img src="{{ config('payments.org.logo_url') }}" alt="" class="h-9 w-9 rounded object-contain">
        <span class="font-semibold text-slate-900">{{ config('payments.org.name') }}</span>
      </a>
      <a href="{{ route('login') }}" class="text-sm text-slate-600 hover:text-slate-900">Sign in</a>
    </div>
  </nav>
  @endguest

  @auth
  <nav class="bg-white border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 flex items-center gap-6 h-16">
      <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0">
        <img src="{{ config('payments.org.logo_url') }}" alt="" class="h-9 w-9 rounded object-contain">
        <span class="font-semibold text-slate-900 hidden sm:block">{{ config('payments.org.name') }}</span>
      </a>
      <div class="flex items-center gap-1 text-sm overflow-x-auto">
        @php
          // Admins get their own navigation only. They cannot donate, so the
          // giving links would lead straight to a redirect.
          $nav = auth()->user()->is_admin
            ? ['admin.index' => 'Overview', 'admin.transactions' => 'Transactions', 'admin.users' => 'Donors', 'admin.subscriptions' => 'Subscriptions', 'admin.plans' => 'Plans']
            : ['dashboard' => 'Dashboard', 'checkout.choose' => 'Give', 'subscriptions.index' => 'Subscriptions', 'transactions.index' => 'Transactions'];
        @endphp
        @foreach ($nav as $route => $label)
          <a href="{{ route($route) }}"
             class="px-3 py-2 rounded-md whitespace-nowrap {{ request()->routeIs($route) ? 'bg-slate-100 text-slate-900 font-medium' : 'text-slate-600 hover:text-slate-900' }}">{{ $label }}</a>
        @endforeach
      </div>
      <div class="ml-auto flex items-center gap-3 shrink-0">
        @if (auth()->user()->is_admin)
          <span class="text-sm text-slate-600 hidden sm:block">{{ auth()->user()->name }}
            <span class="ml-1 text-xs bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded">admin</span></span>
        @else
          <a href="{{ route('profile') }}" class="text-sm text-slate-600 hover:text-slate-900 hidden sm:block">{{ auth()->user()->name }}</a>
        @endif
        <form method="POST" action="{{ route('logout') }}">@csrf
          <button class="text-sm text-slate-500 hover:text-slate-900">Sign out</button>
        </form>
      </div>
    </div>
  </nav>
  @endauth

  <main class="max-w-6xl mx-auto px-4 py-8">
    @include('partials.flash')
    @yield('content')
  </main>
</div>
</body>
</html>
