<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ValidateProductionEnvironment extends Command
{
    protected $signature = 'app:validate-production';

    protected $description = 'Validate required production configuration without displaying secret values';

    public function handle(): int
    {
        $invalid = [];

        $this->expect($invalid, config('app.env') === 'production', 'APP_ENV');
        $this->expect($invalid, $this->strictEnvironmentBoolean('APP_DEBUG', false, config('app.debug')), 'APP_DEBUG');
        $this->expect($invalid, $this->validApplicationKey(config('app.key')), 'APP_KEY');
        $this->expect($invalid, $this->validPublicHttpsUrl(config('app.url'), rootOnly: true), 'APP_URL');
        $this->expect($invalid, config('database.default') === 'mysql', 'DB_CONNECTION');
        $this->expect($invalid, $this->present(config('database.connections.mysql.host')), 'DB_HOST');
        $this->expect($invalid, $this->validPort(config('database.connections.mysql.port')), 'DB_PORT');
        $this->expect($invalid, $this->present(config('database.connections.mysql.database')), 'DB_DATABASE');
        $this->expect($invalid, $this->present(config('database.connections.mysql.username')), 'DB_USERNAME');
        $this->expect($invalid, $this->present(config('database.connections.mysql.password')), 'DB_PASSWORD');
        $mysqlOptions = config('database.connections.mysql.options', []);
        $sslCa = is_array($mysqlOptions) ? ($mysqlOptions[\PDO::MYSQL_ATTR_SSL_CA] ?? null) : null;
        $verifyServerCertificate = is_array($mysqlOptions) ? ($mysqlOptions[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? null) : null;
        $this->expect($invalid, $this->validReadableFile($sslCa), 'MYSQL_ATTR_SSL_CA');
        $this->expect($invalid, $verifyServerCertificate === true, 'MYSQL_ATTR_SSL_VERIFY_SERVER_CERT');
        $this->expect($invalid, $this->validTimeout(config('deployment.database_connect_timeout')), 'DB_CONNECT_TIMEOUT');
        $this->expect($invalid, config('logging.default') === 'stderr', 'LOG_CHANNEL');
        $this->expect($invalid, config('session.driver') === 'database', 'SESSION_DRIVER');
        $this->expect($invalid, config('cache.default') === 'database', 'CACHE_STORE');
        $this->expect($invalid, config('queue.default') === 'sync', 'QUEUE_CONNECTION');
        $this->expect($invalid, $this->strictEnvironmentBoolean('SESSION_SECURE_COOKIE', true, config('session.secure')), 'SESSION_SECURE_COOKIE');
        $this->expect($invalid, $this->strictEnvironmentBoolean('SESSION_HTTP_ONLY', true, config('session.http_only')), 'SESSION_HTTP_ONLY');
        $this->expect($invalid, in_array(config('session.same_site'), ['lax', 'strict'], true), 'SESSION_SAME_SITE');
        $this->expect($invalid, $this->strictEnvironmentBoolean('RUN_MIGRATIONS', null, config('deployment.run_migrations')), 'RUN_MIGRATIONS');

        $this->validateProductImages($invalid);

        $this->validateVnPay($invalid);

        if ($invalid !== []) {
            foreach (array_unique($invalid) as $name) {
                $this->error("Invalid production configuration: {$name}.");
            }

            return self::FAILURE;
        }

        $this->info('Production configuration is valid.');

        return self::SUCCESS;
    }

    /** @param array<int, string> $invalid */
    private function validateProductImages(array &$invalid): void
    {
        $this->expect($invalid, config('product-images.driver') === 'cloudinary', 'PRODUCT_IMAGE_DRIVER');

        foreach ([
            'CLOUDINARY_CLOUD_NAME' => config('product-images.cloudinary.cloud_name'),
            'CLOUDINARY_API_KEY' => config('product-images.cloudinary.api_key'),
            'CLOUDINARY_API_SECRET' => config('product-images.cloudinary.api_secret'),
        ] as $name => $value) {
            $this->expect($invalid, $this->validCredential($value), $name);
        }

        $folder = config('product-images.cloudinary.folder');
        $this->expect(
            $invalid,
            is_string($folder)
                && $folder === 'computer-accessories-store/production',
            'CLOUDINARY_FOLDER'
        );
    }

    private function validCredential(mixed $value): bool
    {
        if (! $this->present($value)) {
            return false;
        }

        $raw = (string) $value;
        $normalized = strtolower(trim($raw));

        return ! in_array($normalized, ['changeme', 'placeholder', 'your-value', 'example'], true)
            && trim($raw) === $raw
            && ! preg_match('/[\r\n]/', $raw);
    }

    /** @param array<int, string> $invalid */
    private function validateVnPay(array &$invalid): void
    {
        $values = [
            'VNPAY_TERMINAL_CODE' => config('services.vnpay.terminal_code'),
            'VNPAY_HASH_SECRET' => config('services.vnpay.hash_secret'),
            'VNPAY_RETURN_URL' => config('services.vnpay.return_url'),
        ];

        if (! collect($values)->contains(fn (mixed $value): bool => $this->present($value))) {
            return;
        }

        foreach ($values as $name => $value) {
            $valid = $name === 'VNPAY_RETURN_URL'
                ? $this->validVnPayReturnUrl($value)
                : $this->present($value);
            $this->expect($invalid, $valid, $name);
        }
    }

    /** @param array<int, string> $invalid */
    private function expect(array &$invalid, bool $condition, string $name): void
    {
        if (! $condition) {
            $invalid[] = $name;
        }
    }

    private function validApplicationKey(mixed $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return is_string($decoded) && strlen($decoded) === 32;
        }

        return strlen($key) === 32;
    }

    private function validPublicHttpsUrl(mixed $url, bool $rootOnly = false): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        if (isset($parts['port'])) {
            return false;
        }

        foreach (['user', 'pass', 'query', 'fragment'] as $component) {
            if (array_key_exists($component, $parts)) {
                return false;
            }
        }

        $path = $parts['path'] ?? '';
        if ($rootOnly && ! in_array($path, ['', '/'], true)) {
            return false;
        }

        return $this->validPublicHostname((string) $parts['host']);
    }

    private function validPort(mixed $port): bool
    {
        if (is_int($port)) {
            return $port >= 1 && $port <= 65535;
        }

        return is_string($port)
            && preg_match('/^[1-9][0-9]{0,4}$/D', $port) === 1
            && (int) $port <= 65535;
    }

    private function validTimeout(mixed $timeout): bool
    {
        return $this->validPort($timeout) && (int) $timeout <= 2;
    }

    private function validReadableFile(mixed $path): bool
    {
        return $this->present($path) && is_file($path) && is_readable($path);
    }

    private function validPublicHostname(string $host): bool
    {
        $host = strtolower($host);

        if ($host === '' || strlen($host) > 253 || str_ends_with($host, '.')) {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function validVnPayReturnUrl(mixed $url): bool
    {
        if (! $this->validPublicHttpsUrl($url)) {
            return false;
        }

        $returnParts = parse_url((string) $url);
        $applicationParts = parse_url((string) config('app.url'));

        return is_array($returnParts)
            && is_array($applicationParts)
            && strtolower((string) ($returnParts['host'] ?? '')) === strtolower((string) ($applicationParts['host'] ?? ''))
            && ($returnParts['path'] ?? '') === '/checkout/vnpay/return';
    }

    private function strictEnvironmentBoolean(string $name, ?bool $expected, mixed $configured): bool
    {
        if (! is_bool($configured) || ($expected !== null && $configured !== $expected)) {
            return false;
        }

        $raw = getenv($name);
        if ($raw === false) {
            return true;
        }

        if ($raw !== 'true' && $raw !== 'false') {
            return false;
        }

        $rawBoolean = $raw === 'true';

        return $configured === $rawBoolean
            && ($expected === null || $rawBoolean === $expected);
    }

    private function present(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
