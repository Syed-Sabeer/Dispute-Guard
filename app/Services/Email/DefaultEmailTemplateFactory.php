<?php
declare(strict_types=1);
namespace App\Services\Email;
use App\Enums\DisputeReason;
use App\Enums\OrderShippingState;
use App\Models\Shop;
final class DefaultEmailTemplateFactory
{
    public function make(DisputeReason $reason, OrderShippingState $state): array
    {
        if ($state === OrderShippingState::UNKNOWN) { throw new \InvalidArgumentException('No automatic template for unknown shipment state.'); }
        $intro = match ($reason) {
            DisputeReason::PRODUCT_NOT_RECEIVED => 'We received a notification that your order may not have arrived. We would like to help you locate it.',
            DisputeReason::PRODUCT_UNACCEPTABLE => 'We received a concern about your purchase. Please tell us whether an item was damaged, incorrect, incomplete, or different from its description so we can help.',
            DisputeReason::FRAUDULENT => "We received notification that this transaction may not be recognized. If you recognize this purchase, please contact your payment provider. If you don't recognize it, please contact our support team. Shipping information does not establish who authorized a purchase.",
            DisputeReason::CREDIT_NOT_PROCESSED => 'We received your refund or credit concern. Our team will review the refund, credit, or return status with you. Shipment information below does not confirm that a refund has been processed.',
        };
        $shipping = match ($state) {
            OrderShippingState::UNFULFILLED => 'Our records indicate that the order has not yet been fulfilled. Our team will review its status; please contact us to clarify your concern.',
            OrderShippingState::TRACKING_ADDED => 'Tracking has been prepared, but carrier movement has not yet been confirmed.',
            OrderShippingState::IN_TRANSIT => 'Carrier information indicates shipment movement or a delay while in transit.',
            OrderShippingState::OUT_FOR_DELIVERY => 'Carrier information indicates out for delivery or an attempted delivery. Please check tracking for the latest details.',
            OrderShippingState::DELIVERED => 'Carrier records currently indicate delivery. If you cannot locate your package, please contact our support team so we can help.',
        };
        return [
            'subject' => 'An update about your order {{order_number}} from {{store_name}}',
            'body' => '<p>Hello {{customer_name}},</p><p>'.$intro.'</p><p>Order {{order_number}} · {{order_amount}} {{currency}}</p><p>'.$shipping.'</p><p>Carrier: {{carrier}}<br>Tracking: {{tracking_number}}<br>{{tracking_url}}</p><p>Please reply or contact {{support_email}} for assistance.</p><p>If the issue has now been resolved, please contact your card provider regarding the open dispute.</p><p>{{store_name}}</p>',
        ];
    }
    public function seed(Shop $shop): void
    {
        foreach (DisputeReason::cases() as $reason) {
            foreach (OrderShippingState::automatic() as $state) {
                $shop->emailTemplates()->firstOrCreate(['dispute_reason'=>$reason->value,'shipping_state'=>$state->value], $this->make($reason, $state));
            }
        }
    }
}
