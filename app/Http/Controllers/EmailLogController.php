<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Services\DeploymentMode;
use Illuminate\Http\Request;

class EmailLogController extends MerchantController
{
    public function index(Request $request)
    {
        $q = $this->shop()->emailLogs()->with('dispute');
        if (app()->environment('production')) {
            $q->where(function ($q) {
                $q->where('type', 'automatic')->whereHas('dispute', fn ($q) => $q->where('source', 'shopify'));
                if (DeploymentMode::testTools()) {
                    $q->orWhere(fn ($q) => $q->where('type', 'test')->whereNull('dispute_id'));
                }
            });
        }
        if (in_array($request->input('type'), ['test', 'automatic'], true)) {
            $q->where('type', $request->input('type'));
        }

        return $this->page('email-logs.index', ['logs' => $q->latest()->paginate(25)->withQueryString()]);
    }

    public function show(EmailLog $log)
    {
        $this->owned($log);
        $visible = ($log->type === 'automatic' && $log->dispute?->source === 'shopify')
            || (DeploymentMode::testTools() && $log->type === 'test' && ! $log->dispute_id);
        abort_if(app()->environment('production') && ! $visible, 404);

        return $this->page('email-logs.show', ['log' => $log]);
    }
}
