param(
    [string] $DatabasePrefix = 'computer_accessories_customer_cancel_qa'
)

$ErrorActionPreference = 'Stop'
$databaseName = '{0}_{1}_{2}' -f $DatabasePrefix, (Get-Date -Format 'yyyyMMddHHmmss'), $PID

if ($databaseName -notmatch '^[A-Za-z0-9_]+_customer_cancel_qa_[A-Za-z0-9_]+$') {
    throw 'The temporary database name must contain the _customer_cancel_qa_ safety marker.'
}

$previous = @{}
foreach ($name in @('DB_DATABASE', 'DB_CONNECTION', 'RUN_MARIADB_CUSTOMER_CANCEL_QA')) {
    $previous[$name] = [PSCustomObject]@{ Exists = Test-Path "Env:$name"; Value = [Environment]::GetEnvironmentVariable($name) }
}
$databaseCreated = $false

$createDatabase = @'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = $argv[1];
if (! preg_match('/^[A-Za-z0-9_]+_customer_cancel_qa_[A-Za-z0-9_]+$/', $name)) { throw new RuntimeException('Unsafe QA database name.'); }
Illuminate\Support\Facades\DB::statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
'@

$dropDatabase = @'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = $argv[1];
if (! preg_match('/^[A-Za-z0-9_]+_customer_cancel_qa_[A-Za-z0-9_]+$/', $name)) { throw new RuntimeException('Unsafe QA database name.'); }
Illuminate\Support\Facades\DB::statement('DROP DATABASE IF EXISTS `'.$name.'`');
'@

try {
    & php -r $createDatabase $databaseName
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the temporary MariaDB Customer cancellation QA database.' }
    $databaseCreated = $true

    $env:DB_CONNECTION = 'mysql'
    $env:DB_DATABASE = $databaseName
    $env:RUN_MARIADB_CUSTOMER_CANCEL_QA = '1'

    & php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Customer cancellation QA migration failed.' }

    & php artisan migrate:rollback --step=1 --force
    if ($LASTEXITCODE -ne 0) { throw 'Support Chat migration rollback before Customer cancellation QA failed.' }

    & php artisan migrate:rollback --step=1 --force
    if ($LASTEXITCODE -ne 0) { throw 'Customer cancellation QA migration rollback failed.' }

    & php artisan migrate --path=database/migrations/2026_10_05_000000_create_order_cancellation_requests.php --force
    if ($LASTEXITCODE -ne 0) { throw 'Customer cancellation QA migration reapply failed.' }

    & php artisan migrate --path=database/migrations/2026_10_06_000000_create_support_chat_tables.php --force
    if ($LASTEXITCODE -ne 0) { throw 'Support Chat migration reapply after Customer cancellation QA failed.' }

    & php vendor/bin/phpunit --no-configuration tests/Feature/MariaDbCustomerOrderCancellationConcurrencyTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Customer cancellation MariaDB metadata/concurrency QA failed.' }
} finally {
    foreach ($name in $previous.Keys) {
        if ($previous[$name].Exists) {
            [Environment]::SetEnvironmentVariable($name, $previous[$name].Value)
        } else {
            Remove-Item "Env:$name" -ErrorAction SilentlyContinue
        }
    }

    if ($databaseCreated) {
        & php -r $dropDatabase $databaseName
        if ($LASTEXITCODE -ne 0) { Write-Warning ('Temporary Customer cancellation QA database cleanup failed: {0}' -f $databaseName) }
    }
}
