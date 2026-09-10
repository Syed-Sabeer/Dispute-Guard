<?php

namespace App\Http\Controllers;

use App\Services\Email\MerchantSenderService;
use Illuminate\Http\Request;

class SenderMailboxVerificationController extends Controller
{
    public function show()
    {
        return response()->view('settings.verify-mailbox')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request, MerchantSenderService $senders)
    {
        $token = $request->input('token');
        $ok = is_string($token) && $senders->confirmMailbox($token);

        return response()->view('settings.verify-mailbox', ['confirmed' => $ok], $ok ? 200 : 422)
            ->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }
}
