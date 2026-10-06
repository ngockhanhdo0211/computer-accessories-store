<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MySql84MigrationCompatibilityTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function databaseVersions(): array
    {
        return [
            'MariaDB standard' => ['10.4.32-MariaDB', true],
            'MariaDB compatibility prefix' => ['5.5.5-10.4.32-MariaDB', true],
            'MariaDB mixed case' => ['5.5.5-10.4.32-MaRiAdB-log', true],
            'Oracle MySQL 8.4' => ['8.4.8', false],
            'Oracle MySQL distribution suffix' => ['8.4.8-commercial', false],
        ];
    }

    #[DataProvider('databaseVersions')]
    public function test_version_detection_handles_mariadb_prefix_and_oracle_mysql(string $version, bool $mariaDb): void
    {
        $this->assertSame($mariaDb, str_contains(strtolower($version), 'mariadb'));
    }

    public function test_case_sensitive_checks_keep_equivalent_mariadb_and_mysql_84_patterns(): void
    {
        $expectations = [
            '2026_09_30_000000_add_idempotency_fingerprint_to_orders_table.php' => [
                ["idempotency_fingerprint REGEXP BINARY '^[0-9a-f]{64}$'", "REGEXP_LIKE(idempotency_fingerprint, '^[0-9a-f]{64}$', 'c')"],
            ],
            '2026_10_03_000000_enable_vnpay_callback_finalization.php' => [
                ["{\$column} REGEXP BINARY '^[0-9a-f]{64}$'", "REGEXP_LIKE({\$column}, '^[0-9a-f]{64}$', 'c')"],
                ["{\$column} REGEXP BINARY '^[0-9]{2}$'", "REGEXP_LIKE({\$column}, '^[0-9]{2}$', 'c')"],
            ],
            '2026_10_04_000000_enable_vnpay_refund_processing.php' => [
                ["{\$column} REGEXP BINARY '{\$pattern}'", "REGEXP_LIKE({\$column}, '{\$pattern}', 'c')"],
            ],
            '2026_10_05_000000_create_order_cancellation_requests.php' => [
                ["review_fingerprint REGEXP BINARY '^[0-9a-f]{64}$'", "REGEXP_LIKE(review_fingerprint, '^[0-9a-f]{64}$', 'c')"],
            ],
            '2026_10_06_000000_create_support_chat_tables.php' => [
                ["client_message_key REGEXP BINARY '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'", "REGEXP_LIKE(client_message_key, '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', 'c')"],
            ],
            '2026_10_08_000000_add_idempotency_to_return_inspections.php' => [
                ["{\$column} REGEXP BINARY '{\$pattern}'", "REGEXP_LIKE({\$column}, '{\$pattern}', 'c')"],
            ],
        ];

        foreach ($expectations as $migration => $pairs) {
            $contents = file_get_contents(dirname(__DIR__, 2).'/database/migrations/'.$migration);

            $this->assertIsString($contents, $migration);
            $this->assertStringContainsString("str_contains(\$version, 'mariadb')", $contents, $migration);

            foreach ($pairs as [$mariaDb, $mysql]) {
                $this->assertStringContainsString($mariaDb, $contents, $migration);
                $this->assertStringContainsString($mysql, $contents, $migration);
            }
        }
    }
}
