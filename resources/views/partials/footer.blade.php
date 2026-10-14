{{-- Site footer. Everything in it comes from config('payments.org'), so it
     cannot drift from what the receipts print. --}}
@php
  $org = config('payments.org');
  $registrations = array_filter([
    'PAN' => $org['pan'] ?? null,
    '80G' => $org['registration_80g'] ?? null,
    '12A' => $org['registration_12a'] ?? null,
    'NGO DARPAN' => $org['darpan_id'] ?? null,
  ]);
@endphp
<footer class="mt-auto bg-ink text-white/80">
  <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
    <div class="flex flex-col gap-8 border-b border-white/10 pb-10 md:flex-row md:items-start md:justify-between">
      <div class="max-w-sm">
        <div class="flex items-center gap-2.5">
          <img src="{{ $org['logo_url'] }}" alt="" class="h-9 w-9 rounded bg-white object-contain p-0.5">
          <span class="text-lg font-semibold tracking-tight text-white">{{ $org['name'] }}</span>
        </div>
        <p class="mt-4 text-sm leading-relaxed text-white/60">{{ $org['address'] }}</p>
      </div>
      <div class="flex flex-col gap-3 text-lg sm:flex-row sm:gap-8">
        @if ($org['email'])
          <a href="mailto:{{ $org['email'] }}" class="inline-flex items-center gap-2.5 text-accent hover:underline">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
            {{ $org['email'] }}
          </a>
        @endif
        @if ($org['phone'])
          <a href="tel:{{ preg_replace('/[^\d+]/', '', $org['phone']) }}" class="inline-flex items-center gap-2.5 text-accent hover:underline">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
            {{ $org['phone'] }}
          </a>
        @endif
      </div>
    </div>

    <div class="flex flex-col gap-6 pt-8 text-sm md:flex-row md:items-end md:justify-between">
      @if ($registrations)
        <dl class="grid grid-cols-2 gap-x-8 gap-y-2 sm:flex sm:flex-wrap">
          @foreach ($registrations as $label => $value)
            <div>
              <dt class="text-xs uppercase tracking-wider text-white/40">{{ $label }}</dt>
              <dd class="font-medium text-white/80 break-all">{{ $value }}</dd>
            </div>
          @endforeach
        </dl>
      @endif
      <div class="flex flex-wrap gap-x-6 gap-y-2 text-white/50">
        @if ($org['website'])<a href="{{ $org['website'] }}" class="hover:text-white">Website</a>@endif
        <a href="{{ route('checkout.choose') }}#give" class="hover:text-white">Donate</a>
        @guest<a href="{{ route('login') }}" class="hover:text-white">My donations</a>@endguest
        <span>© {{ now()->year }} {{ $org['name'] }}</span>
      </div>
    </div>
  </div>
</footer>
