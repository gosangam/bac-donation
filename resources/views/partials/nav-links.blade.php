@php
  // Admins get their own navigation only. They cannot donate, so the giving
  // links would lead straight to a redirect.
  $nav = auth()->user()->is_admin
    ? ['admin.index' => 'Overview', 'admin.transactions' => 'Transactions', 'admin.users' => 'Donors', 'admin.subscriptions' => 'Subscriptions', 'admin.plans' => 'Plans']
    : ['dashboard' => 'Dashboard', 'checkout.choose' => 'Give', 'subscriptions.index' => 'Subscriptions', 'transactions.index' => 'Transactions'];
@endphp
@php $block = $block ?? false; @endphp
@foreach ($nav as $route => $label)
  <a href="{{ route($route) }}"
     @if (request()->routeIs($route)) aria-current="page" @endif
     class="rounded-md whitespace-nowrap {{ $block ? 'px-3 py-2.5' : 'px-3 py-2' }} {{ request()->routeIs($route) ? 'bg-slate-100 text-slate-900 font-medium' : 'text-slate-600 hover:text-slate-900' }}">{{ $label }}</a>
@endforeach
