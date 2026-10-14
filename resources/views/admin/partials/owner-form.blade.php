{{-- Reassigns a subscription or payment to another donor by email, opened by
     the pen button (admin.partials.owner-toggle) beside the donor's name.

     Expects: $id, $action (the PUT url), $current (the owner, or null). A blank
     email unlinks. Opens in place rather than as a popover, which the tables'
     overflow-x-auto wrapper would clip. Suggestions come from the page's
     #donor-emails datalist. --}}
<form id="{{ $id }}" method="POST" action="{{ $action }}" hidden
      class="mt-2 w-64 max-w-full rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2">
  @csrf @method('PUT')
  <label for="{{ $id }}-email" class="block text-xs font-medium text-slate-700">Donor's account email</label>
  <input id="{{ $id }}-email" type="email" name="email" value="{{ $current?->email }}" list="donor-emails"
         placeholder="Leave blank to unlink"
         class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
  <button class="w-full rounded-lg bg-primary text-white px-3 py-2 text-sm font-medium">Save</button>
</form>

@once
  @push('scripts')
  <script>
  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-owner-toggle]');
    if (!toggle) return;

    var form = document.getElementById(toggle.getAttribute('aria-controls'));
    form.hidden = !form.hidden;
    toggle.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
    if (!form.hidden) form.querySelector('input[type="email"]').focus();
  });
  </script>
  @endpush
@endonce
