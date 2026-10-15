<?php

namespace Tests\Feature\Fulfilment;

use App\Enums\OrderStatus as S;
use PHPUnit\Framework\TestCase;

/** The state machine is pure logic: no framework, no database. */
class OrderStateMachineTest extends TestCase
{
    private const EXPECTED = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['packed', 'cancelled'],
        'packed' => ['pickup_requested', 'cancelled'],
        'pickup_requested' => ['shipped', 'cancelled'],
        'shipped' => ['out_for_delivery', 'delivery_failed'],
        'out_for_delivery' => ['delivered', 'delivery_failed'],
        'delivery_failed' => ['out_for_delivery', 'returned'],
        'delivered' => ['return_requested'],
        'return_requested' => ['returned'],
        'returned' => ['refund_pending'],
        'refund_pending' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    public function test_there_are_exactly_fourteen_statuses_and_all_are_mapped(): void
    {
        $this->assertCount(14, S::cases());
        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED), array_map(fn ($s) => $s->value, S::cases()));
    }

    public function test_every_one_of_the_196_status_pairs_is_decided_correctly(): void
    {
        $allowed = $refused = 0;

        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                $expected = in_array($to->value, self::EXPECTED[$from->value], true);
                $this->assertSame($expected, $from->canTransitionTo($to), "{$from->value} -> {$to->value}");
                $expected ? $allowed++ : $refused++;
            }
        }

        $this->assertSame([20, 176], [$allowed, $refused], "8 statuses have two exits, 4 have one, 2 are final");
    }

    public function test_no_status_can_move_to_itself(): void
    {
        foreach (S::cases() as $s) {
            $this->assertFalse($s->canTransitionTo($s), $s->value);
        }
    }

    public function test_cancelled_and_refunded_are_final(): void
    {
        $final = array_map(fn ($s) => $s->value, array_filter(S::cases(), fn ($s) => $s->isFinal()));
        $this->assertEqualsCanonicalizing(['cancelled', 'refunded'], $final);
    }

    public function test_every_status_is_reachable_from_pending(): void
    {
        $seen = [S::Pending->value => true];
        $queue = [S::Pending];
        while ($queue) {
            foreach (array_shift($queue)->next() as $n) {
                if (! isset($seen[$n->value])) {
                    $seen[$n->value] = true;
                    $queue[] = $n;
                }
            }
        }

        $this->assertCount(14, $seen, 'no orphan status: ' . implode(',', array_diff(array_keys(self::EXPECTED), array_keys($seen))));
    }

    public function test_only_cancellation_returns_and_refunds_need_the_admin_role(): void
    {
        $admin = array_map(fn ($s) => $s->value, array_filter(S::cases(), fn ($s) => $s->requiresAdminRole()));
        $this->assertEqualsCanonicalizing(['cancelled', 'returned', 'refund_pending', 'refunded'], $admin);
    }

    public function test_the_customer_rules_from_phase_6_agree_with_the_machine(): void
    {
        foreach (S::cases() as $s) {
            if ($s->canCustomerCancel()) {
                $this->assertTrue($s->canTransitionTo(S::Cancelled), "customer may cancel {$s->value}, so the machine must allow it");
            }
            if ($s->canCustomerReturn()) {
                $this->assertTrue($s->canTransitionTo(S::ReturnRequested));
            }
        }
        $this->assertFalse(S::Packed->canCustomerCancel(), 'customers cannot cancel once packed (admins still can until the pickup)');
        $this->assertTrue(S::Packed->canTransitionTo(S::Cancelled));
    }
}
