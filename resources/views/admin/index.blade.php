@extends('layouts.app')
@section('title', 'Admin')
@section('content')
  <h1 class="text-2xl font-semibold text-slate-900 mb-6">Overview</h1>

  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Received</div>
      @forelse ($received as $row)
        <div class="text-xl font-semibold text-slate-900 mt-1">
          {{ \App\Support\Money::format($row->total, $row->currency) }}
        </div>
        <div class="text-xs text-slate-500">{{ $row->count }} payments in {{ $row->currency }}</div>
      @empty
        <div class="text-xl font-semibold text-slate-900 mt-1">—</div>
      @endforelse
    </div>
    @foreach ([['Donors', $donors], ['Active subscriptions', $activeSubscriptions], ['Pending / failed', $pending.' / '.$failed]] as [$label, $value])
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">{{ $label }}</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ $value }}</div>
      </div>
    @endforeach
  </div>

  <div class="grid lg:grid-cols-2 gap-6">
    <section class="bg-white rounded-xl border border-slate-200">
      <h2 class="px-5 py-4 border-b border-slate-200 font-semibold text-slate-900">By gateway</h2>
      @forelse ($byGateway as $row)
        <div class="px-5 py-3 border-b border-slate-100 last:border-0 flex justify-between text-sm">
          <span class="text-slate-600">{{ ucfirst($row->gateway) }} <span class="text-slate-400">({{ $row->count }})</span></span>
          <span class="font-medium">{{ \App\Support\Money::format($row->total, $row->currency) }}</span>
        </div>
      @empty
        <p class="px-5 py-8 text-center text-sm text-slate-500">No completed payments yet.</p>
      @endforelse
    </section>

    <section class="bg-white rounded-xl border border-slate-200">
      <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
        <h2 class="font-semibold text-slate-900">Recent</h2>
        <a href="{{ route('admin.transactions') }}" class="text-sm text-slate-500 hover:text-slate-900">All</a>
      </div>
      @forelse ($recent as $txn)
        <div class="px-5 py-3 border-b border-slate-100 last:border-0 flex items-center justify-between gap-3 text-sm">
          <div class="min-w-0">
            <div class="font-medium text-slate-900 truncate">{{ $txn->donor_name }}</div>
            <div class="text-xs text-slate-500">{{ $txn->created_at->format('d M, g:i a') }} · {{ $txn->gateway_label }}</div>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <span class="font-medium">{{ $txn->amount_formatted }}</span>
            @include('partials.status-badge', ['status' => $txn->status])
          </div>
        </div>
      @empty
        <p class="px-5 py-8 text-center text-sm text-slate-500">Nothing yet.</p>
      @endforelse
    </section>
  </div>
@endsection
