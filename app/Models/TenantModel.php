<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
abstract class TenantModel extends Model
{
    use HasFactory;
    public function shop() { return $this->belongsTo(Shop::class); }
    public function scopeForShop($query, Shop $shop) { return $query->where($this->qualifyColumn('shop_id'), $shop->id); }
}
