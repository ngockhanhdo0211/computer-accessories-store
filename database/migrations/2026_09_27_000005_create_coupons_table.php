<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGETS = [
        'coupon_products' => ['target' => 'product_id', 'table' => 'products', 'scope' => 'product'],
        'coupon_categories' => ['target' => 'category_id', 'table' => 'categories', 'scope' => 'category'],
        'coupon_brands' => ['target' => 'brand_id', 'table' => 'brands', 'scope' => 'brand'],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteTables();
        } elseif ($driver === 'mysql') {
            $this->createMariaDbTables();
        } else {
            throw new RuntimeException("Unsupported database driver for coupon constraints: {$driver}");
        }

        $this->createScopeTriggers($driver);
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        $this->dropScopeTriggers($driver);

        foreach (array_reverse(array_keys(self::TARGETS)) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::dropIfExists('coupons');
    }

    private function createMariaDbTables(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('type', 20);
            $table->string('scope', 16);
            $table->unsignedBigInteger('value')->default(0);
            $table->unsignedBigInteger('min_subtotal_vnd')->default(0);
            $table->string('required_tier', 16)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_user')->nullable();
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['is_active', 'starts_at', 'ends_at'], 'coupons_active_window_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE coupons
                ADD CONSTRAINT coupons_type_check CHECK (type IN ('percent','fixed','free_shipping')),
                ADD CONSTRAINT coupons_scope_check CHECK (scope IN ('cart','product','category','brand')),
                ADD CONSTRAINT coupons_value_check CHECK (
                    (type = 'percent' AND value BETWEEN 1 AND 100) OR
                    (type = 'fixed' AND value > 0) OR
                    (type = 'free_shipping' AND value = 0)
                ),
                ADD CONSTRAINT coupons_min_subtotal_check CHECK (min_subtotal_vnd >= 0),
                ADD CONSTRAINT coupons_required_tier_check CHECK (required_tier IS NULL OR required_tier IN ('dong','bac','vang','kim_cuong')),
                ADD CONSTRAINT coupons_max_uses_check CHECK (max_uses IS NULL OR max_uses > 0),
                ADD CONSTRAINT coupons_max_uses_per_user_check CHECK (max_uses_per_user IS NULL OR max_uses_per_user > 0),
                ADD CONSTRAINT coupons_time_window_check CHECK (ends_at > starts_at),
                ADD CONSTRAINT coupons_is_active_check CHECK (is_active IN (0,1))
            SQL);

        foreach (self::TARGETS as $table => $definition) {
            Schema::create($table, function (Blueprint $blueprint) use ($definition) {
                $blueprint->unsignedBigInteger('coupon_id');
                $blueprint->unsignedBigInteger($definition['target']);
                $blueprint->primary(['coupon_id', $definition['target']]);
                $blueprint->foreign('coupon_id')->references('id')->on('coupons')->restrictOnDelete()->restrictOnUpdate();
                $blueprint->foreign($definition['target'])->references('id')->on($definition['table'])->restrictOnDelete()->restrictOnUpdate();
                $blueprint->index([$definition['target'], 'coupon_id'], $definition['target'].'_coupon_id_index');
            });
        }
    }

    private function createSqliteTables(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE coupons (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code VARCHAR(80) COLLATE NOCASE NOT NULL,
                type VARCHAR(20) NOT NULL,
                scope VARCHAR(16) NOT NULL,
                value INTEGER NOT NULL DEFAULT 0,
                min_subtotal_vnd INTEGER NOT NULL DEFAULT 0,
                required_tier VARCHAR(16) NULL,
                max_uses INTEGER NULL,
                max_uses_per_user INTEGER NULL,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT coupons_code_unique UNIQUE (code),
                CONSTRAINT coupons_type_check CHECK (type IN ('percent','fixed','free_shipping')),
                CONSTRAINT coupons_scope_check CHECK (scope IN ('cart','product','category','brand')),
                CONSTRAINT coupons_value_check CHECK (
                    (type = 'percent' AND value BETWEEN 1 AND 100) OR
                    (type = 'fixed' AND value > 0) OR
                    (type = 'free_shipping' AND value = 0)
                ),
                CONSTRAINT coupons_min_subtotal_check CHECK (min_subtotal_vnd >= 0),
                CONSTRAINT coupons_required_tier_check CHECK (required_tier IS NULL OR required_tier IN ('dong','bac','vang','kim_cuong')),
                CONSTRAINT coupons_max_uses_check CHECK (max_uses IS NULL OR max_uses > 0),
                CONSTRAINT coupons_max_uses_per_user_check CHECK (max_uses_per_user IS NULL OR max_uses_per_user > 0),
                CONSTRAINT coupons_time_window_check CHECK (ends_at > starts_at),
                CONSTRAINT coupons_is_active_check CHECK (is_active IN (0,1))
            )
            SQL);
        DB::statement('CREATE INDEX coupons_active_window_index ON coupons (is_active, starts_at, ends_at)');

        foreach (self::TARGETS as $table => $definition) {
            $target = $definition['target'];
            $targetTable = $definition['table'];
            DB::statement("CREATE TABLE {$table} (
                coupon_id INTEGER NOT NULL,
                {$target} INTEGER NOT NULL,
                PRIMARY KEY (coupon_id, {$target}),
                FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                FOREIGN KEY ({$target}) REFERENCES {$targetTable}(id) ON UPDATE RESTRICT ON DELETE RESTRICT
            )");
            DB::statement("CREATE INDEX {$target}_coupon_id_index ON {$table} ({$target}, coupon_id)");
        }
    }

    private function createScopeTriggers(string $driver): void
    {
        foreach (self::TARGETS as $table => $definition) {
            foreach (['insert' => 'NEW', 'update' => 'NEW'] as $event => $row) {
                $name = "{$table}_scope_{$event}";
                $scope = $definition['scope'];

                if ($driver === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($event)." ON {$table}
                        WHEN COALESCE((SELECT scope FROM coupons WHERE id = {$row}.coupon_id), '') <> '{$scope}'
                        BEGIN SELECT RAISE(ABORT, 'coupon target scope mismatch'); END");
                } else {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($event)." ON {$table} FOR EACH ROW
                        BEGIN
                            DECLARE coupon_scope VARCHAR(16);
                            SELECT scope INTO coupon_scope FROM coupons WHERE id = {$row}.coupon_id FOR UPDATE;
                            IF coupon_scope IS NULL OR coupon_scope <> '{$scope}' THEN
                                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coupon target scope mismatch';
                            END IF;
                        END");
                }
            }
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER coupons_scope_update BEFORE UPDATE OF scope ON coupons
                WHEN NEW.scope <> OLD.scope AND (
                    EXISTS (SELECT 1 FROM coupon_products WHERE coupon_id = OLD.id) OR
                    EXISTS (SELECT 1 FROM coupon_categories WHERE coupon_id = OLD.id) OR
                    EXISTS (SELECT 1 FROM coupon_brands WHERE coupon_id = OLD.id)
                )
                BEGIN SELECT RAISE(ABORT, 'remove coupon targets before changing scope'); END
                SQL);
        } else {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER coupons_scope_update BEFORE UPDATE ON coupons FOR EACH ROW
                BEGIN
                    IF NEW.scope <> OLD.scope AND (
                        EXISTS (SELECT 1 FROM coupon_products WHERE coupon_id = OLD.id) OR
                        EXISTS (SELECT 1 FROM coupon_categories WHERE coupon_id = OLD.id) OR
                        EXISTS (SELECT 1 FROM coupon_brands WHERE coupon_id = OLD.id)
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'remove coupon targets before changing scope';
                    END IF;
                END
                SQL);
        }
    }

    private function dropScopeTriggers(string $driver): void
    {
        foreach (array_keys(self::TARGETS) as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_scope_insert");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_scope_update");
        }
        DB::statement('DROP TRIGGER IF EXISTS coupons_scope_update');
    }
};
