<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Services\DeploymentMode;
use App\Services\Email\EmailComposer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

class SendTestAutomationEmail extends QueuedJob
{
    public function __construct(public int $logId, public string $encryptedInput) {}

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
        $message = $composer->compose($shop, $template, $input, true);
        if (! EmailLog::whereKey($log->id)->where('status', 'QUEUED')->update(['status' => 'SENDING'])) {
            return;
        }
        try {
            $shop->refresh();
            if (! $shop->active()) {
                $log->update(['status' => 'CANCELLED']);

                return;
            }
            Mail::to($input['customer_email'])->send($message['mailable']);
            $log->update(['status' => 'SENT', 'sent_at' => now(), 'subject' => $message['subject'], 'rendered_body' => $message['body']]);
        } catch (\Throwable) {
            $log->update(['status' => 'FAILED', 'error_message' => 'Test email failed or delivery outcome is uncertain.']);
        }
    }
}
