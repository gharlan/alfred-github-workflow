<?php

require 'item.php';

class Workflow
{
    const VERSION = '$Format:%H$';
    const BUNDLE = 'de.gh01.alfred.github';

    private static $fileCookies;
    private static $fileConfig;
    private static $fileCache;
    private static $config = array();
    private static $configChanged = false;
    private static $cache = array();
    private static $cacheChanged = false;
    private static $query;
    private static $items = array();

    public static function init($query = null)
    {
        ini_set('display_errors', false);
        self::$query = $query;
        $dataDir  = $_SERVER['HOME'] . '/Library/Application Support/Alfred 2/Workflow Data/' . self::BUNDLE;
        $cacheDir = $_SERVER['HOME'] . '/Library/Caches/com.runningwithcrayons.Alfred-2/Workflow Data/' . self::BUNDLE;
        if (!is_dir($dataDir)) {
            mkdir($dataDir);
        }
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir);
        }
        self::$fileCookies = $dataDir . '/cookies';
        self::$fileConfig  = $dataDir . '/config.json';
        self::$fileCache   = $cacheDir . '/cache.json';
        register_shutdown_function(array(__CLASS__, 'shutdown'));
        if (file_exists(self::$fileConfig)) {
            self::$config = json_decode(file_get_contents(self::$fileConfig), true);
        }
        if (file_exists(self::$fileCache)) {
            self::$cache = json_decode(file_get_contents(self::$fileCache), true);
        }
    }

    public static function shutdown()
    {
        if (self::$configChanged) {
            file_put_contents(self::$fileConfig, json_encode(self::$config));
        }
        if (self::$cacheChanged) {
            file_put_contents(self::$fileCache, json_encode(self::$cache));
        }
    }

    public static function setConfig($key, $value)
    {
        self::$config[$key] = $value;
        self::$configChanged = true;
    }

    public static function getConfig($key, $default = null)
    {
        return isset(self::$config[$key]) ? self::$config[$key] : $default;
    }

    public static function removeConfig($key)
    {
        unset(self::$config[$key]);
        self::$configChanged = true;
    }

    public static function request($url, &$status = null, &$etag = null, $post = false, $token = null, array $data = array())
    {
        $debug = false;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, self::$fileCookies);
        curl_setopt($ch, CURLOPT_COOKIEFILE, self::$fileCookies);
        curl_setopt($ch, CURLOPT_USERAGENT, 'alfred-github-workflow');
        if ($debug) {
            curl_setopt($ch, CURLOPT_PROXY, 'localhost');
            curl_setopt($ch, CURLOPT_PROXYPORT, 8888);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        }
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            if ($token) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-CSRF-Token: ' . $token));
            }
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

    public static function requestCache($url, $maxAge = 10)
    {
        if (!isset(self::$cache[$url]['timestamp']) || self::$cache[$url]['timestamp'] < time() - 60 * $maxAge) {
            $etag = isset(self::$cache[$url]['etag']) ? self::$cache[$url]['etag'] : null;
            $content = self::request($url, $status, $etag);
            if (false === $content) {
                return isset(self::$cache[$url]['content']) ? self::$cache[$url]['content'] : null;
            }
            switch ($status) {
                /** @noinspection PhpMissingBreakStatementInspection */
                case 200:
                    self::$cache[$url]['content'] = $content;
                    self::$cache[$url]['status'] = $status;
                // fall trough
                case 304:
                    self::$cache[$url]['etag'] = $etag;
                    self::$cache[$url]['timestamp'] = time();
                    self::$cacheChanged = true;
                    break;

                default:
                    unset(self::$cache[$url]);
                    self::$cacheChanged = true;
                    return null;
            }
        }
        return self::$cache[$url]['content'];
    }

    public static function requestCacheJson($url, $key = null, $maxAge = 10)
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
        if (file_exists(self::$fileCache)) {
            unlink(self::$fileCache);
        }
        self::$cache = array();
    }

    public static function deleteCookies()
    {
        if (file_exists(self::$fileCookies)) {
            unlink(self::$fileCookies);
        }
        self::removeConfig('user');
    }

    public static function getToken()
    {
        $c = self::request('https://github.com/');
        preg_match('@<meta content="(.*)" name="csrf-token" />@U', $c, $match);
        return isset($match[1]) ? $match[1] : null;
    }

    public static function checkUpdate()
    {
        if (self::getConfig('version') !== self::VERSION) {
            if (file_exists($file = __DIR__ . '/class.php')) {
                unlink($file);
            }
            self::setConfig('version', self::VERSION);
            self::deleteCache();
        }
        if (!self::getConfig('autoupdate', true)) {
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
}
