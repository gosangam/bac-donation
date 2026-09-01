@extends('layouts.app')
@section('title', 'Complete payment')
@section('content')
  <div class="max-w-lg mx-auto text-center">
    <div class="bg-white rounded-xl border border-slate-200 p-8">
      <h1 class="text-xl font-semibold text-slate-900">Complete your payment</h1>
      <p class="text-sm text-slate-500 mt-2">
        The {{ ucfirst($gateway) }} window should open automatically.
      </p>

      <div class="mt-6 text-left bg-slate-50 rounded-lg p-4 text-sm">
        <div class="flex justify-between py-1"><span class="text-slate-500">Amount</span>
          <span class="font-medium">{{ $transaction->amount_formatted }}</span></div>
        <div class="flex justify-between py-1"><span class="text-slate-500">Reference</span>
          <span class="font-mono text-xs">{{ $transaction->reference }}</span></div>
      </div>

      <button id="pay" class="mt-6 w-full rounded-lg bg-brand text-white py-2.5 text-sm font-medium hover:opacity-90">
        Open payment window
      </button>
      <p class="mt-4 text-xs text-slate-500">
        Closed it by accident? Click above to reopen — you have not been charged.
      </p>
    </div>
  </div>

  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
  <script>
    // Amount is deliberately absent: it is fixed by the order/subscription that
    // the server already created, so it cannot be tampered with here.
    const options = @json($options);

    options.handler = function (response) {
      // Post back to our return route so the payment is confirmed server-side.
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = @json(route('checkout.return', $transaction));
      const fields = {
        _token: document.querySelector('meta[name="csrf-token"]').content,
        ...response,
      };
      for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value ?? '';
        form.appendChild(input);
      }
      document.body.appendChild(form);
      form.submit();
    };

    options.modal = {
      ondismiss: function () {
        window.location = @json(route('checkout.cancel', $transaction));
      },
    };

    const rzp = new Razorpay(options);
    rzp.on('payment.failed', function (response) {
      alert('Payment failed: ' + (response.error?.description ?? 'unknown error'));
    });

    document.getElementById('pay').onclick = () => rzp.open();
    rzp.open();
  </script>
@endsection
