<?php

namespace Tests\Feature;

use App\Actions\PromoteCustomerToEmployee;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbSecureProductionEmployeeBootstrapConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MARIADB_EMPLOYEE_BOOTSTRAP_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB Employee Bootstrap QA runner.');
        }
        if (DB::getDriverName() !== 'mysql'
            || ! str_contains((string) DB::getDatabaseName(), '_employee_bootstrap_qa_')) {
            $this->fail('Employee Bootstrap concurrency QA requires the isolated marker database.');
        }
    }

    public function test_concurrent_commands_create_one_employee_promotion_and_one_audit(): void
    {
        $user = User::factory()->create(['email' => 'concurrent-employee@example.com']);
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'employee-bootstrap-'.Str::uuid();
        $processes = array_map(function () use ($user, $barrier): Process {
            $process = new Process([
                PHP_BINARY,
                '-r',
                $this->workerCode(),
                $user->email,
                $barrier,
            ], base_path());
            $process->setTimeout(25);
            $process->start();

            return $process;
        }, [1, 2]);

        try {
            usleep(100000);
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }

        $this->assertSame(UserRole::Employee, $user->refresh()->role);
        $this->assertSame(1, AuditLog::query()
            ->where('action', PromoteCustomerToEmployee::AUDIT_ACTION)
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->count());
    }

    private function workerCode(): string
    {
        return <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$deadline = microtime(true) + 10;
while (! file_exists($argv[2]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[2])) { fwrite(STDERR, 'barrier timeout'); exit(4); }
try {
    $exit = Illuminate\Support\Facades\Artisan::call('app:promote-customer-to-employee', [
        'email' => $argv[1],
        '--no-interaction' => true,
    ]);
    exit($exit);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
    }
}
