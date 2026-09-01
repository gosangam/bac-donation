{{-- Mobile navigation drawer. Vanilla JS on purpose: this app has no build step
     and no Alpine, and a menu is not worth adding either for. --}}
<button type="button" id="nav-toggle"
        class="sm:hidden -mr-1 p-2 rounded-md text-slate-600 hover:bg-slate-100"
        aria-controls="mobile-nav" aria-expanded="false" aria-label="Open menu">
  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" aria-hidden="true">
    <path d="M4 7h16M4 12h16M4 17h16" />
  </svg>
</button>

<div id="mobile-nav" class="sm:hidden hidden fixed inset-0 z-50" role="dialog" aria-modal="true"
     aria-label="Menu">
  <div id="nav-backdrop" class="absolute inset-0 bg-slate-900/50"></div>

  {{-- Offset set inline rather than with a Tailwind class: one unambiguous
       property to read and write, whatever the CDN runtime is doing. --}}
  <div id="nav-panel"
       class="absolute inset-y-0 right-0 w-72 max-w-[85%] bg-white shadow-xl flex flex-col
              transition-transform duration-200 motion-reduce:transition-none"
       style="transform: translateX(100%)">
    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200">
      <span class="font-semibold text-slate-900 truncate">{{ config('payments.org.name') }}</span>
      <button type="button" id="nav-close" class="p-2 -mr-2 rounded-md text-slate-500 hover:bg-slate-100"
              aria-label="Close menu">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" aria-hidden="true">
          <path d="M6 6l12 12M18 6L6 18" />
        </svg>
      </button>
    </div>

    <nav class="flex-1 overflow-y-auto p-3 flex flex-col gap-1 text-sm">
      @include('partials.nav-links', ['block' => true])
    </nav>

    <div class="border-t border-slate-200 p-4">
      <div class="text-sm text-slate-900 font-medium truncate">{{ auth()->user()->name }}</div>
      <div class="text-xs text-slate-500 truncate">{{ auth()->user()->email }}</div>
      @unless (auth()->user()->is_admin)
        <a href="{{ route('profile') }}" class="block mt-3 text-sm text-slate-600 hover:text-slate-900">Your details</a>
      @endunless
      <form method="POST" action="{{ route('logout') }}" class="mt-3">@csrf
        <button class="text-sm text-slate-500 hover:text-slate-900">Sign out</button>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var drawer = document.getElementById('mobile-nav');
  var panel  = document.getElementById('nav-panel');
  var toggle = document.getElementById('nav-toggle');
  var lastFocused = null;

  function focusable() {
    return drawer.querySelectorAll('a[href], button:not([disabled])');
  }

  function open() {
    lastFocused = document.activeElement;
    drawer.classList.remove('hidden');
    // Force a reflow so the browser has a start value to transition FROM.
    // Deliberately not requestAnimationFrame: if rAF is throttled or never
    // fires, the panel would sit off-screen and the menu would look broken.
    void panel.offsetWidth;
    panel.style.transform = 'translateX(0)';
    toggle.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';   // no scrolling the page behind it
    var first = focusable()[0];
    if (first) first.focus();
  }

  function close() {
    panel.style.transform = 'translateX(100%)';
    toggle.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    // Wait for the slide-out before hiding, or it vanishes instantly. No wait
    // when the user has asked for reduced motion — there is nothing to watch.
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    setTimeout(function () { drawer.classList.add('hidden'); }, reduced ? 0 : 200);
    if (lastFocused) lastFocused.focus();
  }

  toggle.addEventListener('click', open);
  document.getElementById('nav-close').addEventListener('click', close);
  document.getElementById('nav-backdrop').addEventListener('click', close);

  document.addEventListener('keydown', function (e) {
    if (drawer.classList.contains('hidden')) return;

    if (e.key === 'Escape') { close(); return; }

    // Keep Tab inside the drawer while it is modal.
    if (e.key === 'Tab') {
      var items = focusable();
      if (!items.length) return;
      var first = items[0], last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  // Returning via the back button can restore a page with the drawer open.
  window.addEventListener('pageshow', function () {
    if (!drawer.classList.contains('hidden')) close();
  });
})();
</script>
