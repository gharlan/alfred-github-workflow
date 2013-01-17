<?php

define('FILE_COOKIES', __DIR__.'/cookies');
define('FILE_CACHE', __DIR__.'/cache.json');
define('FILE_USER', __DIR__.'/user');

function request($url, &$status = null, $post = false, $token = null, array $data = array()) {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_COOKIEJAR, FILE_COOKIES);
  curl_setopt($ch, CURLOPT_COOKIEFILE, FILE_COOKIES);
  #curl_setopt($ch, CURLOPT_PROXY, 'localhost');
  #curl_setopt($ch, CURLOPT_PROXYPORT, 8888);
  if ($post) {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    if ($token) {
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-CSRF-Token: '.$token));
    }
  }
  $o = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $status == 200 ? $o : null;
}

function request_cache($url, &$status = null) {
  static $cache;
  if ($cache === null) {
    $cache = array();
    if (file_exists(FILE_CACHE)) {
      $cache = json_decode(file_get_contents(FILE_CACHE), true);
      if ($cache['timestamp'] < time() - 60*5) {
        $cache = array('timestamp' => time());
      }
    }
  }
  if (!isset($cache[$url])) {
    $cache[$url]['content'] = request($url, $status);
    $cache[$url]['status'] = $status;
    file_put_contents(FILE_CACHE, json_encode($cache));
  }
  $status = $cache[$url]['status'];
  return $cache[$url]['content'];
}

function delete_cache() {
  if (file_exists(FILE_CACHE))
    unlink(FILE_CACHE);
}

function delete_cookies() {
  if (file_exists(FILE_COOKIES))
    unlink(FILE_COOKIES);
  if (file_exists(FILE_USER))
    unlink(FILE_USER);
}

function get_token() {
  $c = request('https://github.com/');
  preg_match('@<meta content="(.*)" name="csrf-token" />@U', $c, $match);
  return $match[1];
}

function array2xml(array $data) {
  $items = new SimpleXMLElement("<items></items>");

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

function match($str1, $str2, &$ls = null) {
  return ($ls = levenshtein(strtolower($str1), strtolower($str2), 1, 1000, 1000)) < 1000;
}

function sameCharsFromBeginning($str1, $str2) {
  $str1 = strtolower($str1);
  $str2 = strtolower($str2);
  $end = min(strlen($str1), strlen($str2));
  for ($i = 0; $i < $end && $str1[$i] === $str2[$i]; ++$i);
  return $i;
}
