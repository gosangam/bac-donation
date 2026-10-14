{{-- The design system, shared by both layouts. Tailwind runs from the Play CDN
     (there is no build step), so the palette and the few component classes
     live here once rather than being repeated across every view.

     Palette: taken from the logo — its exact red as the primary and its
     yellow as the accent, on a white
     ground with near-black ink. `soft` is a pale tint of the yellow for
     highlighted bands and the current nav item; `blush` a pale tint of the red
     that some sections fade down from, so the page is not one flat white. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        fontFamily: { sans: ['"Plus Jakarta Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
        colors: {
          ink: '#1C1917',
          primary: { DEFAULT: '#EC1C24', dark: '#C8141B' },
          accent: { DEFAULT: '#FDEE00', dark: '#EBDB00' },
          soft: '#FFF9D1',
          blush: '#FFE9E7',
          line: '#ECE7E2',
        },
      },
    },
  }
</script>
<style type="text/tailwindcss">
  @layer components {
    .btn-primary { @apply inline-flex items-center justify-center gap-2 rounded-[3px] bg-primary px-5 py-3 text-sm font-semibold uppercase tracking-wide text-white transition hover:bg-primary-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-white disabled:cursor-not-allowed disabled:opacity-40; }
    .btn-secondary { @apply inline-flex items-center justify-center gap-2 rounded-[3px] bg-accent px-5 py-3 text-sm font-semibold uppercase tracking-wide text-ink transition hover:bg-accent-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2; }
    .btn-ghost { @apply inline-flex items-center justify-center gap-2 rounded-[3px] border border-line bg-white px-4 py-2.5 text-sm font-semibold text-ink transition hover:border-primary hover:text-primary; }
    .eyebrow { @apply inline-flex items-center gap-2.5 text-[15px] font-medium text-ink before:block before:h-2.5 before:w-2.5 before:bg-primary before:content-['']; }
    .card { @apply rounded-md border border-line bg-white; }
    .field { @apply w-full rounded-[3px] border border-line bg-white px-3 py-2.5 text-sm text-ink placeholder:text-stone-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20; }
    .field-label { @apply mb-1.5 block text-sm font-medium text-ink; }
  }
</style>
