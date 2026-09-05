<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ResyncDispute;
use App\Models\Dispute;
use Illuminate\Http\Request;

class DisputeController extends MerchantController
{
    public function index(Request $request)
    {
        $q = $this->shop()->disputes();
        foreach (['reason', 'status', 'shipping_state', 'automation_status', 'source'] as $field) {
            if ($request->filled($field)) {
                $q->where($field, substr((string) $request->input($field), 0, 100));
            }
        }
        if ($request->filled('search')) {
            $q->where('order_name', 'like', '%'.substr($request->string('search')->toString(), 0, 100).'%');
        }
        if ($request->filled('date') && preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $request->input('date'))) {
            $q->whereDate('created_at', $request->input('date'));
        }

        return $this->page('disputes.index', ['disputes' => $q->orderBy('created_at', $request->input('sort') === 'oldest' ? 'asc' : 'desc')->paginate(25)->withQueryString()]);
    }

    public function show(Dispute $dispute)
    {
        $this->owned($dispute);

        return $this->page('disputes.show', ['dispute' => $dispute->load(['emailLogs', 'automationDeliveries.template'])]);
    }

    public function resync(Dispute $dispute)
    {
        $this->owned($dispute);
        ResyncDispute::dispatch($dispute->id)->onConnection('database');

        return response()->json(['message' => 'Resync queued. It will not send another automatic email.']);
    }
}
