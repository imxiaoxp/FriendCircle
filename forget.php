<?php

/**
 * 游客评论记忆清理端点
 *
 * POST /usr/themes/FriendCircle/forget.php
 * Response: {"ok": true, "removed": ["author", "mail", "url"]}
 *
 * 站点开启 Cookie httponly 时（__TYPECHO_COOKIE_OPTIONS__），Typecho 原生评论者
 * 记忆 cookie（__typecho_remember_author/mail/url，带 md5(rootUrl) 前缀）对前端
 * JS 完全不可见、无法删除，必须由服务端下发过期 Set-Cookie 才能清除。
 * 本端点用与写入时完全一致的 path / domain / httponly 下发删除指令；
 * Set-Cookie 仅作用于发起请求的浏览器自身，游客清理自己的身份信息，
 * 无需鉴权、不返回任何敏感数据。清除按钮点击时由 app.js 调用。
 */

$config = dirname(dirname(dirname(__DIR__))) . '/config.inc.php';
if (!file_exists($config)) {
    header('Content-Type: application/json; charset=UTF-8');
    \Typecho\Response::getInstance()->setStatus(500);
    exit(json_encode(['ok' => false, 'error' => 'Config not found']));
}
require_once $config;

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    /* Common::init 的 ob 回调会按 Response 状态发头，须用 Response 设置而非 http_response_code */
    \Typecho\Response::getInstance()->setStatus(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

/* cookie 前缀来源必须与前台入口（Init::execute 的 Cookie::setPrefix($options->rootUrl)）
   一致：rootUrl 未预定义时 Options 会退回 getRequestRoot()（当前请求 URL 的根），
   独立脚本路径 /usr/themes/<主题>/forget.php 会让其带上目录前缀，md5 前缀即不匹配。
   这里按 SCRIPT_NAME 截掉主题路径 define 真实站点根后再取 rootUrl */
$request = \Typecho\Request::getInstance();
$scriptName = str_replace('\\', '/', (string) $request->getServer('SCRIPT_NAME'));
$themePath = '/usr/themes/' . basename(dirname(__DIR__)) . '/';
$pos = 0 === strpos($scriptName, $themePath) ? 0 : strrpos($scriptName, $themePath);
$basePath = (false !== $pos) ? substr($scriptName, 0, $pos) : '';
define('__TYPECHO_ROOT_URL__', rtrim($request->getUrlPrefix() . $basePath, '/'));
$removed = [];
/* $_COOKIE 含请求携带的全部 cookie（含 HttpOnly），按后缀匹配覆盖任意前缀
   （含更换域名 / 站点地址前后遗留的孤儿 cookie）；path / domain 与写入形态对齐，
   服务端下发 Set-Cookie 不受 HttpOnly 限制 */
$rootUrl = \Widget\Options::alloc()->rootUrl;
$parts = parse_url($rootUrl);
$host = isset($parts['host']) ? $parts['host'] : '';
$path = isset($parts['path']) && '' !== $parts['path'] ? rtrim($parts['path'], '/') . '/' : '/';
$response = \Typecho\Response::getInstance();
foreach ($_COOKIE as $name => $value) {
    if (!preg_match('/__typecho_remember_(author|mail|url)$/', (string) $name)) {
        continue;
    }
    $response->setCookie((string) $name, '', -1, $path, $host, false, false);
    $removed[] = $name;
}

exit(json_encode(['ok' => true, 'removed' => $removed]));
