<?php

use PHPUnit\Framework\TestCase;

abstract class HttpServerTestCase extends TestCase
{
    private static string $baseUrl = '';

    /** @var resource|null */
    private static $serverProcess = null;

    /** Absolute path to the PHP router script that handles incoming requests. */
    abstract protected static function routerPath(): string;

    /**
     * Extra env variables to pass to the test server process.
     *
     * @return array<string, string>
     */
    protected static function serverEnv(): array
    {
        return [];
    }

    public static function setUpBeforeClass(): void
    {
        $port = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        $cmd = sprintf(
            'exec php -S 127.0.0.1:%d %s',
            $port,
            escapeshellarg(static::routerPath()),
        );
        $env = ['PHP_CLI_SERVER_WORKERS' => '4'] + static::serverEnv() + getenv();

        $process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $env,
        );
        if (!is_resource($process)) {
            self::fail('failed to spawn test server');
        }
        self::$serverProcess = $process;

        for ($i = 0; $i < 100; ++$i) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (false !== $sock) {
                fclose($sock);

                return;
            }
            usleep(50_000);
        }
        self::fail('test server did not start within 5 seconds');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
    }

    protected static function baseUrl(): string
    {
        return self::$baseUrl;
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (false === $sock) {
            self::fail("could not allocate test port: $errstr");
        }
        $name = stream_socket_get_name($sock, false);
        if (false === $name) {
            fclose($sock);
            self::fail('could not read socket name');
        }
        fclose($sock);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
