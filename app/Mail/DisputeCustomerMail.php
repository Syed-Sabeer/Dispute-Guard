<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

class DisputeCustomerMail extends Mailable
{
    public function __construct(public string $mailSubject, public string $safeBody, public ?string $merchantReplyTo, public ?string $fromEmail = null, public ?string $fromName = null) {}

    public function build(): static
    {
        $this->subject($this->mailSubject)->view('emails.dispute', ['safeBody' => $this->safeBody]);
        if ($this->fromEmail) {
            $this->from($this->fromEmail, $this->fromName);
        }
        if ($this->merchantReplyTo) {
            $this->replyTo($this->merchantReplyTo);
        }

        return $this;
    }
}
