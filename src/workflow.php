<?php

require __DIR__ . '/item.php';
require __DIR__ . '/fetcher.php';

final class Workflow
{
    public const VERSION = '1.9.2';
    public const BUNDLE = 'de.gh01.alfred.github';

    private static string $filePids;

    private static string $fileDb;
    private static ?PDO $db = null;
    /** @var array<string, PDOStatement> */
    private static array $statements = [];

    private static bool $enterprise = false;
    private static ?string $baseUrl = 'https://github.com';
    private static ?string $apiUrl = 'https://api.github.com';
    private static ?string $gistUrl = 'https://gist.github.com';

    private static string $query = '';
    private static bool|string $hotkey = false;
    /** @var list<Item> */
    private static array $items = [];

    /** @var array<string, true> */
    private static array $refreshUrls = [];

    private static bool $debug = false;

    public static function init(bool $enterprise = false, ?string $query = null, bool|string $hotkey = false): void
    {
        date_default_timezone_set('UTC');

        self::$enterprise = $enterprise;
        self::$query = ltrim($query ?? '');
        self::$hotkey = $hotkey;

        $dataDir = getenv('alfred_workflow_data');
        if (!$dataDir) {
            $dataDir = getenv('HOME') . '/Library/Application Support/Alfred/Workflow Data/' . self::BUNDLE;
            putenv('alfred_workflow_data="' . $dataDir . '"');
        }
        if (!is_dir($dataDir)) {
            mkdir($dataDir);
        }

        self::$filePids = $dataDir . '/pid';

        self::$fileDb = $dataDir . '/db.sqlite';
        $exists = file_exists(self::$fileDb);
        self::$db = new PDO('sqlite:' . self::$fileDb, null, null);
        self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$db->exec('PRAGMA busy_timeout = 5000');
        self::$db->exec('PRAGMA journal_mode = WAL');

        if (!$exists) {
            self::createTables();
        }

        if (self::$enterprise) {
            self::$baseUrl = self::getConfig('enterprise_url');
            self::$apiUrl = self::$baseUrl ? self::$baseUrl . '/api/v3' : null;
            self::$gistUrl = self::$baseUrl ? self::$baseUrl . '/gist' : null;
        }

        self::$debug = getenv('alfred_debug') && defined('STDERR');

        register_shutdown_function([__CLASS__, 'shutdown']);
    }

    public static function shutdown(): void
    {
        if (self::$refreshUrls) {
            $urls = implode(',', array_keys(self::$refreshUrls));
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/action.php') . ' "> refresh-cache ' . $urls . '" > /dev/null 2>&1 &');
            self::log('refreshing cache in background for %s', $urls);
        }
    }

    public static function setConfig(string $key, string|int $value): void
    {
        self::getStatement('REPLACE INTO config VALUES(?, ?)')->execute([$key, $value]);
    }

    public static function getConfig(string $key, mixed $default = null): mixed
    {
        $stmt = self::getStatement('SELECT value FROM config WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return false !== $value ? $value : $default;
    }

    public static function removeConfig(string $key): void
    {
        self::getStatement('DELETE FROM config WHERE key = ?')->execute([$key]);
    }

    public static function getBaseUrl(): ?string
    {
        return self::$baseUrl;
    }

    public static function getApiUrl(?string $path = null): ?string
    {
        $url = self::$apiUrl;

        if ($path) {
            $paramStart = !str_contains($path, '?') ? '?' : '&';
            $url .= $path . $paramStart . 'per_page=100';
        }

        return $url;
    }

    public static function getGistUrl(): ?string
    {
        return self::$gistUrl;
    }

    public static function setAccessToken(string $token): void
    {
        self::setConfig(self::$enterprise ? 'enterprise_access_token' : 'access_token', $token);
    }

    public static function getAccessToken(): ?string
    {
        return self::getConfig(self::$enterprise ? 'enterprise_access_token' : 'access_token');
    }

    public static function removeAccessToken(): void
    {
        self::removeConfig(self::$enterprise ? 'enterprise_access_token' : 'access_token');
    }

    public static function markUrlForBackgroundRefresh(string $url): void
    {
        self::$refreshUrls[$url] = true;
    }

    public static function cleanCache(): void
    {
        self::$db->exec('DELETE FROM request_cache WHERE timestamp < ' . (time() - 100 * 24 * 60 * 60));
    }

    public static function deleteCache(): void
    {
        self::$db->exec('DELETE FROM request_cache');
    }

    public static function cacheWarmup(): void
    {
        $paths = ['/user', '/user/orgs', '/user/starred', '/user/subscriptions', '/user/repos', '/user/following'];
        foreach ($paths as $path) {
            self::$refreshUrls[self::getApiUrl($path)] = true;
        }
    }

    public static function startServer(): void
    {
        self::stopServer();
        shell_exec(sprintf(
            'alfred_workflow_data=%s %s -d variables_order=EGPCS -S localhost:2233 %s > /dev/null 2>&1 & echo $! >> %s',
            escapeshellarg(getenv('alfred_workflow_data')), // @phpstan-ignore argument.type
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/server.php'),
            escapeshellarg(self::$filePids)
        ));
    }

    public static function stopServer(): void
    {
        if (file_exists(self::$filePids)) {
            $pids = file(self::$filePids);
            assert(is_array($pids));
            foreach ($pids as $pid) {
                shell_exec('kill -9 ' . $pid);
            }
            unlink(self::$filePids);
        }
    }

    public static function checkUpdate(): bool
    {
        if (self::VERSION !== self::getConfig('version')) {
            self::setConfig('version', self::VERSION);
        }
        if (!self::getConfig('autoupdate', 1)) {
            return false;
        }
        $release = Fetcher::requestUrl('https://api.github.com/repos/gharlan/alfred-github-workflow/releases/latest', new FetchOptions(firstPageOnly: true, maxAgeMinutes: 1440));
        if (!$release) {
            return false;
        }
        $version = ltrim($release->tag_name, 'v');

        return version_compare($version, self::VERSION) > 0;
    }

    private static function createTables(): void
    {
        self::$db->exec('
            CREATE TABLE config (
                key TEXT PRIMARY KEY NOT NULL,
                value TEXT
            ) WITHOUT ROWID
        ');

        self::$db->exec('
            CREATE TABLE request_cache (
                url TEXT PRIMARY KEY NOT NULL,
                timestamp INTEGER NOT NULL,
                etag TEXT,
                content TEXT,
                refresh INTEGER,
                parent TEXT
            ) WITHOUT ROWID
        ');
        self::$db->exec('CREATE INDEX parent_url ON request_cache(parent) WHERE parent IS NOT NULL');
    }

    public static function deleteDatabase(): void
    {
        self::closeCursors();
        self::$db = null;
        unlink(self::$fileDb);
    }

    public static function addItemIfMatches(Item $item): void
    {
        if ($item->match(self::$query)) {
            self::$items[] = $item;
        }
    }

    public static function addItem(Item $item): void
    {
        self::$items[] = $item;
    }

    public static function sortItems(): void
    {
        usort(self::$items, static fn (Item $a, Item $b) => $a->compare($b));
    }

    public static function getItemsAsXml(): string|false
    {
        return Item::toXml(self::$items, self::$enterprise, self::$hotkey, self::getBaseUrl());
    }

    public static function log(string $msg, mixed ...$args): void
    {
        if (self::$debug) {
            fwrite(STDERR, "\n" . sprintf($msg, ...$args));
        }
    }

    public static function getStatement(string $query): PDOStatement
    {
        if (!isset(self::$statements[$query])) {
            self::$statements[$query] = self::$db->prepare($query);
        }

        return self::$statements[$query];
    }

    private static function closeCursors(): void
    {
        foreach (self::$statements as $statement) {
            $statement->closeCursor();
        }
        self::$statements = [];
    }
}
