<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

class DisputeCustomerMail extends Mailable
{
    public function __construct(public string $mailSubject, public string $safeBody, public ?string $merchantReplyTo) {}

    public function build(): static
    {
        $this->subject($this->mailSubject)->view('emails.dispute', ['safeBody' => $this->safeBody]);
        if ($this->merchantReplyTo) {
            $this->replyTo($this->merchantReplyTo);
        }

        return $this;
    }
}
