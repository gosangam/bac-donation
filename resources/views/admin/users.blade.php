@extends('layouts.app')
@section('title', 'Donors')
@section('content')
  <h1 class="text-2xl font-semibold text-slate-900 mb-6">Donors</h1>
  <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600">
        <tr>
          <th class="text-left font-medium px-4 py-3">Name</th>
          <th class="text-left font-medium px-4 py-3">Contact</th>
          <th class="text-left font-medium px-4 py-3">Joined</th>
          <th class="text-right font-medium px-4 py-3">Payments</th>
          <th class="text-right font-medium px-4 py-3">Total given</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($users as $user)
          <tr class="border-t border-slate-100">
            <td class="px-4 py-3">
              <a href="{{ route('admin.users.show', $user) }}"
                 class="font-medium text-slate-900 hover:underline">{{ $user->name }}</a>
              @if ($user->is_admin)<span class="ml-2 text-xs bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded">admin</span>@endif
            </td>
            <td class="px-4 py-3 text-slate-600">
              <div>{{ $user->email }}</div>
              <div class="text-xs text-slate-500">{{ $user->phone ?? '—' }}</div>
            </td>
            <td class="px-4 py-3 whitespace-nowrap">{{ $user->created_at->format('d M Y') }}</td>
            <td class="px-4 py-3 text-right">{{ $user->transactions_count }}</td>
            <td class="px-4 py-3 text-right font-medium whitespace-nowrap">
              {{ \App\Support\Money::format((int) ($user->paid_total ?? 0), config('payments.default_currency')) }}
            </td>
            <td class="px-4 py-3 text-right">
              <a href="{{ route('admin.users.show', $user) }}" class="text-slate-600 hover:text-slate-900 underline">View</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div class="mt-4">{{ $users->links() }}</div>
@endsection
