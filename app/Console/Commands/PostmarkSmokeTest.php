<?php

namespace App\Console\Commands;

use App\Exceptions\EmailProviderException;
use App\Services\Email\EmailProviderInterface;
use App\Services\Email\MerchantSenderService;
use App\Services\Email\Recipient;
use Illuminate\Console\Command;
use Symfony\Component\Mime\Address;

class PostmarkSmokeTest extends Command
{
    protected $signature = 'chargeguard:postmark-smoke-test {recipient : Your own operator email address} {--force : Explicitly confirm one production test send}';

    protected $description = 'Send one clearly labelled operator test through the configured Postmark provider';

    public function handle(EmailProviderInterface $provider): int
    {
        $recipient = $this->argument('recipient');
        if (! Recipient::valid($recipient) || preg_match('/[\x00-\x20\x7f,;]/', $recipient)) {
            $this->error('FAIL Provide one valid operator email address.');

            return self::FAILURE;
        }
        try {
            $from = app(MerchantSenderService::class)->systemIdentity()['email'];
        } catch (EmailProviderException) {
            $this->error('FAIL Configure Postmark and a valid system verification sender.');

            return self::FAILURE;
        }
        $name = config('mail.from.name', 'Dispute Guard');
        if (config('mail.default') !== 'postmark' || ! config('services.postmark.token')
            || config('services.postmark.token') === 'POSTMARK_API_TEST'
            || ! Recipient::valid($from) || preg_match('/[\p{C}<>]/u', $name)) {
            $this->error('FAIL Configure Postmark and a valid system verification sender.');

            return self::FAILURE;
        }
        if (app()->environment('production') && ! $this->option('force')
            && ! $this->confirm('Send one real [TEST] email to the explicitly supplied operator recipient?', false)) {
            $this->line('Cancelled. No message sent.');

            return self::SUCCESS;
        }
        try {
            $id = $provider->send([
                'From' => (new Address($from, $name))->toString(), 'To' => $recipient, 'ReplyTo' => $from,
                'Subject' => '[TEST] Dispute Guard production email-provider smoke test',
                'HtmlBody' => '<p>This is a Dispute Guard production email-provider smoke test requested by an operator. It is not a customer dispute email.</p>',
            ]);
            $this->line('PASS Postmark accepted test message');
            $this->line('Message ID: '.$id);

            return self::SUCCESS;
        } catch (EmailProviderException $e) {
            $this->error($e->category === 'DELIVERY_OUTCOME_UNKNOWN'
                ? 'UNKNOWN — provider acceptance could not be determined; do not automatically retry.'
                : 'FAIL Provider rejected test or is not configured.');

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('UNKNOWN — provider acceptance could not be determined; do not automatically retry.');

            return self::FAILURE;
        }
    }
}
