<?php

namespace App\Services\Email;

interface EmailProviderInterface
{
    public function ensureDomain(string $domain): array;

    public function verifyDomain(int $id): array;

    public function send(array $message): string;
}
