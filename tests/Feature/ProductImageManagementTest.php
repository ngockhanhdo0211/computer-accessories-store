<?php

namespace Tests\Feature;

use App\Actions\AddProductImages;
use App\Actions\DeleteProduct;
use App\Actions\DeleteProductImage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductImageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_admin_uploads_valid_images_with_server_names_order_alt_and_one_primary(): void
    {
        $product = Product::factory()->create();
        $first = $this->image('../unsafe client name.jpg');
        $second = $this->image('second.png');
        $this->actingAs($this->admin());

        $this->post(route('admin.products.images.store', $product), [
            'images' => [$first, $second],
            'image_alt_texts' => ['  Ảnh trước  ', 'Ảnh bên cạnh'],
        ])->assertRedirect(route('admin.products.edit', $product))->assertSessionHas('status');

        $images = $product->images()->get();
        $this->assertCount(2, $images);
        $this->assertSame([0, 1], $images->pluck('sort_order')->all());
        $this->assertSame(1, $images->where('is_primary', true)->count());
        $this->assertSame('Ảnh trước', $images->first()->alt_text);
        foreach ($images as $image) {
            $this->assertStringStartsWith('products/'.$product->id.'/', $image->path);
            $this->assertStringNotContainsString('unsafe', $image->path);
            $this->assertStringNotContainsString('..', $image->path);
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_upload_validation_rejects_non_image_svg_oversize_and_too_many_files(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->admin());

        $cases = [
            [[UploadedFile::fake()->create('payload.txt', 10, 'text/plain')], 'images.0'],
            [[UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')], 'images.0'],
            [[UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo "unsafe";')], 'images.0'],
            [[UploadedFile::fake()->createWithContent('text.png', 'not an image')], 'images.0'],
            [[UploadedFile::fake()->create('declared.png', 10, 'text/plain')], 'images.0'],
            [[$this->image('large.png', 5 * 1024 * 1024)], 'images.0'],
            [array_map(fn ($index) => $this->image("{$index}.png"), range(1, 9)), 'images'],
        ];

        foreach ($cases as [$files, $error]) {
            $this->post(route('admin.products.images.store', $product), ['images' => $files])
                ->assertSessionHasErrors($error);
        }

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_total_image_limit_is_enforced_after_lock_and_new_files_are_cleaned(): void
    {
        $product = Product::factory()->create();
        ProductImage::factory()->count(8)->for($product)->sequence(fn ($sequence) => ['sort_order' => $sequence->index])->create();

        try {
            app(AddProductImages::class)->handle($product, [$this->image('ninth.png')]);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('images', $exception->errors());
        }

        $this->assertDatabaseCount('product_images', 8);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_admin_reorders_images_and_changes_exactly_one_primary(): void
    {
        $product = Product::factory()->create();
        $images = ProductImage::factory()->count(3)->for($product)->sequence(
            ['sort_order' => 0, 'is_primary' => true],
            ['sort_order' => 1, 'is_primary' => false],
            ['sort_order' => 2, 'is_primary' => false],
        )->create();
        $this->actingAs($this->admin());

        $this->patch(route('admin.products.images.update', $product), [
            'ordered_image_ids' => [$images[2]->id, $images[0]->id, $images[1]->id],
            'primary_image_id' => $images[1]->id,
        ])->assertRedirect(route('admin.products.edit', $product));

        $ordered = $product->images()->get();
        $this->assertSame([$images[2]->id, $images[0]->id, $images[1]->id], $ordered->pluck('id')->all());
        $this->assertSame([0, 1, 2], $ordered->pluck('sort_order')->all());
        $this->assertSame([$images[1]->id], $ordered->where('is_primary', true)->pluck('id')->all());
    }

    public function test_reorder_rejects_missing_duplicate_foreign_or_wrong_primary_ids_atomically(): void
    {
        $product = Product::factory()->create();
        $images = ProductImage::factory()->count(2)->for($product)->sequence(
            ['sort_order' => 0, 'is_primary' => true],
            ['sort_order' => 1, 'is_primary' => false],
        )->create();
        $other = ProductImage::factory()->create();
        $this->actingAs($this->admin());

        $cases = [
            [['ordered_image_ids' => [$images[0]->id], 'primary_image_id' => $images[0]->id], 'ordered_image_ids'],
            [['ordered_image_ids' => [$images[0]->id, $images[0]->id], 'primary_image_id' => $images[0]->id], 'ordered_image_ids.1'],
            [['ordered_image_ids' => [$images[0]->id, $other->id], 'primary_image_id' => $images[0]->id], 'ordered_image_ids'],
            [['ordered_image_ids' => [$images[0]->id, $images[1]->id], 'primary_image_id' => $other->id], 'ordered_image_ids'],
        ];

        foreach ($cases as [$data, $error]) {
            $this->patch(route('admin.products.images.update', $product), $data)->assertSessionHasErrors($error);
        }

        $this->assertTrue($images[0]->fresh()->is_primary);
        $this->assertFalse($images[1]->fresh()->is_primary);
        $this->assertSame([0, 1], $product->images()->pluck('sort_order')->all());
    }

    public function test_deleting_normal_and_primary_images_resequences_and_selects_first_fallback(): void
    {
        $product = Product::factory()->create();
        $images = ProductImage::factory()->count(3)->for($product)->sequence(
            ['path' => 'products/1/first.jpg', 'sort_order' => 0, 'is_primary' => true],
            ['path' => 'products/1/second.jpg', 'sort_order' => 1, 'is_primary' => false],
            ['path' => 'products/1/third.jpg', 'sort_order' => 2, 'is_primary' => false],
        )->create();
        foreach ($images as $image) {
            Storage::disk('public')->put($image->path, 'image');
        }
        $this->actingAs($this->admin());

        $this->delete(route('admin.products.images.destroy', [$product, $images[2]]))->assertRedirect();
        Storage::disk('public')->assertMissing($images[2]->path);
        $this->assertSame([0, 1], $product->images()->pluck('sort_order')->all());

        $this->delete(route('admin.products.images.destroy', [$product, $images[0]]))->assertRedirect();
        Storage::disk('public')->assertMissing($images[0]->path);
        $remaining = $product->images()->get();
        $this->assertCount(1, $remaining);
        $this->assertTrue($remaining->first()->is_primary);
        $this->assertSame(0, $remaining->first()->sort_order);
    }

    public function test_admin_cannot_delete_image_owned_by_another_product(): void
    {
        $product = Product::factory()->create();
        $otherImage = ProductImage::factory()->create(['path' => 'products/other/keep.jpg']);
        Storage::disk('public')->put($otherImage->path, 'keep');

        $this->actingAs($this->admin())
            ->delete(route('admin.products.images.destroy', [$product, $otherImage]))
            ->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $otherImage->id]);
        Storage::disk('public')->assertExists($otherImage->path);
    }

    public function test_failure_midway_through_multi_upload_cleans_files_already_written(): void
    {
        $product = Product::factory()->create();
        $source = $this->image('source.png');
        $failing = new class($source->getRealPath(), 'failing.png', 'image/png', UPLOAD_ERR_OK, true) extends UploadedFile
        {
            public function storeAs($path, $name = null, $options = [])
            {
                return false;
            }
        };

        try {
            app(AddProductImages::class)->handle($product, [
                $this->image('stored-first.png'),
                $failing,
            ]);
            $this->fail('Expected upload validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('images', $exception->errors());
        }

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_database_failure_during_metadata_insert_cleans_every_new_file(): void
    {
        $product = Product::factory()->create();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_images_fail_insert
            BEFORE INSERT ON product_images
            BEGIN SELECT RAISE(ABORT, 'metadata_failure'); END;
        SQL);

        try {
            app(AddProductImages::class)->handle($product, [
                $this->image('one.png'),
                $this->image('two.png'),
            ]);
            $this->fail('Expected query exception.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_deleting_product_removes_metadata_then_files_after_commit(): void
    {
        $product = Product::factory()->create();
        $images = ProductImage::factory()->count(2)->for($product)
            ->sequence(fn ($sequence) => [
                'path' => 'products/'.$product->id.'/delete-'.$sequence->index.'.jpg',
                'sort_order' => $sequence->index,
            ])->create();
        foreach ($images as $image) {
            Storage::disk('public')->put($image->path, 'image');
        }

        $this->actingAs($this->admin())
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['product_id' => $product->id]);
        foreach ($images as $image) {
            Storage::disk('public')->assertMissing($image->path);
        }
    }

    public function test_future_foreign_key_dependency_rolls_back_metadata_and_keeps_files(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->create(['path' => 'products/'.$product->id.'/keep.jpg']);
        Storage::disk('public')->put($image->path, 'keep');

        Schema::create('product_dependencies', function ($table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
        });
        DB::table('product_dependencies')->insert(['product_id' => $product->id]);

        try {
            app(DeleteProduct::class)->handle($product);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product', $exception->errors());
        }

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_failed_product_update_validation_preserves_existing_images_and_files(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->primary()->create(['path' => 'products/'.$product->id.'/existing.jpg']);
        Storage::disk('public')->put($image->path, 'existing');
        $this->actingAs($this->admin());

        $this->put(route('admin.products.update', $product), [
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'name' => '   ',
            'slug' => $product->slug,
            'sku' => $product->sku,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'price_vnd' => $product->price_vnd,
            'visibility' => 'active',
            'images' => [$this->image('new.png')],
        ])->assertSessionHasErrors('name');

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->path);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_file_cleanup_waits_for_outer_commit_and_is_cancelled_on_rollback(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->create([
            'path' => 'products/'.$product->id.'/rollback.jpg',
        ]);
        Storage::disk('public')->put($image->path, 'keep');

        DB::beginTransaction();

        try {
            app(DeleteProductImage::class)->handle($product, $image);
            $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
            Storage::disk('public')->assertExists($image->path);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->path);

        $wholeProduct = Product::factory()->create();
        $wholeImage = ProductImage::factory()->for($wholeProduct)->create([
            'path' => 'products/'.$wholeProduct->id.'/whole-rollback.jpg',
        ]);
        Storage::disk('public')->put($wholeImage->path, 'keep');

        DB::beginTransaction();

        try {
            app(DeleteProduct::class)->handle($wholeProduct);
            $this->assertDatabaseMissing('products', ['id' => $wholeProduct->id]);
            Storage::disk('public')->assertExists($wholeImage->path);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseHas('products', ['id' => $wholeProduct->id]);
        $this->assertDatabaseHas('product_images', ['id' => $wholeImage->id]);
        Storage::disk('public')->assertExists($wholeImage->path);
    }

    public function test_missing_or_unsafe_physical_paths_do_not_break_metadata_cleanup_or_delete_outside_product_directory(): void
    {
        $missingProduct = Product::factory()->create();
        $missing = ProductImage::factory()->for($missingProduct)->create([
            'path' => 'products/'.$missingProduct->id.'/already-missing.jpg',
        ]);

        app(DeleteProductImage::class)->handle($missingProduct, $missing);
        $this->assertDatabaseMissing('product_images', ['id' => $missing->id]);

        $unsafeProduct = Product::factory()->create();
        $unsafe = ProductImage::factory()->for($unsafeProduct)->create([
            'path' => 'products/'.$unsafeProduct->id.'/../outside.txt',
        ]);
        Storage::disk('public')->put('products/outside.txt', 'keep');

        app(DeleteProductImage::class)->handle($unsafeProduct, $unsafe);
        $this->assertDatabaseMissing('product_images', ['id' => $unsafe->id]);
        Storage::disk('public')->assertExists('products/outside.txt');

        $unsafeProductImage = ProductImage::factory()->for($unsafeProduct)->create([
            'path' => 'products/'.$unsafeProduct->id.'/../outside.txt',
        ]);
        app(DeleteProduct::class)->handle($unsafeProduct);

        $this->assertDatabaseMissing('products', ['id' => $unsafeProduct->id]);
        $this->assertDatabaseMissing('product_images', ['id' => $unsafeProductImage->id]);
        Storage::disk('public')->assertExists('products/outside.txt');
    }

    public function test_primary_fallback_is_deterministic_when_sort_orders_tie(): void
    {
        $product = Product::factory()->create();
        $primary = ProductImage::factory()->for($product)->primary()->create([
            'path' => 'products/'.$product->id.'/primary.jpg',
            'sort_order' => 0,
        ]);
        $expected = ProductImage::factory()->for($product)->create([
            'path' => 'products/'.$product->id.'/expected.jpg',
            'sort_order' => 1,
        ]);
        ProductImage::factory()->for($product)->create([
            'path' => 'products/'.$product->id.'/later.jpg',
            'sort_order' => 1,
        ]);
        Storage::disk('public')->put($primary->path, 'image');

        app(DeleteProductImage::class)->handle($product, $primary);

        $expected->refresh();
        $this->assertTrue($expected->is_primary);
        $this->assertSame(0, $expected->sort_order);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_upload_action_revalidates_file_content_and_alt_text_outside_http(): void
    {
        $product = Product::factory()->create();

        try {
            app(AddProductImages::class)->handle(
                $product,
                [UploadedFile::fake()->createWithContent('payload.jpg', '<?php echo "unsafe";')]
            );
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('images.0', $exception->errors());
        }

        try {
            app(AddProductImages::class)->handle(
                $product,
                [$this->image('valid.png')],
                [str_repeat('a', 256)]
            );
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('image_alt_texts.0', $exception->errors());
        }
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_unrelated_delete_query_exception_is_not_disguised_as_business_error(): void
    {
        $product = Product::factory()->create();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER products_unrelated_delete
            BEFORE DELETE ON products
            BEGIN SELECT RAISE(ABORT, 'unrelated_delete_failure'); END;
        SQL);

        $this->expectException(QueryException::class);
        app(DeleteProduct::class)->handle($product);
    }

    public function test_image_routes_share_admin_authorization_and_have_no_get_mutation_routes(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->create();

        $this->get(route('admin.products.images.store', $product))->assertMethodNotAllowed();
        $this->get(route('admin.products.images.update', $product))->assertMethodNotAllowed();
        $this->get(route('admin.products.images.destroy', [$product, $image]))->assertMethodNotAllowed();

        foreach ([User::factory()->create(), User::factory()->employee()->create()] as $user) {
            $this->actingAs($user)->post(route('admin.products.images.store', $product), [])->assertForbidden();
            $this->actingAs($user)->patch(route('admin.products.images.update', $product), [])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.products.images.destroy', [$product, $image]))->assertForbidden();
        }
    }

    private function image(string $name, int $paddingBytes = 0): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($name, $png.str_repeat('x', $paddingBytes));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}
