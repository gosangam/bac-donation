@php
  // Admins see the admin pages first, then their own giving: they donate like
  // anyone else, and their dashboard holds their own subscriptions and payments.
  $nav = auth()->user()->is_admin
    ? ['admin.index' => 'Overview', 'admin.transactions' => 'Transactions', 'admin.users' => 'Donors', 'admin.subscriptions' => 'Subscriptions', 'admin.plans' => 'Plans', 'checkout.choose' => 'Give', 'dashboard' => 'My donations']
    : ['dashboard' => 'Dashboard', 'checkout.choose' => 'Give', 'subscriptions.index' => 'Subscriptions', 'transactions.index' => 'Transactions'];
@endphp
@php $block = $block ?? false; @endphp
@foreach ($nav as $route => $label)
  <a href="{{ route($route) }}"
     @if (request()->routeIs($route)) aria-current="page" @endif
     class="rounded-md whitespace-nowrap {{ $block ? 'px-3 py-2.5' : 'px-3 py-2' }} {{ request()->routeIs($route) ? 'bg-soft text-ink font-medium' : 'text-stone-600 hover:text-primary' }}">{{ $label }}</a>
@endforeach
