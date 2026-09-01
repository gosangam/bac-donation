@php
  $styles = [
    'paid' => 'bg-emerald-100 text-emerald-800',
    'active' => 'bg-emerald-100 text-emerald-800',
    'pending' => 'bg-amber-100 text-amber-800',
    'failed' => 'bg-red-100 text-red-800',
    'cancelled' => 'bg-slate-200 text-slate-700',
  ];
@endphp
<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $styles[$status] ?? 'bg-slate-100 text-slate-700' }}">
  {{ ucfirst($status) }}
</span>
