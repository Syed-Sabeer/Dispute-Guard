<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EmailProviderException;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Services\DeploymentMode;
use App\Services\Email\EmailComposer;
use App\Services\Email\MerchantSenderService;
use App\Services\Email\Recipient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

class SendTestAutomationEmail extends QueuedJob
{
    public ?string $senderIdentityHash = null;

    public function __construct(public int $logId, public string $encryptedInput, ?string $senderIdentityHash = null)
    {
        $this->senderIdentityHash = $senderIdentityHash;
    }

    public function handle(EmailComposer $composer): void
    {
        $log = EmailLog::find($this->logId);
        if (! $log || $log->status !== 'QUEUED') {
            return;
        }
        $shop = $log->shop;
        if (! $shop->active() || ! DeploymentMode::testTools()) {
            $log->update(['status' => 'CANCELLED']);

            return;
        }
        $template = EmailTemplate::forShop($shop)->find($log->email_template_id);
        if (! $template) {
            $log->update(['status' => 'CANCELLED']);

            return;
        }
        $input = json_decode(Crypt::decryptString($this->encryptedInput), true, flags: JSON_THROW_ON_ERROR);
        if (! Recipient::valid($input['customer_email'] ?? null)) {
            $log->update(['status' => 'FAILED', 'error_message' => 'Invalid test recipient.']);

            return;
        }
        try {
            $message = $composer->compose($shop, $template, $input, true);
            if (! $this->senderIdentityHash || ! hash_equals($this->senderIdentityHash,
                app(MerchantSenderService::class)->testIdentityKey($shop, $message['identity']))) {
                $log->update(['status' => 'CANCELLED', 'error_message' => 'Test sender identity changed while queued. Submit a new test.']);

                return;
            }
        } catch (EmailProviderException $e) {
            $log->update(['status' => 'FAILED', 'error_message' => $e->getMessage()]);

            return;
        }
        if (! EmailLog::whereKey($log->id)->where('status', 'QUEUED')->update(['status' => 'SENDING'])) {
            return;
        }
        try {
            $shop->refresh();
            if (! $shop->active()) {
                $log->update(['status' => 'CANCELLED']);

                return;
            }
            $sent = app(MerchantSenderService::class)->guardTest($shop, $message['identity'], fn () => Mail::to($input['customer_email'])->send($message['mailable']));
            $log->update(['status' => 'SENT', 'sent_at' => now(), 'subject' => $message['subject'], 'rendered_body' => $message['body'],
                'provider_message_id' => config('mail.default') === 'postmark' ? $sent?->getMessageId() : null]);
        } catch (\Throwable) {
            $log->update(['status' => 'FAILED', 'error_message' => 'Test email failed or delivery outcome is uncertain.']);
        }
    }
}
