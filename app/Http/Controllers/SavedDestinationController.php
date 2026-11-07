<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDestinationRequest;
use App\Support\Destinations;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class SavedDestinationController extends Controller
{
    public function store(StoreDestinationRequest $request, Destinations $destinations): RedirectResponse
    {
        try {
            $destinations->save($request->user('web'), $request->string('label')->toString(),
                $request->string('account_number')->toString(), $request->string('bank_code')->toString());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['account_number' => $exception->getMessage()]);
        }

        return to_route('withdrawals.index');
    }
}
