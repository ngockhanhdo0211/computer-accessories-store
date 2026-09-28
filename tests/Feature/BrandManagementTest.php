<?php

namespace Tests\Feature;

use App\Actions\DeleteBrand;
use App\Actions\SaveBrand;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BrandManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Logitech', 'slug' => 'logitech', 'is_visible' => '1'], $overrides);
    }

    public function test_guest_is_redirected_and_customer_employee_are_forbidden(): void
    {
        $this->get(route('admin.brands.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.brands.index'))->assertForbidden();
        $this->actingAs(User::factory()->employee()->create())->get(route('admin.brands.index'))->assertForbidden();
    }

    public function test_non_admin_roles_cannot_use_any_brand_endpoint(): void
    {
        $brand = Brand::factory()->create();

        foreach ([User::factory()->create(), User::factory()->employee()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.brands.create'))->assertForbidden();
            $this->post(route('admin.brands.store'), $this->payload())->assertForbidden();
            $this->get(route('admin.brands.edit', $brand))->assertForbidden();
            $this->put(route('admin.brands.update', $brand), $this->payload())->assertForbidden();
            $this->delete(route('admin.brands.destroy', $brand))->assertForbidden();
        }

        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_admin_sees_empty_state_and_accessible_create_form(): void
    {
        $this->actingAs($this->admin())->get(route('admin.brands.index'))
            ->assertOk()
            ->assertSee('Chưa có thương hiệu')
            ->assertSee('0 thương hiệu')
            ->assertSee('class="page-heading"', false)
            ->assertSee('class="empty-state resource-empty-state"', false);

        $this->get(route('admin.brands.create'))->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('label for="name"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="slug"', false)
            ->assertSee('name="is_visible"', false)
            ->assertSee('class="resource-form-layout"', false)
            ->assertSee('class="action-group resource-form__actions"', false);
    }

    public function test_admin_creates_trimmed_brand_with_generated_slug_and_flash(): void
    {
        $this->actingAs($this->admin())->post(route('admin.brands.store'), $this->payload([
            'name' => '  Phụ kiện   Việt  ',
            'slug' => '',
        ]))->assertRedirect(route('admin.brands.index'))->assertSessionHas('status', 'Đã tạo thương hiệu.');

        $this->assertDatabaseHas('brands', [
            'name' => 'Phụ kiện Việt',
            'slug' => 'phu-kien-viet',
            'is_visible' => 1,
        ]);
    }

    public function test_index_renders_real_escaped_brands_without_product_data_or_n_plus_one(): void
    {
        Brand::factory()->count(30)->create();
        Brand::factory()->hidden()->create([
            'name' => '<script>alert(1)</script>',
            'slug' => 'escaped-brand',
        ]);

        $this->actingAs($this->admin());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('admin.brands.index'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Đang ẩn')
            ->assertDontSee('0 sản phẩm');

        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
        $this->assertStringNotContainsString('product_count', $response->getContent());
    }

    public function test_validation_rejects_required_blank_long_invalid_and_duplicate_values(): void
    {
        Brand::factory()->create(['slug' => 'logitech']);
        $this->actingAs($this->admin());

        $this->post(route('admin.brands.store'), $this->payload(['name' => null, 'slug' => 'new']))->assertSessionHasErrors('name');
        $this->post(route('admin.brands.store'), $this->payload(['name' => '   ', 'slug' => 'new']))->assertSessionHasErrors('name');
        $this->post(route('admin.brands.store'), $this->payload(['name' => str_repeat('a', 256), 'slug' => 'new']))->assertSessionHasErrors('name');
        $this->post(route('admin.brands.store'), $this->payload(['slug' => '!!!']))->assertSessionHasErrors('slug');
        $this->post(route('admin.brands.store'), $this->payload())->assertSessionHasErrors('slug');
        $this->post(route('admin.brands.store'), $this->payload(['slug' => 'new', 'is_visible' => 'wrong']))->assertSessionHasErrors('is_visible');
    }

    public function test_explicit_slug_is_normalized_and_unknown_fields_are_not_saved(): void
    {
        $this->actingAs($this->admin())->post(route('admin.brands.store'), $this->payload([
            'slug' => '  Phụ Kiện Cao Cấp  ',
            'id' => 99999,
            'created_at' => now()->addYear(),
            'unexpected' => 'ignored',
        ]))->assertRedirect();

        $brand = Brand::query()->where('slug', 'phu-kien-cao-cap')->firstOrFail();
        $this->assertNotSame(99999, $brand->id);
        $this->assertLessThan(now()->addMinute(), $brand->created_at);
        $this->assertFalse(Schema::hasColumn('brands', 'unexpected'));
    }

    public function test_update_name_keeps_slug_and_admin_can_change_slug_and_visibility(): void
    {
        $brand = Brand::factory()->create(['name' => 'Old name', 'slug' => 'old-slug', 'is_visible' => true]);
        $this->actingAs($this->admin())->put(route('admin.brands.update', $brand), $this->payload([
            'name' => '  New   name ',
            'slug' => '   ',
            'is_visible' => '0',
        ]))->assertRedirect(route('admin.brands.edit', $brand))->assertSessionHas('status', 'Đã cập nhật thương hiệu.');

        $brand->refresh();
        $this->assertSame('New name', $brand->name);
        $this->assertSame('old-slug', $brand->slug);
        $this->assertFalse($brand->is_visible);

        $this->put(route('admin.brands.update', $brand), $this->payload(['slug' => 'New Slug']))
            ->assertRedirect(route('admin.brands.edit', ['brand' => 'new-slug']));
        $this->assertSame('new-slug', $brand->fresh()->slug);
        $this->get('/admin/brands/old-slug/edit')->assertNotFound();
        $this->get(route('admin.brands.edit', $brand->fresh()))->assertOk();
    }

    public function test_route_model_binding_uses_slug_including_numeric_slugs(): void
    {
        $admin = $this->admin();
        $brand = Brand::factory()->create(['slug' => 'route-slug']);
        $numeric = Brand::factory()->create(['slug' => '12345']);

        $this->actingAs($admin)->get(route('admin.brands.edit', $brand))->assertOk();
        $this->assertStringEndsWith('/admin/brands/route-slug/edit', route('admin.brands.edit', $brand));
        $this->get('/admin/brands/missing-slug/edit')->assertNotFound();
        $this->get('/admin/brands/'.$brand->id.'/edit')->assertNotFound();
        $this->get(route('admin.brands.edit', $numeric))->assertOk();
    }

    public function test_update_allows_current_slug_but_rejects_another_brand_slug(): void
    {
        $brand = Brand::factory()->create(['slug' => 'current']);
        Brand::factory()->create(['slug' => 'taken']);
        $this->actingAs($this->admin());

        $this->put(route('admin.brands.update', $brand), $this->payload(['slug' => 'current']))->assertRedirect();
        $this->put(route('admin.brands.update', $brand), $this->payload(['slug' => 'taken']))->assertSessionHasErrors('slug');
        $this->assertSame('current', $brand->fresh()->slug);
    }

    public function test_delete_unreferenced_brand_succeeds_with_flash(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs($this->admin())->delete(route('admin.brands.destroy', $brand))
            ->assertRedirect(route('admin.brands.index'))->assertSessionHas('status', 'Đã xóa thương hiệu.');

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    }

    public function test_forms_have_csrf_and_expected_http_methods(): void
    {
        $brand = Brand::factory()->create();
        $this->actingAs($this->admin())->get(route('admin.brands.edit', $brand))->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PUT"', false);
        $this->get(route('admin.brands.index'))->assertOk()
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('data-confirm-delete-message=', false);
        $this->get('/admin/brands/'.$brand->slug.'/delete')->assertNotFound();
        $this->post(route('admin.brands.destroy', $brand))->assertMethodNotAllowed();
    }

    public function test_duplicate_key_after_validation_becomes_slug_error(): void
    {
        Brand::factory()->create(['slug' => 'logitech']);

        try {
            app(SaveBrand::class)->handle($this->payload());
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slug', $exception->errors());
        }
    }

    public function test_duplicate_slug_during_update_is_atomic_and_becomes_slug_error(): void
    {
        $brand = Brand::factory()->create(['name' => 'Original', 'slug' => 'original']);
        Brand::factory()->create(['slug' => 'taken']);

        try {
            app(SaveBrand::class)->handle($this->payload([
                'name' => 'Changed',
                'slug' => 'taken',
            ]), $brand);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slug', $exception->errors());
        }

        $brand->refresh();
        $this->assertSame('Original', $brand->name);
        $this->assertSame('original', $brand->slug);
    }

    public function test_unrelated_unique_error_is_not_disguised_as_slug_error(): void
    {
        DB::statement('CREATE TABLE unrelated_brand_unique (value TEXT UNIQUE)');
        DB::table('unrelated_brand_unique')->insert(['value' => 'taken']);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER brands_unrelated_unique
            BEFORE INSERT ON brands
            BEGIN INSERT INTO unrelated_brand_unique (value) VALUES ('taken'); END;
        SQL);

        $this->expectException(UniqueConstraintViolationException::class);
        app(SaveBrand::class)->handle($this->payload());
    }

    public function test_foreign_key_delete_violation_becomes_business_error(): void
    {
        $brand = Brand::factory()->create();

        try {
            Schema::create('brand_dependencies', function ($table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            });
            DB::table('brand_dependencies')->insert(['brand_id' => $brand->id]);

            try {
                app(DeleteBrand::class)->handle($brand);
                $this->fail('Expected validation exception.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('brand', $exception->errors());
                $this->assertDatabaseHas('brands', ['id' => $brand->id]);
            }

            DB::table('brand_dependencies')->where('brand_id', $brand->id)->delete();
            app(DeleteBrand::class)->handle($brand);
            $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        } finally {
            Schema::dropIfExists('brand_dependencies');
        }
    }

    public function test_missing_brand_routes_return_not_found(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/brands/missing/edit')->assertNotFound();
        $this->put('/admin/brands/missing', $this->payload())->assertNotFound();
        $this->delete('/admin/brands/missing')->assertNotFound();
    }

    public function test_pagination_has_stable_order_and_out_of_range_page_redirects(): void
    {
        Brand::factory()->count(26)->create(['name' => 'Same name']);
        $this->actingAs($this->admin());

        $firstPageIds = $this->get(route('admin.brands.index'))->viewData('brands')->pluck('id')->all();
        $this->assertSame($firstPageIds, collect($firstPageIds)->sort()->values()->all());
        $this->get(route('admin.brands.index', ['page' => 999]))
            ->assertRedirect(route('admin.brands.index', ['page' => 2]));
    }

    public function test_unicode_only_name_and_overlong_normalized_slug_are_rejected(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.brands.store'), $this->payload(['name' => '❤️✨', 'slug' => '']))
            ->assertSessionHasErrors('slug');
        $this->post(route('admin.brands.store'), $this->payload(['slug' => str_repeat('a', 256)]))
            ->assertSessionHasErrors('slug');
    }

    public function test_unrelated_delete_error_is_not_disguised_as_foreign_key_error(): void
    {
        $brand = Brand::factory()->create();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER brands_unrelated_delete
            BEFORE DELETE ON brands
            BEGIN SELECT RAISE(ABORT, 'unrelated_delete_failure'); END;
        SQL);

        $this->expectException(QueryException::class);
        app(DeleteBrand::class)->handle($brand);
    }

    public function test_action_rejects_invalid_input_when_called_directly(): void
    {
        try {
            app(SaveBrand::class)->handle($this->payload(['is_visible' => 'wrong']));
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_visible', $exception->errors());
        }
    }

    public function test_locked_inactive_and_invalid_stored_accounts_fail_safely(): void
    {
        foreach ([User::factory()->admin()->locked()->create(), User::factory()->admin()->inactive()->create()] as $admin) {
            $this->actingAs($admin)->get(route('admin.brands.index'))->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $invalidRole = $this->admin();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('users')->where('id', $invalidRole->id)->update(['role' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->actingAs($invalidRole)->get(route('admin.brands.index'))->assertForbidden();

        $invalidStatus = $this->admin();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('users')->where('id', $invalidStatus->id)->update(['status' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->actingAs($invalidStatus)->get(route('admin.brands.index'))->assertRedirect(route('login'));
    }

    public function test_admin_navigation_has_active_brand_link_and_other_users_do_not(): void
    {
        $link = 'href="'.route('admin.brands.index').'"';
        $this->get(route('home'))->assertDontSee($link, false);
        $this->actingAs(User::factory()->create())->get(route('home'))->assertDontSee($link, false);
        $this->actingAs($this->admin())->get(route('admin.brands.index'))->assertSee($link, false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_customer_and_employee_dashboards_do_not_receive_brand_data(): void
    {
        Brand::factory()->create(['name' => 'Private Brand']);

        $this->actingAs(User::factory()->create())->get(route('customer.dashboard'))->assertOk()->assertDontSee('Private Brand');
        $this->actingAs(User::factory()->employee()->create())->get(route('employee.dashboard'))->assertOk()->assertDontSee('Private Brand');
    }

    public function test_guest_cannot_use_any_brand_endpoint(): void
    {
        $brand = Brand::factory()->create();

        $this->get(route('admin.brands.create'))->assertRedirect(route('login'));
        $this->post(route('admin.brands.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('admin.brands.edit', $brand))->assertRedirect(route('login'));
        $this->put(route('admin.brands.update', $brand), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('admin.brands.destroy', $brand))->assertRedirect(route('login'));
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_validation_error_is_rendered_next_to_the_field_and_old_input_is_preserved(): void
    {
        $this->actingAs($this->admin())->from(route('admin.brands.create'))
            ->post(route('admin.brands.store'), $this->payload(['name' => '', 'slug' => 'kept-slug']))
            ->assertRedirect(route('admin.brands.create'))->assertSessionHasErrors('name');

        $this->get(route('admin.brands.create'))->assertOk()
            ->assertSee('id="name-message"', false)
            ->assertSee('aria-describedby="name-message"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Vui lòng nhập tên thương hiệu.')
            ->assertSee('value="kept-slug"', false);
    }
}
