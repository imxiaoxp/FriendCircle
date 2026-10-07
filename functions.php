<?php

/**
 * FriendCircle - 仿微信朋友圈 Typecho 主题
 *
 * @package FriendCircle
 * @author Xiao
 * @version 1.1.0
 * @link https://xiao.cn.mt
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/* 主题版本号：控制台横幅显示，与 index.php 头注释 @version 保持一致 */
if (!defined('FC_VERSION')) {
    define('FC_VERSION', '1.1.0');
}

// 评论渲染与查询函数集（fcCommentToken / fcInlineCommentsHtml / fcInlineCommentForm 等）
require_once __DIR__ . '/comments.php';

/**
 * 主题配置
 *
 * @param Typecho_Widget_Helper_Form $form
 */
function themeConfig($form)
{
    $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
        'fcCoverImage',
        null,
        'assets/img/cover.jpg',
        _t('封面图片 / 视频 URL'),
        _t('顶部封面大图地址，填 mp4/webm 等视频地址时作为静音循环背景视频播放（不受媒体互斥影响），留空时显示纯色渐变')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
        'fcCoverType',
        array('auto' => _t('自动识别'), 'video' => _t('视频'), 'image' => _t('图片')),
        'auto',
        _t('封面类型'),
        _t('地址为无扩展名的跳转链接（如 302 到视频文件）时选「视频」强制按视频背景播放；自动识别按地址扩展名判断')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
        'fcAvatarImage',
        null,
        'assets/img/avatar.svg',
        _t('头像 URL 或邮箱'),
        _t('填写图片地址；也可以填写邮箱地址（按下方头像源获取）')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
        'fcAvatarSource',
        array(
            'lty'       => _t('lty'),
            'cravatar'  => _t('cravatar'),
            'weavatar'  => _t('weavatar'),
            'loli'      => _t('loli'),
            'gravatar'  => _t('gravatar'),
        ),
        'lty',
        _t('头像源'),
        _t('当「头像 URL 或邮箱」填的是邮箱时生效')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
        'fcNickname',
        null,
        '',
        _t('昵称'),
        _t('显示在封面下方，留空使用站点标题')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
        'fcSignature',
        null,
        '',
        _t('个性签名'),
        _t('显示在头像下方，可留空')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
        'fcDarkMode',
        array(
            'auto'  => _t('跟随系统并允许切换'),
            'light' => _t('仅亮色'),
            'dark'  => _t('仅暗色'),
        ),
        'auto',
        _t('暗色模式'),
        _t('跟随系统时访客可手动切换并记忆偏好')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
        'fcPjax',
        array(
            '1' => _t('开启'),
            '0' => _t('关闭'),
        ),
        '0',
        _t('PJAX 无刷新跳转'),
        _t('站内点击链接不整页刷新，切换后自动重载 APlayer / VideoCollector 播放器与代码高亮；背景音乐跨页持续播放')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
        'fcPjaxReload',
        null,
        '',
        _t('PJAX 自定义重载函数'),
        _t('每行一条 JS 语句，PJAX 切页完成后在内置 loadMeting / initVideoCollectors 之后依次执行，用于重载其它插件的播放器或组件；空行与 // 开头的注释行会跳过，留空则不执行')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
        'fcInlineCommentsNum',
        null,
        '3',
        _t('动态内联评论数'),
        _t('首页每条动态下方展示的最新评论条数，默认 3')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
        'fcBgmMusic',
        null,
        '',
        _t('背景音乐'),
        _t('每行一条，格式：音乐地址|歌名（歌名可省略，自动取文件名）。保存后顶栏显示音乐播放器')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
        'fcFriendLinks',
        null,
        '',
        _t('友情链接'),
        _t('每行一条，格式：名称|地址|头像地址（头像可省略，显示默认图）。保存后顶栏显示友链入口')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
        'fcCounterEnabled',
        array(
            '0' => _t('关闭'),
            '1' => _t('开启'),
        ),
        '0',
        _t('访问统计'),
        _t('开启后显示今日/昨日/总访问统计，数据以 SQL 写入站点数据库（首次访问自动建表）')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
        'fcCounterPosition',
        array(
            'bottom-right' => _t('悬浮右下'),
            'bottom-left'  => _t('悬浮左下'),
            'top-right'    => _t('悬浮右上'),
            'top-left'     => _t('悬浮左上'),
        ),
        'bottom-right',
        _t('统计插入位置'),
        _t('固定在视口对应角落（右下会自动避开悬浮按钮组）')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
        'fcCounterRefresh',
        array(
            '0'   => _t('关闭'),
            '10'  => _t('每 10 秒'),
            '30'  => _t('每 30 秒'),
            '60'  => _t('每 60 秒'),
            '300' => _t('每 5 分钟'),
        ),
        '0',
        _t('统计自动刷新'),
        _t('开启后页面定时通过轻量 JSON 接口拉取最新访问数并滚动更新（轮询本身不计入访问）')
    ));

    $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
        'fcCounterTimezone',
        array(
            '8'   => _t('北京时间 UTC+8'),
            '9'   => _t('东京/首尔 UTC+9'),
            '7'   => _t('曼谷/河内 UTC+7'),
            '5.5' => _t('印度 UTC+5.5'),
            '4'   => _t('迪拜 UTC+4'),
            '3'   => _t('莫斯科 UTC+3'),
            '2'   => _t('雅典/开罗 UTC+2'),
            '1'   => _t('柏林/巴黎 UTC+1'),
            '0'   => _t('世界协调时 UTC±0'),
            '-3'  => _t('圣保罗 UTC-3'),
            '-5'  => _t('纽约 UTC-5'),
            '-8'  => _t('洛杉矶 UTC-8'),
        ),
        '8',
        _t('统计时区'),
        _t('访问统计的"今天/昨天"按此时区翻转，默认北京时间（不受服务器时区影响）')
    ));
}

/**
 * 主题初始化：注册访问统计钩子
 *
 * @param mixed $archive
 */
function themeInit($archive)
{
    // 注意：themeInit 运行在普通函数作用域，$archive->options 是 protected 属性，
    // 从外部访问会经 __get 魔术方法返回 NULL，必须用 Helper::options() 获取共享配置
    $options = Helper::options();
    if ($options->fcCounterEnabled == '1') {
        // 轻量 JSON 统计接口：在 header 钩子注册前拦截并退出，轮询本身不计入访问
        if (isset($_GET['counter_stats']) && 'json' === $_GET['counter_stats']) {
            counterOutputStatsJson();
        }
        \Typecho\Plugin::factory('Widget\Archive')->header = 'recordCounterVisit';
    }
}

/**
 * 向文章编辑页追加自定义字段
 *
 * @param Typecho_Widget_Helper_Layout $layout
 */
function themeFields(Typecho_Widget_Helper_Layout $layout)
{
    $location = new Typecho_Widget_Helper_Form_Element_Text(
        'location',
        null,
        null,
        _t('所在位置'),
        _t('显示在动态内容下方，如：江西·赣州')
    );
    $layout->addItem($location);
}

/**
 * 读取主题配置，未设置时返回默认值
 *
 * @param mixed $options
 * @param string $name
 * @param string $default
 * @return string
 */
function fcOption($options, $name, $default = '')
{
    // 注意：Widget 未实现 __isset，isset() 对魔术属性恒为 false，必须用 null 合并
    $value = $options->{$name} ?? null;
    return ($value === null || $value === '') ? $default : (string) $value;
}

/**
 * 按邮箱获取头像 URL
 *
 * @param string $mail
 * @param mixed $options
 * @return string
 */
function fcMailAvatar($mail, $options)
{
    static $cache = array();
    if (isset($cache[$mail])) {
        return $cache[$mail];
    }
    $hash = md5($mail);
    switch (fcOption($options, 'fcAvatarSource', 'lty')) {
        case 'gravatar':
            $url = 'https://gravatar.com/avatar/' . $hash . '?s=128&r=X&d=mp';
            break;
        case 'cravatar':
            $url = 'https://cravatar.com/avatar/' . $hash . '?s=128&r=X&d=mp';
            break;
        case 'weavatar':
            $url = 'https://weavatar.com/avatar/' . $hash . '?s=128&r=X&d=mp';
            break;
        case 'loli':
            $url = 'https://gravatar.loli.net/avatar/' . $hash . '?s=128&r=X&d=mp';
            break;
        default:
            $url = 'https://api.lty.fun/avatar/' . $hash . '?s=128&r=X';
    }
    $cache[$mail] = $url;
    return $url;
}

/**
 * 解析主题头像配置：邮箱走头像源，其余按地址处理，空值回落主题默认头像
 *
 * @param mixed $options
 * @return string
 */
function fcHeaderAvatar($options)
{
    $value = trim((string) ($options->fcAvatarImage ?? ''));
    if ('' === $value) {
        return $options->themeUrl . '/assets/img/avatar.svg';
    }
    if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return fcMailAvatar($value, $options);
    }
    if (preg_match('#^https?://#i', $value) || 0 === strpos($value, '//')) {
        return $value;
    }
    return $options->themeUrl . '/' . ltrim($value, '/');
}

/**
 * 解析封面图配置，空值返回空字符串（前端显示渐变兜底）
 *
 * @param mixed $options
 * @return string
 */
function fcCoverImage($options)
{
    $value = fcOption($options, 'fcCoverImage', 'assets/img/cover.jpg');
    if ('' === $value) {
        return '';
    }
    if (preg_match('#^https?://#i', $value) || 0 === strpos($value, '//')) {
        return $value;
    }
    return $options->themeUrl . '/' . ltrim($value, '/');
}

/**
 * 提取文章内容中的图片地址（最多 limit 张）
 *
 * @param string $content
 * @param int $limit
 * @return array
 */
function fcImages($content, $limit = 9)
{
    preg_match_all('/<img[^>]*\ssrc="([^"]*)"[^>]*>/i', (string) $content, $matches);
    $images = isset($matches[1]) ? $matches[1] : array();
    return array_slice($images, 0, $limit);
}

/**
 * 解析多行竖线分隔配置（背景音乐 / 友情链接），返回数组的数组
 *
 * @param string $value 原始文本，每行一条，字段用 | 分隔
 * @param int $parts 字段数上限
 * @return array
 */
function fcParseLines($value, $parts = 3)
{
    $list = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) $value) as $line) {
        $line = trim($line);
        if ('' === $line) {
            continue;
        }
        $fields = array_map('trim', explode('|', $line, $parts));
        if ('' === $fields[0]) {
            continue;
        }
        $list[] = $fields;
    }
    return $list;
}

/**
 * 背景音乐列表：每行「音乐地址|歌名」，歌名缺省取 URL 文件名
 *
 * @param mixed $options
 * @return array array(array('url' =>, 'name' =>), ...)
 */
function fcBgmSongs($options)
{
    $songs = array();
    foreach (fcParseLines($options->fcBgmMusic ?? '', 2) as $fields) {
        $url = $fields[0];
        $name = isset($fields[1]) && '' !== $fields[1]
            ? $fields[1]
            : preg_replace('/\.[a-z0-9]+$/i', '', rawurldecode(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_FILENAME)));
        $songs[] = array('url' => $url, 'name' => $name);
    }
    return $songs;
}

/**
 * 友情链接列表：每行「名称|地址|头像」，头像缺省用主题默认图
 *
 * @param mixed $options
 * @return array array(array('name' =>, 'url' =>, 'avatar' =>), ...)
 */
function fcFriendList($options)
{
    $list = array();
    foreach (fcParseLines($options->fcFriendLinks ?? '', 3) as $fields) {
        $name = $fields[0];
        $url = $fields[1] ?? '';
        $avatar = isset($fields[2]) ? $fields[2] : '';

        // 容错：名称与地址漏写分隔符粘连成一段（如「名称https://example.com|头像」），
        // 从第一段剥离出 URL，原第二段顺延为头像
        if (preg_match('#^(.*?)(https?://\S+)$#i', $name, $m)) {
            $name = trim($m[1]);
            if ('' === $avatar && '' !== $url && preg_match('#^https?://#i', $url)) {
                $avatar = $url;
            }
            $url = $m[2];
        }

        if ('' === $url || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        // 剥离后名称为空（如整行只写了地址）时用域名兜底
        if ('' === $name) {
            $name = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        }
        $list[] = array(
            'name'   => $name,
            'url'    => $url,
            'avatar' => '' !== $avatar && preg_match('#^https?://#i', $avatar) ? $avatar : '',
        );
    }
    return $list;
}

/**
 * 从已渲染的文章内容中提取第一个指定 div 容器的完整 HTML 块
 *
 * 按 div 开闭标签配对扫描，得到结构完整的第一个容器块
 * （getPlayerHtml / getAplayerHtml 共用实现，均依赖返回与原文
 * 完全一致的字符串供正文清理使用）。
 *
 * @param string $content 已渲染的文章内容
 * @param string $marker 容器开头的唯一标识
 * @return string 容器 HTML，不存在时返回空字符串
 */
function extractDivBlock($content, $marker)
{
    $pos = strpos($content, $marker);
    if ($pos === false) {
        return '';
    }

    $depth = 0;
    $offset = $pos;
    $len = strlen($content);
    while ($offset < $len) {
        $open = strpos($content, '<div', $offset);
        $close = strpos($content, '</div>', $offset);
        if ($close === false) {
            break;
        }
        if ($open !== false && $open < $close) {
            $depth++;
            $offset = $open + 4;
        } else {
            $depth--;
            $offset = $close + 6;
            if ($depth === 0) {
                return substr($content, $pos, $offset - $pos);
            }
        }
    }
    return '';
}

/**
 * 提取第一个视频播放器（VideoCollector）HTML 块
 *
 * 内容经过插件链后 [play] 短代码已转换为 .play-container 播放器 HTML。
 *
 * @param string $content 已渲染的文章内容
 * @return string 播放器 HTML，不存在时返回空字符串
 */
function getPlayerHtml($content)
{
    return extractDivBlock($content, '<div class="play-container"');
}

/**
 * 首页展示用播放器：去掉分集切换按钮，分集请进入详情页操作
 *
 * @param string $content 已渲染的文章内容
 * @return string 播放器 HTML，不存在时返回空字符串
 */
function getPostPlayerHtml($content)
{
    $player = getPlayerHtml($content);
    if ('' === $player) {
        return '';
    }
    // video-tabs 内只有 span，无嵌套 div，非贪婪匹配可安全剔除
    return preg_replace('/<div class="video-tabs">.*?<\/div>/s', '', $player);
}

/**
 * 提取第一个音乐播放器（APlayer/Meting）HTML 块
 *
 * 内容经过插件链后 [Meting] 短代码已转换为 .aplayer 播放器 div
 * （data-* 参数由插件前端 Meting.min.js 初始化），供动态卡片直接展示。
 *
 * @param string $content 已渲染的文章内容
 * @return string 播放器 HTML，不存在时返回空字符串
 */
function getAplayerHtml($content)
{
    return extractDivBlock($content, '<div class="aplayer"');
}

/**
 * 链接统一新窗口：未带 target 的 <a> 补 _blank 与 rel（防 opener 泄露）
 *
 * @param string $html 已渲染的 HTML 内容
 * @return string
 */
function fcLinkBlank($html)
{
    if (false === strpos((string) $html, '<a')) {
        return $html;
    }
    return preg_replace_callback('/<a\s([^>]*)>/i', function ($m) {
        $attrs = $m[1];
        if (false === stripos($attrs, 'target=')) {
            $attrs .= ' target="_blank" rel="noopener noreferrer nofollow"';
        }
        return '<a ' . $attrs . '>';
    }, $html);
}

/**
 * 动态正文：剔除脚本/播放器等块级内容，仅保留行内文字式样
 *
 * @param string $content 已渲染的文章内容
 * @param bool $keepBlocks 详情页传 true：保留 <pre> 代码块供 Prism 高亮
 * @return string
 */
function fcMomentText($content, $keepBlocks = false)
{
    $html = (string) $content;

    // 脚本、样式、行内框架、媒体元素整体剔除
    $html = preg_replace('#<(script|style|iframe|object|embed|video|audio)\b[^>]*>.*?</\1\s*>#is', '', $html);
    $html = preg_replace('#<(script|style|iframe|object|embed|video|audio|input|button|form)\b[^>]*/?>#is', '', $html);

    // 块级标签结尾转为换行，保留段落感（详情页保留 <pre> 代码块原样输出）
    $blocks = $keepBlocks ? 'p|div|h[1-6]|li|blockquote|section|article' : 'p|div|h[1-6]|li|blockquote|pre|section|article';
    $html = preg_replace('#</(?:' . $blocks . ')>#i', '<br>', $html);

    // 仅保留行内文字式样标签（详情页追加 pre；strip_tags 对白名单标签保留原属性）
    $allowed = '<b><strong><i><em><u><s><del><strike><code><mark><span><a><sub><sup><small><br>';
    if ($keepBlocks) {
        $allowed .= '<pre>';
    }
    $html = trim(strip_tags($html, $allowed));

    // 链接统一新窗口
    $html = fcLinkBlank($html);

    // 去掉首尾多余换行
    $html = preg_replace('#^(?:\s*<br>\s*)+#', '', $html);
    $html = preg_replace('#(?:\s*<br>\s*)+$#', '', $html);

    return $html;
}

/**
 * 主题文件地址（根相对路径，避免跨域）
 *
 * @param string $file 主题根目录下的文件名
 * @return string
 */
function fcActionUrl($file)
{
    $root = rtrim(str_replace('\\', '/', realpath(__TYPECHO_ROOT_DIR__)), '/');
    $path = $root . '/usr/themes/' . Helper::options()->theme . '/' . $file;
    $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/')
        : '';
    if ('' !== $docRoot && 0 === strpos($path, $docRoot)) {
        return substr($path, strlen($docRoot));
    }
    return $path;
}

/**
 * 点赞接口地址
 *
 * @return string
 */
function fcLikeUrl()
{
    return fcActionUrl('like.php');
}

/**
 * 卡片社交数据共享缓存（点赞数 / 评论总数 / 最新评论）
 *
 * 返回引用：fcAgreeNum / fcCommentsTotal / fcLatestComments 未命中时回源写回，
 * 列表页由 fcPrefetchSocialData 以分组查询一次填充，消除每卡片 3 条查询的 N+1
 *
 * @return array
 */
function &fcSocialCache()
{
    static $cache = array('agree' => array(), 'total' => array(), 'comments' => array());
    return $cache;
}

/**
 * 列表页批量预取卡片社交数据（点赞数 / 评论总数 / 最新评论）
 *
 * 3 条分组查询（IN 本页全部 cid）替代每卡片 3 条独立查询；
 * 仅填充共享缓存，不改变任何渲染行为
 *
 * @param array $cids 本页动态 cid 列表
 */
function fcPrefetchSocialData(array $cids)
{
    $cids = array_values(array_unique(array_map('intval', $cids)));
    if (!$cids) {
        return;
    }
    $db = \Typecho\Db::get();
    $cache = &fcSocialCache();

    // 点赞数（agree 自定义字段）：缺失的 cid 先填 0，再由查询结果覆盖
    $missing = array();
    foreach ($cids as $cid) {
        if (!isset($cache['agree'][$cid])) {
            $missing[] = $cid;
        }
    }
    if ($missing) {
        foreach ($missing as $cid) {
            $cache['agree'][$cid] = 0;
        }
        $rows = $db->fetchAll(
            $db->select('cid', 'str_value')->from('table.fields')
                ->where('cid IN ?', $missing)->where('name = ?', 'agree')
        );
        foreach ($rows as $row) {
            $cache['agree'][(int) $row['cid']] = (int) $row['str_value'];
        }
    }

    // 评论总数（仅统计已通过）
    $missing = array();
    foreach ($cids as $cid) {
        if (!isset($cache['total'][$cid])) {
            $missing[] = $cid;
        }
    }
    if ($missing) {
        foreach ($missing as $cid) {
            $cache['total'][$cid] = 0;
        }
        $rows = $db->fetchAll(
            $db->select('cid', 'COUNT(coid) AS num')->from('table.comments')
                ->where('cid IN ?', $missing)->where('status = ?', 'approved')
                ->group('cid')
        );
        foreach ($rows as $row) {
            $cache['total'][(int) $row['cid']] = (int) $row['num'];
        }
    }

    // 最新评论：一次取回本页全部已通过评论，PHP 内按 cid 切出最新 N 条
    // （N 与列表页渲染口径一致，见 fcInlineCommentsHtml 的 fcInlineCommentsNum）
    $limit = max(1, (int) fcOption(Helper::options(), 'fcInlineCommentsNum', 3));
    $rows = $db->fetchAll(
        $db->select('coid', 'cid', 'author', 'url', 'mail', 'text', 'created', 'parent')
            ->from('table.comments')
            ->where('cid IN ?', $cids)
            ->where('status = ?', 'approved')
            ->order('created', \Typecho\Db::SORT_DESC)
    );
    $picked = array();
    foreach ($rows as $row) {
        $cid = (int) $row['cid'];
        if (count($picked[$cid] ?? array()) < $limit) {
            $picked[$cid][] = $row;
        }
    }
    foreach ($picked as $cid => $list) {
        $cache['comments'][$cid] = array(
            'rows'  => array_reverse($list),
            'limit' => $limit,
        );
    }
}

/**
 * 获取文章点赞数（agree 自定义字段）
 *
 * @param int $cid
 * @return int
 */
function fcAgreeNum($cid)
{
    $cid = (int) $cid;
    $cache = &fcSocialCache();
    if (!isset($cache['agree'][$cid])) {
        $field = \Typecho\Db::get()->fetchRow(
            \Typecho\Db::get()->select('str_value')->from('table.fields')
                ->where('cid = ?', $cid)->where('name = ?', 'agree')
        );
        $cache['agree'][$cid] = $field ? (int) $field['str_value'] : 0;
    }
    return $cache['agree'][$cid];
}

/**
 * 渲染一条动态（首页信息流与详情页共用骨架）
 *
 * @param Widget\Archive $archive 文章组件
 * @param bool $detail 是否详情页（详情页不折叠正文、完整评论列表）
 */
function fcMomentHtml($archive, $detail = false)
{
    // 注意：$archive->options 是 protected 属性，函数作用域经 __get 会返回 NULL，
    // 必须用 Helper::options() 获取共享配置
    $options = Helper::options();
    $author = htmlspecialchars($archive->author->name, ENT_QUOTES, 'UTF-8');
    $avatar = fcMailAvatar($archive->author->mail, $options);
    // 作者归档页链接（点击头像/昵称查看该作者全部文章）
    $authorUrl = htmlspecialchars($archive->author->permalink, ENT_QUOTES, 'UTF-8');
    // 播放器块（VideoCollector/APlayer）先从正文剔除，再独立输出到正文后，
    // 避免 strip_tags 剥掉 div 标签时分集标题、影片名等内文泄漏进正文
    // 详情页按原 Typecho 渲染：图片、代码块、播放器插件输出全部保留在正文原位
    $fcRaw = (string) $archive->content;
    if ($detail) {
        $text = fcLinkBlank($fcRaw);
    } else {
        $fcVideoFull = getPlayerHtml($fcRaw);
        $fcAudio = getAplayerHtml($fcRaw);
        $fcVideo = getPostPlayerHtml($fcRaw);
        $fcClean = $fcRaw;
        if ('' !== $fcVideoFull) {
            $fcClean = str_replace($fcVideoFull, '', $fcClean);
        }
        if ('' !== $fcAudio) {
            $fcClean = str_replace($fcAudio, '', $fcClean);
        }
        $text = fcMomentText($fcClean, true);
    }
    $images = $detail ? array() : fcImages($archive->content);
    $location = trim((string) ($archive->fields->location ?? ''));
    // $archive->date('Y-m-d') 是输出型魔术调用（直接 echo 且返回 null），
    // 必须取 Date 对象 format 返回值，否则日期裸露在列表外、卡片内时间为空
    $date = $archive->date->format('Y-m-d');
    $permalink = $archive->permalink;
    $cid = $archive->cid;
    $likeNum = fcAgreeNum($cid);
    $commentsNum = (int) $archive->commentsNum;
    $likeUrl = htmlspecialchars(fcLikeUrl(), ENT_QUOTES, 'UTF-8');
    ?>
    <article class="fc-card" data-cid="<?php echo $cid; ?>">
        <div class="fc-card-side">
            <div class="fc-card-avatar">
                <a href="<?php echo $authorUrl; ?>" title="<?php echo $author; ?>"><img
                        src="<?php echo htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo $author; ?>" loading="lazy"></a>
            </div>
            <?php if ($detail): ?>
            <a class="fc-back-float" href="<?php echo htmlspecialchars((string) $options->rootUrl . '/', ENT_QUOTES, 'UTF-8'); ?>"
                title="<?php _e('返回'); ?>" aria-label="<?php _e('返回'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6" />
                </svg>
            </a>
            <?php endif; ?>
        </div>
        <div class="fc-card-body">
            <div class="fc-card-head">
                <div class="fc-card-name"><p><a href="<?php echo $authorUrl; ?>"><?php echo $author; ?></a></p></div>
                <<?php echo $detail ? 'div class="fc-text fc-detail-text"' : 'div class="fc-text fc-fold"'; ?>><?php echo $text; ?></div>
                <?php if (!$detail): ?>
                    <a class="fc-fulltext" href="<?php echo $permalink; ?>" hidden>全文</a>
                <?php endif; ?>
            </div>

            <?php /* 播放器仅首页独立输出（已从正文剔除）；详情页在正文原位，随 content 原样渲染 */ ?>
            <?php if (!$detail): ?>
                <?php echo $fcVideo; ?>
                <?php echo $fcAudio; ?>
            <?php endif; ?>

            <?php if ($images): ?>
                <div class="fc-gallery">
                    <?php foreach ($images as $image): ?>
                        <div class="fc-photo">
                            <img src="<?php echo htmlspecialchars($image, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ('' !== $location): ?>
                <div class="fc-location">
                    <a><?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            <?php endif; ?>

            <div class="fc-card-footer">
                <div class="fc-card-time">
                    <a href="<?php echo $permalink; ?>"><span><?php echo $date; ?></span></a>
                </div>
                <div class="fc-card-actions">
                    <!-- 赞/评论按钮默认收起，点击「两个点」展开（首页与详情页一致） -->
                    <div class="fc-action-capsule">
                        <button class="pill-btn like-btn" type="button" data-cid="<?php echo $cid; ?>"
                            data-url="<?php echo $likeUrl; ?>" aria-label="赞">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
                            </svg>
                            <span class="pill-text"><?php echo _t('赞'); ?></span>
                        </button>
                        <p></p>
                        <button class="pill-btn comment-btn" type="button" data-cid="<?php echo $cid; ?>"
                            aria-label="评论">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                            </svg>
                            <span class="pill-text"><?php echo _t('评论'); ?></span>
                        </button>
                    </div>
                    <!-- 两个点：点击展开/收起赞、评论按钮 -->
                    <div class="fc-action-dots" title="<?php echo _t('赞、评论'); ?>">
                        <p class="fc-action-icon"></p>
                        <p></p>
                    </div>
                </div>
            </div>

            <?php $showZanp = $likeNum > 0 || $commentsNum > 0; ?>
            <div class="fc-panel"<?php if ($detail): ?> id="comments"<?php endif; ?><?php if (!$showZanp): ?> hidden<?php endif; ?>>
                <div class="fc-likes"<?php if ($likeNum <= 0): ?> hidden<?php endif; ?>>
                    <div class="fc-likes-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor" stroke="none">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
                        </svg>
                    </div>
                    <ul class="fc-likes-list">
                        <li><span class="like-count"><?php echo $likeNum; ?></span>&nbsp;人点赞</li>
                    </ul>
                </div>

                <div class="fc-inline-list"><?php
                    // 详情页展示全部评论；首页按主题配置条数展示最新几条
                    echo fcInlineCommentsHtml($cid, $permalink, $commentsNum, $detail ? $commentsNum : null);
                ?></div>
                <?php echo fcInlineCommentForm($archive, $options, $detail); ?>
            </div>
        </div>
    </article>
    <?php
}

/* ==================== 访问统计 ==================== */

/**
 * 记录访问并缓存统计数据（挂载于 Widget\Archive:header 钩子，仅 HTML 页面触发）
 *
 * 数据以 SQL 写入站点数据库：
 *   {prefix}counter_visits —— 访客明细（站点+日期+访客指纹 唯一去重，仅保留 90 天）
 *   {prefix}counter_stats  —— 各站点总访问累计
 * 同一访客（IP+UA 指纹）同一天只计一次；常见爬虫 UA 不计入。
 * 统计结果存入 $GLOBALS 供 renderCounterBar() 渲染，失败时静默跳过不影响页面。
 *
 * @return void
 */
function recordCounterVisit()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        counterDoVisit();
    } catch (Exception $e) {
        // 表可能尚未创建：建表后重试一次，仍失败则放弃统计
        try {
            counterCreateTables();
            counterDoVisit();
        } catch (Exception $e) {
        }
    }
}

/**
 * 统计时区偏移小时数（后台主题设置 fcCounterTimezone，默认北京时间 UTC+8）
 *
 * @return float
 */
function counterTimezoneOffset()
{
    static $offset = null;
    if ($offset === null) {
        $value = null;
        try {
            $value = Helper::options()->fcCounterTimezone;
        } catch (Exception $e) {
        }
        // 未保存过设置时为 null，回落到北京时间；'0' 是合法的 UTC±0，不可当作空值
        $offset = ($value === null || $value === '') ? 8.0 : (float) $value;
    }
    return $offset;
}

/**
 * 按后台设置的统计时区计算日期键（默认北京时间 UTC+8，无夏令时）
 *
 * 统计的"今天/昨天"必须按所选时区翻转；海外主机 PHP 默认时区多为 UTC，
 * 直接 date('Y-m-d') 会导致日期在北京时间早上 8 点才翻转。
 *
 * @param int $offsetDays 相对今天的偏移天数（昨天为 -1）
 * @return string Y-m-d 格式日期
 */
function counterBeijingDate($offsetDays = 0)
{
    return gmdate('Y-m-d', time() + counterTimezoneOffset() * 3600 + $offsetDays * 86400);
}

/**
 * 执行一次访问记录与统计查询
 *
 * @return void
 * @throws Exception
 */
function counterDoVisit()
{
    $db = \Typecho\Db::get();
    $site = 'default';
    $today = counterBeijingDate();
    $yesterday = counterBeijingDate(-1);

    // 访客指纹：客户端 IP（取第一个合法 XFF）+ UA + 站点标识
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $candidate = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/bot|spider|crawler|slurp|curl|wget|python|headless|phantom/i', $ua)) {
        return;
    }
    $visitorHash = substr(hash('sha256', $ip . '|' . $ua . '|' . $site), 0, 16);

    $visitsTable = '`' . $db->getPrefix() . 'counter_visits`';
    $statsTable = '`' . $db->getPrefix() . 'counter_stats`';
    $now = time();

    // 唯一键去重：同访客同日仅插入一次；rowCount>0 表示今天首次来访
    // MySQL 用 INSERT IGNORE，SQLite 用 INSERT OR IGNORE
    $ignoreSyntax = stripos($db->getAdapterName(), 'sqlite') !== false ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
    $inserted = $db->query(
        "{$ignoreSyntax} {$visitsTable} (`site`, `date`, `visitor_hash`, `created_at`)
         VALUES ('{$site}', '{$today}', '{$visitorHash}', {$now})",
        \Typecho\Db::WRITE,
        \Typecho\Db::UPDATE
    );

    if ($inserted > 0) {
        // 累计总数；UPDATE 影响 0 行说明站点首次来访，插入初始行
        $updated = $db->query(
            "UPDATE {$statsTable} SET `total` = `total` + 1, `updated_at` = {$now} WHERE `site` = '{$site}'",
            \Typecho\Db::WRITE,
            \Typecho\Db::UPDATE
        );
        if (0 == $updated) {
            $db->query(
                "INSERT INTO {$statsTable} (`site`, `total`, `updated_at`) VALUES ('{$site}', 1, {$now})",
                \Typecho\Db::WRITE,
                \Typecho\Db::INSERT
            );
        }
    }

    // 约 10% 概率清理 90 天前明细
    if (mt_rand(1, 100) <= 10) {
        $cutoff = counterBeijingDate(-90);
        $db->query(
            "DELETE FROM {$visitsTable} WHERE `site` = '{$site}' AND `date` < '{$cutoff}'",
            \Typecho\Db::WRITE,
            \Typecho\Db::DELETE
        );
    }

    // 一条 SQL 同时统计今日/昨日
    $GLOBALS['fcCounterStats'] = counterQueryStats($db, $site);
}

/**
 * 查询当前统计数据（今日/昨日/近7天/近30天明细计数 + 累计总数）
 *
 * 近 N 天按自然日计，含今天（如近7天 = 今天往前共 7 个自然日）。
 *
 * @param \Typecho\Db $db 数据库对象
 * @param string $site 站点标识
 * @return array array('today' => int, 'yesterday' => int, 'week' => int, 'month' => int, 'total' => int)
 */
function counterQueryStats($db, $site)
{
    $today = counterBeijingDate();
    $yesterday = counterBeijingDate(-1);
    $weekStart = counterBeijingDate(-6);
    $monthStart = counterBeijingDate(-29);

    // 一次范围扫描同时算出 4 项明细计数（Y-m-d 字符串比较即日期比较）
    $row = $db->fetchRow(
        "SELECT COUNT(CASE WHEN `date` = '{$today}' THEN 1 END) AS `today_cnt`,
                COUNT(CASE WHEN `date` = '{$yesterday}' THEN 1 END) AS `yesterday_cnt`,
                COUNT(CASE WHEN `date` >= '{$weekStart}' THEN 1 END) AS `week_cnt`,
                COUNT(CASE WHEN `date` >= '{$monthStart}' THEN 1 END) AS `month_cnt`
         FROM `{$db->getPrefix()}counter_visits` WHERE `site` = '{$site}' AND `date` >= '{$monthStart}'"
    );
    $totalRow = $db->fetchRow(
        "SELECT `total` FROM `{$db->getPrefix()}counter_stats` WHERE `site` = '{$site}'"
    );

    return array(
        'today' => (int) ($row['today_cnt'] ?? 0),
        'yesterday' => (int) ($row['yesterday_cnt'] ?? 0),
        'week' => (int) ($row['week_cnt'] ?? 0),
        'month' => (int) ($row['month_cnt'] ?? 0),
        'total' => (int) ($totalRow['total'] ?? 0),
    );
}

/**
 * 输出访问统计 JSON（?counter_stats=json，供前端定时刷新拉取）
 *
 * 由 themeInit 在 header 钩子注册前调用并 exit，因此轮询请求不会执行页面渲染、
 * 也不会触发访问记录。表未创建或查询失败时返回全 0；带 no-store 避免 CDN 缓存旧值。
 *
 * @return void
 */
function counterOutputStatsJson()
{
    $stats = array('today' => 0, 'yesterday' => 0, 'total' => 0);
    try {
        $stats = counterQueryStats(\Typecho\Db::get(), 'default');
    } catch (Exception $e) {
    }

    @header('Content-Type: application/json; charset=UTF-8');
    @header('Cache-Control: no-store');
    echo json_encode($stats);
    exit;
}

/**
 * 创建访问统计所需数据表（MySQL / SQLite 双兼容）
 *
 * @return void
 * @throws Exception
 */
function counterCreateTables()
{
    $db = \Typecho\Db::get();
    $prefix = $db->getPrefix();
    $isSqlite = stripos($db->getAdapterName(), 'sqlite') !== false;

    if ($isSqlite) {
        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}counter_visits` (
            `vid` INTEGER PRIMARY KEY AUTOINCREMENT,
            `site` VARCHAR(64) NOT NULL DEFAULT '',
            `date` VARCHAR(10) NOT NULL DEFAULT '',
            `visitor_hash` VARCHAR(16) NOT NULL DEFAULT '',
            `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE (`site`, `date`, `visitor_hash`)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}counter_stats` (
            `site` VARCHAR(64) NOT NULL,
            `total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`site`)
        )");
        return;
    }

    $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}counter_visits` (
        `vid` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `site` VARCHAR(64) NOT NULL DEFAULT '',
        `date` VARCHAR(10) NOT NULL DEFAULT '',
        `visitor_hash` VARCHAR(16) NOT NULL DEFAULT '',
        `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`vid`),
        UNIQUE KEY `uk_site_date_visitor` (`site`, `date`, `visitor_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}counter_stats` (
        `site` VARCHAR(64) NOT NULL,
        `total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`site`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * 渲染访问统计条 HTML（数字由 app.js 读取 data-value 后做滚动动画；
 * 明暗配色由主题 CSS 按 html[data-theme="dark"] 自动切换）
 *
 * @param string $position 插入位置：bottom-right / bottom-left / top-right / top-left
 * @return string 统计条 HTML
 */
function renderCounterBar($position = 'bottom-right')
{
    $stats = $GLOBALS['fcCounterStats']
        ?? array('today' => 0, 'yesterday' => 0, 'week' => 0, 'month' => 0, 'total' => 0);

    $class = 'fc-vc-bar fc-vc-' . htmlspecialchars($position, ENT_QUOTES, 'UTF-8');

    // 自动刷新：间隔与 JSON 接口地址由 app.js 读取
    $refresh = (int) Helper::options()->fcCounterRefresh;
    $refreshAttr = '';
    if ($refresh > 0) {
        $endpoint = rtrim(Helper::options()->siteUrl, '/') . '/?counter_stats=json';
        $refreshAttr = ' data-refresh="' . $refresh . '" data-endpoint="' . htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') . '"';
    }

    // 近 7 天/近 30 天在鼠标悬停气泡中展示；数字由 app.js 自动构建滚动动画并参与轮询刷新
    return '<div class="' . $class . '" id="fc-vc-counter"' . $refreshAttr . '>'
        . '<span class="fc-vc-dot"></span>'
        . '<div class="fc-vc-item"><span class="fc-vc-label">今日访问</span><span class="fc-vc-number" data-key="today" data-value="' . (int) $stats['today'] . '"></span></div>'
        . '<div class="fc-vc-item"><span class="fc-vc-label">昨日访问</span><span class="fc-vc-number" data-key="yesterday" data-value="' . (int) $stats['yesterday'] . '"></span></div>'
        . '<div class="fc-vc-item"><span class="fc-vc-label">总访问</span><span class="fc-vc-number" data-key="total" data-value="' . (int) $stats['total'] . '"></span></div>'
        . '<div class="fc-vc-pop" aria-hidden="true">'
        . '<div class="fc-vc-pop-row"><span class="fc-vc-label">近 7 天</span><span class="fc-vc-number" data-key="week" data-value="' . (int) ($stats['week'] ?? 0) . '"></span></div>'
        . '<div class="fc-vc-pop-row"><span class="fc-vc-label">近 30 天</span><span class="fc-vc-number" data-key="month" data-value="' . (int) ($stats['month'] ?? 0) . '"></span></div>'
        . '</div>'
        . '</div>';
}
