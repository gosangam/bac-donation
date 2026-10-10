{{-- Pen button that opens an owner form (admin.partials.owner-form) placed
     elsewhere in the same row.

     Expects: $target (the form's id), $current (the owner, or null). --}}
<button type="button" data-owner-toggle aria-controls="{{ $target }}" aria-expanded="false"
        title="{{ $current ? 'Change donor' : 'Link donor' }}"
        aria-label="{{ $current ? 'Change donor' : 'Link donor' }}"
        class="inline-grid h-6 w-6 shrink-0 place-items-center rounded-md align-middle text-slate-400
               hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M12 20h9" /><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
  </svg>
</button>
