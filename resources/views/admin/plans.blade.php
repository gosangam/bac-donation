@extends('layouts.app')
@section('title', 'Plans')
@section('content')
  <h1 class="text-2xl font-semibold text-slate-900 mb-1">Plans</h1>
  <p class="text-sm text-slate-500 mb-6">
    A plan must also exist inside each gateway you want to offer it through. This app only stores the
    mapping — creating the plan in Razorpay, Stripe or PayPal is done in their dashboards.
  </p>

  <div class="space-y-4 mb-8">
    @forelse ($plans as $plan)
      <details class="bg-white rounded-xl border border-slate-200">
        <summary class="px-4 sm:px-5 py-4 cursor-pointer flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <div class="min-w-0">
            <span class="font-semibold text-slate-900">{{ $plan->name }}</span>
            <span class="text-sm text-slate-500 sm:ml-2 block sm:inline">{{ $plan->amount_formatted }} · {{ $plan->cadence }}</span>
          </div>
          {{-- Wraps rather than pushing the row past the viewport on a phone. --}}
          <div class="flex flex-wrap items-center gap-2 text-xs">
            @foreach (['razorpay', 'stripe', 'paypal'] as $g)
              <span class="px-2 py-0.5 rounded {{ $plan->gatewayPlanId($g) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-400' }}">{{ $g }}</span>
            @endforeach
            @include('partials.status-badge', ['status' => $plan->is_active ? 'active' : 'cancelled'])
          </div>
        </summary>
        <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="px-4 sm:px-5 pb-5 pt-2 border-t border-slate-100">
          @csrf @method('PUT')
          @include('admin.partials.plan-fields', ['plan' => $plan])
          <button class="mt-4 rounded-lg bg-slate-900 text-white px-4 py-2 text-sm font-medium">Save changes</button>
        </form>
      </details>
    @empty
      <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
        No plans yet. Add the first one below.
      </div>
    @endforelse
  </div>

  <section class="bg-white rounded-xl border border-slate-200 p-5">
    <h2 class="font-semibold text-slate-900 mb-4">Add a plan</h2>
    <form method="POST" action="{{ route('admin.plans.store') }}">
      @csrf
      @include('admin.partials.plan-fields', ['plan' => null])
      <button class="mt-4 rounded-lg bg-brand text-white px-4 py-2 text-sm font-medium">Create plan</button>
    </form>
  </section>

  <script>
    // Populates a datalist from the gateway's own plan list. A datalist keeps the
    // field free-text, so an id can still be pasted if the gateway call fails.
    document.querySelectorAll('[data-fetch-plans]').forEach((button) => {
      button.addEventListener('click', async () => {
        const gateway = button.dataset.fetchPlans;
        const targetId = button.dataset.target;
        const input = document.getElementById(targetId);
        const list = document.getElementById(targetId + '-options');
        const status = document.querySelector(`[data-plans-status="${targetId}"]`);

        button.disabled = true;
        const original = button.textContent;
        button.textContent = 'Fetching…';
        status.textContent = '';
        status.className = 'text-xs text-slate-500 mt-1';

        try {
          const response = await fetch(`{{ url('admin/gateway-plans') }}/${gateway}`, {
            headers: { Accept: 'application/json' },
          });
          const data = await response.json();

          if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);

          list.innerHTML = '';
          for (const plan of data.plans) {
            const option = document.createElement('option');
            option.value = plan.id;
            const money = plan.amount != null
              ? new Intl.NumberFormat(plan.currency === 'INR' ? 'en-IN' : 'en-US',
                  { style: 'currency', currency: plan.currency }).format(plan.amount / 100)
              : null;
            option.label = [plan.name, money, plan.cadence].filter(Boolean).join(' · ');
            list.appendChild(option);
          }

          status.textContent = data.plans.length
            ? `${data.plans.length} plan(s) — click the field to choose.`
            : 'No recurring plans exist in that gateway yet.';
          if (data.plans.length) input.focus();
        } catch (error) {
          status.textContent = error.message;
          status.className = 'text-xs text-red-600 mt-1';
        } finally {
          button.disabled = false;
          button.textContent = original;
        }
      });
    });
  </script>
@endsection
