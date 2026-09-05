<?php

declare(strict_types=1);

namespace App\Services\Email;

final class Recipient
{
    public static function hash(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key'));
    }

    public static function mask(string $email): string
    {
        return substr($email, 0, 1).'***@'.(explode('@', $email, 2)[1] ?? 'redacted');
    }

    public static function valid(?string $email): bool
    {
        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
