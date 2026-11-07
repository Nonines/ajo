<?php

namespace App\Http\Controllers;

use App\Support\PaymentInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentInbox $inbox): Response
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > config('payments.webhook_max_bytes'), 413);
        $header = config('payments.webhook_mode') === 'timestamped' ? 'Paystack-Signature' : 'x-paystack-signature';
        $inbox->receive($raw, $request->header($header));

        return response('OK', 200);
    }
}
