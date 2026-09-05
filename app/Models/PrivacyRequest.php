<?php
declare(strict_types=1);
namespace App\Models;
class PrivacyRequest extends TenantModel
{
    protected $fillable = ['shop_id','webhook_id','status','export','completed_at'];
    protected $casts = ['export'=>'encrypted:array','completed_at'=>'datetime'];
    protected $hidden = ['export'];
}
