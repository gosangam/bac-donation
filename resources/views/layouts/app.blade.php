<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Dashboard') · {{ config('payments.org.name') }}</title>
  @include('partials.theme-head')
</head>
<body class="min-h-full bg-cream font-sans text-forest antialiased">
<div class="min-h-screen flex flex-col">
  @guest
  <nav class="sticky top-0 z-40 border-b border-line/70 bg-cream/90 backdrop-blur">
    <div class="mx-auto flex h-[72px] max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
      <a href="{{ route('checkout.choose') }}" class="flex min-w-0 items-center gap-2.5">
        <img src="{{ config('payments.org.logo_url') }}" alt="" class="h-9 w-9 shrink-0 rounded object-contain">
        {{-- Name is dropped on the narrowest screens; it wraps to two lines and
             squeezes the actions otherwise. --}}
        <span class="hidden truncate text-lg font-semibold tracking-tight sm:block">{{ config('payments.org.name') }}</span>
      </a>
      @if (request()->routeIs('checkout.choose'))
        <div class="hidden items-center gap-8 text-[15px] text-stone-600 lg:flex">
          <a href="#about" class="hover:text-forest">About</a>
          <a href="#work" class="hover:text-forest">Our work</a>
          <a href="#give" class="hover:text-forest">Donate</a>
          <a href="#faq" class="hover:text-forest">FAQs</a>
        </div>
      @endif
      <div class="flex shrink-0 items-center gap-4 text-sm sm:gap-6">
        <a href="{{ route('login') }}" class="whitespace-nowrap text-stone-600 hover:text-forest">
          <span class="hidden sm:inline">My donations</span>
          <span class="sm:hidden">Sign in</span>
        </a>
        <a href="{{ route('checkout.choose') }}#give" class="btn-primary !px-4 !py-2.5">Donate</a>
      </div>
    </div>
  </nav>
  @endguest

  @auth
  {{-- Solid, not blurred: backdrop-filter would make this nav the containing
       block for the fixed mobile drawer inside it, trapping the drawer here. --}}
  <nav class="sticky top-0 z-40 border-b border-line/70 bg-cream">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
      <div class="flex items-center gap-3 sm:gap-6 h-14 sm:h-16">
        <a href="{{ route(auth()->user()->is_admin ? 'admin.index' : 'dashboard') }}"
           class="flex items-center gap-2 shrink-0 min-w-0">
          <img src="{{ config('payments.org.logo_url') }}" alt="" class="h-9 w-9 rounded object-contain shrink-0">
          <span class="font-semibold tracking-tight hidden md:block truncate">{{ config('payments.org.name') }}</span>
        </a>

        {{-- Inline on desktop; on mobile the links move to their own row below so
             the current page is not pushed off the edge. --}}
        <div class="hidden sm:flex items-center gap-1 text-sm overflow-x-auto">
          @include('partials.nav-links')
        </div>

        <div class="ml-auto flex items-center gap-3 shrink-0">
          @if (auth()->user()->is_admin)
            <span class="text-sm text-stone-600 hidden lg:block">{{ auth()->user()->name }}
              <span class="ml-1 text-xs bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded">admin</span></span>
          @else
            <a href="{{ route('profile') }}" class="text-sm text-stone-600 hover:text-forest hidden lg:block">{{ auth()->user()->name }}</a>
          @endif
          <form method="POST" action="{{ route('logout') }}" class="hidden sm:block">@csrf
            <button class="text-sm text-stone-500 hover:text-forest whitespace-nowrap">Sign out</button>
          </form>

          @include('partials.mobile-nav')
        </div>
      </div>
    </div>
  </nav>
  @endauth

  {{-- A page that defines 'landing' lays out its own full-width sections and
       places the flash messages itself; everything else gets the container. --}}
  @hasSection('landing')
    <main class="flex-1">@yield('landing')</main>
  @else
    <main class="w-full max-w-6xl mx-auto flex-1 px-4 sm:px-6 py-10">
      @include('partials.flash')
      @yield('content')
    </main>
  @endif

  @unless (auth()->user()?->is_admin)
    @include('partials.footer')
  @endunless
</div>

{{-- Partials that need behaviour push it here, so their script lands once and
     after the markup it drives. --}}
@stack('scripts')
</body>
</html>
