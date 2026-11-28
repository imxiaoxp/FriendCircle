<?php

/**
 * FriendCircle B 站视频解析（DASH）
 *
 * GET /usr/themes/FriendCircle/bili.php?action=mpd&url=<B站视频链接>&p=1
 *     输出 DASH 清单（1080P 画面轨 + 音频轨），前端由 dash.js 播放。
 *     国外服务器优先选 AV1（与官网一致），国内服务器保持 AVC 优先。
 *     清单内 BaseURL 直接写 B 站 CDN 地址，媒体分片由访客浏览器直连，
 *     本端点只返回几 KB 的清单，不代理任何媒体流量。
 *
 * 1080P 需要主题设置「B 站解析 Cookie」（SESSDATA 为已 URL 编码的原始值）；
 * 未配置时 B 站只返回低清晰度 DASH。playurl 结果缓存 5 分钟到系统临时目录。
 *
 * 节点选择：mcdn 节点天然免 Referer 直连，优先写入清单；没有 mcdn 时
 * （海外服务器常见 akamaized 等境外节点）服务端并行探测各节点，仅保留免 Referer
 * 可拿到数据的地址，全部不可用则返回 502 纯文本提示。
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

/** 带 B 站请求头的 GET，返回 body/url/error */
function fcBiliGet($url, $cookie, $timeout)
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

    $ch = curl_init();
    if (false === $ch) {
        return array('body' => '', 'url' => $url, 'error' => 'curl 不可用');
    }
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers
    ));
    $body = curl_exec($ch);
    $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);

    return array(
        'body' => is_string($body) ? $body : '',
        'url' => $effective,
        'error' => $error
    );
}

function fcBiliJson($url, $cookie)
{
    $r = fcBiliGet($url, $cookie, 15);
    if ('' !== $r['error']) {
        return null;
    }
    $data = json_decode($r['body'], true);
    return is_array($data) ? $data : null;
}

/** 服务器出口是否在国外：B 站按调用方 IP 返回归属地。结果缓存 5 分钟，
    查询失败时按国内处理（保持 AVC 优先） */
function fcBiliOverseas($cookie)
{
    $hit = fcBiliCacheGet('zone');
    if (null !== $hit) {
        return !empty($hit['overseas']);
    }
    $data = fcBiliJson('https://api.bilibili.com/x/web-interface/zone', $cookie);
    $country = (string) ($data['data']['country'] ?? '');
    /* 仅「中国」按国内处理；中国香港/澳门/台湾及境外均按国外，优先 AV1 */
    $overseas = ('' !== $country && '中国' !== $country);
    fcBiliCacheSet('zone', array('overseas' => $overseas));
    return $overseas;
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
        $r = fcBiliGet($s, $cookie, 15);
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

/** 选轨：国外环境优先 1080P AV1（与官网默认源一致，规避 AVC 文件残缺），
    国内环境维持 1080P AVC 优先；都没有时取最高分辨率 */
function fcBiliPickVideo($list, $preferAv1)
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
    $byCodec = function ($minHeight, $codecid) use ($all) {
        foreach ($all as $v) {
            if ((int) ($v['height'] ?? 0) >= $minHeight && $codecid === (int) ($v['codecid'] ?? 0)) {
                return $v;
            }
        }
        return null;
    };
    foreach ($preferAv1 ? array(13, 7) : array(7, 13) as $codecid) {
        $t = $byCodec(1080, $codecid);
        if (null !== $t) {
            return $t;
        }
    }
    usort($all, function ($a, $b) {
        $d = (int) ($b['height'] ?? 0) - (int) ($a['height'] ?? 0);
        return 0 !== $d ? $d : (int) ($b['id'] ?? 0) - (int) ($a['id'] ?? 0);
    });
    return $all[0];
}

/** 卡住重连时的备用视频轨：首选轨的文件在边缘节点上分片残缺时换成另一条轨道文件
    （不同清晰度/编码是不同文件，缓存状态互不影响），按分辨率从高到低轮换 */
function fcBiliAltVideo($list, $preferred, $skip)
{
    if ($skip <= 0 || null === $preferred) {
        return $preferred;
    }
    $rest = array();
    foreach ((array) $list as $t) {
        if (fcBiliTrackOk($t) && $t !== $preferred) {
            $rest[] = $t;
        }
    }
    if (empty($rest)) {
        return $preferred;
    }
    usort($rest, function ($a, $b) {
        $d = (int) ($b['height'] ?? 0) - (int) ($a['height'] ?? 0);
        if (0 !== $d) {
            return $d;
        }
        $bw = (int) ($b['bandwidth'] ?? 0) - (int) ($a['bandwidth'] ?? 0);
        return 0 !== $bw ? $bw : (int) ($b['id'] ?? 0) - (int) ($a['id'] ?? 0);
    });
    return $rest[($skip - 1) % count($rest)];
}

/** 音轨：优先 AAC，按码率从高到低排序；$skip 用于卡住时换到另一条音轨文件 */
function fcBiliPickAudio($list, $skip = 0)
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
    return $pool[$skip % count($pool)];
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

/** playurl 解析（含缓存）：返回 video/audio/duration。
    $fresh 为 true 时跳过缓存重新请求，用于播放卡住后换一份新签名地址 */
function fcBiliStream($bvid, $cid, $cookie, $fresh = false)
{
    $key = 'stream:' . $bvid . ':' . $cid;
    if (!$fresh) {
        $hit = fcBiliCacheGet($key);
        if (null !== $hit) {
            return $hit;
        }
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
    $video = fcBiliPickVideo($dash['video'] ?? array(), fcBiliOverseas($cookie));
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
        'duration' => $duration,
        /* 完整轨道列表：首选轨道无免 Referer 节点时按分辨率降级用 */
        'videos' => $dash['video'] ?? array(),
        'audios' => $dash['audio'] ?? array()
    );
    fcBiliCacheSet($key, $stream);
    return $stream;
}

/** 轨道全部候选地址（主地址 + 备用地址，去重） */
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
    return array_values(array_unique($all));
}

/** 并行免 Referer 探测一批地址：返回 [地址 => 是否可直连]
    只取 2 字节即中断，节点忽略 Range 返回整文件时判为不可用（避免浏览器下载大文件） */
function fcBiliProbeMany($urls, $timeout, $fresh = false)
{
    $result = array();
    $pending = array();
    foreach ($urls as $u) {
        $hit = $fresh ? null : fcBiliCacheGet('probe:' . $u);
        if (null !== $hit) {
            $result[$u] = !empty($hit['ok']);
        } else {
            $pending[] = $u;
        }
    }
    if (empty($pending)) {
        return $result;
    }

    $mh = curl_multi_init();
    $handles = array();
    foreach ($pending as $u) {
        $ch = curl_init();
        if (false === $ch) {
            $result[$u] = false;
            continue;
        }
        curl_setopt_array($ch, array(
            CURLOPT_URL => $u,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            /* 不带 Referer：浏览器无法伪造 B 站 Referer，这里模拟访客的真实请求 */
            CURLOPT_HTTPHEADER => array(
                'User-Agent: ' . FC_BILI_UA,
                'Accept: */*',
                'Range: bytes=0-1'
            ),
            /* 收到首块数据立即中断 */
            CURLOPT_WRITEFUNCTION => function () {
                return 0;
            }
        ));
        curl_multi_add_handle($mh, $ch);
        $handles[$u] = $ch;
    }

    $running = 0;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running > 0 && CURLM_OK === $status);

    foreach ($handles as $u => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (float) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $ok = (206 === $code) || (200 === $code && $size > 0 && $size <= 4096);
        $result[$u] = $ok;
        /* 只缓存成功结果：失败可能是临时的（跨境波动/风控），缓存会把可用的节点误判 5 分钟 */
        if ($ok) {
            fcBiliCacheSet('probe:' . $u, array('ok' => true));
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $result;
}

/** 轨道可用直连地址：mcdn 节点免 Referer 直连，优先使用；
    没有 mcdn 时（海外服务器常见 akamaized 等境外节点，或国内节点池未下发 mcdn）
    取 probe 探测通过的地址。$skip 用于卡住重连时轮换到下一个可用节点 */
function fcBiliDirectUrls($track, $probe, $skip = 0)
{
    $mcdn = array();
    $others = array();
    foreach (fcBiliCandidates($track) as $u) {
        if (false !== strpos($u, 'mcdn.bilivideo.cn')) {
            $mcdn[] = $u;
        } else {
            $others[] = $u;
        }
    }
    /* 轮换时把目标节点排到首位，其余地址保留作为兜底候选 */
    $rotate = function ($list, $skip) {
        $n = count($list);
        if ($n <= 1 || 0 === $skip % $n) {
            return $list;
        }
        $i = $skip % $n;
        return array_merge(array_slice($list, $i), array_slice($list, 0, $i));
    };
    if (!empty($mcdn)) {
        return $rotate($mcdn, $skip);
    }
    $ok = array();
    foreach ($others as $u) {
        if (!empty($probe[$u])) {
            $ok[] = $u;
        }
    }
    if (!empty($ok)) {
        return $rotate($ok, $skip);
    }
    return array();
}

/** 选可直连轨道：优先指定轨道；该轨道没有免 Referer 可用节点时，按分辨率从高到低
    回退，保证降分辨率时拿到的仍是 noRef=206 的地址。全部不可直连时保留首选轨道的
    原始候选，交给 dash.js 自行尝试。$skip 轮换节点，$fresh 跳过探测结果缓存 */
function fcBiliPickTrack($tracks, $preferred, $skip = 0, $fresh = false)
{
    $rest = array();
    foreach ((array) $tracks as $t) {
        if (fcBiliTrackOk($t) && $t !== $preferred) {
            $rest[] = $t;
        }
    }
    usort($rest, function ($a, $b) {
        $d = (int) ($b['height'] ?? 0) - (int) ($a['height'] ?? 0);
        return 0 !== $d ? $d : (int) ($b['id'] ?? 0) - (int) ($a['id'] ?? 0);
    });

    foreach (array_merge(array($preferred), $rest) as $t) {
        $urls = fcBiliCandidates($t);
        $need = array();
        foreach ($urls as $u) {
            if (false === strpos($u, 'mcdn.bilivideo.cn')) {
                $need[] = $u;
            }
        }
        $probe = !empty($need) ? fcBiliProbeMany($need, 6, $fresh) : array();
        $direct = fcBiliDirectUrls($t, $probe, $skip);
        if (!empty($direct)) {
            return array($t, $direct);
        }
    }

    return array($preferred, fcBiliCandidates($preferred));
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

    /* 播放卡住后的重连参数：fresh 跳过 playurl 缓存换一份新签名地址，
       alt 轮换音轨与备用节点，避开当前边缘节点取不到的分片区间 */
    $fresh = !empty($_GET['fresh']);
    $alt = isset($_GET['alt']) ? max(0, (int) $_GET['alt']) : 0;

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
        $stream = fcBiliStream($bvid, $cid, $cookie, $fresh);
    } catch (\Exception $e) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=UTF-8');
        exit($e->getMessage());
    }

    /* 优先用首选轨道；它没有免 Referer 节点时按分辨率降级，仍要求 noRef=206。
       alt 大于 0 时换一条视频/音轨文件，并轮换到下一个可用节点 */
    $videos = $stream['videos'] ?? array($stream['video']);
    $audios = $stream['audios'] ?? array($stream['audio']);
    $videoPref = $alt > 0 ? fcBiliAltVideo($videos, $stream['video'], $alt) : $stream['video'];
    $audioPref = $alt > 0 ? fcBiliPickAudio($audios, $alt) : $stream['audio'];
    if (null === $videoPref) {
        $videoPref = $stream['video'];
    }
    if (null === $audioPref) {
        $audioPref = $stream['audio'];
    }
    list($v, $vUrls) = fcBiliPickTrack($videos, $videoPref, $alt, $fresh);
    list($a, $aUrls) = fcBiliPickTrack($audios, $audioPref, $alt, $fresh);
    $durText = 'PT' . number_format(max(0.001, (float) $stream['duration']), 3, '.', '') . 'S';

    $segmentBase = function ($track) {
        $sb = $track['SegmentBase'];
        return '<SegmentBase indexRange="' . fcBiliXmlEsc($sb['indexRange']) . '" indexRangeExact="true">'
            . '<Initialization range="' . fcBiliXmlEsc($sb['Initialization']) . '"/>'
            . '</SegmentBase>';
    };

    /* 清单里直接写 B 站 CDN 地址，媒体流量由访客浏览器直连，不经过本站 */
    $baseUrlsXml = function ($urls) {
        $xml = '';
        foreach ($urls as $u) {
            $xml .= '<BaseURL>' . fcBiliXmlEsc($u) . '</BaseURL>';
        }
        return $xml;
    };

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
