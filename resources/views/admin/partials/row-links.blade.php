{{-- Makes any element with data-href open that page when clicked, so a whole
     table row or card can lead to a record's details. Clicks on the links,
     buttons and forms inside the row still do their own thing. Each row also
     carries a real link, so this is a convenience, not the only way in. --}}
@once
  @push('scripts')
  <script>
  document.addEventListener('click', function (event) {
    var row = event.target.closest('[data-href]');
    if (!row || event.target.closest('a, button, input, select, textarea, label, form, summary, details')) return;
    if (window.getSelection().toString() !== '') return;   // selecting text, not navigating

    if (event.metaKey || event.ctrlKey) {
      window.open(row.dataset.href, '_blank');
    } else {
      window.location.href = row.dataset.href;
    }
  });
  </script>
  @endpush
@endonce
