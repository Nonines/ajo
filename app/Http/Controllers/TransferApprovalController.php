<?php

namespace App\Http\Controllers;

use App\Support\Withdrawals\Withdrawals;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Authenticates provider server-approval callbacks before checking the local payout reservation.
 */
class TransferApprovalController extends Controller
{
    /**
     * Checks the shared token and body limit, then accepts only matching payout approval details.
     */
    public function __invoke(Request $request, Withdrawals $withdrawals): Response
    {
        $token = config('withdrawals.approval_token');
        $candidate = $request->query('token');
        abort_unless(is_string($token) && strlen($token) >= 32 && is_string($candidate) && hash_equals($token, $candidate), 400);
        abort_if(strlen($request->getContent()) > 16384, 400);
        abort_unless($withdrawals->providerApproval($request->only(['reference', 'amount', 'recipient', 'currency', 'source'])), 400);

        return response('', 200);
    }
}
