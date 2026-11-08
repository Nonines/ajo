<?php

namespace App\Http\Controllers;

use App\Jobs\VerifyDeposit;
use App\Models\Deposit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Treats customer callbacks as requests for verification rather than proof of payment.
 */
class DepositConfirmationController extends Controller
{
    /**
     * Queues verification for an owned deposit while retaining scheduler recovery if dispatch fails.
     */
    public function store(Request $request, Deposit $deposit): RedirectResponse
    {
        abort_unless($request->user('web')->is_active, 403);
        abort_unless($deposit->user_id === $request->user('web')->id, 404);

        try {
            VerifyDeposit::dispatch($deposit->id);
        } catch (Throwable) {
            // The saved checkout remains eligible for scheduled verification.
        }

        return to_route('deposits.show', $deposit->reference);
    }

    /**
     * Resolves the callback reference within the signed-in customer's deposits before requesting verification.
     */
    public function show(Request $request): RedirectResponse
    {
        $reference = $request->query('reference');
        abort_unless(is_string($reference), 404);
        $deposit = Deposit::where('reference', $reference)->where('user_id', $request->user('web')->id)->firstOrFail();

        return $this->store($request, $deposit);
    }
}
