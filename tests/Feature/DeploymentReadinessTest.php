<?php

namespace Tests\Feature;

use App\Services\VnPayRefundGateway;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_DEBUG', 'SESSION_SECURE_COOKIE', 'SESSION_HTTP_ONLY', 'RUN_MIGRATIONS'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }

        parent::tearDown();
    }

    public function test_liveness_is_minimal_public_and_never_queries_the_database(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $response = $this->get('/up')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame('ok', $response->getContent());
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertLessThanOrEqual(2, strlen((string) $response->getContent()));

        $this->get('/up')
            ->assertOk()
            ->assertSeeText('ok')
            ->assertDontSee('Laravel')
            ->assertDontSee(PHP_VERSION);

        $this->head('/up')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/up')->assertMethodNotAllowed();

        $this->assertSame(0, $queries);
        $this->assertSame([], Route::getRoutes()->getByName('health.up')?->gatherMiddleware());
    }

    public function test_readiness_performs_one_safe_query_and_hides_database_failures(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $response = $this->get('/health/ready')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSeeText('ready');

        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame(1, $queries);
        $this->assertSame([], Route::getRoutes()->getByName('health.ready')?->gatherMiddleware());
        $this->post('/health/ready')->assertMethodNotAllowed();
        $this->assertSame(1, $queries);

        DB::shouldReceive('select')
            ->once()
            ->andThrow(new RuntimeException('internal-db-host.example secret-password'));

        $this->get('/health/ready')
            ->assertStatus(503)
            ->assertSeeText('unavailable')
            ->assertDontSee('internal-db-host.example')
            ->assertDontSee('secret-password');
    }

    public function test_production_validation_accepts_safe_contract_and_reports_only_invalid_names(): void
    {
        $this->setValidProductionConfiguration();

        $exitCode = Artisan::call('app:validate-production');
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Production configuration is valid.', $output);

        config()->set('app.key', 'do-not-print-this-value');
        config()->set('app.url', 'http://127.0.0.1:8000/private');
        config()->set('app.debug', true);

        $this->assertSame(1, Artisan::call('app:validate-production'));
        $output = Artisan::output();
        $this->assertStringContainsString('APP_KEY', $output);
        $this->assertStringContainsString('APP_URL', $output);
        $this->assertStringContainsString('APP_DEBUG', $output);
        $this->assertStringNotContainsString('do-not-print-this-value', $output);
        $this->assertStringNotContainsString('127.0.0.1', $output);
    }

    public function test_production_validation_rejects_url_port_boolean_and_timeout_boundaries(): void
    {
        foreach ([
            'http://shop.example.test',
            'https://localhost',
            'https://store.localhost',
            'https://127.0.0.1',
            'https://10.0.0.5',
            'https://169.254.1.1',
            'https://[::1]',
            'https://user:pass@shop.example.test',
            'https://shop.example.test:8443',
            'https://shop.example.test/admin',
            'https://shop.example.test?next=attacker',
            'https://shop.example.test#fragment',
            'https://shop.example.test.',
            'https://single-label',
            'https://xn--.example.test',
        ] as $url) {
            $this->setValidProductionConfiguration();
            config()->set('app.url', $url);

            $this->assertSame(1, Artisan::call('app:validate-production'), $url);
            $this->assertStringContainsString('APP_URL', Artisan::output());
            $this->assertStringNotContainsString($url, Artisan::output());
        }

        foreach (['0', '03306', '+3306', '3306 ', '65536', [], null] as $port) {
            $this->setValidProductionConfiguration();
            config()->set('database.connections.mysql.port', $port);

            $this->assertSame(1, Artisan::call('app:validate-production'), 'DB_PORT');
            $this->assertStringContainsString('DB_PORT', Artisan::output());
        }

        foreach ([0, 3, '02', '2 ', [], null] as $timeout) {
            $this->setValidProductionConfiguration();
            config()->set('deployment.database_connect_timeout', $timeout);

            $this->assertSame(1, Artisan::call('app:validate-production'), 'DB_CONNECT_TIMEOUT');
            $this->assertStringContainsString('DB_CONNECT_TIMEOUT', Artisan::output());
        }

        $this->assertInvalidRawBoolean('APP_DEBUG', '0', false);
        $this->assertInvalidRawBoolean('APP_DEBUG', 'off', false);
        $this->assertInvalidRawBoolean('SESSION_SECURE_COOKIE', '1', true);
        $this->assertInvalidRawBoolean('SESSION_HTTP_ONLY', 'yes', true);
        $this->assertInvalidRawBoolean('RUN_MIGRATIONS', ' false ', false);
    }

    public function test_production_validation_requires_a_readable_ca_and_server_certificate_verification(): void
    {
        $this->setValidProductionConfiguration();
        config()->set('database.connections.mysql.options', [
            \PDO::MYSQL_ATTR_SSL_CA => 'C:/private/do-not-report-ca.pem',
            \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]);

        $this->assertSame(1, Artisan::call('app:validate-production'));
        $output = Artisan::output();
        $this->assertStringContainsString('MYSQL_ATTR_SSL_CA', $output);
        $this->assertStringContainsString('MYSQL_ATTR_SSL_VERIFY_SERVER_CERT', $output);
        $this->assertStringNotContainsString('C:/private/do-not-report-ca.pem', $output);
    }

    public function test_production_validation_requires_cloudinary_without_reporting_credentials(): void
    {
        $this->setValidProductionConfiguration();
        config()->set([
            'product-images.driver' => 'local',
            'product-images.cloudinary.api_secret' => 'do-not-report-cloudinary-secret',
            'product-images.cloudinary.folder' => 'computer-accessories-store/qa',
        ]);

        $this->assertSame(1, Artisan::call('app:validate-production'));
        $output = Artisan::output();
        $this->assertStringContainsString('PRODUCT_IMAGE_DRIVER', $output);
        $this->assertStringContainsString('CLOUDINARY_FOLDER', $output);
        $this->assertStringNotContainsString('do-not-report-cloudinary-secret', $output);
        $this->assertStringNotContainsString('computer-accessories-store/qa', $output);

        foreach ([
            'CLOUDINARY_CLOUD_NAME' => 'product-images.cloudinary.cloud_name',
            'CLOUDINARY_API_KEY' => 'product-images.cloudinary.api_key',
            'CLOUDINARY_API_SECRET' => 'product-images.cloudinary.api_secret',
        ] as $name => $configKey) {
            $this->setValidProductionConfiguration();
            config()->set($configKey, null);

            $this->assertSame(1, Artisan::call('app:validate-production'), $name);
            $this->assertStringContainsString($name, Artisan::output());
        }

        $this->setValidProductionConfiguration();
        config()->set('product-images.cloudinary.api_key', ' key-with-space ');
        $this->assertSame(1, Artisan::call('app:validate-production'));
        $this->assertStringContainsString('CLOUDINARY_API_KEY', Artisan::output());
    }

    public function test_local_database_configuration_does_not_force_tls_without_a_ca(): void
    {
        foreach (['mysql', 'mariadb'] as $connection) {
            $options = config("database.connections.{$connection}.options");

            $this->assertIsArray($options);
            $this->assertArrayNotHasKey(\PDO::MYSQL_ATTR_SSL_CA, $options);
            $this->assertArrayNotHasKey(\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT, $options);
        }
    }

    public function test_vnpay_validation_requires_the_canonical_return_endpoint_when_enabled(): void
    {
        foreach ([
            'https://attacker.example.test/checkout/vnpay/return',
            'https://shop.example.test/checkout/vnpay/return/extra',
            'https://shop.example.test/checkout/vnpay/return?next=attacker',
        ] as $returnUrl) {
            $this->setValidProductionConfiguration();
            config()->set([
                'services.vnpay.terminal_code' => 'terminal',
                'services.vnpay.hash_secret' => 'secret',
                'services.vnpay.return_url' => $returnUrl,
            ]);

            $this->assertSame(1, Artisan::call('app:validate-production'), $returnUrl);
            $this->assertStringContainsString('VNPAY_RETURN_URL', Artisan::output());
            $this->assertStringNotContainsString($returnUrl, Artisan::output());
        }
    }

    public function test_vnpay_validation_requires_safe_refund_configuration_when_enabled(): void
    {
        foreach ([
            'VNPAY_REFUND_URL' => ['services.vnpay.refund_url', 'https://example.test/refund'],
            'VNPAY_REFUND_CREATE_BY' => ['services.vnpay.refund_create_by', ''],
            'VNPAY_REFUND_IP_ADDRESS' => ['services.vnpay.refund_ip_address', 'not-an-ip'],
            'VNPAY_REFUND_CONNECT_TIMEOUT' => ['services.vnpay.refund_connect_timeout', 0],
            'VNPAY_REFUND_TIMEOUT' => ['services.vnpay.refund_timeout', 0],
            'VNPAY_REFUND_SUBMISSION_STALE_SECONDS' => ['services.vnpay.refund_submission_stale_seconds', 15],
        ] as $name => [$configKey, $invalidValue]) {
            $this->setValidProductionConfiguration();
            config()->set($configKey, $invalidValue);

            $this->assertSame(1, Artisan::call('app:validate-production'), $name);
            $this->assertStringContainsString($name, Artisan::output());
            if ((string) $invalidValue !== '') {
                $this->assertStringNotContainsString((string) $invalidValue, Artisan::output());
            }
        }
    }

    public function test_forwarded_https_generates_secure_urls_without_trusting_forwarded_host(): void
    {
        config()->set('app.url', 'https://shop.example.test');

        Route::get('/deployment-url-probe', fn () => response()->json([
            'home' => route('home'),
            'asset' => asset('images/laptop-accessories-hero.jpg'),
            'vnpay_return' => route('checkout.vnpay.return'),
            'vnpay_ipn' => route('checkout.vnpay.ipn'),
        ]));

        $secure = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '444',
            'X-Forwarded-Host' => 'attacker.example.test',
        ])->get('http://shop.example.test/deployment-url-probe')->assertOk()->json();

        foreach ($secure as $url) {
            $this->assertStringStartsWith('https://', $url);
            $this->assertSame('shop.example.test', parse_url($url, PHP_URL_HOST));
            $this->assertStringNotContainsString('attacker.example.test', $url);
            $this->assertStringNotContainsString(':444', $url);
        }

        $trustedHosts = app(TrustHosts::class)->hosts();
        $this->assertSame(['^shop\.example\.test$'], $trustedHosts);

        Request::setTrustedHosts($trustedHosts);
        try {
            $this->assertSame('shop.example.test', Request::create('http://SHOP.EXAMPLE.TEST')->getHost());

            foreach ([
                'shop-example.test',
                'shop.example.test.',
                'user@shop.example.test',
                'shop.example.test,attacker.example.test',
                'attacker.example.test',
            ] as $host) {
                try {
                    Request::create('/probe', 'GET', [], [], [], ['HTTP_HOST' => $host])->getHost();
                    $this->fail("Untrusted Host [{$host}] must be rejected.");
                } catch (SuspiciousOperationException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            Request::setTrustedHosts([]);
        }
    }

    public function test_apache_rejects_explicit_port_and_unsafe_host_shapes(): void
    {
        $virtualHost = file_get_contents(base_path('docker/apache-vhost.conf'));

        $this->assertIsString($virtualHost);
        $this->assertStringContainsString('RewriteCond %{HTTP_HOST} !^[A-Za-z0-9.-]+$', $virtualHost);
        $this->assertStringContainsString('RewriteRule ^ - [R=400,L]', $virtualHost);
        $this->assertStringContainsString('Options -Indexes +FollowSymLinks', $virtualHost);
    }

    public function test_sensitive_paths_are_not_application_routes(): void
    {
        foreach (['/.env', '/.git/config', '/composer.json', '/storage/logs/laravel.log', '/docker/render-start.sh'] as $path) {
            $response = $this->get($path);

            $this->assertContains($response->getStatusCode(), [403, 404]);
            $response->assertDontSee('APP_KEY');
            $response->assertDontSee('render-start');
        }
    }

    public function test_cloudinary_secrets_are_excluded_from_docker_and_render_blueprint_contains_names_only(): void
    {
        $dockerIgnore = file_get_contents(base_path('.dockerignore'));
        $render = file_get_contents(base_path('render.yaml'));

        $this->assertIsString($dockerIgnore);
        $this->assertStringContainsString('cloudinary-*.env', $dockerIgnore);
        $this->assertStringContainsString('*.pem', $dockerIgnore);
        $this->assertIsString($render);
        foreach (['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $name) {
            $this->assertMatchesRegularExpression('/key:\s+'.$name.'\s+sync:\s+false/s', $render);
        }
        $this->assertStringContainsString('computer-accessories-store/production', $render);
        $this->assertStringNotContainsString('computer-accessories-store/qa', $render);
    }

    public function test_docker_runtime_grants_apache_worker_access_to_render_secret_files(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('getent group 1000', $dockerfile);
        $this->assertStringContainsString('groupadd --gid 1000 render-secrets', $dockerfile);
        $this->assertStringContainsString('usermod --append --groups "$render_secrets_group" www-data', $dockerfile);
        $this->assertStringContainsString("id -G www-data | tr ' ' '\\n' | grep -qx '1000'", $dockerfile);
        $this->assertStringNotContainsString('/etc/secrets', $dockerfile);
        $this->assertStringNotContainsString('MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=false', $dockerfile);
    }

    public function test_local_request_without_forwarded_headers_remains_http(): void
    {
        Route::get('/deployment-local-url-probe', fn () => response()->json([
            'home' => route('home'),
            'asset' => asset('images/laptop-accessories-hero.jpg'),
        ]));

        $local = $this->get('/deployment-local-url-probe')->assertOk()->json();
        foreach ($local as $url) {
            $this->assertStringStartsWith('http://', $url);
            $this->assertSame('localhost', parse_url($url, PHP_URL_HOST));
        }
    }

    public function test_secure_session_contract_and_production_exception_response(): void
    {
        $this->setValidProductionConfiguration();

        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertNull(config('session.domain'));

        Route::get('/deployment-exception-probe', function (): never {
            throw new RuntimeException('internal-sql-and-secret-value');
        });

        Log::spy();

        $this->get('/deployment-exception-probe')
            ->assertStatus(500)
            ->assertDontSee('internal-sql-and-secret-value')
            ->assertDontSee('RuntimeException');
    }

    private function setValidProductionConfiguration(): void
    {
        putenv('APP_DEBUG=false');
        putenv('SESSION_SECURE_COOKIE=true');
        putenv('SESSION_HTTP_ONLY=true');
        putenv('RUN_MIGRATIONS=false');

        config()->set([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://shop.example.test',
            'database.default' => 'mysql',
            'database.connections.mysql.host' => 'db.example.test',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'shop',
            'database.connections.mysql.username' => 'shop_user',
            'database.connections.mysql.password' => 'not-reported',
            'database.connections.mysql.options' => [
                \PDO::MYSQL_ATTR_SSL_CA => __FILE__,
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ],
            'deployment.database_connect_timeout' => 2,
            'logging.default' => 'stderr',
            'session.driver' => 'database',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.domain' => null,
            'cache.default' => 'database',
            'queue.default' => 'sync',
            'deployment.run_migrations' => false,
            'product-images.driver' => 'cloudinary',
            'product-images.cloudinary.cloud_name' => 'deployment-test-cloud',
            'product-images.cloudinary.api_key' => 'deployment-test-key',
            'product-images.cloudinary.api_secret' => 'deployment-test-secret',
            'product-images.cloudinary.folder' => 'computer-accessories-store/production',
            'filesystems.default' => 'public',
            'services.vnpay.terminal_code' => 'ABCDEFGH',
            'services.vnpay.hash_secret' => 'deployment-vnpay-secret',
            'services.vnpay.return_url' => 'https://shop.example.test/checkout/vnpay/return',
            'services.vnpay.refund_url' => VnPayRefundGateway::SANDBOX_REFUND_URL,
            'services.vnpay.refund_create_by' => 'refund-system',
            'services.vnpay.refund_ip_address' => '203.0.113.10',
            'services.vnpay.refund_connect_timeout' => 5,
            'services.vnpay.refund_timeout' => 15,
            'services.vnpay.refund_submission_stale_seconds' => 60,
        ]);
    }

    private function assertInvalidRawBoolean(string $name, string $value, bool $configured): void
    {
        $previous = getenv($name);

        try {
            $this->setValidProductionConfiguration();
            putenv("{$name}={$value}");

            switch ($name) {
                case 'APP_DEBUG':
                    config()->set('app.debug', $configured);
                    break;
                case 'SESSION_SECURE_COOKIE':
                    config()->set('session.secure', $configured);
                    break;
                case 'SESSION_HTTP_ONLY':
                    config()->set('session.http_only', $configured);
                    break;
                case 'RUN_MIGRATIONS':
                    config()->set('deployment.run_migrations', $configured);
                    break;
            }

            $this->assertSame(1, Artisan::call('app:validate-production'), $name);
            $this->assertStringContainsString($name, Artisan::output());
            $this->assertStringNotContainsString($value, Artisan::output());
        } finally {
            $previous === false ? putenv($name) : putenv("{$name}={$previous}");
        }
    }
}
