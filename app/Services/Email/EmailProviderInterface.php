<?php

namespace App\Services\Email;

interface EmailProviderInterface
{
    public function createSenderSignature(string $email, string $name): array;

    public function getSenderSignature(int $id, string $email): array;

    public function resendSenderSignatureConfirmation(int $id, string $email): void;

    public function findSenderSignatureByEmail(string $email): array;

    public function ensureDomain(string $domain): array;

    public function verifyDomain(int $id): array;

    public function send(array $message): string;
}
