<?php

declare(strict_types=1);

namespace App\Services\Email;

final class TemplateRenderer
{
    public const VARIABLES = ['customer_name', 'customer_email', 'order_number', 'order_date', 'order_amount', 'currency', 'carrier', 'tracking_number', 'tracking_url', 'shipment_status', 'raw_shipment_status', 'dispute_reason', 'dispute_status', 'dispute_amount', 'dispute_date', 'store_name', 'support_email'];

    public function render(string $template, array $variables, bool $html = true): string
    {
        $result = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($match) use ($variables, $html) {
            if (! in_array($match[1], self::VARIABLES, true)) {
                return '';
            }
            $value = (string) ($variables[$match[1]] ?? '');

            return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
        }, $template);

        return $html ? $this->sanitize($result) : trim(preg_replace('/[\r\n]+/', ' ', strip_tags($result)));
    }

    public function sanitize(string $html): string
    {
        // Only formatting tags survive. All attributes (including URL/event/style attributes) are removed.
        $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li>');

        return preg_replace('/<(\/?)(p|br|strong|b|em|i|ul|ol|li)\b[^>]*>/i', '<$1$2>', $html);
    }
}
