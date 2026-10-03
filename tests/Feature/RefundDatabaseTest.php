<?php

namespace Tests\Feature;

use App\Actions\CreatePendingRefund;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class RefundDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_factory_casts_relationships_and_guards_are_valid(): void
    {
        $refund = Refund::factory()->create();
        $originalAmount = $refund->amount_vnd;

        $this->assertTrue(Schema::hasColumns('refunds', [
            'payment_attempt_id', 'order_id', 'amount_vnd', 'reason', 'status',
            'gateway_refund_reference', 'note', 'created_at', 'updated_at',
        ]));
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame(RefundReason::StockUnavailable, $refund->reason);
        $this->assertTrue($refund->paymentAttempt->refund->is($refund));
        $this->assertSame($refund->paymentAttempt->amount_vnd, $refund->amount_vnd);
        $this->assertSame(['*'], $refund->getGuarded());

        foreach ([fn () => $refund->forceFill(['amount_vnd' => $refund->amount_vnd + 1])->save(), fn () => $refund->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Refund model allowed immutable evidence mutation.');
            } catch (LogicException) {
                $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'amount_vnd' => $originalAmount]);
            }
        }
    }

    public function test_database_enforces_reason_status_amount_unique_attempt_and_no_delete(): void
    {
        $refund = Refund::factory()->create();
        foreach ([
            ['amount_vnd' => 0], ['reason' => 'unknown'], ['status' => 'unknown'],
        ] as $invalid) {
            try {
                $attributes = Refund::factory()->make()->getAttributes();
                DB::table('refunds')->insert(array_merge($attributes, $invalid));
                $this->fail('Database accepted invalid Refund evidence.');
            } catch (QueryException) {
                $this->assertDatabaseCount('refunds', 1);
            }
        }

        try {
            Refund::factory()->create(['payment_attempt_id' => $refund->payment_attempt_id]);
            $this->fail('Database accepted a second Refund for one Payment Attempt.');
        } catch (QueryException) {
            $this->assertDatabaseCount('refunds', 1);
        }
        try {
            DB::table('refunds')->where('id', $refund->id)->delete();
            $this->fail('Database deleted Refund evidence.');
        } catch (QueryException) {
            $this->assertDatabaseHas('refunds', ['id' => $refund->id]);
        }

        try {
            DB::table('refunds')->where('id', $refund->id)->update(['amount_vnd' => $refund->amount_vnd + 1]);
            $this->fail('Database changed immutable Refund evidence.');
        } catch (QueryException) {
            $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'amount_vnd' => $refund->amount_vnd]);
        }
    }

    public function test_internal_action_is_full_amount_exactly_once_and_rejects_conflict(): void
    {
        $attempt = $this->paidAttempt();
        $action = app(CreatePendingRefund::class);
        $first = $action->handleLocked($attempt, RefundReason::SnapshotIncomplete, now());
        $second = $action->handleLocked($attempt, RefundReason::SnapshotIncomplete, now()->addSecond());

        $this->assertTrue($first->is($second));
        $this->assertSame($attempt->amount_vnd, $first->amount_vnd);
        $this->assertNull($first->order_id);
        $this->assertDatabaseCount('refunds', 1);

        $this->expectException(ValidationException::class);
        $action->handleLocked($attempt, RefundReason::StockUnavailable, now());
    }

    public function test_refund_status_can_leave_pending_once_but_cannot_reverse_or_switch_terminal_state(): void
    {
        $modelRefund = Refund::factory()->create();
        $modelRefund->forceFill(['status' => RefundStatus::Succeeded])->save();
        $this->assertSame(RefundStatus::Succeeded, $modelRefund->fresh()->status);

        try {
            $modelRefund->forceFill(['status' => RefundStatus::Failed])->save();
            $this->fail('Refund model allowed a terminal status switch.');
        } catch (LogicException) {
            $this->assertSame(RefundStatus::Succeeded, $modelRefund->fresh()->status);
        }

        $databaseRefund = Refund::factory()->create();
        DB::table('refunds')->where('id', $databaseRefund->id)->update(['status' => RefundStatus::Failed->value]);
        $this->assertDatabaseHas('refunds', ['id' => $databaseRefund->id, 'status' => RefundStatus::Failed->value]);

        try {
            DB::table('refunds')->where('id', $databaseRefund->id)->update(['status' => RefundStatus::Pending->value]);
            $this->fail('Database allowed a terminal Refund to return to pending.');
        } catch (QueryException) {
            $this->assertDatabaseHas('refunds', ['id' => $databaseRefund->id, 'status' => RefundStatus::Failed->value]);
        }
    }

    public function test_internal_action_rejects_a_paid_attempt_that_already_has_an_order(): void
    {
        $attempt = $this->paidAttempt();
        Order::factory()->forVerifiedAttempt($attempt)->create();

        $this->expectException(ValidationException::class);
        app(CreatePendingRefund::class)->handleLocked($attempt, RefundReason::StockUnavailable, now());
    }

    private function paidAttempt(): PaymentAttempt
    {
        $attempt = PaymentAttempt::factory()->create();
        $attempt->finalizeCallback([
            'status' => PaymentStatus::Paid,
            'gateway_transaction_id' => '123456789',
            'gateway_result_code' => '00',
            'gateway_transaction_status' => '00',
            'gateway_paid_at' => now(),
            'callback_fingerprint' => hash('sha256', 'refund-test'),
            'verified_at' => now(),
        ]);

        return $attempt;
    }
}
