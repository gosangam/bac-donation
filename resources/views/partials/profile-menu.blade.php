{{-- Profile menu: an initials avatar opening "Your profile" and "Sign out".
     Desktop only; on phones the same two links sit in the mobile drawer.
     Vanilla JS for the same reason as that drawer: no build step, no Alpine. --}}
@php
  $user = auth()->user();
  $initials = collect(preg_split('/\s+/', trim($user->name)))
    ->filter()
    ->take(2)
    ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
    ->implode('');
@endphp

<div class="relative hidden sm:block" data-profile-menu>
  <button type="button" data-profile-toggle aria-haspopup="menu" aria-expanded="false"
          aria-controls="profile-menu" aria-label="Account menu for {{ $user->name }}"
          class="flex items-center gap-1.5 rounded-full p-0.5 pr-1.5 text-stone-600 hover:text-primary
                 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
    <span class="grid h-9 w-9 place-items-center rounded-full bg-primary text-accent text-sm font-semibold">
      {{ $initials ?: '?' }}
    </span>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M6 9l6 6 6-6" />
    </svg>
  </button>

  <div id="profile-menu" role="menu" hidden
       class="absolute right-0 mt-2 w-64 rounded-xl border border-line bg-white shadow-lg overflow-hidden z-50">
    <div class="px-4 py-3 border-b border-line/70">
      <div class="flex items-center gap-2">
        <span class="font-medium text-ink truncate">{{ $user->name }}</span>
        @if ($user->is_admin)
          <span class="text-xs bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded shrink-0">admin</span>
        @endif
      </div>
      <div class="text-xs text-stone-500 truncate">{{ $user->email }}</div>
    </div>
    <div class="p-1.5 text-sm">
      <a href="{{ route('profile') }}" role="menuitem"
         class="flex items-center gap-2 rounded-lg px-3 py-2 text-stone-700 hover:bg-stone-50 hover:text-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" />
        </svg>
        Your profile
      </a>
      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" role="menuitem"
                class="w-full flex items-center gap-2 rounded-lg px-3 py-2 text-stone-700 hover:bg-stone-50 hover:text-primary">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
          </svg>
          Sign out
        </button>
      </form>
    </div>
  </div>
</div>

@push('scripts')
<script>
(function () {
  var root = document.querySelector('[data-profile-menu]');
  if (!root) return;

  var toggle = root.querySelector('[data-profile-toggle]');
  var menu = root.querySelector('[role="menu"]');

  function setOpen(open) {
    menu.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) menu.querySelector('[role="menuitem"]').focus();
  }

  toggle.addEventListener('click', function () { setOpen(menu.hidden); });

  document.addEventListener('click', function (event) {
    if (!menu.hidden && !root.contains(event.target)) setOpen(false);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !menu.hidden) {
      setOpen(false);
      toggle.focus();
    }
  });
})();
</script>
@endpush
