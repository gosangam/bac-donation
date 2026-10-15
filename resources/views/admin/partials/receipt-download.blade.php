{{-- PDF receipt download with a choice of how the donor's ID is printed.

     Expects: $txn. The menu floats with position: fixed, placed under its
     button by the script below: absolute positioning would be clipped by the
     tables' overflow-x-auto wrapper, and opening in place pushed the row
     taller. A payment with no ID on file has nothing to choose between and
     gets a plain link. --}}
@if (blank($txn->donor_id_number))
  <a href="{{ route('admin.receipt', $txn) }}" class="text-primary font-medium hover:underline">PDF</a>
@else
  <details class="inline-block text-left" data-receipt-menu>
    <summary class="list-none cursor-pointer inline-flex items-center gap-1 text-primary font-medium hover:underline">
      PDF
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
    </summary>
    <div class="fixed z-50 w-56 rounded-lg border border-slate-200 bg-white p-1 text-sm font-normal shadow-lg" data-receipt-menu-panel>
      <a href="{{ route('admin.receipt', [$txn, 'identity' => 'masked']) }}"
         class="block rounded-md px-3 py-2 text-slate-700 hover:bg-slate-50 whitespace-nowrap">Receipt with ID masked</a>
      <a href="{{ route('admin.receipt', [$txn, 'identity' => 'full']) }}"
         class="block rounded-md px-3 py-2 text-slate-700 hover:bg-slate-50 whitespace-nowrap">Receipt with full ID</a>
    </div>
  </details>

  @once
    @push('scripts')
    <script>
    (function () {
      function menus() { return document.querySelectorAll('[data-receipt-menu][open]'); }
      function closeAll(except) {
        menus().forEach(function (menu) { if (menu !== except) menu.open = false; });
      }

      // Pin the open panel under its button, right-aligned, flipping above
      // when there is no room below.
      document.addEventListener('toggle', function (event) {
        var menu = event.target;
        if (!menu.matches || !menu.matches('[data-receipt-menu]') || !menu.open) return;

        closeAll(menu);
        var button = menu.querySelector('summary').getBoundingClientRect();
        var panel = menu.querySelector('[data-receipt-menu-panel]');
        var below = button.bottom + 6;
        var top = below + panel.offsetHeight > window.innerHeight ? button.top - panel.offsetHeight - 6 : below;

        panel.style.top = Math.max(8, top) + 'px';
        panel.style.left = Math.max(8, button.right - panel.offsetWidth) + 'px';
      }, true);

      document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-receipt-menu]')) closeAll();
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeAll();
      });
      // A fixed panel would drift away from its button on scroll, so close it.
      window.addEventListener('scroll', function () { closeAll(); }, true);
      window.addEventListener('resize', function () { closeAll(); });
    })();
    </script>
    @endpush
  @endonce
@endif
