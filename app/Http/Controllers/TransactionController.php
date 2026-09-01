<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\ReceiptRenderer;
use App\Support\GuestCheckout;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->transactions()->with('subscription.plan')->latest();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('transactions.index', [
            'transactions' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('type', 'status'),
        ]);
    }

    public function show(Request $request, Transaction $transaction)
    {
        abort_unless(GuestCheckout::authorises($request, $transaction), 403);

        return view('transactions.show', ['txn' => $transaction->load('subscription.plan')]);
    }

    public function receipt(Request $request, Transaction $transaction, ReceiptRenderer $renderer)
    {
        abort_unless(GuestCheckout::authorises($request, $transaction), 403);

        // A receipt is proof money was received. Issuing one for a pending or
        // failed payment would be a false document.
        abort_unless($transaction->isPaid(), 404, 'No receipt: this payment has not completed.');

        return response($renderer->pdf($transaction), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$renderer->filename($transaction).'"',
        ]);
    }

    /** Same document in the browser, for a quick look before downloading. */
    public function receiptPreview(Request $request, Transaction $transaction, ReceiptRenderer $renderer)
    {
        abort_unless(GuestCheckout::authorises($request, $transaction), 403);
        abort_unless($transaction->isPaid(), 404);

        return response($renderer->html($transaction));
    }
}
