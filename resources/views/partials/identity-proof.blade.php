{{-- Identity proof: a type, and a number whose format depends on it.

     The four types are the ones Form 10BD accepts, which is why a rupee
     donation cannot be taken without one — the trust files the donor's ID
     against the receipt. Used by both the checkout and the profile.

     Expects: $type, $number, $required, and optionally $label — the checkout
     frames this with its own heading, where a second "Identity proof" would
     just repeat it. --}}
@php
  $required ??= false;
  $label ??= 'Identity proof';
  $types = \App\Support\IdentityProof::TYPES;
@endphp

<div>
  <label for="id_type" class="block text-sm font-medium text-slate-700 mb-1">
    {{ $label }} @unless ($required)<span class="text-slate-400">(optional)</span>@endunless
  </label>
  <select id="id_type" name="id_type" @if ($required) required @endif data-id-type
          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-slate-400 focus:outline-none">
    <option value="">Select identity proof…</option>
    @foreach ($types as $value => $typeLabel)
      <option value="{{ $value }}"
              data-pattern="{{ \App\Support\IdentityProof::browserPattern($value) }}"
              data-example="{{ \App\Support\IdentityProof::example($value) }}"
              data-maxlength="{{ \App\Support\IdentityProof::maxLength($value) }}"
              @selected($type === $value)>{{ $typeLabel }}</option>
    @endforeach
  </select>
  @error('id_type')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
</div>

{{-- Revealed once a type is chosen: an unlabelled number box before then has
     nothing to tell the donor what to type. --}}
<div data-id-number-field @class(['hidden' => ! $type])>
  <label for="id_number" class="block text-sm font-medium text-slate-700 mb-1">
    <span data-id-number-label>{{ \App\Support\IdentityProof::label($type) ?? 'Number' }}</span>
  </label>
  <input id="id_number" name="id_number" type="text" value="{{ $number }}"
         autocomplete="off" spellcheck="false" style="text-transform:uppercase"
         maxlength="{{ \App\Support\IdentityProof::maxLength($type) }}"
         placeholder="{{ \App\Support\IdentityProof::example($type) }}"
         @if ($type) pattern="{{ \App\Support\IdentityProof::browserPattern($type) }}" @endif
         @if ($required && $type) required @endif
         class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-slate-400 focus:outline-none">
  @error('id_number')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
</div>

@once
  @push('scripts')
    <script>
      // Reveals the number field once a type is chosen and retunes it: label,
      // example, length and pattern all differ per proof.
      (function () {
        const select = document.querySelector('[data-id-type]');
        const field = document.querySelector('[data-id-number-field]');
        if (!select || !field) return;

        const input = field.querySelector('input');
        const label = field.querySelector('[data-id-number-label]');
        const required = select.hasAttribute('required');

        function apply(clear) {
          const option = select.selectedOptions[0];
          const type = select.value;

          field.classList.toggle('hidden', !type);

          if (!type) {
            // A hidden required field blocks submit with a message nobody sees.
            input.required = false;
            input.removeAttribute('pattern');
            input.value = '';
            return;
          }

          label.textContent = option.textContent.trim();
          input.placeholder = option.dataset.example || '';
          input.maxLength = Number(option.dataset.maxlength) || 32;
          input.pattern = option.dataset.pattern || '';
          input.required = required;
          input.inputMode = type === 'aadhaar' ? 'numeric' : 'text';

          if (clear) input.value = '';
        }

        // Separators are presentation: donors type "1234 5678 9012" and
        // "DL-0420110149646", and neither the spaces nor the hyphens are
        // stored. Stripping as they type keeps the pattern honest.
        input.addEventListener('input', () => {
          const caretAtEnd = input.selectionStart === input.value.length;
          const cleaned = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
          if (cleaned !== input.value) {
            input.value = cleaned;
            if (caretAtEnd) input.setSelectionRange(cleaned.length, cleaned.length);
          }
        });

        select.addEventListener('change', () => { apply(true); input.focus(); });

        // On first paint keep whatever came back from a failed submit rather
        // than wiping what the donor typed.
        apply(false);
      })();
    </script>
  @endpush
@endonce
