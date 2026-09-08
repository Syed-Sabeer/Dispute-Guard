<?php

namespace App\Services\Email;

use App\Exceptions\EmailProviderException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

class PostmarkTransport extends AbstractTransport
{
    public function __construct(private EmailProviderInterface $provider)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (! $email instanceof Email || count($email->getFrom()) !== 1 || count($message->getEnvelope()->getRecipients()) !== 1 || $email->getAttachments()) {
            throw new EmailProviderException('CONFIGURATION');
        }
        $id = $this->provider->send([
            'From' => $email->getFrom()[0]->toString(),
            'To' => $message->getEnvelope()->getRecipients()[0]->getAddress(),
            'ReplyTo' => isset($email->getReplyTo()[0]) ? $email->getReplyTo()[0]->getAddress() : $email->getFrom()[0]->getAddress(),
            'Subject' => $email->getSubject(),
            'HtmlBody' => $email->getHtmlBody(),
        ]);
        $message->setMessageId($id);
    }

    public function __toString(): string
    {
        return 'postmark';
    }
}
