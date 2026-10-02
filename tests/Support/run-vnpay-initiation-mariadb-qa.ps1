param(
    [string] $DatabasePrefix = 'computer_accessories_vnpay_initiation_qa'
)

$ErrorActionPreference = 'Stop'
$databaseName = '{0}_{1}_{2}' -f $DatabasePrefix, (Get-Date -Format 'yyyyMMddHHmmss'), $PID
if ($databaseName -notmatch '^[A-Za-z0-9_]+_vnpay_initiation_qa_[A-Za-z0-9_]+$') {
    throw 'The temporary database name must contain the _vnpay_initiation_qa_ safety marker.'
}

$oldDatabase = $env:DB_DATABASE
$oldConnection = $env:DB_CONNECTION
$oldFlag = $env:RUN_MARIADB_VNPAY_INITIATION_QA
$created = $false
$create = @'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = $argv[1];
if (! preg_match('/^[A-Za-z0-9_]+_vnpay_initiation_qa_[A-Za-z0-9_]+$/', $name)) { throw new RuntimeException('Unsafe QA database name.'); }
Illuminate\Support\Facades\DB::statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
'@
$drop = @'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = $argv[1];
if (! preg_match('/^[A-Za-z0-9_]+_vnpay_initiation_qa_[A-Za-z0-9_]+$/', $name)) { throw new RuntimeException('Unsafe QA database name.'); }
Illuminate\Support\Facades\DB::statement('DROP DATABASE IF EXISTS `'.$name.'`');
'@

try {
    & php -r $create $databaseName
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the temporary VNPay QA database.' }
    $created = $true
    $env:DB_CONNECTION = 'mysql'
    $env:DB_DATABASE = $databaseName
    $env:RUN_MARIADB_VNPAY_INITIATION_QA = '1'
    & php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'VNPay QA migration failed.' }
    & php vendor/bin/phpunit --no-configuration tests/Feature/MariaDbVnPayInitiationConcurrencyTest.php
    if ($LASTEXITCODE -ne 0) { throw 'VNPay MariaDB QA failed.' }
} finally {
    if ($null -eq $oldDatabase) { Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue } else { $env:DB_DATABASE = $oldDatabase }
    if ($null -eq $oldConnection) { Remove-Item Env:DB_CONNECTION -ErrorAction SilentlyContinue } else { $env:DB_CONNECTION = $oldConnection }
    if ($null -eq $oldFlag) { Remove-Item Env:RUN_MARIADB_VNPAY_INITIATION_QA -ErrorAction SilentlyContinue } else { $env:RUN_MARIADB_VNPAY_INITIATION_QA = $oldFlag }
    if ($created) {
        & php -r $drop $databaseName
        if ($LASTEXITCODE -ne 0) { Write-Warning ('Temporary QA database cleanup failed: {0}' -f $databaseName) }
    }
}
