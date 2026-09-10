<?php

declare(strict_types=1);

namespace App\Mail;

use App\Exceptions\EmailProviderException;
use App\Services\Email\Recipient;
use Illuminate\Mail\Mailable;

class DisputeCustomerMail extends Mailable
{
    public function __construct(public string $mailSubject, public string $safeBody, public string $merchantReplyTo, public string $fromEmail, public string $fromName) {}

    public function build(): static
    {
        $this->subject($this->mailSubject)->view('emails.dispute', ['safeBody' => $this->safeBody]);
        if (! Recipient::valid($this->fromEmail) || ! Recipient::valid($this->merchantReplyTo)) {
            throw new EmailProviderException('SENDER_NOT_VERIFIED');
        }
        $this->from($this->fromEmail, $this->fromName);
        $this->replyTo($this->merchantReplyTo);

        return $this;
    }
}
