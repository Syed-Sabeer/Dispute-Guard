<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderShippingState;
use App\Services\Orders\OrderShippingStateResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ShopifyData;

class ShippingStateResolverTest extends TestCase
{
    use ShopifyData;

    public static function statuses(): array
    {
        return array_map(fn ($raw, $expected) => [$raw, $expected],
            ['CONFIRMED', 'LABEL_PURCHASED', 'LABEL_PRINTED', 'CARRIER_PICKED_UP', 'IN_TRANSIT', 'DELAYED', 'OUT_FOR_DELIVERY', 'ATTEMPTED_DELIVERY', 'DELIVERED', 'FAILURE', 'UNSUPPORTED'],
            ['TRACKING_ADDED', 'TRACKING_ADDED', 'TRACKING_ADDED', 'IN_TRANSIT', 'IN_TRANSIT', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'OUT_FOR_DELIVERY', 'DELIVERED', 'UNKNOWN', 'UNKNOWN']);
    }

    #[DataProvider('statuses')]
    public function test_carrier_mapping(string $raw, string $expected): void
    {
        $result = (new OrderShippingStateResolver)->inspect($this->order($raw));
        $this->assertSame($expected, $result['state']->value);
        $this->assertSame($raw, $result['raw']);
    }

    public function test_empty_and_incomplete_information(): void
    {
        $resolver = new OrderShippingStateResolver;
        $this->assertSame(OrderShippingState::UNFULFILLED, $resolver->resolve(['fulfillments' => []]));
        $this->assertSame(OrderShippingState::UNKNOWN, $resolver->resolve([]));
        $order = $this->order();
        $order['fulfillments'][0]['trackingInfo'] = [];
        $this->assertSame(OrderShippingState::UNKNOWN, $resolver->resolve($order));
        $order = $this->order();
        $order['fulfillments'][0]['events']['nodes'] = [];
        $order['fulfillments'][0]['displayStatus'] = '';
        $this->assertSame(OrderShippingState::TRACKING_ADDED, $resolver->resolve($order));
    }

    public function test_shopify_fulfilled_with_tracking_but_no_carrier_events_is_tracking_added(): void
    {
        $order = [
            'displayFulfillmentStatus' => 'FULFILLED',
            'fulfillments' => [[
                'status' => 'SUCCESS',
                'displayStatus' => 'FULFILLED',
                'trackingInfo' => [['company' => 'Other', 'number' => 'r555', 'url' => null]],
                'events' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
            ]],
        ];
        $result = (new OrderShippingStateResolver)->inspect($order);
        $this->assertSame(OrderShippingState::TRACKING_ADDED, $result['state']);
        $this->assertSame('FULFILLED', $result['raw']);
        $this->assertSame($order['fulfillments'][0]['trackingInfo'], $result['tracking']);
    }

    public function test_carrier_events_take_precedence_over_merchant_fulfillment(): void
    {
        $resolver = new OrderShippingStateResolver;
        foreach (['IN_TRANSIT', 'DELIVERED', 'FAILURE'] as $status) {
            $order = $this->order($status);
            $order['fulfillments'][0]['displayStatus'] = 'FULFILLED';
            $result = $resolver->inspect($order);
            $this->assertSame($status === 'FAILURE' ? OrderShippingState::UNKNOWN : OrderShippingState::from($status), $result['state']);
            $this->assertSame($status, $result['raw']);
        }
    }

    public function test_mixed_partial_and_truncated_shipments_require_review(): void
    {
        $resolver = new OrderShippingStateResolver;
        $order = $this->order('DELIVERED');
        $order['fulfillments'][] = $this->order('IN_TRANSIT')['fulfillments'][0];
        $this->assertSame(OrderShippingState::UNKNOWN, $resolver->resolve($order));
        $order = $this->order('DELIVERED');
        $order['displayFulfillmentStatus'] = 'PARTIALLY_FULFILLED';
        $this->assertSame(OrderShippingState::UNKNOWN, $resolver->resolve($order));
        $order = $this->order('DELIVERED');
        $order['fulfillments'][0]['events']['pageInfo']['hasNextPage'] = true;
        $this->assertSame(OrderShippingState::UNKNOWN, $resolver->resolve($order));
    }
}
