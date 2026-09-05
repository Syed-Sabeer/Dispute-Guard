<?php
declare(strict_types=1);
namespace App\Models;
class ShopSetting extends TenantModel
{
    protected $fillable = ['shop_id','store_display_name','support_email','reply_to_email','auto_email_enabled','test_mode','timezone','email_footer','templates_reviewed_at','onboarded_at'];
    protected $casts = ['auto_email_enabled'=>'boolean','test_mode'=>'boolean','templates_reviewed_at'=>'datetime','onboarded_at'=>'datetime'];
}
