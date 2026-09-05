<?php
declare(strict_types=1);
namespace App\Services\Email;
use App\Models\{Shop,Dispute,EmailTemplate};
use App\Mail\DisputeCustomerMail;
class EmailComposer
{
    public function __construct(private TemplateRenderer $renderer) {}
    public function variables(Shop $shop, Dispute $dispute, array $order): array
    {
        $settings = $shop->settings;
        return [
            'customer_name'=>data_get($order,'customer.firstName','Customer'),'customer_email'=>$order['email'] ?? '',
            'order_number'=>$order['name'] ?? '', 'order_date'=>substr($order['createdAt'] ?? '', 0, 10),
            'order_amount'=>data_get($order,'totalPriceSet.shopMoney.amount',''),'currency'=>data_get($order,'totalPriceSet.shopMoney.currencyCode',$dispute->currency),
            'carrier'=>$dispute->tracking_company,'tracking_number'=>$dispute->tracking_number,'tracking_url'=>$dispute->tracking_url,
            'shipment_status'=>$dispute->shipping_state,'raw_shipment_status'=>$dispute->raw_shipping_status,
            'dispute_reason'=>$dispute->reason,'dispute_status'=>$dispute->status,'dispute_amount'=>$dispute->amount,'dispute_date'=>$dispute->initiated_at?->toDateString(),
            'store_name'=>$settings?->store_display_name ?: $shop->store_name,'support_email'=>$settings?->support_email,
        ];
    }
    public function compose(Shop $shop, EmailTemplate $template, array $variables, bool $test = false): array
    {
        $subject = ($test ? '[TEST] ' : '').$this->renderer->render($template->subject, $variables, false);
        $body = $this->renderer->render($template->body, $variables);
        if ($test) { $body = '<p><strong>TEST MODE — sample automation email</strong></p>'.$body; }
        $settings = $shop->settings()->first();
        if ($settings?->email_footer) { $body .= '<p>'.htmlspecialchars($settings->email_footer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>'; }
        $replyTo = $settings?->reply_to_email ?: $settings?->support_email;
        return ['subject'=>$subject,'body'=>$body,'mailable'=>new DisputeCustomerMail($subject,$body,Recipient::valid($replyTo) ? $replyTo : null)];
    }
}
