<?php

use App\Contracts\Clock;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\FrozenClock;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\Wallets;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

test('balanced credit persists immutable history and exact projections', function () {
    $user = User::factory()->create();

    $journal = postWalletTestCredit($user);

    expect($journal->entries)->toHaveCount(2);
    $wallet = app(Wallets::class)->provision($user);
    expect($wallet->balance->kobo)->toBe(-10000);
    expect(app(Ledger::class)->balance($wallet)->kobo)->toBe(-10000);
    expect(app(Ledger::class)->balance($wallet, '2026-10-31')->kobo)->toBe(0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    expect(json_encode($journal->entries->first()->amount))->toBeString();
});

test('identical operation replay returns one journal and changed replay fails without writes', function () {
    $user = User::factory()->create();
    $journal = postWalletTestCredit($user);

    expect(postWalletTestCredit($user)->id)->toBe($journal->id);
    expect(fn () => postWalletTestCredit($user, 'credit', 20000))->toThrow(DomainException::class);
    $this->assertDatabaseCount('journals', 1);
    $this->assertDatabaseCount('ledger_entries', 2);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('unbalanced and foreign customer journals leave no financial effect', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $wallet = app(Wallets::class)->provision($owner);
    $cash = LedgerAccount::where('code', 'house:cash_at_gateway')->sole();
    $entries = [['account_id' => $wallet->id, 'amount' => new Money(-100)], ['account_id' => $cash->id, 'amount' => new Money(99)]];

    expect(fn () => app(Ledger::class)->post('unbalanced', $entries, '2026-11-01', 'Invalid', $owner->id))->toThrow(DomainException::class);
    $entries[1]['amount'] = new Money(100);
    expect(fn () => app(Ledger::class)->post('foreign', $entries, '2026-11-01', 'Invalid', $other->id))->toThrow(DomainException::class);

    $this->assertDatabaseCount('journals', 0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('wallet spending reads the ledger even when its projection is corrupted', function () {
    $user = User::factory()->create();
    postWalletTestCredit($user);
    $wallet = app(Wallets::class)->provision($user);
    DB::table('ledger_accounts')->where('id', $wallet->id)->update(['balance' => -99999999]);
    $entries = [['account_id' => $wallet->id, 'amount' => new Money(10001)], ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-10001)]];

    expect(fn () => app(Ledger::class)->post('overspend', $entries, '2026-11-01', 'Invalid', $user->id))->toThrow(DomainException::class);
    app(Ledger::class)->rebuildProjections();

    expect($wallet->fresh()->balance->kobo)->toBe(-10000);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    $this->assertDatabaseCount('journals', 1);
});

test('failed projection writes roll back journals and entries together', function () {
    $user = User::factory()->create();
    app(Wallets::class)->provision($user);
    DB::unprepared("CREATE TRIGGER reject_projection BEFORE UPDATE OF balance ON ledger_accounts BEGIN SELECT RAISE(ABORT, 'Injected failure'); END");

    expect(fn () => postWalletTestCredit($user))->toThrow(QueryException::class);

    $this->assertDatabaseCount('journals', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('posted journals and entries reject direct database mutation', function (string $table, string $operation) {
    postWalletTestCredit(User::factory()->create());

    expect(function () use ($table, $operation) {
        $query = DB::table($table);
        if ($operation === 'update') {
            $query->update($table === 'journals' ? ['description' => 'Changed'] : ['amount' => 1]);
        } else {
            $query->delete();
        }
    })->toThrow(QueryException::class);

    $this->assertDatabaseCount('journals', 1);
    $this->assertDatabaseCount('ledger_entries', 2);
})->with(['journals', 'ledger_entries'])->with(['update', 'delete']);

test('model and relationship insertion paths cannot append ledger history', function () {
    $journal = postWalletTestCredit(User::factory()->create());

    expect(fn () => (new Journal)->forceFill(['operation_key' => 'rogue'])->save())->toThrow(LogicException::class);
    expect(fn () => $journal->entries()->save((new LedgerEntry)->forceFill(['account_id' => 1, 'amount' => new Money(1)])))->toThrow(LogicException::class);
    expect(fn () => $journal->forceFill(['description' => 'Changed'])->save())->toThrow(LogicException::class);
    expect(fn () => LedgerEntry::insert(['journal_id' => $journal->id, 'account_id' => 1, 'amount' => 1]))->toThrow(LogicException::class);
    expect(fn () => LedgerEntry::upsert([['journal_id' => $journal->id, 'account_id' => 1, 'amount' => 1]], ['id']))->toThrow(LogicException::class);
    $this->assertDatabaseCount('ledger_entries', 2);
});

test('linked reversing journals correct balances once without changing original history', function () {
    $user = User::factory()->create();
    $journal = postWalletTestCredit($user);

    $reverse = app(Ledger::class)->reverse($journal, 'reverse', '2026-11-02', 'Correction', $user->id);
    expect(app(Ledger::class)->reverse($journal, 'reverse', '2026-11-02', 'Correction', $user->id)->id)->toBe($reverse->id);
    expect(fn () => app(Ledger::class)->reverse($journal, 'another-reverse', '2026-11-02', 'Correction', $user->id))->toThrow(DomainException::class);

    expect(app(Wallets::class)->provision($user)->balance->kobo)->toBe(0);
    expect($journal->fresh()->description)->toBe('Test credit');
    $this->assertDatabaseCount('journals', 2);
});

test('large cancelling entries and ledger aggregates remain exact', function () {
    $accounts = LedgerAccount::limit(4)->pluck('id')->all();
    $amounts = [PHP_INT_MAX, PHP_INT_MAX, -PHP_INT_MAX, -PHP_INT_MAX];
    $entries = [];
    foreach ($accounts as $i => $id) {
        $entries[] = ['account_id' => $id, 'amount' => new Money($amounts[$i])];
    }
    app(Ledger::class)->post('large', $entries, '2026-11-01', 'Large balanced journal');
    $this->assertDatabaseCount('ledger_entries', 4);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    expect(fn () => app(Ledger::class)->post('overflow', $entries, '2026-11-01', 'Overflow'))->toThrow(IntegerOverflowException::class);
    $this->assertDatabaseCount('journals', 1);
});

test('seeded valid operation sequences preserve every phase one invariant', function (int $seed) {
    $random = new Randomizer(new Mt19937($seed));
    $user = User::factory()->create();
    $wallet = app(Wallets::class)->provision($user);
    $cash = LedgerAccount::where('code', 'house:cash_at_gateway')->sole();
    $expected = 0;
    for ($i = 0; $i < 40; $i++) {
        $delta = $expected > 0 && $random->getInt(0, 1) ? -$random->getInt(1, $expected) : $random->getInt(1, 10000);
        $entries = [['account_id' => $wallet->id, 'amount' => new Money(-$delta)], ['account_id' => $cash->id, 'amount' => new Money($delta)]];
        $journal = app(Ledger::class)->post('sequence-'.$i, $entries, '2026-11-01', 'Sequence', $user->id);
        $expected += $delta;
        expect($wallet->fresh()->balance->kobo)->toBe(-$expected);
        expect($expected)->toBeGreaterThanOrEqual(0);
        expect(app(Ledger::class)->projectionMismatches())->toBe([]);
        $sum = BigInteger::zero();
        foreach ($journal->entries as $entry) {
            $sum = $sum->plus($entry->amount->kobo);
        }
        expect((string) $sum)->toBe('0');
        expect($journal->entries)->toHaveCount(2);
        expect(app(Ledger::class)->post('sequence-'.$i, $entries, '2026-11-01', 'Sequence', $user->id)->id)->toBe($journal->id);
    }
})->with([2718, 31415]);

test('operation keys reject changed account date description and customer inputs', function (string $field) {
    $user = User::factory()->create();
    postWalletTestCredit($user);
    $wallet = app(Wallets::class)->provision($user);
    $entries = [
        ['account_id' => $wallet->id, 'amount' => new Money(-10000)],
        ['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(10000)],
    ];
    if ($field === 'account') {
        $entries[1]['account_id'] = LedgerAccount::where('code', 'clearing:deposits_in_transit')->sole()->id;
    }

    expect(fn () => app(Ledger::class)->post('credit', $entries, $field === 'date' ? '2026-11-02' : '2026-11-01', $field === 'description' ? 'Changed' : 'Test credit', $field === 'customer' ? null : $user->id))->toThrow(DomainException::class);

    $this->assertDatabaseCount('journals', 1);
    expect($wallet->fresh()->balance->kobo)->toBe(-10000);
})->with(['account', 'date', 'description', 'customer']);

test('projection verification reports corruption and rebuild restores it', function () {
    $user = User::factory()->create();
    postWalletTestCredit($user);
    $wallet = app(Wallets::class)->provision($user);
    DB::table('ledger_accounts')->where('id', $wallet->id)->update(['balance' => -1]);

    $this->artisan('ledger:projections')->assertFailed();
    $this->artisan('ledger:projections', ['--rebuild' => true])->assertSuccessful();

    expect($wallet->fresh()->balance->kobo)->toBe(-10000);
});

test('journals retain economic date independently of the actual UTC posting instant', function () {
    $this->app->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-04 00:30:00', 'Africa/Lagos')));

    $journal = postWalletTestCredit(User::factory()->create());

    expect($journal->effective_date)->toBe('2026-11-01');
    expect($journal->posted_at)->toBe('2026-11-03 23:30:00.000000');
});

test('replacement statements cannot rewrite immutable ledger identities', function (string $collision) {
    $user = User::factory()->create();
    $journal = postWalletTestCredit($user);
    if ($collision === 'reversal') {
        $journal = app(Ledger::class)->reverse($journal, 'reverse', '2026-11-02', 'Correction', $user->id);
    }
    $beforeJournals = DB::table('journals')->orderBy('id')->get()->toJson();
    $beforeEntries = DB::table('ledger_entries')->orderBy('id')->get()->toJson();
    $recursiveTriggers = DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers;
    DB::statement('PRAGMA recursive_triggers = OFF');

    try {
        expect(function () use ($collision, $journal) {
            if ($collision === 'entry') {
                $entry = $journal->entries()->firstOrFail();
                DB::insert('INSERT OR REPLACE INTO ledger_entries (id, journal_id, account_id, amount) VALUES (?, ?, ?, ?)', [$entry->id, $journal->id, $entry->account_id, 123]);

                return;
            }
            $row = DB::table('journals')->find($journal->id);
            DB::insert('INSERT OR REPLACE INTO journals (id, operation_key, fingerprint, currency, effective_date, posted_at, description, reverses_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                $collision === 'journal_id' ? $row->id : null,
                $collision === 'operation_key' ? $row->operation_key : 'replacement',
                $row->fingerprint, $row->currency, $row->effective_date, $row->posted_at, 'Tampered', $row->reverses_id,
            ]);
        })->toThrow(QueryException::class, 'Ledger history is immutable');
    } finally {
        DB::statement('PRAGMA recursive_triggers = '.(int) $recursiveTriggers);
    }

    expect(DB::table('journals')->orderBy('id')->get()->toJson())->toBe($beforeJournals);
    expect(DB::table('ledger_entries')->orderBy('id')->get()->toJson())->toBe($beforeEntries);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
})->with(['entry', 'journal_id', 'operation_key', 'reversal']);

test('minimum signed integer entries reverse through bounded lines with exact projections and replay', function () {
    $accounts = LedgerAccount::whereIn('code', ['house:cash_at_gateway', 'house:gateway_fees', 'house:interest_expense'])->orderBy('id')->get();
    $entries = [
        ['account_id' => $accounts[0]->id, 'amount' => new Money(PHP_INT_MIN)],
        ['account_id' => $accounts[1]->id, 'amount' => new Money(PHP_INT_MAX)],
        ['account_id' => $accounts[2]->id, 'amount' => new Money(1)],
    ];
    $journal = app(Ledger::class)->post('minimum', $entries, '2026-11-01', 'Signed minimum');

    $reversal = app(Ledger::class)->reverse($journal, 'minimum-reversal', '2026-11-02', 'Correction');

    expect($reversal->entries)->toHaveCount(4);
    expect($reversal->entries()->where('account_id', $accounts[0]->id)->get()->map(fn ($entry) => $entry->amount->kobo)->sort()->values()->all())->toBe([1, PHP_INT_MAX]);
    foreach ($accounts as $account) {
        expect($account->fresh()->balance->kobo)->toBe(0);
    }
    expect(app(Ledger::class)->reverse($journal, 'minimum-reversal', '2026-11-02', 'Correction')->id)->toBe($reversal->id);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
    expect($journal->entries()->where('account_id', $accounts[0]->id)->sole()->amount->kobo)->toBe(PHP_INT_MIN);
    $this->assertDatabaseCount('journals', 2);

    app(Ledger::class)->reverse($reversal, 'restore-minimum', '2026-11-03', 'Restore original');

    expect($accounts[0]->fresh()->balance->kobo)->toBe(PHP_INT_MIN);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});

test('repeated account entries aggregate exactly and replay independently of ordering', function () {
    $user = User::factory()->create();
    $wallet = app(Wallets::class)->provision($user);
    $cash = LedgerAccount::where('code', 'house:cash_at_gateway')->sole();
    $entries = [
        ['account_id' => $wallet->id, 'amount' => new Money(-50)],
        ['account_id' => $wallet->id, 'amount' => new Money(-150)],
        ['account_id' => $cash->id, 'amount' => new Money(200)],
    ];

    $journal = app(Ledger::class)->post('multiple-lines', $entries, '2026-11-01', 'Multiple lines', $user->id);

    expect($journal->entries)->toHaveCount(3);
    expect($wallet->fresh()->balance->kobo)->toBe(-200);
    expect(app(Ledger::class)->post('multiple-lines', array_reverse($entries), '2026-11-01', 'Multiple lines', $user->id)->id)->toBe($journal->id);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);

    $entries[0]['amount'] = new Money(100);
    $entries[1]['amount'] = new Money(101);
    $entries[2]['amount'] = new Money(-201);
    expect(fn () => app(Ledger::class)->post('multiple-line-overspend', $entries, '2026-11-02', 'Overspend', $user->id))->toThrow(DomainException::class, 'Insufficient wallet funds');
    $this->assertDatabaseCount('journals', 1);
    expect($wallet->fresh()->balance->kobo)->toBe(-200);
});

test('a linked reversal must cancel the original per-account totals', function () {
    $cash = LedgerAccount::where('code', 'house:cash_at_gateway')->sole();
    $clearing = LedgerAccount::where('code', 'clearing:deposits_in_transit')->sole();
    $journal = app(Ledger::class)->post('original-totals', [
        ['account_id' => $cash->id, 'amount' => new Money(100)],
        ['account_id' => $clearing->id, 'amount' => new Money(-100)],
    ], '2026-11-01', 'Original');

    expect(fn () => app(Ledger::class)->post('wrong-reversal', [
        ['account_id' => $cash->id, 'amount' => new Money(-50)],
        ['account_id' => $cash->id, 'amount' => new Money(-49)],
        ['account_id' => $clearing->id, 'amount' => new Money(99)],
    ], '2026-11-02', 'Wrong reversal', reversesId: $journal->id))->toThrow(DomainException::class, 'A reversal must exactly reverse its linked journal');

    expect($cash->fresh()->balance->kobo)->toBe(100);
    expect($clearing->fresh()->balance->kobo)->toBe(-100);
    $this->assertDatabaseCount('journals', 1);
    expect(app(Ledger::class)->projectionMismatches())->toBe([]);
});
