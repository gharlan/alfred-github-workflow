<?php

require 'item.php';

class Workflow
{
    const VERSION = '$Format:%H$';
    const BUNDLE = 'de.gh01.alfred.github';
    const DEFAULT_CACHE_MAX_AGE = 10;

    private static $filePids;

    /** @var PDO */
    private static $db;
    /** @var PDOStatement[] */
    private static $statements = array();

    private static $query;
    private static $items = array();

    private static $refreshUrls = array();

    public static function init($query = null)
    {
        date_default_timezone_set('UTC');
        self::$query = $query;
        if (isset($_ENV['alfred_workflow_data'])) {
            $dataDir = $_ENV['alfred_workflow_data'];
        } else {
            $dataDir = (isset($_ENV['HOME']) ? $_ENV['HOME'] : $_SERVER['HOME']) . '/Library/Application Support/Alfred 2/Workflow Data/' . self::BUNDLE;
        }
        if (!is_dir($dataDir)) {
            mkdir($dataDir);
        }
        self::$filePids = $dataDir . '/pid';
        $fileDb = $dataDir . '/db.sqlite';
        $exists = file_exists($fileDb);
        self::$db = new PDO('sqlite:' . $fileDb, null, null);
        if (!$exists) {
            self::$db->exec('
                CREATE TABLE config (
                    key TEXT PRIMARY KEY,
                    value TEXT
                )
            ');
            self::createRequestCacheTable();
        }
        register_shutdown_function(array(__CLASS__, 'shutdown'));
    }

    public static function shutdown()
    {
        if (self::$refreshUrls) {
            exec('php action.php "> refresh-cache ' . implode(',', self::$refreshUrls) . '" > /dev/null 2>&1 &');
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

    public static function request($url, $etag = null, $post = false, array $data = array())
    {
        $debug = false;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $header = array('Authorization: token ' . self::getConfig('access_token'));
        curl_setopt($ch, CURLOPT_USERAGENT, 'alfred-github-workflow');
        if ($debug) {
            curl_setopt($ch, CURLOPT_PROXY, 'localhost');
            curl_setopt($ch, CURLOPT_PROXYPORT, 8888);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        }
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } elseif ($etag) {
            $header[] = 'If-None-Match: ' . $etag;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $rawResponse = curl_exec($ch);
        if (false === $rawResponse) {
            curl_close($ch);
            return null;
        }
        if ($debug) {
            list(, $header, $body) = explode("\r\n\r\n", $rawResponse, 3);
        } else {
            list($header, $body) = explode("\r\n\r\n", $rawResponse, 2);
        }
        $response = new stdClass();
        $response->status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $headerNames = array(
            'etag' => 'ETag',
            'contentType' => 'Content-Type',
            'link' => 'Link',
        );
        foreach ($headerNames as $key => $name) {
            if (preg_match('/^' . preg_quote($name, '/') . ': (\V*)/mi', $header, $match)) {
                $response->$key = $match[1];
            } else {
                $response->$key = null;
            }
        }
        if (200 == $response->status) {
            $response->content = $body;
        }
        return $response;
    }

    public static function requestCache($url, $maxAge = self::DEFAULT_CACHE_MAX_AGE, $refreshInBackground = true)
    {
        $stmt = self::getStatement('SELECT * FROM request_cache WHERE url = ?');
        $stmt->execute(array($url));
        $stmt->bindColumn('timestamp', $timestamp);
        $stmt->bindColumn('etag', $etag);
        $stmt->bindColumn('content', $content);
        $stmt->bindColumn('refresh', $refresh);
        $stmt->fetch(PDO::FETCH_BOUND);

        $shouldRefresh = $timestamp < time() - 60 * $maxAge;
        $refreshInBackground = $refreshInBackground && !is_null($content);

        if ($shouldRefresh && $refreshInBackground && $refresh < time() - 60) {
            self::getStatement('UPDATE request_cache SET refresh = ? WHERE url = ?')->execute(array(time(), $url));
            self::$refreshUrls[] = $url;
        }

        if (!$shouldRefresh || $refreshInBackground) {
            $content = json_decode($content);
            $stmt = self::getStatement('SELECT url, content FROM request_cache WHERE parent = ? ORDER BY `timestamp` DESC');
            while ($stmt->execute(array($url)) && $data = $stmt->fetchObject()) {
                $content += json_decode($data->content);
                $url = $data->url;
            }
            return $content;
        }

        $i = 0;
        $responses = array();
        $parent = null;
        do {
            $response = self::request($url, $etag);
            if ($response && in_array($response->status, array(200, 304))) {
                if (304 == $response->status) {
                    $response->content = $content;
                } elseif (false === stripos($response->contentType, 'json')) {
                    $response->content = json_encode($response->content);
                }
                self::getStatement('REPLACE INTO request_cache VALUES(?, ?, ?, ?, 0, ?)')->execute(array($url, time(), $response->etag, $response->content, $parent));
                if ($response->link && preg_match('/<(.+)>; rel="next"/U', $response->link, $match)) {
                    $nextUrl = $match[1];
                    $parent = $url;
                    $stmt->execute(array($nextUrl));
                    $stmt->bindColumn('etag', $etag);
                    $stmt->bindColumn('content', $content);
                    $stmt->fetch(PDO::FETCH_BOUND);
                    $url = $nextUrl;
                } else {
                    self::getStatement('DELETE FROM request_cache WHERE parent = ?')->execute(array($url));
                    $url = null;
                }
                $responses[] = $response->content;
            } else {
                self::getStatement('DELETE FROM request_cache WHERE url = ?')->execute(array($url));
                $url = null;
            }
            $i++;
        } while ($url);
        if (empty($responses)) {
            return false;
        }
        if (1 === count($responses)) {
            return json_decode($responses[0]);
        }
        return array_reduce($responses, function ($content, $response) {
            return $content + json_decode($response);
        }, array());
    }

    public static function requestGithubApi($url, $maxAge = self::DEFAULT_CACHE_MAX_AGE)
    {
        $url = 'https://api.github.com' . $url . '?per_page=100';
        $content = self::requestCache($url, $maxAge) ?: array();
        return $content;
    }

    public static function cleanCache()
    {
        self::$db->exec('DELETE FROM request_cache WHERE timestamp < ' . (time() - 30 * 24 * 60 * 60));
    }

    public static function deleteCache()
    {
        self::$db->exec('DELETE FROM request_cache');
    }

    public static function startServer()
    {
        if (version_compare(PHP_VERSION, '5.4', '>=')) {
            self::stopServer();
            shell_exec('php -S localhost:2233 server.php > /dev/null 2>&1 & echo $! >> "' . self::$filePids . '"');
        }
    }

    public static function stopServer()
    {
        if (file_exists(self::$filePids)) {
            $pids = file(self::$filePids);
            foreach ($pids as $pid) {
                shell_exec('kill -9 ' . $pid);
            }
            unlink(self::$filePids);
        }
    }

    public static function checkUpdate()
    {
        if (self::getConfig('version') !== self::VERSION) {
            self::setConfig('version', self::VERSION);
            //self::deleteCache();
            self::closeCursors();
            self::$db->exec('DROP TABLE request_cache');
            self::createRequestCacheTable();
        }
        if (!self::getConfig('autoupdate', 1)) {
            return false;
        }
        $version = self::requestCache('http://gh01.de/alfred/github/current', 1440);
        return $version !== null && $version !== self::VERSION;
    }

    private static function createRequestCacheTable()
    {
        self::$db->exec('
            CREATE TABLE request_cache (
                url TEXT PRIMARY KEY,
                timestamp INTEGER,
                etag TEXT,
                content TEXT,
                refresh INTEGER,
                parent TEXT
            )
        ');
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
        return Item::toXml(self::$items);
    }

    /**
     * @param string $query
     * @return PDOStatement
     */
    protected static function getStatement($query)
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
