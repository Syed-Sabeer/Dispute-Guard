<?php
declare(strict_types=1);
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use App\Services\CurrentShop;
abstract class MerchantRequest extends FormRequest
{
    public function authorize(): bool { return app(CurrentShop::class)->get()->active(); }
    protected function emailRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable','string','max:254', function ($attribute,$value,$fail) {
            if (!\App\Services\Email\Recipient::valid($value) || preg_match('/[\r\n\x00]/', $value)) { $fail('Enter a valid email address without control characters.'); }
        }];
    }
}
