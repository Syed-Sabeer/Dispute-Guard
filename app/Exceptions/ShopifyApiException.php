<?php
declare(strict_types=1);
namespace App\Exceptions;
final class ShopifyApiException extends \RuntimeException
{
    public function __construct(public readonly bool $transient = false, string $reason = 'Shopify information is unavailable. Please resync or reconnect the app.')
    { parent::__construct($reason); }
}
