param([string] $DatabasePrefix = 'computer_accessories_support_chat_qa')

$ErrorActionPreference = 'Stop'
$databaseName = '{0}_{1}_{2}' -f $DatabasePrefix, (Get-Date -Format 'yyyyMMddHHmmss'), $PID
if ($databaseName -notmatch '^[A-Za-z0-9_]+_support_chat_qa_[A-Za-z0-9_]+$') { throw 'Unsafe Support Chat QA database name.' }
$previous = @{}
foreach ($name in @('DB_DATABASE','DB_CONNECTION','RUN_MARIADB_SUPPORT_CHAT_QA')) {
    $previous[$name] = [PSCustomObject]@{ Exists = Test-Path "Env:$name"; Value = [Environment]::GetEnvironmentVariable($name) }
}
$created = $false
$createCode = @'
require getcwd().'/vendor/autoload.php'; $app=require getcwd().'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name=$argv[1]; if (!preg_match('/^[A-Za-z0-9_]+_support_chat_qa_[A-Za-z0-9_]+$/',$name)) throw new RuntimeException('Unsafe name');
Illuminate\Support\Facades\DB::statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
'@
$dropCode = @'
require getcwd().'/vendor/autoload.php'; $app=require getcwd().'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name=$argv[1]; if (!preg_match('/^[A-Za-z0-9_]+_support_chat_qa_[A-Za-z0-9_]+$/',$name)) throw new RuntimeException('Unsafe name');
Illuminate\Support\Facades\DB::statement('DROP DATABASE IF EXISTS `'.$name.'`');
'@
try {
    & php -r $createCode $databaseName
    if ($LASTEXITCODE -ne 0) { throw 'Could not create Support Chat QA database.' }
    $created = $true
    $env:DB_CONNECTION = 'mysql'; $env:DB_DATABASE = $databaseName; $env:RUN_MARIADB_SUPPORT_CHAT_QA = '1'
    & php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Support Chat QA migration failed.' }
    & php artisan migrate:rollback --step=1 --force
    if ($LASTEXITCODE -ne 0) { throw 'Support Chat QA rollback failed.' }
    & php artisan migrate --path=database/migrations/2026_10_06_000000_create_support_chat_tables.php --force
    if ($LASTEXITCODE -ne 0) { throw 'Support Chat QA reapply failed.' }
    & php vendor/bin/phpunit --no-configuration tests/Feature/MariaDbSupportChatConcurrencyTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Support Chat MariaDB QA failed.' }
} finally {
    foreach ($name in $previous.Keys) {
        if ($previous[$name].Exists) { [Environment]::SetEnvironmentVariable($name,$previous[$name].Value) }
        else { Remove-Item "Env:$name" -ErrorAction SilentlyContinue }
    }
    if ($created) { & php -r $dropCode $databaseName }
}
