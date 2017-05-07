<?php

/*
 * This file is part of the alfred-github-workflow package.
 *
 * (c) Gregor Harlan
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require 'item.php';
require 'curl.php';

class Workflow
{
    const VERSION = '1.6';
    const BUNDLE = 'de.gh01.alfred.github';
    const DEFAULT_CACHE_MAX_AGE = 10;

    private static $filePids;

    private static $fileDb;
    /** @var PDO */
    private static $db;
    /** @var PDOStatement[] */
    private static $statements = array();

    private static $enterprise;
    private static $baseUrl = 'https://github.com';
    private static $apiUrl = 'https://api.github.com';
    private static $gistUrl = 'https://gist.github.com';

    private static $query;
    private static $hotkey;
    private static $items = array();

    private static $refreshUrls = array();

    private static $debug = false;

    public static function init($enterprise = false, $query = null, $hotkey = false)
    {
        date_default_timezone_set('UTC');

        self::$enterprise = $enterprise;
        self::$query = ltrim($query);
        self::$hotkey = $hotkey;

        $dataDir = getenv('alfred_workflow_data');
        if (!$dataDir) {
            $dataDir = getenv('HOME').'/Library/Application Support/Alfred 3/Workflow Data/'.self::BUNDLE;
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

        register_shutdown_function(array(__CLASS__, 'shutdown'));
    }

    public static function shutdown()
    {
        if (self::$refreshUrls) {
            $urls = implode(',', array_keys(self::$refreshUrls));
            exec('php action.php "> refresh-cache '.$urls.'" > /dev/null 2>&1 &');
            self::log('refreshing cache in background for %s', $urls);
        }
    }

    public static function setConfig($key, $value)
    {
        self::getStatement('REPLACE INTO config VALUES(?, ?)')->execute(array($key, $value));
    }

    public static function getConfig($key, $default = null)
    {
        $stmt = self::getStatement('SELECT value FROM config WHERE key = ?');
        $stmt->execute(array($key));
        $value = $stmt->fetchColumn();
        return false !== $value ? $value : $default;
    }

    public static function removeConfig($key)
    {
        self::getStatement('DELETE FROM config WHERE key = ?')->execute(array($key));
    }

    public static function getBaseUrl()
    {
        return self::$baseUrl;
    }

    public static function getApiUrl($path = null)
    {
        $url = self::$apiUrl;

        if ($path) {
            $paramStart = false === strpos($path, '?') ? '?' : '&';
            $url .= $path.$paramStart.'per_page=100';
        }

        return $url;
    }

    public static function getGistUrl()
    {
        return self::$gistUrl;
    }

    public static function setAccessToken($token)
    {
        self::setConfig(self::$enterprise ? 'enterprise_access_token' : 'access_token', $token);
    }

    public static function getAccessToken()
    {
        return self::getConfig(self::$enterprise ? 'enterprise_access_token' : 'access_token');
    }

    public static function removeAccessToken()
    {
        self::removeConfig(self::$enterprise ? 'enterprise_access_token' : 'access_token');
    }

    public static function request($url, Curl $curl = null, $callback = null, $withAuthorization = true)
    {
        self::log('loading content for %s', $url);

        $return = false;
        $returnValue = null;
        if (!$curl) {
            $curl = new Curl();
            $return = true;
            $callback = function ($content) use (&$returnValue) {
                $returnValue = $content;
            };
        }

        $token = $withAuthorization ? self::getAccessToken() : null;
        $curl->add(new CurlRequest($url, null, $token, function (CurlResponse $response) use ($callback) {
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
     * @param string   $url
     * @param Curl     $curl
     * @param callable $callback
     * @param bool     $firstPageOnly
     * @param int      $maxAge
     * @param bool     $refreshInBackground
     *
     * @return mixed
     */
    public static function requestCache($url, Curl $curl = null, $callback = null, $firstPageOnly = false, $maxAge = self::DEFAULT_CACHE_MAX_AGE, $refreshInBackground = true)
    {
        $return = false;
        $returnValue = null;
        if (!$curl) {
            $curl = new Curl();
            $return = true;
            $callback = function ($content) use (&$returnValue) {
                $returnValue = $content;
            };
        }

        $stmt = self::getStatement('SELECT * FROM request_cache WHERE url = ?');
        $stmt->execute(array($url));
        $stmt->bindColumn('timestamp', $timestamp);
        $stmt->bindColumn('etag', $etag);
        $stmt->bindColumn('content', $content);
        $stmt->bindColumn('refresh', $refresh);
        $stmt->fetch(PDO::FETCH_BOUND);

        $shouldRefresh = $timestamp < time() - 60 * $maxAge;
        $refreshInBackground = $refreshInBackground && !is_null($content);

        if ($shouldRefresh && $refreshInBackground && $refresh < time() - 3 * 60) {
            self::getStatement('UPDATE request_cache SET refresh = ? WHERE url = ?')->execute(array(time(), $url));
            self::$refreshUrls[$url] = true;
        }

        if (!$shouldRefresh || $refreshInBackground) {
            self::log('using cached content for %s', $url);
            $content = json_decode($content);

            if (!$firstPageOnly) {
                $stmt = self::getStatement('SELECT url, content FROM request_cache WHERE parent = ? ORDER BY `timestamp` DESC');
                while ($stmt->execute(array($url)) && $data = $stmt->fetchObject()) {
                    $content = array_merge($content, json_decode($data->content));
                    $url = $data->url;
                }
            }

            if (is_callable($callback)) {
                $callback($content);
            }

            return $returnValue;
        }

        $responses = array();

        $handleResponse = function (CurlResponse $response, $content, $parent = null) use (&$handleResponse, $curl, &$responses, $stmt, $callback, $firstPageOnly) {
            $url = $response->request->url;
            if ($response && in_array($response->status, array(200, 304))) {
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
                $responses[] = $response->content;
                Workflow::getStatement('REPLACE INTO request_cache VALUES(?, ?, ?, ?, 0, ?)')
                    ->execute(array($url, time(), $response->etag, json_encode($response->content), $parent));

                if ($firstPageOnly) {
                    // do nothing
                } elseif ($checkNext || $response->link && preg_match('/<(.+)>; rel="next"/U', $response->link, $match)) {
                    $stmt = Workflow::getStatement('SELECT * FROM request_cache WHERE parent = ?');
                    $stmt->execute(array($url));
                    if ($checkNext) {
                        $stmt->bindColumn('url', $nextUrl);
                    } else {
                        $nextUrl = $match[1];
                    }
                    $stmt->bindColumn('etag', $etag);
                    $stmt->bindColumn('content', $content);
                    $stmt->fetch(PDO::FETCH_BOUND);
                    if ($nextUrl) {
                        $curl->add(new CurlRequest($nextUrl, $etag, Workflow::getAccessToken(), function (CurlResponse $response) use ($handleResponse, $url, $content) {
                            $handleResponse($response, $content, $url);
                        }));
                        return;
                    }
                } else {
                    Workflow::getStatement('DELETE FROM request_cache WHERE parent = ?')->execute(array($url));
                }
            } else {
                Workflow::getStatement('DELETE FROM request_cache WHERE url = ?')->execute(array($url));
                $url = null;
            }

            if (is_callable($callback)) {
                if (empty($responses)) {
                    $callback(array());
                    return;
                }
                if (1 === count($responses)) {
                    $callback($responses[0]);
                    return;
                }
                $callback(array_reduce($responses, function ($content, $response) {
                    return array_merge($content, $response);
                }, array()));
            }
        };

        self::log('loading content for %s', $url);
        $curl->add(new CurlRequest($url, $etag, self::getAccessToken(), function (CurlResponse $response) use (&$responses, $handleResponse, $callback, $content) {
            $handleResponse($response, $content);
        }));

        if ($return) {
            $curl->execute();
        }
        return $returnValue;
    }

    public static function requestApi($url, Curl $curl = null, $callback = null, $firstPageOnly = false, $maxAge = self::DEFAULT_CACHE_MAX_AGE)
    {
        $url = self::getApiUrl($url);
        return self::requestCache($url, $curl, $callback, $firstPageOnly, $maxAge);
    }

    public static function cleanCache()
    {
        self::$db->exec('DELETE FROM request_cache WHERE timestamp < '.(time() - 100 * 24 * 60 * 60));
    }

    public static function deleteCache()
    {
        self::$db->exec('DELETE FROM request_cache');
    }

    public static function cacheWarmup()
    {
        $paths = array('/user', '/user/orgs', '/user/starred', '/user/subscriptions', '/user/repos', '/user/following');
        foreach ($paths as $path) {
            self::$refreshUrls[self::getApiUrl($path)] = true;
        }
    }

    public static function startServer()
    {
        if (version_compare(PHP_VERSION, '5.4', '>=')) {
            self::stopServer();
            shell_exec(sprintf(
                'alfred_workflow_data=%s php -d variables_order=EGPCS -S localhost:2233 server.php > /dev/null 2>&1 & echo $! >> %s',
                escapeshellarg(getenv('alfred_workflow_data')),
                escapeshellarg(self::$filePids)
            ));
        }
    }

    public static function stopServer()
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
        if (self::getConfig('version') !== self::VERSION) {
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

    private static function createTables()
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

    public static function deleteDatabase()
    {
        self::closeCursors();
        self::$db = null;
        unlink(self::$fileDb);
    }

    public static function addItem(Item $item, $check = true)
    {
        if (!$check || $item->match(self::$query)) {
            self::$items[] = $item;
        }
    }

    public static function sortItems()
    {
        usort(self::$items, function (Item $a, Item $b) {
            return $a->compare($b);
        });
    }

    public static function getItemsAsXml()
    {
        return Item::toXml(self::$items, self::$enterprise, self::$hotkey, self::getBaseUrl());
    }

    public static function log($msg)
    {
        if (self::$debug) {
            fwrite(STDERR, "\n".call_user_func_array('sprintf', func_get_args()));
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

    protected static function closeCursors()
    {
        foreach (self::$statements as $statement) {
            $statement->closeCursor();
        }
        self::$statements = array();
    }
}
