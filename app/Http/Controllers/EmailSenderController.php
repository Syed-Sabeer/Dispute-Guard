<?php

namespace App\Http\Controllers;

use App\Services\Email\MerchantSenderService;
use Illuminate\Http\Request;

class EmailSenderController extends MerchantController
{
    public function index()
    {
        return $this->page('settings.email-sender', ['sender' => $this->shop()->emailSender()->with('sendingDomain')->first()]);
    }

    public function save(Request $request, MerchantSenderService $senders)
    {
        $data = $request->validate(['sender_name' => ['required', 'string', 'max:100'], 'sender_email' => ['required', 'string', 'max:254'],
            'shop_id' => ['prohibited'], 'sender_id' => ['prohibited'], 'provider_domain_id' => ['prohibited'], 'verification_status' => ['prohibited']]);
        $senders->save($this->shop(), $data['sender_name'], $data['sender_email']);

        return response()->json(['message' => 'Sender saved. Add the DNS records and check verification.', 'reload' => true]);
    }

    public function verify(MerchantSenderService $senders)
    {
        $sender = $senders->check($this->shop());

        return response()->json(['message' => $sender->verification_status === 'VERIFIED' ? 'Sending domain verified. Automation remains under your control in Settings.' : 'Verification is still pending. Check the DNS records and allow time for propagation.', 'reload' => true]);
    }

    public function disconnect(Request $request, MerchantSenderService $senders)
    {
        abort_unless($request->boolean('confirmed'), 422, 'Confirm disconnecting this sender.');
        $senders->disconnect($this->shop());

        return response()->json(['message' => 'Custom sender disconnected. New emails will use managed sending.', 'reload' => true]);
    }
}
