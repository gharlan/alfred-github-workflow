<?php

class gh
{
  static private
    $fileCookies,
    $fileConfig,
    $fileCache,
    $config = array(),
    $configChanged = false,
    $cache = array(),
    $cacheChanged = false;

  static public function init()
  {
    $dataDir  = $_SERVER['HOME'] . '/Library/Application Support/Alfred 2/Workflow Data/gharlan.github';
    $cacheDir = $_SERVER['HOME'] . '/Library/Caches/com.runningwithcrayons.Alfred-2/Workflow Data/gharlan.github';
    if (!is_dir($dataDir)) {
      mkdir($dataDir);
    }
    if (!is_dir($cacheDir)) {
      mkdir($cacheDir);
    }
    self::$fileCookies = $dataDir . '/cookies';
    self::$fileConfig  = $dataDir . '/config.json';
    self::$fileCache   = $cacheDir . '/cache.json';
    register_shutdown_function('gh::shutdown');
    if (file_exists(self::$fileConfig)) {
      self::$config = json_decode(file_get_contents(self::$fileConfig), true);
    }
    if (file_exists(self::$fileCache)) {
      self::$cache = json_decode(file_get_contents(self::$fileCache), true);
    }
  }

  static public function shutdown()
  {
    if (self::$configChanged) {
      file_put_contents(self::$fileConfig, json_encode(self::$config));
    }
    if (self::$cacheChanged) {
      file_put_contents(self::$fileCache, json_encode(self::$cache));
    }
  }

  static public function setConfig($key, $value)
  {
    self::$config[$key] = $value;
    self::$configChanged = true;
  }

  static public function getConfig($key, $default = null)
  {
    return isset(self::$config[$key]) ? self::$config[$key] : $default;
  }

  static public function removeConfig($key)
  {
    unset(self::$config[$key]);
    self::$configChanged = true;
  }

  static public function request($url, &$status = null, $post = false, $token = null, array $data = array())
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, self::$fileCookies);
    curl_setopt($ch, CURLOPT_COOKIEFILE, self::$fileCookies);
    #curl_setopt($ch, CURLOPT_PROXY, 'localhost');
    #curl_setopt($ch, CURLOPT_PROXYPORT, 8888);
    if ($post) {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
      if ($token) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-CSRF-Token: ' . $token));
      }
    }
    $o = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status == 200 ? $o : null;
  }

  static public function requestCache($url, &$status = null)
  {
    if (!isset(self::$cache[$url]['timestamp']) || self::$cache[$url]['timestamp'] < time() - 60 * 5) {
      self::$cache[$url]['content'] = self::request($url, $status);
      self::$cache[$url]['status'] = $status;
      self::$cache[$url]['timestamp'] = time();
      self::$cacheChanged = true;
    }
    $status = self::$cache[$url]['status'];
    return self::$cache[$url]['content'];
  }

  static public function deleteCache()
  {
    if (file_exists(self::$fileCache))
      unlink(self::$fileCache);
    self::$cache = null;
  }

  static public function deleteCookies()
  {
    if (file_exists(self::$fileCookies))
      unlink(self::$fileCookies);
    self::removeConfig('user');
  }

  static public function getToken()
  {
    $c = self::request('https://github.com/');
    preg_match('@<meta content="(.*)" name="csrf-token" />@U', $c, $match);
    return isset($match[1]) ? $match[1] : null;
  }

  static public function array2xml(array $data)
  {
    $items = new SimpleXMLElement('<items></items>');

    foreach ($data as $uid => $d) {
      $c = $items->addChild('item');
      $c->addAttribute('uid', 'github-' . $uid);
      $c->addChild('icon', 'icon.png');
      $keys = array('arg', 'valid', 'autocomplete');
      foreach ($keys as $key) {
        if (isset($d[$key]))
          $c->addAttribute($key, $d[$key]);
      }
      $keys = array('title', 'subtitle');
      foreach ($keys as $key) {
        if (isset($d[$key]))
          $c->addChild($key, htmlspecialchars($d[$key]));
      }
    }

    return $items->asXML();
  }

  static public function match($str1, $str2, &$ls = null)
  {
    return ($ls = levenshtein(strtolower($str1), strtolower($str2), 1, 1000, 1000)) < 1000;
  }

  static public function sameCharsFromBeginning($str1, $str2)
  {
    $str1 = strtolower($str1);
    $str2 = strtolower($str2);
    $end = min(strlen($str1), strlen($str2));
    for ($i = 0; $i < $end && $str1[$i] === $str2[$i]; ++$i);
    return $i;
  }

  static public function updateWorkflow()
  {
    if (file_exists($file = __DIR__ . '/functions.php')) {
      unlink($file);
      if (file_exists($file = __DIR__ . '/cache.json')) {
        unlink($file);
      }
      if (file_exists($file = __DIR__ . '/user')) {
        self::setConfig('user', file_get_contents($file));
      }
      if (file_exists($file = __DIR__ . '/cookies')) {
        rename($file, self::$fileCookies);
      }
      self::deleteCache();
      return true;
    }
    return false;
  }
}
