<?php
declare(strict_types=1);
namespace App\Models;
class Dispute extends TenantModel
{
    protected $fillable = ['shop_id','shopify_dispute_id','shopify_order_id','order_name','reason','shopify_reason_raw','status','shopify_status_raw','type','amount','currency','shipping_state','raw_shipping_status','tracking_company','tracking_number','tracking_url','customer_email_hash','refunds','initiated_at','evidence_due_at','automation_status','review_reason','email_sent','email_sent_at','initial_processed_at','last_synced_at','source','redacted_at'];
    protected $casts = ['amount'=>'decimal:4','email_sent'=>'boolean','refunds'=>'array','initiated_at'=>'datetime','evidence_due_at'=>'datetime','email_sent_at'=>'datetime','initial_processed_at'=>'datetime','last_synced_at'=>'datetime','redacted_at'=>'datetime','tracking_number'=>'encrypted','tracking_url'=>'encrypted'];
    public function automationDeliveries() { return $this->hasMany(AutomationDelivery::class); }
    public function emailLogs() { return $this->hasMany(EmailLog::class); }
}
