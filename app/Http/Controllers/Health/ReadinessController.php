<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(): Response
    {
        try {
            $this->assertDatabaseSocketReachable();
            $this->prepareConnectionTimeout();
            DB::select('SELECT 1');
        } catch (Throwable) {
            return $this->response('unavailable', 503);
        }

        return $this->response('ready', 200);
    }

    private function assertDatabaseSocketReachable(): void
    {
        if (config('database.default') !== 'mysql') {
            return;
        }

        $socket = @fsockopen(
            (string) config('database.connections.mysql.host'),
            (int) config('database.connections.mysql.port'),
            $errorCode,
            $errorMessage,
            (float) config('deployment.database_connect_timeout', 2),
        );

        if ($socket === false) {
            throw new RuntimeException('Database socket unavailable.');
        }

        fclose($socket);
    }

    private function prepareConnectionTimeout(): void
    {
        $connection = (string) config('database.default');
        if ($connection !== 'mysql') {
            return;
        }

        $options = config("database.connections.{$connection}.options", []);
        if (! is_array($options)) {
            $options = [];
        }

        $options[PDO::ATTR_TIMEOUT] = (int) config('deployment.database_connect_timeout', 2);
        config()->set("database.connections.{$connection}.options", $options);
        DB::purge($connection);
    }

    private function response(string $body, int $status): Response
    {
        return response($body, $status, [
            'Cache-Control' => 'no-store, private',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
