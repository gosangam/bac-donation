@php
  $v = fn ($field, $default = '') => old($field, $plan->$field ?? $default);
@endphp
<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
  <div>
    <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
    <input name="name" value="{{ $v('name') }}" required
           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-sm font-medium text-slate-700 mb-1">Slug</label>
    <input name="slug" value="{{ $v('slug') }}" required placeholder="monthly-500"
           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-sm font-medium text-slate-700 mb-1">Amount</label>
    <input name="amount_major" type="number" step="0.01" min="0.01" required
           value="{{ old('amount_major', $plan ? \App\Support\Money::toDecimal($plan->amount, $plan->currency) : '') }}"
           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    <p class="text-xs text-slate-500 mt-1">In rupees/dollars, not paise.</p>
  </div>
  <div>
    <label class="block text-sm font-medium text-slate-700 mb-1">Currency</label>
    <input name="currency" value="{{ $v('currency', config('payments.default_currency')) }}" maxlength="3" required
           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase">
  </div>
  <div>
    <label class="block text-sm font-medium text-slate-700 mb-1">Interval</label>
    <select name="interval" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      @foreach (['daily', 'weekly', 'monthly', 'yearly'] as $interval)
        <option value="{{ $interval }}" @selected($v('interval', 'monthly') === $interval)>{{ ucfirst($interval) }}</option>
      @endforeach
    </select>
  </div>
  <div>
    <label class="block text-sm font-medium text-slate-700 mb-1">Every</label>
    <input name="interval_count" type="number" min="1" max="12" value="{{ $v('interval_count', 1) }}" required
           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
  </div>
  <div class="sm:col-span-2 lg:col-span-3">
    <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
    <input name="description" value="{{ $v('description') }}" maxlength="500"
           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
  </div>

  @php
    $ids = [
      ['razorpay_plan_id', 'Razorpay plan id', 'plan_…', 'razorpay'],
      ['stripe_price_id', 'Stripe price id', 'price_…', 'stripe'],
      ['paypal_plan_id', 'PayPal plan id', 'P-…', 'paypal'],
    ];
  @endphp
  @foreach ($ids as [$field, $label, $placeholder, $gatewayKey])
    <div>
      <div class="flex items-center justify-between mb-1">
        <label class="block text-sm font-medium text-slate-700">{{ $label }}</label>
        {{-- Fetches what already exists in that gateway, so ids are picked, not
             retyped from another dashboard. --}}
        <button type="button" class="text-xs text-slate-500 hover:text-slate-900 underline"
                data-fetch-plans="{{ $gatewayKey }}"
                data-target="{{ $field }}-{{ $plan?->id ?? 'new' }}">Fetch from {{ ucfirst($gatewayKey) }}</button>
      </div>
      <input name="{{ $field }}" id="{{ $field }}-{{ $plan?->id ?? 'new' }}" placeholder="{{ $placeholder }}"
             list="{{ $field }}-{{ $plan?->id ?? 'new' }}-options"
             value="{{ old($field, $plan?->gatewayPlanId($gatewayKey)) }}"
             class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs">
      <datalist id="{{ $field }}-{{ $plan?->id ?? 'new' }}-options"></datalist>
      <p class="text-xs text-slate-500 mt-1" data-plans-status="{{ $field }}-{{ $plan?->id ?? 'new' }}"></p>
    </div>
  @endforeach

  <div class="flex items-end gap-6">
    <label class="flex items-center gap-2 text-sm text-slate-700">
      <input type="hidden" name="is_active" value="0">
      <input type="checkbox" name="is_active" value="1" @checked($v('is_active', true)) class="rounded border-slate-300">
      Active
    </label>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Sort</label>
      <input name="sort_order" type="number" min="0" value="{{ $v('sort_order', 0) }}"
             class="w-24 rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>
  </div>
</div>
