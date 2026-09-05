<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Models\{Shop,TenantModel};
use App\Services\CurrentShop;
use App\Policies\TenantPolicy;
abstract class MerchantController extends Controller
{
    protected function shop(): Shop { return app(CurrentShop::class)->get(); }
    protected function owned(TenantModel $record): TenantModel
    {
        abort_unless(app(TenantPolicy::class)->access($this->shop(), $record), 404); return $record;
    }
    protected function page(string $view, array $data = [])
    { return view($view, array_merge(['shop'=>$this->shop()],$data)); }
}
