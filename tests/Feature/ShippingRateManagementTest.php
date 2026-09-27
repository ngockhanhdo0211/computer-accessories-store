<?php

namespace Tests\Feature;

use App\Actions\CalculateShippingRate;
use App\Actions\UpdateShippingRate;
use App\Enums\ShippingRegion;
use App\Exceptions\ShippingRateUnavailable;
use App\Models\AuditLog;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShippingRateManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function rate(string $region = 'ha_noi'): ShippingRate
    {
        return ShippingRate::query()->where('region_key', $region)->firstOrFail();
    }

    public function test_only_active_admin_can_access_all_shipping_rate_routes(): void
    {
        $rate = $this->rate();
        $this->get(route('admin.shipping-rates.index'))->assertRedirect(route('login'));

        foreach ([User::factory()->create(), User::factory()->employee()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.shipping-rates.index'))->assertForbidden();
            $this->get(route('admin.shipping-rates.edit', $rate))->assertForbidden();
            $this->put(route('admin.shipping-rates.update', $rate), ['fee_vnd' => '32000'])->assertForbidden();
        }

        foreach ([User::factory()->admin()->locked()->create(), User::factory()->admin()->inactive()->create()] as $admin) {
            $this->actingAs($admin)->get(route('admin.shipping-rates.index'))->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $invalidRole = $this->admin();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        DB::table('users')->where('id', $invalidRole->id)->update(['role' => 'unknown']);
        DB::statement('PRAGMA ignore_check_constraints = OFF');
        $this->actingAs($invalidRole)->get(route('admin.shipping-rates.index'))->assertForbidden();

        $invalidStatus = $this->admin();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        DB::table('users')->where('id', $invalidStatus->id)->update(['status' => 'unknown']);
        DB::statement('PRAGMA ignore_check_constraints = OFF');
        $this->actingAs($invalidStatus)->get(route('admin.shipping-rates.index'))->assertRedirect(route('login'));
    }

    public function test_admin_index_uses_real_data_stable_order_and_bounded_queries(): void
    {
        $admin = User::factory()->admin()->create(['name' => '<img src=x onerror=alert(1)>']);
        $this->actingAs($admin);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->get(route('admin.shipping-rates.index'))->assertOk()
            ->assertSee('Hà Nội')->assertSee('30.000 VND')
            ->assertSee('Tỉnh/thành khác')->assertSee('45.000 VND')
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertDontSee('Checkout ngay');

        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
        $this->assertSame(['ha_noi', 'other'], $response->viewData('shippingRates')->pluck('region_key')->map->value->all());
    }

    public function test_admin_updates_only_fee_and_writes_complete_audit_atomically(): void
    {
        $admin = $this->admin();
        $rate = $this->rate();

        $this->actingAs($admin)->put(route('admin.shipping-rates.update', $rate), [
            'fee_vnd' => ' 32000 ',
            'region_key' => 'other',
            'updated_by' => 999,
        ])->assertRedirect(route('admin.shipping-rates.edit', $rate))
            ->assertSessionHas('status', 'Đã cập nhật phí vận chuyển.');

        $rate->refresh();
        $this->assertSame(32_000, $rate->fee_vnd);
        $this->assertSame(ShippingRegion::HaNoi, $rate->region_key);
        $this->assertSame($admin->id, $rate->updated_by);

        $audit = AuditLog::query()->where('action', 'shipping_rate.updated')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(ShippingRate::class, $audit->subject_type);
        $this->assertSame($rate->id, $audit->subject_id);
        $this->assertSame(['region_key' => 'ha_noi', 'fee_vnd' => 30_000, 'updated_by' => null], $audit->before_json);
        $this->assertSame(['region_key' => 'ha_noi', 'fee_vnd' => 32_000, 'updated_by' => $admin->id], $audit->after_json);
    }

    public function test_same_fee_is_a_no_op_without_audit_or_timestamp_change(): void
    {
        $admin = $this->admin();
        $rate = $this->rate();
        $updatedAt = $rate->updated_at;

        $result = app(UpdateShippingRate::class)->handle($rate, $admin, '30000');

        $this->assertSame(30_000, $result->fee_vnd);
        $this->assertNull($result->updated_by);
        $this->assertTrue($result->updated_at->equalTo($updatedAt));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'shipping_rate.updated']);
    }

    public function test_audit_failure_rolls_back_fee_and_updater(): void
    {
        $admin = $this->admin();
        $rate = $this->rate();
        DB::unprepared("CREATE TRIGGER shipping_rate_audit_failure BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'shipping_rate.updated'
            BEGIN SELECT RAISE(ABORT, 'forced shipping audit failure'); END");

        try {
            app(UpdateShippingRate::class)->handle($rate, $admin, '32000');
            $this->fail('Expected audit insert failure.');
        } catch (QueryException) {
            $this->assertSame(30_000, $rate->fresh()->fee_vnd);
            $this->assertNull($rate->fresh()->updated_by);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'shipping_rate.updated']);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS shipping_rate_audit_failure');
        }
    }

    public function test_serialized_admin_updates_preserve_audit_before_after_chain(): void
    {
        $firstAdmin = $this->admin();
        $secondAdmin = $this->admin();
        $rate = $this->rate();

        app(UpdateShippingRate::class)->handle($rate, $firstAdmin, '31000');
        app(UpdateShippingRate::class)->handle($rate, $secondAdmin, '32000');

        $audits = AuditLog::query()->where('action', 'shipping_rate.updated')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame(30_000, $audits[0]->before_json['fee_vnd']);
        $this->assertSame(31_000, $audits[0]->after_json['fee_vnd']);
        $this->assertSame(31_000, $audits[1]->before_json['fee_vnd']);
        $this->assertSame(32_000, $audits[1]->after_json['fee_vnd']);
        $this->assertSame($secondAdmin->id, $rate->fresh()->updated_by);
    }

    public function test_validation_rejects_missing_negative_float_scientific_formatted_and_overflow_fee(): void
    {
        $rate = $this->rate();
        $this->actingAs($this->admin());

        foreach ([null, '', '-1', '1.5', '3e4', '30.000', '9223372036854775808', [], true] as $invalid) {
            $this->from(route('admin.shipping-rates.edit', $rate))
                ->put(route('admin.shipping-rates.update', $rate), ['fee_vnd' => $invalid])
                ->assertRedirect(route('admin.shipping-rates.edit', $rate))
                ->assertSessionHasErrors('fee_vnd');
        }

        $this->assertSame(30_000, $rate->fresh()->fee_vnd);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'shipping_rate.updated']);
    }

    public function test_zero_and_signed_bigint_maximum_are_accepted_explicitly(): void
    {
        $admin = $this->admin();
        $other = $this->rate('other');

        app(UpdateShippingRate::class)->handle($other, $admin, '0');
        $this->assertSame(0, $other->fresh()->fee_vnd);

        app(UpdateShippingRate::class)->handle($other, $admin, (string) PHP_INT_MAX);
        $this->assertSame(PHP_INT_MAX, $other->fresh()->fee_vnd);
    }

    public function test_calculator_normalizes_keys_returns_stable_snapshot_and_reflects_new_fee(): void
    {
        $calculator = app(CalculateShippingRate::class);
        $quote = $calculator->handle('  HA_NOI ');

        $this->assertSame(ShippingRegion::HaNoi, $quote['rate']->region_key);
        $this->assertSame(30_000, $quote['fee_vnd']);
        $this->assertSame([
            'shipping_rate_id' => $quote['rate']->id,
            'region_key' => 'ha_noi',
            'region_label' => 'Hà Nội',
            'shipping_fee_vnd' => 30_000,
        ], $quote['snapshot']);

        $quote['rate']->forceFill(['fee_vnd' => 99_999]);
        $this->assertSame(30_000, $quote['snapshot']['shipping_fee_vnd']);

        $other = $calculator->handle(ShippingRegion::Other);
        $this->assertSame(45_000, $other['fee_vnd']);

        $this->rate()->forceFill(['fee_vnd' => 31_000])->save();
        $this->assertSame(31_000, $calculator->handle('ha_noi')['fee_vnd']);
    }

    public function test_calculator_rejects_invalid_types_names_unknown_keys_and_missing_row(): void
    {
        foreach (['hanoi', 'Hà Nội', '', 'unknown', null, [], true, false, 1] as $region) {
            try {
                app(CalculateShippingRate::class)->handle($region);
                $this->fail('Expected unavailable shipping rate.');
            } catch (ShippingRateUnavailable $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        DB::statement('DROP TRIGGER shipping_rates_delete_guard');
        DB::table('shipping_rates')->where('region_key', 'other')->delete();

        $this->expectException(ShippingRateUnavailable::class);
        app(CalculateShippingRate::class)->handle(ShippingRegion::Other);
    }

    public function test_action_rejects_non_admin_and_invalid_direct_input(): void
    {
        $rate = $this->rate();

        try {
            app(UpdateShippingRate::class)->handle($rate, User::factory()->employee()->create(), '30000');
            $this->fail('Expected authorization validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authorization', $exception->errors());
        }

        try {
            app(UpdateShippingRate::class)->handle($rate, $this->admin(), '1.5');
            $this->fail('Expected fee validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fee_vnd', $exception->errors());
        }
    }

    public function test_routes_have_no_create_store_delete_or_get_mutation(): void
    {
        $rate = $this->rate();
        $this->actingAs($this->admin());

        $this->get('/admin/shipping-rates/create')->assertMethodNotAllowed();
        $this->post('/admin/shipping-rates', ['fee_vnd' => '1'])->assertMethodNotAllowed();
        $this->delete(route('admin.shipping-rates.update', $rate))->assertMethodNotAllowed();
        $this->post(route('admin.shipping-rates.update', $rate), ['fee_vnd' => '1'])->assertMethodNotAllowed();
        $this->get('/admin/shipping-rates/'.$rate->getRouteKey().'/update')->assertNotFound();
    }

    public function test_route_binding_form_accessibility_error_display_and_navigation_are_correct(): void
    {
        $rate = $this->rate();
        $this->actingAs($this->admin());

        $this->get(route('admin.shipping-rates.edit', $rate))->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('label for="fee_vnd"', false)
            ->assertSee('inputmode="numeric"', false);
        $this->get('/admin/shipping-rates/missing/edit')->assertNotFound();

        $this->from(route('admin.shipping-rates.edit', $rate))
            ->put(route('admin.shipping-rates.update', $rate), ['fee_vnd' => '1.5'])
            ->assertSessionHasErrors('fee_vnd');
        $this->get(route('admin.shipping-rates.edit', $rate))->assertSee('aria-invalid="true"', false)
            ->assertSee('value="1.5"', false);

        $link = 'href="'.route('admin.shipping-rates.index').'"';
        $this->get(route('admin.shipping-rates.index'))->assertSee($link, false)->assertSee('aria-current="page"', false);
        $this->actingAs(User::factory()->create())->get(route('home'))->assertDontSee($link, false);
    }
}
