{{-- The design system, shared by both layouts. Tailwind runs from the Play CDN
     (there is no build step), so the palette and the few component classes
     live here once rather than being repeated across every view.

     Palette: cream ground, forest ink, moss for actions, a pale lime accent —
     calm and outdoorsy, so the animals in the copy are the loudest thing on
     the page. The organisation's red stays in its logo and on the PDF. --}}
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
          cream: '#FEFAE0',
          forest: '#132A13',
          moss: { DEFAULT: '#31572C', dark: '#264422' },
          lime: '#F9FFB2',
          line: '#E7E4C6',
          // Older views style actions with bg-brand; pointing it at moss keeps
          // them inside the palette without touching each one.
          brand: '#31572C',
        },
      },
    },
  }
</script>
<style type="text/tailwindcss">
  @layer components {
    .btn-primary { @apply inline-flex items-center justify-center gap-2 rounded-[3px] bg-moss px-5 py-3 text-sm font-semibold uppercase tracking-wide text-white transition hover:bg-moss-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-moss focus-visible:ring-offset-2 focus-visible:ring-offset-cream disabled:cursor-not-allowed disabled:opacity-40; }
    .btn-secondary { @apply inline-flex items-center justify-center gap-2 rounded-[3px] border border-forest/80 bg-lime px-5 py-3 text-sm font-semibold uppercase tracking-wide text-forest transition hover:bg-[#f1f99a] focus:outline-none focus-visible:ring-2 focus-visible:ring-moss focus-visible:ring-offset-2; }
    .btn-ghost { @apply inline-flex items-center justify-center gap-2 rounded-[3px] border border-line bg-white px-4 py-2.5 text-sm font-semibold text-forest transition hover:border-forest; }
    .eyebrow { @apply inline-flex items-center gap-2.5 text-[15px] font-medium text-forest before:block before:h-2.5 before:w-2.5 before:bg-forest before:content-['']; }
    .card { @apply rounded-md border border-line bg-white; }
    .field { @apply w-full rounded-[3px] border border-line bg-white px-3 py-2.5 text-sm text-forest placeholder:text-stone-400 focus:border-moss focus:outline-none focus:ring-2 focus:ring-moss/25; }
    .field-label { @apply mb-1.5 block text-sm font-medium text-forest; }
  }
</style>
