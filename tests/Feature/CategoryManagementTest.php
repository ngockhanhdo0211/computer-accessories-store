<?php

namespace Tests\Feature;

use App\Actions\DeleteCategory;
use App\Actions\SaveCategory;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Bàn phím', 'slug' => 'ban-phim', 'parent_id' => null, 'is_visible' => '1'], $overrides);
    }

    public function test_guest_is_redirected_and_non_admin_roles_are_forbidden(): void
    {
        $this->get(route('admin.categories.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.categories.index'))->assertForbidden();
        $this->actingAs(User::factory()->employee()->create())->get(route('admin.categories.index'))->assertForbidden();
    }

    public function test_admin_can_view_empty_index_and_create_form(): void
    {
        $this->actingAs($this->admin())->get(route('admin.categories.index'))->assertOk()
            ->assertSee('Chưa có danh mục')
            ->assertSee('Tạo danh mục')
            ->assertSee('class="category-index-header"', false)
            ->assertSee('class="category-index-header__tools"', false)
            ->assertSee('class="category-index-count"', false)
            ->assertDontSee('category-masthead', false)
            ->assertSee('class="empty-state category-empty-state"', false);
        $this->get(route('admin.categories.create'))->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="name"', false)
            ->assertSee('class="category-form-masthead"', false)
            ->assertSee('class="category-form-layout"', false)
            ->assertSee('class="category-editor__actions"', false);
    }

    public function test_locked_and_inactive_admin_sessions_are_revoked(): void
    {
        foreach ([User::factory()->admin()->locked()->create(), User::factory()->admin()->inactive()->create()] as $admin) {
            $this->actingAs($admin)->get(route('admin.categories.index'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_admin_creates_trimmed_root_with_vietnamese_slug(): void
    {
        $this->actingAs($this->admin())->post(route('admin.categories.store'), $this->payload(['name' => '  Bàn   phím cơ  ', 'slug' => '']))
            ->assertRedirect(route('admin.categories.index'))->assertSessionHas('status');
        $this->assertDatabaseHas('categories', ['name' => 'Bàn phím cơ', 'slug' => 'ban-phim-co', 'parent_id' => null, 'is_visible' => 1]);
    }

    public function test_admin_creates_child_and_index_shows_parent_without_n_plus_one(): void
    {
        $parent = Category::factory()->create(['name' => 'Thiết bị nhập']);
        $this->actingAs($this->admin())->post(route('admin.categories.store'), $this->payload(['parent_id' => $parent->id]))->assertRedirect();
        Category::factory()->count(23)->create(['parent_id' => $parent->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('admin.categories.index'))->assertOk()
            ->assertSee('Thiết bị nhập')
            ->assertSee('Bàn phím')
            ->assertSee('class="category-catalog"', false)
            ->assertSee('class="category-catalog__columns"', false)
            ->assertSee('class="status-badge status-badge--success"', false);
        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()));
    }

    public function test_validation_rejects_blank_name_duplicate_slug_missing_parent_and_invalid_visibility(): void
    {
        Category::factory()->create(['slug' => 'ban-phim']);
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('admin.categories.create'))->post(route('admin.categories.store'), $this->payload(['name' => '   ']))->assertSessionHasErrors('name');
        $this->post(route('admin.categories.store'), $this->payload())->assertSessionHasErrors('slug');
        $this->post(route('admin.categories.store'), $this->payload(['slug' => 'khac', 'parent_id' => 99999]))->assertSessionHasErrors('parent_id');
        $this->post(route('admin.categories.store'), $this->payload(['slug' => 'khac', 'is_visible' => 'wrong']))->assertSessionHasErrors('is_visible');
    }

    public function test_nonblank_slug_that_normalizes_to_empty_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), $this->payload(['slug' => '!!!']))
            ->assertSessionHasErrors('slug');
    }

    public function test_duplicate_key_after_validation_becomes_field_error(): void
    {
        Category::factory()->create(['slug' => 'ban-phim']);
        try {
            app(SaveCategory::class)->handle($this->payload());
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slug', $exception->errors());
        }
    }

    public function test_admin_updates_category_without_implicit_slug_change_or_field_injection(): void
    {
        $category = Category::factory()->create(['name' => 'Chuột', 'slug' => 'chuot']);
        $createdAt = $category->created_at;
        $this->actingAs($this->admin())->put(route('admin.categories.update', $category), $this->payload([
            'name' => '  Chuột không dây ', 'slug' => 'chuot', 'id' => 9999, 'created_at' => now()->addYear(),
        ]))->assertRedirect(route('admin.categories.index'))->assertSessionHas('status');
        $category->refresh();
        $this->assertSame('Chuột không dây', $category->name);
        $this->assertSame('chuot', $category->slug);
        $this->assertSame($createdAt->toDateTimeString(), $category->created_at->toDateTimeString());
    }

    public function test_admin_can_explicitly_normalize_changed_slug(): void
    {
        $category = Category::factory()->create(['slug' => 'chuot']);
        $this->actingAs($this->admin())->put(route('admin.categories.update', $category), $this->payload(['slug' => ' Chuột Gaming ']))->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'slug' => 'chuot-gaming']);
    }

    public function test_category_cannot_be_its_own_parent_or_use_descendant_at_any_depth(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.categories.update', $root), $this->payload(['slug' => $root->slug, 'parent_id' => $root->id]))->assertSessionHasErrors('parent_id');
        $this->put(route('admin.categories.update', $root), $this->payload(['slug' => $root->slug, 'parent_id' => $grandchild->id]))->assertSessionHasErrors('parent_id');
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_leaf_can_be_deleted_but_parent_with_children_cannot(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $admin = $this->admin();
        $this->actingAs($admin)->delete(route('admin.categories.destroy', $parent))->assertSessionHasErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
        $this->delete(route('admin.categories.destroy', $child))->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseMissing('categories', ['id' => $child->id]);
    }

    public function test_error_is_rendered_next_to_field_and_routes_use_expected_methods(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('admin.categories.create'))->post(route('admin.categories.store'), $this->payload(['name' => '']))->assertSessionHasErrors('name');
        $this->get(route('admin.categories.create'))->assertOk()->assertSee('id="name-message"', false)
            ->assertSee('aria-describedby="name-message"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Vui lòng nhập tên danh mục.');
        $category = Category::factory()->create();
        $this->get('/admin/categories/'.$category->slug.'/delete')->assertNotFound();
        $this->post(route('admin.categories.destroy', $category))->assertMethodNotAllowed();
    }

    public function test_update_with_blank_slug_keeps_current_url_and_new_slug_changes_binding(): void
    {
        $category = Category::factory()->create(['name' => 'Chuột', 'slug' => 'chuot']);
        $this->actingAs($this->admin())->put(route('admin.categories.update', $category), $this->payload([
            'name' => 'Chuột mới', 'slug' => '   ',
        ]))->assertRedirect();
        $this->assertSame('chuot', $category->fresh()->slug);
        $this->put(route('admin.categories.update', $category), $this->payload([
            'name' => 'Chuột mới', 'slug' => 'Chuot Gaming',
        ]))->assertRedirect();
        $this->assertSame('chuot-gaming', $category->fresh()->slug);
        $this->get('/admin/categories/chuot/edit')->assertNotFound();
        $this->get(route('admin.categories.edit', $category->fresh()))->assertOk();
    }

    public function test_name_that_cannot_make_a_slug_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), $this->payload(['name' => '💻', 'slug' => '']))
            ->assertSessionHasErrors('slug');
    }

    public function test_existing_corrupt_cycle_stops_action_without_looping(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->create(['parent_id' => $a->id]);
        $c = Category::factory()->create(['parent_id' => $b->id]);
        DB::table('categories')->where('id', $a->id)->update(['parent_id' => $c->id]);
        try {
            app(SaveCategory::class)->handle($this->payload(['slug' => 'fresh', 'parent_id' => $a->id]));
            $this->fail('Expected cycle validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_id', $exception->errors());
        }
    }

    public function test_edit_form_excludes_self_and_every_descendant_from_parent_options(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);
        $other = Category::factory()->create();
        $response = $this->actingAs($this->admin())->get(route('admin.categories.edit', $root))->assertOk();
        preg_match('/<select id="parent_id"[^>]*>(.*?)<\/select>/s', $response->getContent(), $matches);
        $options = $matches[1] ?? '';
        $this->assertNotSame('', $options);
        $this->assertStringNotContainsString('<option value="'.$root->id.'"', $options);
        $this->assertStringNotContainsString('<option value="'.$child->id.'"', $options);
        $this->assertStringNotContainsString('<option value="'.$grandchild->id.'"', $options);
        $this->assertStringContainsString('<option value="'.$other->id.'"', $options);
    }

    public function test_hidden_selection_and_missing_visibility_are_handled_explicitly(): void
    {
        $this->actingAs($this->admin())->post(route('admin.categories.store'), $this->payload([
            'slug' => 'hidden-category', 'is_visible' => '0', 'parent_id' => '',
        ]))->assertRedirect();
        $this->assertDatabaseHas('categories', ['slug' => 'hidden-category', 'is_visible' => 0, 'parent_id' => null]);
        $this->post(route('admin.categories.store'), $this->payload(['slug' => 'missing-status', 'is_visible' => null]))
            ->assertSessionHasErrors('is_visible');
    }

    public function test_employee_is_forbidden_on_all_category_endpoints(): void
    {
        $category = Category::factory()->create();
        $this->actingAs(User::factory()->employee()->create())->get(route('admin.categories.index'))->assertForbidden();
        $this->get(route('admin.categories.create'))->assertForbidden();
        $this->post(route('admin.categories.store'), $this->payload())->assertForbidden();
        $this->get(route('admin.categories.edit', $category))->assertForbidden();
        $this->put(route('admin.categories.update', $category), $this->payload())->assertForbidden();
        $this->delete(route('admin.categories.destroy', $category))->assertForbidden();
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_delete_fk_race_becomes_business_error(): void
    {
        $category = Category::factory()->create(['slug' => 'delete-race']);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER categories_delete_race
            BEFORE DELETE ON categories
            WHEN OLD.slug = 'delete-race'
            BEGIN
                INSERT INTO categories (name, slug, parent_id, is_visible, created_at, updated_at)
                VALUES ('New child', 'new-child', OLD.id, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
            END;
        SQL);

        $this->actingAs($this->admin())->delete(route('admin.categories.destroy', $category))
            ->assertSessionHasErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('categories', ['slug' => 'new-child']);
    }

    public function test_unrelated_unique_error_is_not_disguised_as_slug_error(): void
    {
        DB::statement('CREATE TABLE unrelated_unique (value TEXT UNIQUE)');
        DB::table('unrelated_unique')->insert(['value' => 'taken']);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER categories_unrelated_unique
            BEFORE INSERT ON categories
            BEGIN INSERT INTO unrelated_unique (value) VALUES ('taken'); END;
        SQL);

        try {
            app(SaveCategory::class)->handle($this->payload());
            $this->fail('Expected unrelated unique violation.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsString('unrelated_unique', $exception->getMessage());
        }
    }

    public function test_unrelated_delete_error_is_not_disguised_as_fk_error(): void
    {
        $category = Category::factory()->create();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER categories_unrelated_delete
            BEFORE DELETE ON categories
            BEGIN SELECT RAISE(ABORT, 'unrelated_delete_failure'); END;
        SQL);

        $this->expectException(QueryException::class);
        app(DeleteCategory::class)->handle($category);
    }

    public function test_action_rejects_invalid_input_even_when_called_without_form_request(): void
    {
        try {
            app(SaveCategory::class)->handle($this->payload(['is_visible' => 'wrong']));
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_visible', $exception->errors());
        }
    }

    public function test_invalid_stored_role_or_status_fails_safely_on_category_route(): void
    {
        $invalidRole = $this->admin();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('users')->where('id', $invalidRole->id)->update(['role' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->actingAs($invalidRole)->get(route('admin.categories.index'))->assertForbidden();

        $invalidStatus = $this->admin();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('users')->where('id', $invalidStatus->id)->update(['status' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->actingAs($invalidStatus)->get(route('admin.categories.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_navigation_is_confined_to_the_workspace(): void
    {
        $link = 'href="'.route('admin.categories.index').'"';
        $this->get(route('home'))->assertDontSee($link, false);
        $this->actingAs(User::factory()->create())->get(route('home'))->assertDontSee($link, false);
        $this->actingAs($this->admin())->get(route('home'))->assertDontSee($link, false);
        $this->get(route('admin.categories.index'))->assertSee($link, false)
            ->assertSee('aria-current="page"', false);
    }
}
