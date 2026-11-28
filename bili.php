<?php

/**
 * FriendCircle B 站视频解析（DASH）
 *
 * GET /usr/themes/FriendCircle/bili.php?action=mpd&url=<B站视频链接>&p=1
 *     输出 DASH 清单（1080P 画面轨 + 音频轨），前端由 dash.js 播放。
 *     清单内 BaseURL 直接写 B 站 CDN 地址，媒体分片由访客浏览器直连，
 *     本端点只返回几 KB 的清单，不代理任何媒体流量。
 *
 * 1080P 需要主题设置「B 站解析 Cookie」（SESSDATA 为已 URL 编码的原始值）；
 * 未配置时 B 站只返回低清晰度 DASH。playurl 结果缓存 5 分钟到系统临时目录。
 */

$config = dirname(dirname(dirname(__DIR__))) . '/config.inc.php';
if (!file_exists($config)) {
    http_response_code(500);
    exit('Config not found');
}
require_once $config;

define('FC_BILI_UA', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
define('FC_BILI_TTL', 300);

/** 主题设置里的解析 Cookie（SESSDATA 等） */
function fcBiliCookie()
{
    try {
        $options = \Widget\Options::alloc();
        return trim((string) ($options->fcBiliCookie ?? ''));
    } catch (\Exception $e) {
        return '';
    }
}

/** 带 B 站请求头的 GET，返回 status/headers/body/url/error */
function fcBiliGet($url, $cookie, $timeout, $range)
{
    $headers = array(
        'User-Agent: ' . FC_BILI_UA,
        'Accept: application/json, text/plain, */*',
        'Accept-Language: zh-CN,zh;q=0.9',
        'Referer: https://www.bilibili.com/'
    );
    if ('' !== $cookie) {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ('' !== $range) {
        $headers[] = 'Range: ' . $range;
    }

    $hdrs = array();
    $ch = curl_init();
    if (false === $ch) {
        return array('status' => 0, 'headers' => array(), 'body' => '', 'url' => $url, 'error' => 'curl 不可用');
    }
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdrs) {
            if (0 === stripos($line, 'HTTP/')) {
                $hdrs = array();
            } else {
                $pos = strpos($line, ':');
                if (false !== $pos) {
                    $hdrs[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
            }
            return strlen($line);
        }
    ));
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);

    return array(
        'status' => $status,
        'headers' => $hdrs,
        'body' => is_string($body) ? $body : '',
        'url' => $effective,
        'error' => $error
    );
}

function fcBiliJson($url, $cookie)
{
    $r = fcBiliGet($url, $cookie, 15, '');
    if ('' !== $r['error']) {
        return null;
    }
    $data = json_decode($r['body'], true);
    return is_array($data) ? $data : null;
}

/** 从链接或 BV 号解析 bvid（b23.tv 短链跟随跳转后取） */
function fcBiliBvid($input, $cookie)
{
    $s = trim((string) $input);
    if ('' === $s) {
        return '';
    }
    if (preg_match('/^(BV[0-9A-Za-z]+)$/', $s, $m)) {
        return $m[1];
    }
    if (preg_match('#bilibili\.com/video/(BV[0-9A-Za-z]+)#i', $s, $m)) {
        return $m[1];
    }
    if (preg_match('#^https?://b23\.tv/#i', $s)) {
        $r = fcBiliGet($s, $cookie, 15, '');
        if (preg_match('#/video/(BV[0-9A-Za-z]+)#i', $r['url'], $m)) {
            return $m[1];
        }
    }
    return '';
}

function fcBiliXmlEsc($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function fcBiliTrackOk($t)
{
    return is_array($t) && !empty($t['baseUrl']) && !empty($t['SegmentBase'])
        && !empty($t['SegmentBase']['Initialization']) && !empty($t['SegmentBase']['indexRange']);
}

/** 优先 1080P（id=80）AVC，其次 1080P 任意编码，最后取最高画质 AVC */
function fcBiliPickVideo($list)
{
    $all = array();
    foreach ((array) $list as $t) {
        if (fcBiliTrackOk($t)) {
            $all[] = $t;
        }
    }
    if (empty($all)) {
        return null;
    }
    $preferAvc = function ($arr) {
        foreach ($arr as $v) {
            if (7 === (int) ($v['codecid'] ?? 0)) {
                return $v;
            }
        }
        return $arr[0];
    };
    $q1080 = array();
    foreach ($all as $v) {
        if (80 === (int) ($v['id'] ?? 0)) {
            $q1080[] = $v;
        }
    }
    if (!empty($q1080)) {
        return $preferAvc($q1080);
    }
    $avc = array();
    foreach ($all as $v) {
        if (7 === (int) ($v['codecid'] ?? 0)) {
            $avc[] = $v;
        }
    }
    $pool = !empty($avc) ? $avc : $all;
    usort($pool, function ($a, $b) {
        return (int) ($b['id'] ?? 0) - (int) ($a['id'] ?? 0);
    });
    return $pool[0];
}

/** 音轨：优先 AAC，取码率最高的一条 */
function fcBiliPickAudio($list)
{
    $all = array();
    foreach ((array) $list as $t) {
        if (fcBiliTrackOk($t)) {
            $all[] = $t;
        }
    }
    if (empty($all)) {
        return null;
    }
    $aac = array();
    foreach ($all as $a) {
        if (preg_match('/mp4a/i', (string) ($a['codecs'] ?? '')) || preg_match('#audio/mp4#i', (string) ($a['mimeType'] ?? ''))) {
            $aac[] = $a;
        }
    }
    $pool = !empty($aac) ? $aac : $all;
    usort($pool, function ($a, $b) {
        return (int) ($b['bandwidth'] ?? 0) - (int) ($a['bandwidth'] ?? 0);
    });
    return $pool[0];
}

function fcBiliCacheFile($key)
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'fc-bili-' . md5($key) . '.json';
}

function fcBiliCacheGet($key)
{
    $file = fcBiliCacheFile($key);
    if (!is_file($file) || filemtime($file) + FC_BILI_TTL < time()) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function fcBiliCacheSet($key, $data)
{
    @file_put_contents(fcBiliCacheFile($key), json_encode($data), LOCK_EX);
}

/** playurl 解析（含缓存）：返回 video/audio/duration */
function fcBiliStream($bvid, $cid, $cookie)
{
    $key = 'stream:' . $bvid . ':' . $cid;
    $hit = fcBiliCacheGet($key);
    if (null !== $hit) {
        return $hit;
    }

    $api = 'https://api.bilibili.com/x/player/playurl?bvid=' . rawurlencode($bvid)
        . '&cid=' . rawurlencode((string) $cid)
        . '&qn=80&fnver=0&fnval=4048&fourk=1&platform=pc';
    $play = fcBiliJson($api, $cookie);
    if (!is_array($play) || 0 !== (int) ($play['code'] ?? -1)) {
        $msg = is_array($play) && !empty($play['message']) ? (string) $play['message'] : '获取播放信息失败';
        throw new Exception($msg);
    }
    $dash = $play['data']['dash'] ?? null;
    if (!is_array($dash)) {
        throw new Exception('未获取到 DASH 流，1080P 需要在主题设置中配置「B 站解析 Cookie」');
    }
    $video = fcBiliPickVideo($dash['video'] ?? array());
    $audio = fcBiliPickAudio($dash['audio'] ?? array());
    if (!$video || !$audio) {
        throw new Exception('DASH 流缺少视频或音频轨道');
    }
    $duration = (float) ($dash['duration'] ?? 0);
    if ($duration <= 0) {
        $duration = ((float) ($play['data']['timelength'] ?? 0)) / 1000;
    }

    $stream = array(
        'video' => $video,
        'audio' => $audio,
        'duration' => $duration
    );
    fcBiliCacheSet($key, $stream);
    return $stream;
}

/** 轨道候选地址：仅保留 mcdn 节点（唯一免 Referer 可直连），以减少清单体积 */
function fcBiliCandidates($track)
{
    $all = array((string) $track['baseUrl']);
    if (!empty($track['backupUrl']) && is_array($track['backupUrl'])) {
        foreach ($track['backupUrl'] as $u) {
            if (is_string($u) && '' !== $u) {
                $all[] = $u;
            }
        }
    }
    $mcdn = array();
    foreach ($all as $u) {
        if (false !== strpos($u, 'mcdn.bilivideo.cn')) {
            $mcdn[] = $u;
        }
    }
    /* 没有 mcdn 节点时退回主地址：直连大概率 403，但保留可比空清单给出更明确的失败提示 */
    return !empty($mcdn) ? array_values(array_unique($mcdn)) : array((string) $track['baseUrl']);
}

/** 输出 DASH 清单：BaseURL 直接用 B 站 CDN 地址，媒体流量不经过本站 */
function fcBiliServeMpd($cookie)
{
    $input = isset($_GET['url']) ? (string) $_GET['url'] : (isset($_GET['bvid']) ? (string) $_GET['bvid'] : '');
    $bvid = fcBiliBvid($input, $cookie);
    if ('' === $bvid) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('无效的 B 站视频地址');
    }

    /* 分 P：优先取显式 p 参数，其次取原链接里的 ?p= */
    $page = isset($_GET['p']) ? (int) $_GET['p'] : 0;
    if ($page <= 0 && preg_match('/[?&]p=(\d+)/i', $input, $m)) {
        $page = (int) $m[1];
    }
    if ($page <= 0) {
        $page = 1;
    }

    try {
        $list = fcBiliJson('https://api.bilibili.com/x/player/pagelist?bvid=' . rawurlencode($bvid), $cookie);
        $pages = isset($list['data']) && is_array($list['data']) ? $list['data'] : array();
        if (empty($pages)) {
            throw new Exception('获取视频列表失败');
        }
        $index = min(max($page, 1), count($pages)) - 1;
        $cid = (string) ($pages[$index]['cid'] ?? '');
        if ('' === $cid) {
            throw new Exception('未找到视频分 P 信息');
        }
        $stream = fcBiliStream($bvid, $cid, $cookie);
    } catch (\Exception $e) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=UTF-8');
        exit($e->getMessage());
    }

    $v = $stream['video'];
    $a = $stream['audio'];
    $durText = 'PT' . number_format(max(0.001, (float) $stream['duration']), 3, '.', '') . 'S';

    $segmentBase = function ($track) {
        $sb = $track['SegmentBase'];
        return '<SegmentBase indexRange="' . fcBiliXmlEsc($sb['indexRange']) . '" indexRangeExact="true">'
            . '<Initialization range="' . fcBiliXmlEsc($sb['Initialization']) . '"/>'
            . '</SegmentBase>';
    };

    /* 清单里直接写 B 站 CDN 地址（含备用地址），媒体流量由访客浏览器直连，不经过本站 */
    $baseUrlsXml = function ($urls) {
        $xml = '';
        foreach ($urls as $u) {
            $xml .= '<BaseURL>' . fcBiliXmlEsc($u) . '</BaseURL>';
        }
        return $xml;
    };

    $vUrls = fcBiliCandidates($v);
    $aUrls = fcBiliCandidates($a);

    $videoRate = !empty($v['frameRate']) ? ' frameRate="' . fcBiliXmlEsc($v['frameRate']) . '"' : '';

    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-live:2011"'
        . ' type="static" mediaPresentationDuration="' . $durText . '" minBufferTime="PT1.5S">'
        . '<Period id="0" start="PT0S" duration="' . $durText . '">'
        . '<AdaptationSet id="1" contentType="video" mimeType="' . fcBiliXmlEsc($v['mimeType'] ?? 'video/mp4') . '"'
        . ' segmentAlignment="true" startWithSAP="1">'
        . '<Representation id="video" bandwidth="' . (int) ($v['bandwidth'] ?? 0) . '"'
        . ' codecs="' . fcBiliXmlEsc($v['codecs'] ?? '') . '" width="' . (int) ($v['width'] ?? 0) . '"'
        . ' height="' . (int) ($v['height'] ?? 0) . '"' . $videoRate . '>'
        . $baseUrlsXml($vUrls)
        . $segmentBase($v)
        . '</Representation></AdaptationSet>'
        . '<AdaptationSet id="2" contentType="audio" mimeType="' . fcBiliXmlEsc($a['mimeType'] ?? 'audio/mp4') . '"'
        . ' segmentAlignment="true" startWithSAP="1">'
        . '<Representation id="audio" bandwidth="' . (int) ($a['bandwidth'] ?? 0) . '"'
        . ' codecs="' . fcBiliXmlEsc($a['codecs'] ?? 'mp4a.40.2') . '">'
        . $baseUrlsXml($aUrls)
        . $segmentBase($a)
        . '</Representation></AdaptationSet>'
        . '</Period></MPD>';

    header('Content-Type: application/dash+xml; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $xml;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if ('mpd' !== $action) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Unknown action');
}

/* 关掉框架的输出缓冲，避免清单前后混入其它输出 */
while (ob_get_level() > 0) {
    ob_end_clean();
}

try {
    fcBiliServeMpd(fcBiliCookie());
} catch (\Exception $e) {
    if (!headers_sent()) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo $e->getMessage();
}
