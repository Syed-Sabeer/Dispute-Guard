<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderShippingState as State;

final class OrderShippingStateResolver
{
    public function resolve(array $order): State
    {
        return $this->inspect($order)['state'];
    }

    public function inspect(array $order): array
    {
        $unknown = ['state' => State::UNKNOWN, 'raw' => 'INCOMPLETE', 'tracking' => []];
        if (! array_key_exists('fulfillments', $order) || ! is_array($order['fulfillments'])) {
            return $unknown;
        }
        $fulfillments = $order['fulfillments'];
        $overall = $order['displayFulfillmentStatus'] ?? null;
        if (! $fulfillments) {
            return in_array($overall, [null, 'UNFULFILLED', 'ON_HOLD', 'SCHEDULED'], true)
                ? ['state' => State::UNFULFILLED, 'raw' => $overall ?? 'UNFULFILLED', 'tracking' => []] : $unknown;
        }
        if (in_array($overall, ['PARTIALLY_FULFILLED', 'UNFULFILLED', 'ON_HOLD', 'SCHEDULED'], true) || count($fulfillments) >= 250) {
            return $unknown;
        }
        $states = [];
        $raw = [];
        $tracking = [];
        foreach ($fulfillments as $f) {
            if (($f['status'] ?? '') !== 'SUCCESS') {
                return $unknown;
            }
            $info = array_values(array_filter($f['trackingInfo'] ?? [], fn ($t) => ! empty($t['number']) || (! empty($t['url']) && filter_var($t['url'], FILTER_VALIDATE_URL) && in_array(parse_url($t['url'], PHP_URL_SCHEME), ['https', 'http'], true))));
            if (! $info || data_get($f, 'events.pageInfo.hasNextPage', false)) {
                return $unknown;
            }
            $events = data_get($f, 'events.nodes', []);
            usort($events, fn ($a, $b) => strcmp($b['happenedAt'] ?? '', $a['happenedAt'] ?? ''));
            $status = $events[0]['status'] ?? $f['displayStatus'] ?? '';
            $state = match ($status) {
                // Merchant fulfillment is not carrier movement. Tracking was
                // validated above; retain UNKNOWN for unrecognized events.
                'FULFILLED' => $events === [] ? State::TRACKING_ADDED : State::UNKNOWN,
                '', 'CONFIRMED','LABEL_PURCHASED','LABEL_PRINTED' => State::TRACKING_ADDED,
                'CARRIER_PICKED_UP','IN_TRANSIT','DELAYED' => State::IN_TRANSIT,
                'OUT_FOR_DELIVERY','ATTEMPTED_DELIVERY' => State::OUT_FOR_DELIVERY,
                'DELIVERED' => State::DELIVERED,
                default => State::UNKNOWN,
            };
            $states[] = $state;
            $raw[] = $status;
            $tracking = array_merge($tracking, $info);
        }
        // A single message cannot accurately describe mixed shipments; never infer complete delivery.
        if (count(array_unique(array_map(fn ($s) => $s->value, $states))) !== 1) {
            return ['state' => State::UNKNOWN, 'raw' => implode(', ', $raw), 'tracking' => $tracking];
        }

        return ['state' => $states[0], 'raw' => implode(', ', $raw), 'tracking' => $tracking];
    }
}
