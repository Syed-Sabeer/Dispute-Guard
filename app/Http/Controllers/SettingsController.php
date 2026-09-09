<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateShopSettingsRequest;

class SettingsController extends MerchantController
{
    public function edit()
    {
        return $this->page('settings.edit', ['settings' => $this->shop()->settings]);
    }

    public function update(UpdateShopSettingsRequest $request)
    {
        $data = $request->safe()->except('templates_reviewed');
        $data['templates_reviewed_at'] = $request->boolean('templates_reviewed') ? now() : null;
        $data['onboarded_at'] = $request->boolean('templates_reviewed') && $request->filled('support_email') ? ($this->shop()->settings->onboarded_at ?? now()) : null;
        $this->shop()->settings->update($data);

        return response()->json(['message' => 'Settings saved.']);
    }

    public function onboarding()
    {
        return $this->page('settings.onboarding', ['settings' => $this->shop()->settings, 'testSent' => $this->shop()->emailLogs()->where('type', 'test')->where('status', 'SENT')->exists()]);
    }
}
