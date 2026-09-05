<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\CurrentShop;
use App\Services\Email\Recipient;
use Illuminate\Foundation\Http\FormRequest;

abstract class MerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CurrentShop::class)->get()->active();
    }

    protected function emailRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:254', function ($attribute, $value, $fail) {
            if (! Recipient::valid($value) || preg_match('/[\r\n\x00]/', $value)) {
                $fail('Enter a valid email address without control characters.');
            }
        }];
    }
}
