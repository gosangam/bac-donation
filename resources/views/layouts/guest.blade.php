<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title') · {{ config('payments.org.name') }}</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full bg-slate-100">
  <div class="min-h-full flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <img src="{{ config('payments.org.logo_url') }}" alt="" class="h-16 w-16 mx-auto rounded object-contain">
        <h1 class="mt-4 text-xl font-semibold text-slate-900">{{ config('payments.org.name') }}</h1>
      </div>
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        @include('partials.flash')
        @yield('content')
      </div>
    </div>
  </div>
</body>
</html>
