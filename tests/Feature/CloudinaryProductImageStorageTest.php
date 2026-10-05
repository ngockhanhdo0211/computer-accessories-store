<?php

namespace Tests\Feature;

use App\Actions\AddProductImages;
use App\Actions\DeleteProduct;
use App\Actions\DeleteProductImage;
use App\Contracts\ProductImageStorage;
use App\Contracts\ProductImageStorageResolver;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\ValueObjects\StoredProductImage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CloudinaryProductImageStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloudinary_upload_persists_provider_evidence_and_renders_without_remote_call(): void
    {
        $storage = new FakeProductImageStorage('cloudinary');
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create();

        $images = app(AddProductImages::class)->handle($product, [$this->image('image.png')]);

        $this->assertCount(1, $images);
        $image = $images[0]->fresh();
        $this->assertSame('cloudinary', $image->storage_provider);
        $this->assertNull($image->path);
        $this->assertSame('https://res.cloudinary.example/image.png', $image->url());
        $this->assertSame(1, $image->width);
        $this->assertSame(68, $image->bytes);
        $this->assertCount(1, $storage->stored);
    }

    public function test_partial_cloudinary_multi_upload_failure_cleans_each_created_asset(): void
    {
        $storage = new FakeProductImageStorage('cloudinary', failAt: 2);
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create();

        try {
            app(AddProductImages::class)->handle($product, [$this->image('one.png'), $this->image('two.png')]);
            $this->fail('Expected storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sanitized fake upload failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('product_images', 0);
        $this->assertCount(1, $storage->deleted);
        $this->assertSame($storage->stored[0]->cloudinaryPublicId, $storage->deleted[0]->cloudinaryPublicId);
    }

    public function test_client_cannot_inject_cloudinary_identity_folder_url_or_provider(): void
    {
        $storage = new FakeProductImageStorage('cloudinary');
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.products.images.store', $product), [
            'images' => [$this->image('client-name.png')],
            'storage_provider' => 'local',
            'path' => '../outside',
            'cloudinary_public_id' => 'attacker/asset',
            'secure_url' => 'http://attacker.example/asset',
            'folder' => 'attacker',
            'transformation' => 'unsafe',
        ])->assertRedirect();

        $image = ProductImage::query()->sole();
        $this->assertSame('cloudinary', $image->storage_provider);
        $this->assertStringStartsWith(
            config('product-images.cloudinary.folder').'/products/'.$product->id.'/',
            $image->cloudinary_public_id
        );
        $this->assertSame('https://res.cloudinary.example/image.png', $image->secure_url);
    }

    public function test_limit_is_rejected_before_cloudinary_upload(): void
    {
        $storage = new FakeProductImageStorage('cloudinary');
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create();
        ProductImage::factory()->count(8)->for($product)->create();

        try {
            app(AddProductImages::class)->handle($product, [$this->image('ninth.png')]);
            $this->fail('Expected image limit validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('images', $exception->errors());
        }

        $this->assertSame([], $storage->stored);
    }

    public function test_database_failure_after_cloudinary_upload_cleans_all_new_assets(): void
    {
        $storage = new FakeProductImageStorage('cloudinary');
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_images_cloud_fail_insert
            BEFORE INSERT ON product_images
            BEGIN SELECT RAISE(ABORT, 'metadata_failure'); END;
        SQL);

        try {
            app(AddProductImages::class)->handle($product, [$this->image('one.png'), $this->image('two.png')]);
            $this->fail('Expected database failure.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('product_images', 0);
        $this->assertCount(2, $storage->stored);
        $this->assertCount(2, $storage->deleted);
    }

    public function test_cloudinary_delete_dispatches_exact_owned_asset_and_missing_is_idempotent(): void
    {
        $storage = new FakeProductImageStorage('cloudinary');
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create();
        $image = $this->cloudinaryImage($product);

        app(DeleteProductImage::class)->handle($product, $image);

        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        $this->assertCount(1, $storage->deleted);
        $this->assertSame($image->cloudinary_public_id, $storage->deleted[0]->cloudinaryPublicId);
    }

    public function test_database_rejects_invalid_cloudinary_and_mixed_provider_shapes(): void
    {
        $product = Product::factory()->create();
        $base = [
            'product_id' => $product->id,
            'alt_text' => null,
            'is_primary' => 0,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach ([
            [...$base, 'storage_provider' => 'cloudinary', 'path' => null],
            [...$base, 'storage_provider' => 'local', 'path' => 'products/'.$product->id.'/a.png', 'secure_url' => 'https://example.test/a.png'],
            [...$base, 'storage_provider' => 'cloudinary', 'path' => null, 'cloudinary_public_id' => '../unsafe', 'secure_url' => 'http://example.test/a.png', 'width' => 1, 'height' => 1, 'bytes' => 1, 'format' => 'PNG'],
        ] as $row) {
            try {
                DB::table('product_images')->insert($row);
                $this->fail('Expected database storage shape rejection.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cloudinary_delete_failure_is_reported_for_reconciliation_without_secret_output(): void
    {
        $storage = new FakeProductImageStorage('cloudinary', failDelete: true);
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create();
        $image = $this->cloudinaryImage($product);

        try {
            app(DeleteProductImage::class)->handle($product, $image);
            $this->fail('Expected reconciliation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cloud image cleanup requires reconciliation.', $exception->getMessage());
            $this->assertStringNotContainsString('fake-secret', $exception->getMessage());
        }

        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    public function test_cloudinary_public_id_outside_configured_product_folder_is_blocked_before_metadata_delete(): void
    {
        $storage = new FakeProductImageStorage('cloudinary');
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create();
        $image = $this->cloudinaryImage($product);
        DB::table('product_images')->where('id', $image->id)->update([
            'cloudinary_public_id' => config('product-images.cloudinary.folder').'/products/999999/foreign-asset',
        ]);
        $image->refresh();

        $this->expectException(RuntimeException::class);
        try {
            app(DeleteProductImage::class)->handle($product, $image);
        } finally {
            $this->assertDatabaseHas('product_images', ['id' => $image->id]);
            $this->assertSame([], $storage->deleted);
        }
    }

    public function test_catalog_and_admin_indexes_render_cloudinary_url_without_storage_calls(): void
    {
        $storage = new FakeProductImageStorage('cloudinary');
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create(['sellable_quantity' => 1]);
        $image = $this->cloudinaryImage($product);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee($image->secure_url, false);
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($image->secure_url, false);

        $this->assertSame([], $storage->stored);
        $this->assertSame([], $storage->deleteAttempts);
    }

    public function test_product_delete_attempts_every_cloud_cleanup_before_reporting_reconciliation(): void
    {
        $storage = new FakeProductImageStorage('cloudinary', failDelete: true);
        $this->app->instance(ProductImageStorageResolver::class, new FakeProductImageStorageResolver($storage));
        $product = Product::factory()->create();
        $this->cloudinaryImage($product, 'first', true, 0);
        $this->cloudinaryImage($product, 'second', false, 1);

        try {
            app(DeleteProduct::class)->handle($product);
            $this->fail('Expected reconciliation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('One or more Cloud image cleanups require reconciliation.', $exception->getMessage());
        }

        $this->assertCount(2, $storage->deleteAttempts);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['product_id' => $product->id]);
    }

    private function cloudinaryImage(
        Product $product,
        string $suffix = 'asset',
        bool $primary = true,
        int $sortOrder = 0,
    ): ProductImage {
        $image = new ProductImage;
        $image->forceFill([
            'storage_provider' => 'cloudinary',
            'path' => null,
            'cloudinary_public_id' => config('product-images.cloudinary.folder').'/products/'.$product->id.'/'.$suffix,
            'secure_url' => 'https://res.cloudinary.example/'.$suffix.'.png',
            'width' => 1,
            'height' => 1,
            'bytes' => 68,
            'format' => 'png',
            'is_primary' => $primary,
            'sort_order' => $sortOrder,
        ]);
        $image->product()->associate($product);
        $image->save();

        return $image;
    }

    private function image(string $name): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($name, $png);
    }
}

class FakeProductImageStorageResolver implements ProductImageStorageResolver
{
    public function __construct(private readonly ProductImageStorage $storage) {}

    public function current(): ProductImageStorage
    {
        return $this->storage;
    }

    public function forProvider(string $provider): ProductImageStorage
    {
        return $this->storage;
    }
}

class FakeProductImageStorage implements ProductImageStorage
{
    /** @var array<int, StoredProductImage> */
    public array $stored = [];

    /** @var array<int, StoredProductImage> */
    public array $deleted = [];

    /** @var array<int, StoredProductImage> */
    public array $deleteAttempts = [];

    public function __construct(
        private readonly string $provider,
        private readonly ?int $failAt = null,
        private readonly bool $failDelete = false,
    ) {}

    public function store(int $productId, UploadedFile $file, string $format): StoredProductImage
    {
        if ($this->failAt === count($this->stored) + 1) {
            throw new RuntimeException('Sanitized fake upload failure.');
        }

        $index = count($this->stored) + 1;
        $image = $this->provider === 'cloudinary'
            ? new StoredProductImage(
                'cloudinary',
                null,
                config('product-images.cloudinary.folder')."/products/{$productId}/asset-{$index}",
                'https://res.cloudinary.example/image.png',
                1,
                1,
                68,
                $format,
            )
            : new StoredProductImage('local', "products/{$productId}/asset-{$index}.{$format}", null, null, null, null, null, null);
        $this->stored[] = $image;

        return $image;
    }

    public function delete(int $productId, StoredProductImage $image): void
    {
        $this->deleteAttempts[] = $image;
        if ($this->failDelete) {
            throw new RuntimeException('Sanitized fake delete failure.');
        }
        $this->deleted[] = $image;
    }
}
