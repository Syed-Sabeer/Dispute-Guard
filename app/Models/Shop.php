<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = ['shop_domain', 'shopify_shop_id', 'access_token', 'store_name', 'email', 'support_email', 'reply_to_email', 'timezone', 'currency', 'status', 'installed_at', 'uninstalled_at', 'billing_status', 'plan_handle', 'billing_checked_at', 'quota_plan', 'billing_period_start', 'billing_period_end'];

    protected $hidden = ['access_token', 'email'];

    protected $casts = ['access_token' => 'encrypted:array', 'installed_at' => 'datetime', 'uninstalled_at' => 'datetime', 'billing_checked_at' => 'datetime', 'billing_period_start' => 'datetime', 'billing_period_end' => 'datetime'];

    public function disputes()
    {
        return $this->hasMany(Dispute::class);
    }

    public function emailTemplates()
    {
        return $this->hasMany(EmailTemplate::class);
    }

    public function emailLogs()
    {
        return $this->hasMany(EmailLog::class);
    }

    public function webhookEvents()
    {
        return $this->hasMany(WebhookEvent::class);
    }

    public function settings()
    {
        return $this->hasOne(ShopSetting::class);
    }

    public function emailSender()
    {
        return $this->hasOne(MerchantEmailSender::class);
    }

    public function active(): bool
    {
        return $this->status === 'ACTIVE' && $this->uninstalled_at === null;
    }

    public function handle(): string
    {
        return substr($this->shop_domain, 0, -strlen('.myshopify.com'));
    }
}
