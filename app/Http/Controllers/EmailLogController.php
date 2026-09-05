<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\Request;

class EmailLogController extends MerchantController
{
    public function index(Request $request)
    {
        $q = $this->shop()->emailLogs()->with('dispute');
        if (in_array($request->input('type'), ['test', 'automatic'], true)) {
            $q->where('type', $request->input('type'));
        }

        return $this->page('email-logs.index', ['logs' => $q->latest()->paginate(25)->withQueryString()]);
    }

    public function show(EmailLog $log)
    {
        $this->owned($log);

        return $this->page('email-logs.show', ['log' => $log]);
    }
}
