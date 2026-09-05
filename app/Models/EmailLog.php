<?php
declare(strict_types=1);
namespace App\Models;
class EmailLog extends TenantModel
{
    protected $fillable = ['shop_id','dispute_id','email_template_id','automation_delivery_id','type','recipient_masked','recipient_hash','subject','rendered_body','shipping_state','dispute_reason','status','provider_message_id','error_message','sent_at'];
    protected $casts = ['sent_at'=>'datetime','subject'=>'encrypted','rendered_body'=>'encrypted'];
    public function dispute() { return $this->belongsTo(Dispute::class); }
}
