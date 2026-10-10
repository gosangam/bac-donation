{{-- Pulls an existing subscription or payment onto the donor being viewed.

     Expects: $action, $name (the field), $placeholder, $hint (or null). --}}
<form method="POST" action="{{ $action }}" class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
  @csrf
  <input type="text" name="{{ $name }}" required placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"
         class="flex-1 sm:w-72 min-w-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
  <button class="rounded-lg bg-slate-900 text-white px-3 py-1.5 text-sm font-medium hover:bg-slate-800">Link</button>
  @if ($hint)
    <span class="basis-full text-xs text-slate-500">{{ $hint }}</span>
  @endif
</form>
