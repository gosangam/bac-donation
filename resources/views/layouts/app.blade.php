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
      <a href="{{ route('checkout.choose') }}" class="flex items-center gap-2 min-w-0">
        <img src="{{ config('payments.org.logo_url') }}" alt="" class="h-9 w-9 rounded object-contain shrink-0">
        {{-- Name is dropped on the narrowest screens; it wraps to two lines and
             squeezes the actions otherwise. --}}
        <span class="font-semibold text-slate-900 hidden sm:block truncate">{{ config('payments.org.name') }}</span>
      </a>
      <div class="flex items-center gap-3 sm:gap-4 text-sm shrink-0">
        <a href="{{ route('login') }}" class="text-slate-600 hover:text-slate-900 whitespace-nowrap">
          <span class="hidden sm:inline">My donations</span>
          <span class="sm:hidden">Sign in</span>
        </a>
        <a href="{{ route('checkout.choose') }}"
           class="rounded-lg bg-brand text-white px-3 sm:px-4 py-2 font-medium hover:opacity-90 whitespace-nowrap">Donate</a>
      </div>
    </div>
  </nav>
  @endguest

  @auth
  <nav class="bg-white border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4">
      <div class="flex items-center gap-3 sm:gap-6 h-14 sm:h-16">
        <a href="{{ route(auth()->user()->is_admin ? 'admin.index' : 'dashboard') }}"
           class="flex items-center gap-2 shrink-0 min-w-0">
          <img src="{{ config('payments.org.logo_url') }}" alt="" class="h-9 w-9 rounded object-contain shrink-0">
          <span class="font-semibold text-slate-900 hidden md:block truncate">{{ config('payments.org.name') }}</span>
        </a>

        {{-- Inline on desktop; on mobile the links move to their own row below so
             the current page is not pushed off the edge. --}}
        <div class="hidden sm:flex items-center gap-1 text-sm overflow-x-auto">
          @include('partials.nav-links')
        </div>

        <div class="ml-auto flex items-center gap-3 shrink-0">
          @if (auth()->user()->is_admin)
            <span class="text-sm text-slate-600 hidden lg:block">{{ auth()->user()->name }}
              <span class="ml-1 text-xs bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded">admin</span></span>
          @else
            <a href="{{ route('profile') }}" class="text-sm text-slate-600 hover:text-slate-900 hidden lg:block">{{ auth()->user()->name }}</a>
          @endif
          <form method="POST" action="{{ route('logout') }}" class="hidden sm:block">@csrf
            <button class="text-sm text-slate-500 hover:text-slate-900 whitespace-nowrap">Sign out</button>
          </form>

          @include('partials.mobile-nav')
        </div>
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
