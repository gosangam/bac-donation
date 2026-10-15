<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReceiptRenderer;
use App\Support\IdentityProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * One payment in full, for the admin who has to answer a question about it.
 *
 * The donor's ID number is masked on the page and only fetched when an admin
 * asks for it, so it is never sitting in the HTML of a screen left open, and
 * every reveal or full-ID receipt is logged against the admin who asked.
 */
class TransactionController extends Controller
{
    public function show(Transaction $transaction): View
    {
        $transaction->load('user', 'subscription.plan', 'subscription.user');

        return view('admin.transaction', [
            'txn' => $transaction,
            'maskedId' => IdentityProof::masked($transaction->donor_id_type, $transaction->donor_id_number),
            'donorEmails' => User::orderBy('email')->pluck('email'),
        ]);
    }

    public function revealIdentity(Request $request, Transaction $transaction): JsonResponse
    {
        abort_if(blank($transaction->donor_id_number), 404);

        $this->logIdentityAccess($request, $transaction, 'revealed on screen');

        return response()->json([
            'number' => IdentityProof::full($transaction->donor_id_type, $transaction->donor_id_number),
        ]);
    }

    public function receipt(Request $request, Transaction $transaction, ReceiptRenderer $renderer): Response
    {
        abort_unless($transaction->isPaid(), 404);

        $identity = $request->query('identity') === 'full' ? 'full' : 'masked';

        if ($identity === 'full' && filled($transaction->donor_id_number)) {
            $this->logIdentityAccess($request, $transaction, 'printed on a receipt');
        }

        return response($renderer->pdf($transaction, $identity), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$renderer->filename($transaction).'"',
        ]);
    }

    private function logIdentityAccess(Request $request, Transaction $transaction, string $how): void
    {
        Log::notice("Donor identity number {$how}", [
            'admin_id' => $request->user()->id,
            'admin_email' => $request->user()->email,
            'transaction_id' => $transaction->id,
            'receipt_no' => $transaction->receipt_no,
        ]);
    }
}
