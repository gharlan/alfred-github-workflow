<?php

/*
 * This file is part of the alfred-github-workflow package.
 *
 * (c) Gregor Harlan
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class Curl
{
    /** @var CurlRequest[] */
    private $requests = array();
    private $running = false;
    private $debug = false;

    private static $multiHandle;

    public function add(CurlRequest $request)
    {
        $this->requests[$request->url] = $request;
        if ($this->running) {
            $this->addHandle($request);
        }
    }

    public function execute()
    {
        $this->running = true;
        if (!is_resource(self::$multiHandle)) {
            self::$multiHandle = curl_multi_init();
        }

        foreach ($this->requests as $request) {
            $this->addHandle($request);
        }

        $finish = false;
        $running = true;
        do {
            $finish = !$running;
            while (CURLM_CALL_MULTI_PERFORM == $execrun = curl_multi_exec(self::$multiHandle, $running));
            if ($execrun != CURLM_OK) {
                break;
            }
            while ($done = curl_multi_info_read(self::$multiHandle)) {
                $ch = $done['handle'];
                $info = curl_getinfo($ch);
                $url = self::getHeader($info['request_header'], 'X-Url');
                $request = $this->requests[$url];
                $rawResponse = curl_multi_getcontent($ch);
                if (preg_match("@^HTTP/\\d\\.\\d 200 Connection established\r\n\r\n@i", $rawResponse)) {
                    list(, $header, $body) = explode("\r\n\r\n", $rawResponse, 3);
                } else {
                    list($header, $body) = explode("\r\n\r\n", $rawResponse, 2);
                }
                $response = new CurlResponse();
                $response->request = $request;
                $response->status = $info['http_code'];
                $headerNames = array(
                    'etag' => 'ETag',
                    'contentType' => 'Content-Type',
                    'link' => 'Link',
                );
                foreach ($headerNames as $key => $name) {
                    $response->$key = self::getHeader($header, $name);
                }
                if (200 == $response->status) {
                    $response->content = $body;
                }
                $callback = $request->callback;
                $callback($response);
                curl_multi_remove_handle(self::$multiHandle, $ch);
                curl_close($ch);
            }
            if ($running || !$finish) {
                if (curl_multi_select(self::$multiHandle, 1) === -1) {
                    usleep(250);
                }
            }
        } while ($running || !$finish);

        $this->running = false;
        return true;
    }

    private function addHandle(CurlRequest $request)
    {
        $defaultOptions = array(
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'alfred-github-workflow',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLINFO_HEADER_OUT => true,
        );
        if ($this->debug) {
            $defaultOptions[CURLOPT_PROXY] = 'localhost';
            $defaultOptions[CURLOPT_PROXYPORT] = 8888;
            $defaultOptions[CURLOPT_SSL_VERIFYPEER] = 0;
        }

        $ch = curl_init();
        $options = $defaultOptions;
        $options[CURLOPT_URL] = $request->url;
        $header = array();
        $header[] = 'X-Url: '.$request->url;
        if ($request->token) {
            $header[] = 'Authorization: token '.$request->token;
        }
        if ($request->etag) {
            $header[] = 'If-None-Match: '.$request->etag;
        }
        $options[CURLOPT_HTTPHEADER] = $header;
        curl_setopt_array($ch, $options);
        curl_multi_add_handle(self::$multiHandle, $ch);
    }

    public static function getHeader($header, $key)
    {
        if (preg_match('/^'.preg_quote($key, '/').': (\V*)/mi', $header, $match)) {
            return $match[1];
        }
        return null;
    }
}

class CurlRequest
{
    public $url;
    public $etag;
    public $token;
    public $callback;

    public function __construct($url, $etag, $token, $callback)
    {
        $this->url = $url;
        $this->etag = $etag;
        $this->token = $token;
        $this->callback = $callback;
    }
}

class CurlResponse
{
    /** @var CurlRequest */
    public $request;
    public $status;
    public $contentType;
    public $etag;
    public $link;
    public $content;
}
