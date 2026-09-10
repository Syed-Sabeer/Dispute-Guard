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
            'sender_mode' => ['sometimes', 'in:SIGNATURE,DOMAIN'],
            'shop_id' => ['prohibited'], 'sender_id' => ['prohibited'], 'provider_signature_id' => ['prohibited'], 'provider_domain_id' => ['prohibited'], 'verification_status' => ['prohibited']]);
        $senders->save($this->shop(), $data['sender_name'], $data['sender_email'], $data['sender_mode'] ?? 'SIGNATURE');

        return response()->json(['message' => 'Sender saved. Complete verification before sending customer or test automation emails.', 'reload' => true]);
    }

    public function verify(MerchantSenderService $senders)
    {
        $sender = $senders->check($this->shop());

        return response()->json(['message' => $sender->verification_status === 'VERIFIED' ? 'Sender verified. Automation remains under your control in Settings.' : 'Verification is pending. Complete mailbox and Postmark confirmation for standard verification, or the DNS records for advanced authentication.', 'reload' => true]);
    }

    public function resend(MerchantSenderService $senders)
    {
        $senders->sendMailboxVerification($this->shop());

        return response()->json(['message' => 'Check your sender mailbox for verification instructions. Complete both Dispute Guard ownership verification and Postmark confirmation.', 'reload' => true]);
    }

    public function disconnect(Request $request, MerchantSenderService $senders)
    {
        abort_unless($request->boolean('confirmed'), 422, 'Confirm disconnecting this sender.');
        $senders->disconnect($this->shop());

        return response()->json(['message' => 'Sender disconnected. Customer and test automation emails are blocked until a sender is verified.', 'reload' => true]);
    }
}
