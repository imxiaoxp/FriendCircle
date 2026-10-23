<?php

/**
 * IP 归属地数据库后台下载端点（admin 页面自动触发）
 *
 * POST /usr/themes/FriendCircle/ipdb-download.php
 * Response: {"ok": bool, "message": "...", "v6": bool}
 *
 * 由 admin/footer.php begin 钩子注入的 JS 在后台页面异步调用
 * （主题设置页 / 文章编辑页等），下载在独立请求中完成：
 * set_time_limit(0) 允许长耗时，不阻塞保存与页面操作。
 */

$config = dirname(dirname(dirname(__DIR__))) . '/config.inc.php';
if (!file_exists($config)) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'message' => 'Config not found']));
}
require_once $config;

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    /* Common::init 的 ob 回调会按 Response 状态发头，须用 Response 设置而非 http_response_code */
    \Typecho\Response::getInstance()->setStatus(405);
    exit(json_encode(['ok' => false, 'message' => 'Method not allowed']));
}

/* 与后台入口对齐的站点根 URL（cookie 前缀依赖），逻辑与 location.php 一致 */
$request = \Typecho\Request::getInstance();
$scriptName = str_replace('\\', '/', (string) $request->getServer('SCRIPT_NAME'));
$themePath = '/usr/themes/' . basename(dirname(__DIR__)) . '/';
$pos = 0 === strpos($scriptName, $themePath) ? 0 : strrpos($scriptName, $themePath);
$basePath = (false !== $pos) ? substr($scriptName, 0, $pos) : '';
define('__TYPECHO_ROOT_URL__', rtrim($request->getUrlPrefix() . $basePath, '/'));
\Typecho\Cookie::setPrefix(\Widget\Options::alloc()->rootUrl);

/* 仅登录用户 */
if (!\Widget\User::alloc()->hasLogin()) {
    \Typecho\Response::getInstance()->setStatus(403);
    exit(json_encode(['ok' => false, 'message' => '仅管理员可用']));
}

/* 受主题设置「插入位置按钮」开关控制 */
if ('1' !== (string) (\Widget\Options::alloc()->fcLocation ?? '0')) {
    exit(json_encode(['ok' => false, 'message' => '插入位置功能未开启，请到主题设置开启']));
}

/* 数据库可能达数十 MB，放开执行时限 */
@set_time_limit(0);

require_once __DIR__ . '/ipdb.php';

$v4 = FC_IpDb::ensure();
$v6 = true === $v4 ? FC_IpDb::ensureV6() : false;

exit(json_encode([
    'ok' => true === $v4,
    'message' => true === $v4
        ? (true === $v6 ? 'IP 归属地数据库已就绪（IPv4 + IPv6）' : 'IPv4 库已就绪，IPv6 库下载失败：' . $v6)
        : (string) $v4,
    'v6' => true === $v6,
]));
