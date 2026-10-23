<?php

/**
 * 位置 AJAX 接口（后台编辑器专用）
 *
 * POST /usr/themes/FriendCircle/location.php
 * Response: {"location": "江西·赣州"} 或 {"error": "..."}
 * Debug:    GET /usr/themes/FriendCircle/location.php?debug=1（登录后浏览器直接
 *           打开可查看服务器视角的请求头与解析结果，用于诊断 CDN / 反代场景）
 *
 * 依据主题自带的 ip2region 数据库（assets/ipdb/）解析文章编辑者的真实 IP
 * 归属地（优先采信 CDN / 反代转发头，回退 REMOTE_ADDR），
 * 供编辑器「插入位置」按钮一键填充。受主题设置「插入位置按钮」开关控制。
 * 输出格式：国内精确到省市（江西·赣州），直辖市只显示市，国外仅国家。
 */

$config = dirname(dirname(dirname(__DIR__))) . '/config.inc.php';
if (!file_exists($config)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Config not found']));
}
require_once $config;

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['debug'])) {
    /* Common::init 的 ob 回调会按 Response 状态发头，须用 Response 设置而非 http_response_code；
       debug 模式放行 GET，方便浏览器地址栏直接查看服务器视角的请求头 */
    \Typecho\Response::getInstance()->setStatus(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

/* 与后台入口对齐的站点根 URL：后台（/admin/*）请求的 rootUrl 会减去 admin 目录，
   而本文件请求路径 /usr/themes/<主题>/location.php 会让 getRequestRoot() 带上目录前缀，
   两者的 md5 前缀不同即读不到登录 cookie。这里按 SCRIPT_NAME 截掉主题路径得到真实站点根，
   必须在 User::alloc() 之前执行（其 execute 阶段即做登录判定） */
$request = \Typecho\Request::getInstance();
$scriptName = str_replace('\\', '/', (string) $request->getServer('SCRIPT_NAME'));
$themePath = '/usr/themes/' . basename(dirname(__DIR__)) . '/';
$pos = 0 === strpos($scriptName, $themePath) ? 0 : strrpos($scriptName, $themePath);
$basePath = (false !== $pos) ? substr($scriptName, 0, $pos) : '';
define('__TYPECHO_ROOT_URL__', rtrim($request->getUrlPrefix() . $basePath, '/'));
\Typecho\Cookie::setPrefix(\Widget\Options::alloc()->rootUrl);

/* 仅登录用户：避免被当作公开 IP 归属地查询接口滥用 */
if (!\Widget\User::alloc()->hasLogin()) {
    \Typecho\Response::getInstance()->setStatus(403);
    exit(json_encode(['error' => '仅管理员可用']));
}

/* 受主题设置「插入位置按钮」开关控制（默认关）；独立脚本无 functions.php，直接读 Options 属性 */
if ('1' !== (string) (\Widget\Options::alloc()->fcLocation ?? '0')) {
    \Typecho\Response::getInstance()->setStatus(403);
    exit(json_encode(['error' => '插入位置功能未开启，请到主题设置开启']));
}

require_once __DIR__ . '/ipdb.php';
/* 懒下载兜底（库可能数十 MB），放开执行时限 */
@set_time_limit(0);
$dbReady = FC_IpDb::ensure();
if (true !== $dbReady) {
    exit(json_encode(['error' => $dbReady]));
}

/* 编辑者真实 IP：从标准转发头还原（CDN 直传头优先，其次 XFF 最左 / RFC 7239
   Forwarded），全部缺失回退 REMOTE_ADDR。仅展示且只返回给调用者自身，不入库 */
$ip = '';
foreach (['EO-Connecting-IP', 'CF-Connecting-IP', 'X-Real-IP', 'X-Client-IP', 'Client-IP'] as $header) {
    $value = trim((string) $request->getHeader($header));
    if ('' !== $value && filter_var($value, FILTER_VALIDATE_IP)) {
        $ip = $value;
        break;
    }
}
if ('' === $ip) {
    $first = trim((string) explode(',', (string) $request->getHeader('X-Forwarded-For'))[0]);
    if ('' !== $first && filter_var($first, FILTER_VALIDATE_IP)) {
        $ip = $first;
    }
}
if ('' === $ip) {
    /* RFC 7239 Forwarded: for=1.2.3.4 或 for="1.2.3.4" */
    if (preg_match('/for="?(\[[0-9a-f:.]+\]|[0-9]{1,3}(?:\.[0-9]{1,3}){3})/i', (string) $request->getHeader('Forwarded'), $m)) {
        $candidate = trim($m[1], '[]"');
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
}
if ('' === $ip) {
    $ip = (string) $request->getServer('REMOTE_ADDR');
}

/* 调试：带 debug=1 时返回服务器视角的全部 IP 线索（仅登录可用），
   用于定位真实 IP 实际由哪个头承载 */
if (isset($_GET['debug'])) {
    $trace = ['remote_addr' => (string) $request->getServer('REMOTE_ADDR'), 'headers' => []];
    if (function_exists('getallheaders')) {
        $trace['headers'] = getallheaders();
    } else {
        foreach ($_SERVER as $key => $value) {
            if (0 === strpos((string) $key, 'HTTP_')) {
                $trace['headers'][str_replace('_', '-', strtolower(substr((string) $key, 5)))] = $value;
            }
        }
    }
    exit(json_encode(['debug' => $trace, 'resolved_ip' => $ip, 'location' => FC_IpDb::format($ip)], JSON_UNESCAPED_UNICODE));
}

if ('' === $ip) {
    exit(json_encode(['error' => '无法识别客户端 IP']));
}

exit(json_encode(['location' => FC_IpDb::format($ip)]));
