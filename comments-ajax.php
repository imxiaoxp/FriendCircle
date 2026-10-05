<?php

/**
 * 首页内联评论接口
 *
 * POST /usr/themes/FriendCircle/comments-ajax.php
 *
 * GET  ?cid=<文章ID>&permalink=<路由路径>
 *      返回评论列表片段：{"list": "<html>"}
 * POST cid=<文章ID>&permalink=<路由路径>&type=comment&_=token
 *      游客另带 author/mail（可选 url），登录用户自动取当前身份
 *      返回 {"success":1,"total":N,"list":"<html>"} 或 {"error":"..."}
 *      待审核时返回 {"success":1,"waiting":1}
 *
 * 说明：POST 复用 Typecho 原生 Feedback 管线（校验、反垃圾 token、审核、插件钩子），
 * 通过 Response 沙箱拦截其成功后的 302 重定向，改为返回 JSON。
 */

$config = dirname(dirname(dirname(__DIR__))) . '/config.inc.php';
if (!file_exists($config)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Config not found']));
}
require_once $config;

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/functions.php';

$db = \Typecho\Db::get();
$isPost = ('POST' === ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$cid = (int) ($isPost ? ($_POST['cid'] ?? 0) : ($_GET['cid'] ?? 0));
$permalink = trim((string) ($isPost ? ($_POST['permalink'] ?? '') : ($_GET['permalink'] ?? '')));
if ($cid <= 0 || '' === $permalink || !preg_match('#^[\w\-./]+$#', $permalink)) {
    exit(json_encode(['error' => '参数错误']));
}

/**
 * 统一 Cookie 前缀：Typecho 以 md5(rootUrl) 作为 Cookie 键名前缀，而 rootUrl 按当前请求
 * 探测（独立端点的 SCRIPT_NAME 会带主题路径导致前缀与前台不一致）。在 Init 引导前按
 * 前台同款规则定义 __TYPECHO_ROOT_URL__，保证登录态与访客 Cookie 两端可互通。
 */
$rootFs = str_replace('\\', '/', (string) realpath(__TYPECHO_ROOT_DIR__));
$dirFs = str_replace('\\', '/', __DIR__);
$themeUrlPart = ('' !== $rootFs && 0 === strpos($dirFs, $rootFs))
    ? substr($dirFs, strlen($rootFs))
    : '';
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptTail = '' !== $themeUrlPart ? $themeUrlPart . '/comments-ajax.php' : '';
$siteBase = ('' !== $scriptTail && '' !== $scriptName
    && strlen($scriptName) > strlen($scriptTail)
    && substr($scriptName, -strlen($scriptTail)) === $scriptTail)
    ? substr($scriptName, 0, -strlen($scriptTail))
    : '';
if (!defined('__TYPECHO_ROOT_URL__')) {
    define('__TYPECHO_ROOT_URL__', \Typecho\Request::getInstance()->getUrlPrefix() . rtrim($siteBase, '/'));
}

// 完整引导（路由表、插件钩子、语言包、登录会话）
\Widget\Init::alloc();

/** 校验内容存在且允许评论 */
$content = $db->fetchRow($db->select('cid', 'type', 'status', 'password')
    ->from('table.contents')
    ->where('cid = ?', $cid));
if (!$content || !in_array($content['type'], ['post', 'page'], true) || 'publish' !== $content['status']) {
    exit(json_encode(['error' => '内容不存在']));
}

if (!$isPost) {
    /** GET：返回评论片段（listall=1 时返回全部，详情页用） */
    $total = fcCommentsTotal($cid);
    $listAll = ('1' === ($_GET['listall'] ?? ''));
    exit(json_encode(['list' => fcInlineCommentsHtml($cid, $permalink, $total, $listAll ? $total : null)]));
}

/** POST：走原生 Feedback 管线，沙箱拦截重定向 */
$before = time();
$ip = \Typecho\Request::getInstance()->getIp();

$response = \Typecho\Response::getInstance();
$response->beginSandbox();
$caught = null;
try {
    \Widget\Feedback::alloc(['checkReferer' => 'false'])->action();
} catch (\Throwable $e) {
    $caught = $e;
}
$response->endSandbox();

// Terminal = Feedback 正常结束（插入成功 redirect 或反垃圾失败 goBack）
if (null !== $caught && !($caught instanceof \Typecho\Widget\Terminal)) {
    exit(json_encode(['error' => $caught->getMessage()]));
}

/** 按文章 + IP + 提交时刻判定是否真实入库 */
$row = $db->fetchRow($db->select('coid', 'status', 'author', 'mail', 'url', 'authorId')
    ->from('table.comments')
    ->where('cid = ?', $cid)
    ->where('ip = ?', $ip)
    ->where('created >= ?', $before)
    ->order('coid', \Typecho\Db::SORT_DESC)
    ->limit(1));

if (!$row) {
    exit(json_encode(['error' => '评论提交失败，请刷新页面后重试']));
}

/** 补发访客信息 Cookie（沙箱拦截了 Feedback 的原生 Set-Cookie） */
if (!\Widget\User::alloc()->hasLogin()) {
    $expire = time() + 30 * 24 * 3600;
    $remembers = [
        '__typecho_remember_author' => (string) $row['author'],
        '__typecho_remember_mail'   => (string) $row['mail'],
        '__typecho_remember_url'    => (string) $row['url'],
    ];
    foreach ($remembers as $key => $value) {
        setrawcookie(
            \Typecho\Cookie::getPrefix() . $key,
            rawurlencode($value),
            $expire,
            \Typecho\Cookie::getPath(),
            \Typecho\Cookie::getDomain(),
            \Typecho\Cookie::getSecure(),
            true
        );
    }
}

$total = fcCommentsTotal($cid);
$listAll = ('1' === ($_POST['listall'] ?? ''));

if ('approved' !== $row['status']) {
    exit(json_encode([
        'success' => true,
        'waiting' => true,
        'total'   => $total,
        'avatar'  => fcMailAvatar((string) $row['mail'], Helper::options()),
    ]));
}

exit(json_encode([
    'success' => true,
    'coid'    => (int) $row['coid'],
    'total'   => $total,
    'avatar'  => fcMailAvatar((string) $row['mail'], Helper::options()),
    'list'    => fcInlineCommentsHtml($cid, $permalink, $total, $listAll ? $total : null),
]));
