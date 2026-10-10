{{-- Logo with the organisation's name stacked beside it: the first word on
     one line, the rest on the next ("Braj" / "Animal Care").

     Expects: $nameVisibility — the classes that hide or show the name, since
     each header has room for it from a different breakpoint. --}}
@php
  [$firstWord, $rest] = array_pad(explode(' ', trim(config('payments.org.name')), 2), 2, '');
@endphp
<img src="{{ config('payments.org.logo_url') }}" alt="" class="h-12 w-12 shrink-0 rounded object-contain">
<span class="{{ $nameVisibility }} flex-col text-[17px] font-semibold leading-[1.1] tracking-tight">
  <span>{{ $firstWord }}</span>
  @if ($rest !== '')<span class="whitespace-nowrap">{{ $rest }}</span>@endif
</span>
