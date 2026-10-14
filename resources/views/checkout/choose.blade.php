@extends('layouts.app')
@section('title', 'Give')

{{-- The public front door. Guests get the full story — who we are, what a gift
     does, then the giving itself; signed-in donors came to give, so they get a
     short header and go straight to it. The giving forms are identical for both.

     Copy is deliberately free of numbers: counts of animals treated or costs
     per meal belong here only once they come from the trust's own records. --}}
@php
  $org = config('payments.org');
  $taxNote = collect([
    filled($org['registration_80g'] ?? null) ? '80G' : null,
    filled($org['registration_12a'] ?? null) ? '12A' : null,
  ])->filter()->implode(' & ');

  $work = [
    ['Rescue & emergency care', 'Dogs hit by traffic, monkeys caught in wires or wounded in fights — we answer the call, reach them where they are and bring them in for treatment.',
      '<path d="M12 21s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.6-7 10-7 10Z"/><path d="M12 8v6M9 11h6"/>'],
    ['Treatment & recovery', 'Wound care, fractures, infections and mange are treated until the animal is strong enough to go home to its own street or troop.',
      '<path d="M10 3h4v7h7v4h-7v7h-4v-7H3v-4h7z"/>'],
    ['Feeding the hungry', 'In the heat of summer and the cold of winter, food runs short. We feed the dogs and monkeys who depend on the streets and ghats of Vrindavan.',
      '<path d="M4 11h16a8 8 0 0 1-16 0Z"/><path d="M8 7c0-1.5 1-2 1-3M12 7c0-1.5 1-2 1-3M16 7c0-1.5 1-2 1-3"/>'],
    ['Sterilisation & vaccination', 'Anti-rabies vaccination and humane sterilisation keep the animal population healthy and make the streets safer for animals and pilgrims alike.',
      '<path d="m18 2 4 4M17 7l-9.5 9.5M14 4l6 6M3 21l3.5-3.5M6.5 14.5l3 3M9 12l3 3"/>'],
  ];

  $promises = [
    ['Receipt', 'in your inbox', 'A PDF receipt with our PAN'.($taxNote ? " and {$taxNote} registration" : '').' is emailed the moment your payment clears — for one-time contributions and every monthly renewal.'],
    ['No account', 'needed to give', 'Give as a guest. We set up a donor account for your email afterwards so every receipt stays in one place.'],
    ['Secure', 'payments', 'Payments go through Razorpay, Stripe or PayPal. Your card details never reach us.'],
    ['Cancel', 'any time', 'Monthly gifts can be cancelled from your donor dashboard whenever you like, with no questions asked.'],
  ];

  $faqs = [
    ['Is my donation tax-deductible?',
      'Donations in rupees are eligible for deduction under Section 80G of the Income Tax Act'.($org['registration_80g'] ? ', vide registration '.$org['registration_80g'] : '').'. Your receipt carries everything you need to claim it, and we report it to the Income Tax Department — which is why we ask for an identity proof on rupee donations.'],
    ['Do I need to create an account?',
      'No. Fill in your details and pay. Once the payment clears we create an account for your email and send you a link to set a password, so you can download receipts later.'],
    ['Can I give from outside India?',
      'Yes — switch the currency to USD and pay by card through Stripe or PayPal. Indian tax deductions apply only to Indian taxpayers, so identity proof is optional for these gifts.'],
    ['How do I stop a monthly donation?',
      'Sign in with the email you donated from, open Subscriptions and choose Cancel. No further payments are taken.'],
    ['I paid but haven\'t received a receipt.',
      'Receipts are sent as soon as the payment provider confirms the payment, which is usually within a minute. Check your spam folder, or sign in to download it. Still stuck? Write to '.$org['email'].'.'],
  ];
@endphp

@section('landing')
  @guest
    {{-- ── Hero ─────────────────────────────────────────────────────────── --}}
    <section class="relative overflow-hidden">
      <div class="pointer-events-none absolute inset-0" aria-hidden="true">
        <div class="absolute -bottom-40 left-1/2 h-[520px] w-[1100px] -translate-x-1/2 rounded-[100%] bg-gradient-to-t from-[#cfe1a8] via-[#e9f2bf] to-transparent opacity-80 blur-2xl"></div>
        <div class="absolute -right-24 top-24 h-72 w-72 rounded-full bg-lime/70 blur-3xl"></div>
      </div>

      <div class="relative mx-auto max-w-4xl px-4 pb-24 pt-16 text-center sm:px-6 sm:pb-32 sm:pt-24">
        <p class="inline-flex items-center gap-2 rounded-full border border-line bg-white/70 px-4 py-1.5 text-sm text-forest">
          <svg class="h-4 w-4 text-moss" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="10" r="2.2"/><circle cx="9.5" cy="5.5" r="2.2"/><circle cx="14.5" cy="5.5" r="2.2"/><circle cx="19" cy="10" r="2.2"/><path d="M12 10.5c3 0 6 4.2 6 6.7 0 2-1.6 2.8-3.2 2.3-1-.3-1.8-.7-2.8-.7s-1.8.4-2.8.7C7.6 20 6 19.2 6 17.2c0-2.5 3-6.7 6-6.7Z"/></svg>
          Vrindavan, Braj{{ $taxNote ? " · {$taxNote} registered" : '' }}
        </p>

        <h1 class="mt-7 text-4xl font-semibold leading-[1.1] tracking-[-0.02em] sm:text-6xl">
          Care for the dogs and monkeys of Vrindavan
        </h1>
        <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-stone-600">
          We rescue, treat and feed the street dogs and monkeys who share Braj with its pilgrims.
          Give once or every month — <strong class="font-semibold text-forest">no account needed</strong>,
          and your receipt is emailed straight away.
        </p>

        <div class="mt-9 flex flex-col justify-center gap-3 sm:flex-row">
          <a href="#give" class="btn-primary">Donate now</a>
          <a href="#plans" class="btn-secondary">Give monthly</a>
        </div>
        <p class="mt-6 text-sm text-stone-500">
          Given before? <a href="{{ route('login') }}" class="font-medium text-forest underline-offset-4 hover:underline">Sign in</a> to see your receipts.
        </p>
      </div>
    </section>

    {{-- ── About ────────────────────────────────────────────────────────── --}}
    <section id="about" class="scroll-mt-20 border-t border-line/70">
      <div class="mx-auto grid max-w-6xl gap-8 px-4 py-20 sm:px-6 md:grid-cols-[1fr_2fr] md:py-28">
        <div><span class="eyebrow">About us</span></div>
        <div>
          <p class="text-2xl font-medium leading-snug tracking-[-0.01em] sm:text-[32px] sm:leading-[1.35]">
            {{ $org['name'] }} looks after the animals no one owns — the street dogs and the monkeys of
            Vrindavan, who live alongside its temples, lanes and ghats and have nowhere else to turn
            when they are hurt or hungry.
          </p>
          <p class="mt-8 max-w-2xl text-[17px] leading-relaxed text-stone-600">
            When an animal is injured or ill, our team goes to it, treats it, and cares for it until it is
            well enough to return to the place it knows. Alongside rescue, we feed animals through the
            hardest months and vaccinate and sterilise to keep the whole community healthy. Every rupee and
            dollar you give goes into that care.
          </p>
          <a href="#give" class="btn-primary mt-9">Support our work</a>
        </div>
      </div>
    </section>

    {{-- ── Our work ─────────────────────────────────────────────────────── --}}
    <section id="work" class="scroll-mt-20 bg-lime">
      <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 md:py-28">
        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
          <div>
            <span class="eyebrow">Our work</span>
            <h2 class="mt-5 max-w-xl text-3xl font-semibold leading-tight tracking-[-0.02em] sm:text-[40px] sm:leading-[1.3]">
              Where your gift goes, every single day
            </h2>
          </div>
          <a href="#give" class="btn-primary self-start md:self-auto">Make a donation</a>
        </div>

        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          @foreach ($work as [$title, $body, $icon])
            <article class="card flex flex-col p-6">
              <span class="grid h-11 w-11 place-items-center rounded-[3px] bg-forest text-lime">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg>
              </span>
              <h3 class="mt-8 text-lg font-semibold">{{ $title }}</h3>
              <p class="mt-2 text-[15px] leading-relaxed text-stone-600">{{ $body }}</p>
            </article>
          @endforeach
        </div>
      </div>
    </section>
  @endguest

  {{-- ── Give ───────────────────────────────────────────────────────────── --}}
  <section id="give" class="scroll-mt-20">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-24">
      @include('partials.flash')

      <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
        @guest
          <div>
            <span class="eyebrow">Donate</span>
            <h2 class="mt-5 text-3xl font-semibold leading-tight tracking-[-0.02em] sm:text-[40px] sm:leading-[1.3]">
              Choose how you'd like to help
            </h2>
          </div>
        @else
          <div>
            <h1 class="text-3xl font-semibold tracking-[-0.02em]">Make a donation</h1>
            <p class="mt-2 text-stone-600">Give once, or set up a monthly gift that renews automatically.</p>
            <a href="{{ route('dashboard') }}" class="mt-3 inline-block text-sm text-stone-600 underline underline-offset-4 hover:text-forest">Your dashboard</a>
          </div>
        @endguest

        <form method="POST" action="{{ route('checkout.currency') }}"
              class="inline-flex self-start rounded-[3px] border border-line bg-white p-1 md:self-auto" role="group"
              aria-label="Giving currency">
          @csrf
          @foreach (\App\Support\Currency::supported() as $code)
            <button name="currency" value="{{ $code }}"
                    @if ($code === $currency) aria-current="true" @endif
                    class="rounded-[2px] px-4 py-2 text-sm font-semibold transition
                           {{ $code === $currency ? 'bg-forest text-white' : 'text-stone-600 hover:text-forest' }}">
              {{ $code === 'INR' ? '₹ INR' : '$ USD' }}
            </button>
          @endforeach
        </form>
      </div>

      {{-- Monthly plans --}}
      <div id="plans" class="mt-12 scroll-mt-24">
        <h3 class="text-xl font-semibold">Give every month</h3>
        <p class="mt-1 text-[15px] text-stone-600">A steady gift lets us plan feeding rounds and keep medicines stocked.</p>

        @if ($plans->isEmpty())
          <div class="card mt-6 p-6 text-sm text-stone-600">
            @auth
              No plans are priced in {{ $currency }} yet. An administrator can add a
              {{ $currency }} amount under Admin → Plans.
            @else
              Monthly giving isn't available in {{ $currency }} just now — a one-time
              contribution below works perfectly.
            @endauth
          </div>
        @else
          <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($plans as $plan)
              <form method="GET" action="{{ route('checkout.details') }}"
                    class="card group flex flex-col p-6 transition hover:-translate-y-0.5 hover:border-forest/40 hover:shadow-[0_12px_30px_-18px_rgba(19,42,19,.35)]">
                <input type="hidden" name="kind" value="plan">
                <input type="hidden" name="plan" value="{{ $plan->slug }}">
                <input type="hidden" name="currency" value="{{ $currency }}">
                <div class="text-[15px] font-medium text-moss">{{ $plan->name }}</div>
                <div class="mt-5 text-4xl font-semibold tracking-[-0.02em]">{{ $plan->amountFormattedIn($currency) }}</div>
                <div class="mt-1 text-sm text-stone-500">{{ $plan->cadence }}</div>
                @if ($plan->description)
                  <p class="mt-4 flex-1 text-[15px] leading-relaxed text-stone-600">{{ $plan->description }}</p>
                @else
                  <div class="flex-1"></div>
                @endif
                <button class="btn-primary mt-6 w-full">Choose</button>
              </form>
            @endforeach
          </div>
        @endif
      </div>

      {{-- One-time --}}
      <div class="mt-16 grid gap-8 rounded-md bg-forest p-6 text-white sm:p-10 lg:grid-cols-[1fr_1.2fr] lg:items-center">
        <div>
          <span class="eyebrow text-white before:bg-lime">One-time contribution</span>
          <h3 class="mt-4 text-2xl font-semibold leading-snug tracking-[-0.01em] sm:text-3xl">Give once, whenever you can</h3>
          <p class="mt-3 max-w-md text-[15px] leading-relaxed text-white/70">
            Every amount helps — a single gift can pay for a rescue, a course of treatment or a day of feeding.
          </p>
        </div>

        <div class="rounded-md bg-white p-5 text-forest sm:p-6">
          {{-- Presets are links, not buttons in the form below: as submit buttons
               they sent a second, empty "amount" alongside the custom field's,
               and Enter in that field submitted the first preset instead. --}}
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ($presets as $preset)
              <a href="{{ route('checkout.details', ['kind' => 'one_off', 'currency' => $currency, 'amount' => \App\Support\Money::toDecimal($preset, $currency)]) }}"
                 class="rounded-[3px] border border-line px-3 py-3 text-center text-sm font-semibold transition hover:border-forest hover:bg-lime">
                {{ \App\Support\Money::format($preset, $currency) }}
              </a>
            @endforeach
          </div>

          <form method="GET" action="{{ route('checkout.details') }}">
            <input type="hidden" name="kind" value="one_off">
            <input type="hidden" name="currency" value="{{ $currency }}">
            <label for="custom-amount" class="field-label mt-5">Or enter an amount</label>
            <div class="flex gap-2">
              <input id="custom-amount" type="number" name="amount" step="0.01" min="1" required
                     placeholder="{{ $currency === 'INR' ? '1000' : '25' }}" class="field flex-1">
              <button class="btn-primary shrink-0">Continue</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>

  @guest
    {{-- ── Why give with us ─────────────────────────────────────────────── --}}
    <section class="border-t border-line/70">
      <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 md:py-28">
        <div class="grid gap-6 md:grid-cols-2 md:items-end">
          <div>
            <span class="eyebrow">Why give with us</span>
            <h2 class="mt-5 text-3xl font-semibold leading-tight tracking-[-0.02em] sm:text-[40px] sm:leading-[1.3]">
              Giving should be simple, safe and transparent
            </h2>
          </div>
          <p class="text-[17px] leading-relaxed text-stone-600 md:justify-self-end md:max-w-md">
            You trust us with your generosity. Here is what you can expect from the moment you give.
          </p>
        </div>

        <div class="mt-12 grid gap-5 md:grid-cols-2">
          @foreach ($promises as [$big, $small, $body])
            <div class="card p-7">
              <div class="text-[15px] font-medium text-moss">{{ ucfirst($small) }}</div>
              <div class="mt-6 text-4xl font-semibold tracking-[-0.02em]">{{ $big }}</div>
              <p class="mt-3 max-w-md text-[15px] leading-relaxed text-stone-600">{{ $body }}</p>
            </div>
          @endforeach
        </div>
      </div>
    </section>

    {{-- ── FAQs ─────────────────────────────────────────────────────────── --}}
    <section id="faq" class="scroll-mt-20">
      <div class="mx-auto max-w-3xl px-4 pb-20 sm:px-6 md:pb-28">
        <div class="text-center">
          <span class="eyebrow">FAQs</span>
          <h2 class="mt-5 text-3xl font-semibold leading-tight tracking-[-0.02em] sm:text-[40px] sm:leading-[1.3]">
            Everything you need to know before you give
          </h2>
        </div>
        <div class="mt-12 divide-y divide-line border-y border-line">
          @foreach ($faqs as [$question, $answer])
            <details class="group py-5">
              <summary class="flex cursor-pointer list-none items-center justify-between gap-6 text-lg font-medium [&::-webkit-details-marker]:hidden">
                {{ $question }}
                <svg class="h-5 w-5 shrink-0 transition group-open:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
              </summary>
              <p class="mt-3 pr-10 text-[15px] leading-relaxed text-stone-600">{{ $answer }}</p>
            </details>
          @endforeach
        </div>
      </div>
    </section>

    {{-- ── Closing call to action ───────────────────────────────────────── --}}
    <section class="mx-auto max-w-6xl px-4 pb-20 sm:px-6 md:pb-28">
      <div class="relative overflow-hidden rounded-md bg-forest px-6 py-14 text-center sm:px-12 sm:py-20">
        <div class="pointer-events-none absolute -left-20 -top-20 h-64 w-64 rounded-full bg-moss/60 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-24 -right-16 h-72 w-72 rounded-full bg-lime/20 blur-3xl" aria-hidden="true"></div>
        <div class="relative">
          <h2 class="mx-auto max-w-2xl text-3xl font-semibold leading-tight tracking-[-0.02em] text-white sm:text-[40px] sm:leading-[1.25]">
            Every animal on these streets deserves a second chance
          </h2>
          <p class="mx-auto mt-5 max-w-lg text-[17px] leading-relaxed text-white/70">
            Your gift today reaches a dog or a monkey that has no one else. Thank you for standing with them.
          </p>
          <a href="#give" class="btn-secondary mt-9">Donate now</a>
        </div>
      </div>
    </section>
  @endguest
@endsection
