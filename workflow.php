<?php

require 'item.php';
require 'curl.php';

class Workflow
{
    public const VERSION = '1.9.2';
    public const BUNDLE = 'de.gh01.alfred.github';
    public const DEFAULT_CACHE_MAX_AGE = 10;

    private static $filePids;

    private static $fileDb;
    /** @var PDO */
    private static $db;
    /** @var PDOStatement[] */
    private static $statements = [];

    private static $enterprise;
    private static $baseUrl = 'https://github.com';
    private static $apiUrl = 'https://api.github.com';
    private static $gistUrl = 'https://gist.github.com';

    private static $query;
    private static $hotkey;
    private static $items = [];

    private static $refreshUrls = [];

    private static $debug = false;

    public static function init($enterprise = false, $query = null, $hotkey = false): void
    {
        date_default_timezone_set('UTC');

        self::$enterprise = $enterprise;
        self::$query = ltrim($query ?? '');
        self::$hotkey = $hotkey;

        $dataDir = getenv('alfred_workflow_data');
        if (!$dataDir) {
            $dataDir = getenv('HOME').'/Library/Application Support/Alfred/Workflow Data/'.self::BUNDLE;
            putenv('alfred_workflow_data="'.$dataDir.'"');
        }
        if (!is_dir($dataDir)) {
            mkdir($dataDir);
        }

        self::$filePids = $dataDir.'/pid';

        self::$fileDb = $dataDir.'/db.sqlite';
        $exists = file_exists(self::$fileDb);
        self::$db = new PDO('sqlite:'.self::$fileDb, null, null);
        self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (!$exists) {
            self::createTables();
        }

        if (self::$enterprise) {
            self::$baseUrl = self::getConfig('enterprise_url');
            self::$apiUrl = self::$baseUrl ? self::$baseUrl.'/api/v3' : null;
            self::$gistUrl = self::$baseUrl ? self::$baseUrl.'/gist' : null;
        }

        self::$debug = getenv('alfred_debug') && defined('STDERR');

        register_shutdown_function([__CLASS__, 'shutdown']);
    }

    public static function shutdown(): void
    {
        if (self::$refreshUrls) {
            $urls = implode(',', array_keys(self::$refreshUrls));
            exec(escapeshellarg(PHP_BINARY).' action.php "> refresh-cache '.$urls.'" > /dev/null 2>&1 &');
            self::log('refreshing cache in background for %s', $urls);
        }
    }

    public static function setConfig($key, $value): void
    {
        self::getStatement('REPLACE INTO config VALUES(?, ?)')->execute([$key, $value]);
    }

    public static function getConfig($key, $default = null)
    {
        $stmt = self::getStatement('SELECT value FROM config WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return false !== $value ? $value : $default;
    }

    public static function removeConfig($key): void
    {
        self::getStatement('DELETE FROM config WHERE key = ?')->execute([$key]);
    }

    public static function getBaseUrl()
    {
        return self::$baseUrl;
    }

    public static function getApiUrl($path = null)
    {
        $url = self::$apiUrl;

        if ($path) {
            $paramStart = !str_contains($path, '?') ? '?' : '&';
            $url .= $path.$paramStart.'per_page=100';
        }

        return $url;
    }

    public static function getGistUrl()
    {
        return self::$gistUrl;
    }

    public static function setAccessToken($token): void
    {
        self::setConfig(self::$enterprise ? 'enterprise_access_token' : 'access_token', $token);
    }

    public static function getAccessToken()
    {
        return self::getConfig(self::$enterprise ? 'enterprise_access_token' : 'access_token');
    }

    public static function removeAccessToken(): void
    {
        self::removeConfig(self::$enterprise ? 'enterprise_access_token' : 'access_token');
    }

    public static function request(string $url, ?Curl $curl = null, $callback = null, bool $withAuthorization = true)
    {
        self::log('loading content for %s', $url);

        $return = false;
        $returnValue = null;
        if (!$curl) {
            $curl = new Curl();
            $return = true;
            $callback = static function ($content) use (&$returnValue) {
                $returnValue = $content;
            };
        }

        $token = $withAuthorization ? self::getAccessToken() : null;
        $curl->add(new CurlRequest($url, null, $token, static function (CurlResponse $response) use ($callback) {
            if (is_callable($callback) && isset($response->content)) {
                $callback($response->content);
            }
        }));

        if ($return) {
            $curl->execute();
        }

        return $returnValue;
    }

    /**
     * @param callable|null $callback
     * @param array|null $fields declarative field whitelist applied before storing in the cache, so large list endpoints (commits, issues)
     *                           only persist what the consumer actually reads. Mix flat names and nested specs:
     *                           `['sha', 'commit' => ['message', 'author' => ['date']]]`
     */
    public static function requestCache(string $url, ?Curl $curl = null, $callback = null, bool $firstPageOnly = false, int $maxAge = self::DEFAULT_CACHE_MAX_AGE, bool $refreshInBackground = true, ?array $fields = null)
    {
        $return = false;
        $returnValue = null;
        if (!$curl) {
            $curl = new Curl();
            $return = true;
            $callback = static function ($content) use (&$returnValue) {
                $returnValue = $content;
            };
        }

        $stmt = self::getStatement('SELECT * FROM request_cache WHERE url = ?');
        $stmt->execute([$url]);
        $stmt->bindColumn('timestamp', $timestamp);
        $stmt->bindColumn('etag', $etag);
        $stmt->bindColumn('content', $content);
        $stmt->bindColumn('refresh', $refresh);
        $stmt->fetch(PDO::FETCH_BOUND);

        $shouldRefresh = $timestamp < time() - 60 * $maxAge;
        $refreshInBackground = $refreshInBackground && null !== $content;

        if ($shouldRefresh && $refreshInBackground && $refresh < time() - 3 * 60) {
            self::getStatement('UPDATE request_cache SET refresh = ? WHERE url = ?')->execute([time(), $url]);
            self::$refreshUrls[$url] = true;
        }

        if (!$shouldRefresh || $refreshInBackground) {
            self::log('using cached content for %s', $url);
            $content = json_decode($content);

            if (!$firstPageOnly) {
                $stmt = self::getStatement('SELECT url, content FROM request_cache WHERE parent = ? ORDER BY `timestamp` DESC');
                while ($stmt->execute([$url]) && $data = $stmt->fetchObject()) {
                    $content = array_merge($content, json_decode($data->content));
                    $url = $data->url;
                }
            }

            if (is_callable($callback)) {
                $callback($content);
            }

            return $returnValue;
        }

        $responses = [];

        $handleResponse = static function (CurlResponse $response, $content, $parent = null) use (&$handleResponse, $curl, &$responses, $stmt, $callback, $firstPageOnly, $fields) {
            $url = $response->request->url;
            if ($response && in_array($response->status, [200, 304])) {
                $checkNext = false;
                if (304 == $response->status) {
                    $response->content = $content;
                    $checkNext = true;
                } elseif (false === stripos($response->contentType, 'json')) {
                    $response->content = json_encode($response->content);
                }
                $response->content = json_decode($response->content);
                if (isset($response->content->items)) {
                    $response->content = $response->content->items;
                }
                if ($fields && 200 == $response->status) {
                    $response->content = self::pickFields($fields, $response->content);
                }
                $responses[] = $response->content;
                self::getStatement('REPLACE INTO request_cache VALUES(?, ?, ?, ?, 0, ?)')
                    ->execute([$url, time(), $response->etag, json_encode($response->content), $parent]);

                if ($firstPageOnly) {
                    // do nothing
                } elseif ($checkNext || $response->link && preg_match('/<([^<>]+)>; rel="next"/U', $response->link, $match)) {
                    $stmt = self::getStatement('SELECT * FROM request_cache WHERE parent = ?');
                    $stmt->execute([$url]);
                    if ($checkNext) {
                        $stmt->bindColumn('url', $nextUrl);
                    } else {
                        $nextUrl = $match[1];
                    }
                    $stmt->bindColumn('etag', $etag);
                    $stmt->bindColumn('content', $content);
                    $stmt->fetch(PDO::FETCH_BOUND);
                    if ($nextUrl) {
                        $curl->add(new CurlRequest($nextUrl, $etag, self::getAccessToken(), static function (CurlResponse $response) use ($handleResponse, $url, $content) {
                            $handleResponse($response, $content, $url);
                        }));

                        return;
                    }
                } else {
                    self::getStatement('DELETE FROM request_cache WHERE parent = ?')->execute([$url]);
                }
            } else {
                self::getStatement('DELETE FROM request_cache WHERE url = ?')->execute([$url]);
                $url = null;
            }

            if (is_callable($callback)) {
                if (empty($responses)) {
                    $callback([]);

                    return;
                }
                if (1 === count($responses)) {
                    $callback($responses[0]);

                    return;
                }
                $callback(array_reduce($responses, static fn ($content, $response) => array_merge($content, $response), []));
            }
        };

        self::log('loading content for %s', $url);
        $curl->add(new CurlRequest($url, $etag, self::getAccessToken(), static function (CurlResponse $response) use (&$responses, $handleResponse, $content) {
            $handleResponse($response, $content);
        }));

        if ($return) {
            $curl->execute();
        }

        return $returnValue;
    }

    public static function requestApi(string $url, ?Curl $curl = null, $callback = null, bool $firstPageOnly = false, int $maxAge = self::DEFAULT_CACHE_MAX_AGE, ?array $fields = null)
    {
        $url = self::getApiUrl($url);

        return self::requestCache($url, $curl, $callback, $firstPageOnly, $maxAge, fields: $fields);
    }

    /**
     * Apply a declarative pick-fields whitelist to an API response, preserving the original nested structure.
     *
     * Spec format: a list of property names, optionally `name => subSpec` for nested objects.
     * An empty subSpec keeps the property as an empty stdClass (useful for `isset` markers).
     *
     * @param mixed $value stdClass, array of stdClass, or scalar — arrays are mapped element-wise
     */
    private static function pickFields(array $spec, mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn ($item) => self::pickFields($spec, $item), $value);
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        $picked = new stdClass();
        foreach ($spec as $key => $sub) {
            if (is_int($key)) {
                if (property_exists($value, $sub)) {
                    $picked->$sub = $value->$sub;
                }
            } elseif (property_exists($value, $key)) {
                $picked->$key = self::pickFields($sub, $value->$key);
            }
        }

        return $picked;
    }

    public static function cleanCache(): void
    {
        self::$db->exec('DELETE FROM request_cache WHERE timestamp < '.(time() - 100 * 24 * 60 * 60));
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
            'alfred_workflow_data=%s %s -d variables_order=EGPCS -S localhost:2233 server.php > /dev/null 2>&1 & echo $! >> %s',
            escapeshellarg(getenv('alfred_workflow_data')),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::$filePids)
        ));
    }

    public static function stopServer(): void
    {
        if (file_exists(self::$filePids)) {
            $pids = file(self::$filePids);
            foreach ($pids as $pid) {
                shell_exec('kill -9 '.$pid);
            }
            unlink(self::$filePids);
        }
    }

    public static function checkUpdate()
    {
        if (self::VERSION !== self::getConfig('version')) {
            self::setConfig('version', self::VERSION);
        }
        if (!self::getConfig('autoupdate', 1)) {
            return false;
        }
        $release = self::requestCache('https://api.github.com/repos/gharlan/alfred-github-workflow/releases/latest', null, null, true, 1440);
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

    public static function getItemsAsXml()
    {
        return Item::toXml(self::$items, self::$enterprise, self::$hotkey, self::getBaseUrl());
    }

    public static function log($msg, ...$args): void
    {
        if (self::$debug) {
            fwrite(STDERR, "\n".sprintf($msg, ...$args));
        }
    }

    /**
     * @param string $query
     *
     * @return PDOStatement
     */
    public static function getStatement($query)
    {
        if (!isset(self::$statements[$query])) {
            self::$statements[$query] = self::$db->prepare($query);
        }

        return self::$statements[$query];
    }

    protected static function closeCursors(): void
    {
        foreach (self::$statements as $statement) {
            $statement->closeCursor();
        }
        self::$statements = [];
    }
}
