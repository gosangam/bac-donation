@extends('layouts.app')
@section('title', 'Subscriptions')
@section('content')
  <h1 class="text-2xl font-semibold text-slate-900 mb-6">Subscriptions</h1>
  <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600">
        <tr>
          @foreach (['Donor', 'Plan', 'Gateway', 'Gateway id', 'Started', 'Status'] as $heading)
            <th class="text-left font-medium px-4 py-3">{{ $heading }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @forelse ($subscriptions as $subscription)
          <tr class="border-t border-slate-100">
            <td class="px-4 py-3">
              <div class="font-medium text-slate-900">{{ $subscription->user->name }}</div>
              <div class="text-xs text-slate-500">{{ $subscription->user->email }}</div>
            </td>
            <td class="px-4 py-3">
              {{ $subscription->plan->name }}
              <div class="text-xs text-slate-500">{{ $subscription->plan->amount_formatted }} · {{ $subscription->plan->cadence }}</div>
            </td>
            <td class="px-4 py-3">{{ ucfirst($subscription->gateway) }}</td>
            <td class="px-4 py-3 font-mono text-xs break-all">{{ $subscription->gateway_subscription_id ?? '—' }}</td>
            <td class="px-4 py-3 whitespace-nowrap">{{ $subscription->started_at?->format('d M Y') ?? '—' }}</td>
            <td class="px-4 py-3">@include('partials.status-badge', ['status' => $subscription->status])</td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500">No subscriptions yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="mt-4">{{ $subscriptions->links() }}</div>
@endsection
