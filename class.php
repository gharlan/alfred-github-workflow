<?php

class gh
{
  static private
    $fileCookies,
    $fileUser,
    $fileCache;

  static public function init()
  {
    self::$fileCookies = __DIR__ . '/cookies';
    self::$fileUser    = __DIR__ . '/user';
    self::$fileCache   = __DIR__ . '/cache.json';
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
    static $cache;
    if ($cache === null) {
      if (file_exists(self::$fileCache)) {
        $cache = json_decode(file_get_contents(self::$fileCache), true);
      }
      if (!isset($cache['timestamp']) || $cache['timestamp'] < time() - 60 * 5) {
        $cache = array('timestamp' => time());
      }
    }
    if (!isset($cache[$url])) {
      $cache[$url]['content'] = self::request($url, $status);
      $cache[$url]['status'] = $status;
      file_put_contents(self::$fileCache, json_encode($cache));
    }
    $status = $cache[$url]['status'];
    return $cache[$url]['content'];
  }

  static public function deleteCache()
  {
    if (file_exists(self::$fileCache))
      unlink(self::$fileCache);
  }

  static public function deleteCookies()
  {
    if (file_exists(self::$fileCookies))
      unlink(self::$fileCookies);
    if (file_exists(self::$fileUser))
      unlink(self::$fileUser);
  }

  static public function getUser()
  {
    static $user;
    if (!$user && file_exists(self::$fileUser)) {
      $user = file_get_contents(self::$fileUser);
    }
    return $user;
  }

  static public function setUser($user)
  {
    file_put_contents(self::$fileUser, $user);
  }

  static public function getToken()
  {
    $c = self::request('https://github.com/');
    preg_match('@<meta content="(.*)" name="csrf-token" />@U', $c, $match);
    return $match[1];
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
}
