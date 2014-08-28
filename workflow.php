<?php

require 'item.php';

class Workflow
{
    const VERSION = '$Format:%H$';
    const BUNDLE = 'de.gh01.alfred.github';
    const DEFAULT_CACHE_MAX_AGE = 10;

    private static $fileCookies;
    private static $filePids;
    /** @var PDO */
    private static $db;
    private static $query;
    private static $items = array();

    public static function init($query = null)
    {
        self::$query = $query;
        $dataDir  = $_ENV['alfred_workflow_data'];
        if (!is_dir($dataDir)) {
            mkdir($dataDir);
        }
        self::$fileCookies = $dataDir . '/cookies';
        self::$filePids = $dataDir . '/pid';
        $fileDb = $dataDir . '/db.sqlite';
        $exists = file_exists($fileDb);
        self::$db = new PDO('sqlite:' . $fileDb, null, null, array(PDO::ATTR_PERSISTENT => true));
        if (!$exists) {
            self::$db->exec('
                CREATE TABLE config (
                    key TEXT PRIMARY KEY,
                    value TEXT
                )
            ');
            self::$db->exec('
                CREATE TABLE request_cache (
                    url TEXT PRIMARY KEY,
                    timestamp INTEGER,
                    etag TEXT,
                    content TEXT,
                    refresh INTEGER
                )
            ');
        }
        register_shutdown_function(array(__CLASS__, 'shutdown'));
    }

    public static function shutdown()
    {
        self::$db->exec('DELETE FROM request_cache WHERE timestamp < ' . (time() - 30 * 24 * 60 * 60));
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

    public static function request($url, &$status = null, &$etag = null, $post = false, array $data = array())
    {
        $debug = false;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, self::$fileCookies);
        curl_setopt($ch, CURLOPT_COOKIEFILE, self::$fileCookies);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: token ' . self::getConfig('access_token')));
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
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('If-None-Match: ' . $etag));
        }
        $response = curl_exec($ch);
        if (false === $response) {
            curl_close($ch);
            return false;
        }
        if ($debug) {
            list(, $header, $body) = explode("\r\n\r\n", $response, 3);
        } else {
            list($header, $body) = explode("\r\n\r\n", $response, 2);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (preg_match('/^ETag: (\V*)/mi', $header, $match)) {
            $etag = $match[1];
        }
        return $status == 200 ? $body : null;
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
        if ($timestamp < time() - 60 * $maxAge) {
            if ($refreshInBackground && !is_null($content)) {
                if ($refresh < time() - 60) {
                    self::getStatement('UPDATE request_cache SET refresh = ? WHERE url = ?')->execute(array(time(), $url));
                    exec('php action.php "> refresh-cache ' . $url . '" > /dev/null 2>&1 &');
                }
                return $content;
            }
            $newContent = self::request($url, $status, $etag);
            if (false === $newContent) {
                return $content;
            }
            switch ($status) {
                /** @noinspection PhpMissingBreakStatementInspection */
                case 200:
                    $content = $newContent;
                // fall trough
                case 304:
                    $timestamp = time();
                    self::getStatement('REPLACE INTO request_cache VALUES(?, ?, ?, ?, 0)')->execute(array($url, $timestamp, $etag, $content));
                    break;

                default:
                    self::getStatement('DELETE FROM request_cache WHERE url = ?')->execute(array($url));
                    return null;
            }
        }
        return $content;
    }

    public static function requestCacheJson($url, $key = null, $maxAge = self::DEFAULT_CACHE_MAX_AGE)
    {
        $content = self::requestCache($url, $maxAge);
        if (!is_string($content)) {
            return null;
        }
        $content = json_decode($content);
        if ($key && !isset($content->$key)) {
            return null;
        }
        return $key ? $content->$key : $content;
    }

    public static function deleteCache()
    {
        self::$db->exec('DELETE FROM request_cache');
    }

    public static function deleteCookies()
    {
        if (file_exists(self::$fileCookies)) {
            unlink(self::$fileCookies);
        }
        self::removeConfig('user');
    }

    public static function startServer()
    {
        self::stopServer();
        shell_exec('php -S localhost:2233 server.php > /dev/null 2>&1 & echo $! >> "' . self::$filePids . '"');
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

    public static function getToken($content = null)
    {
        $content = $content ?: self::request('https://github.com/');
        preg_match('@<meta content="(.*)" name="csrf-token" />@U', $content, $match);
        return isset($match[1]) ? $match[1] : null;
    }

    public static function askForPassword($title, $label)
    {
        return exec('osascript <<END
tell application "Alfred 2"
    activate
    set alfredPath to (path to application "Alfred 2")
    set alfredIcon to path to resource "appicon.icns" in bundle (alfredPath as alias)
    display dialog "' . escapeshellcmd(addslashes($label)) . ':" with title "' . escapeshellcmd(addslashes($title)) . '" buttons {"OK"} default button "OK" default answer "" with icon alfredIcon with hidden answer
    set answer to text returned of result
end tell
END');
    }

    public static function checkUpdate()
    {
        if (self::getConfig('version') !== self::VERSION) {
            if (file_exists($file = __DIR__ . '/class.php')) {
                unlink($file);
            }
            $configFile = $_SERVER['HOME'] . '/Library/Application Support/Alfred 2/Workflow Data/' . self::BUNDLE . '/config.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true);
                foreach ($config as $key => $value) {
                    self::setConfig($key, $value);
                }
                unlink($configFile);
            }
            $cacheDir = $_SERVER['HOME'] . '/Library/Caches/com.runningwithcrayons.Alfred-2/Workflow Data/' . self::BUNDLE;
            $cacheFile = $cacheDir . '/cache.json';
            if (file_exists($cacheFile)) {
                $cache = json_decode(file_get_contents($cacheFile), true);
                $stmt = self::getStatement('REPLACE INTO request_cache VALUES(?, ?, ?, ?, 0)');
                foreach ($cache as $url => $c) {
                    $stmt->execute(array($url, $c['timestamp'], $c['etag'], $c['content']));
                }
                unlink($cacheFile);
            }
            if (is_dir($cacheDir)) {
                rmdir($cacheDir);
            }
            self::setConfig('version', self::VERSION);
            self::deleteCache();
        }
        if (!self::getConfig('autoupdate', 1)) {
            return false;
        }
        $version = self::requestCache('http://gh01.de/alfred/github/current', 1440);
        return $version !== null && $version !== self::VERSION;
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
        static $stmts = array();
        if (!isset($stmts[$query])) {
            $stmts[$query] = self::$db->prepare($query);
        }
        return $stmts[$query];
    }
}
