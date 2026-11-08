<?php

namespace App\Http\Controllers;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Brick\Math\BigInteger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Presents signed wallet liabilities as customer-facing balances and movements.
 */
class WalletController extends Controller
{
    /**
     * Negates ledger liability signs so credits appear positive in the customer wallet history.
     */
    public function index(Request $request): Response
    {
        $user = $request->user('web');
        abort_unless($user->is_active, 403);
        $account = LedgerAccount::where('user_id', $user->id)->where('code', 'customer:'.$user->id.':wallet')->firstOrFail();
        $history = $account->entries()->with('journal')->orderByDesc('id')->paginate(20)
            ->through(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'amount' => (string) BigInteger::of($entry->amount->kobo)->negated(),
                'description' => $entry->journal->description,
                'effective_date' => $entry->journal->effective_date,
                'posted_at' => $entry->journal->posted_at,
            ]);

        return Inertia::render('Wallet', [
            'balance' => $account->balance->negate()->jsonSerialize(),
            'currency' => 'NGN',
            'history' => $history,
        ]);
    }
}
