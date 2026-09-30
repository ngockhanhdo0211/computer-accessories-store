param(
    [string] $DatabasePrefix = 'computer_accessories_coupon_usage_qa'
)

$ErrorActionPreference = 'Stop'
$databaseName = '{0}_{1}_{2}' -f $DatabasePrefix, (Get-Date -Format 'yyyyMMddHHmmss'), $PID

if ($databaseName -notmatch '^[A-Za-z0-9_]+_coupon_usage_qa_[A-Za-z0-9_]+$') {
    throw 'The temporary database name must contain the _coupon_usage_qa_ safety marker.'
}

$hadDatabaseOverride = Test-Path Env:DB_DATABASE
$previousDatabase = $env:DB_DATABASE
$hadQaFlag = Test-Path Env:RUN_MARIADB_COUPON_USAGE_QA
$previousQaFlag = $env:RUN_MARIADB_COUPON_USAGE_QA
$hadConnectionOverride = Test-Path Env:DB_CONNECTION
$previousConnection = $env:DB_CONNECTION
$databaseCreated = $false

$createDatabase = @'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = $argv[1];
if (! preg_match('/^[A-Za-z0-9_]+_coupon_usage_qa_[A-Za-z0-9_]+$/', $name)) { throw new RuntimeException('Unsafe QA database name.'); }
Illuminate\Support\Facades\DB::statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
'@

$dropDatabase = @'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = $argv[1];
if (! preg_match('/^[A-Za-z0-9_]+_coupon_usage_qa_[A-Za-z0-9_]+$/', $name)) { throw new RuntimeException('Unsafe QA database name.'); }
Illuminate\Support\Facades\DB::statement('DROP DATABASE IF EXISTS `'.$name.'`');
'@

try {
    & php -r $createDatabase $databaseName
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the temporary MariaDB QA database.' }
    $databaseCreated = $true

    $env:DB_CONNECTION = 'mysql'
    $env:DB_DATABASE = $databaseName
    $env:RUN_MARIADB_COUPON_USAGE_QA = '1'

    & php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Coupon Usage QA migration failed.' }

    & php vendor/bin/phpunit --no-configuration tests/Feature/MariaDbCouponUsageConcurrencyTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Coupon Usage MariaDB QA failed.' }
} finally {
    if ($hadDatabaseOverride) { $env:DB_DATABASE = $previousDatabase } else { Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue }
    if ($hadConnectionOverride) { $env:DB_CONNECTION = $previousConnection } else { Remove-Item Env:DB_CONNECTION -ErrorAction SilentlyContinue }
    if ($hadQaFlag) { $env:RUN_MARIADB_COUPON_USAGE_QA = $previousQaFlag } else { Remove-Item Env:RUN_MARIADB_COUPON_USAGE_QA -ErrorAction SilentlyContinue }

    if ($databaseCreated) {
        & php -r $dropDatabase $databaseName
        if ($LASTEXITCODE -ne 0) { Write-Warning ('Temporary QA database cleanup failed: {0}' -f $databaseName) }
    }
}
