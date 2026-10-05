<?php

/**
 * 评论渲染与查询函数集
 *
 * 含评论 token、OwO 表情渲染、评论列表 / 输入框渲染，
 * 由 functions.php 顶部 require_once 加载——首页卡片、详情页与 comments-ajax.php
 * 接口共用同一份实现。
 *
 * @package FriendCircle
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 评论接口地址
 *
 * @return string
 */
function fcCommentsUrl()
{
    return fcActionUrl('comments-ajax.php');
}

/**
 * 反垃圾 token（与 Feedback::protect 的校验规则一致：md5(密钥 & 提交页 URL)）
 *
 * @return string
 */
function fcCommentToken()
{
    return \Widget\Security::alloc()->getToken(\Typecho\Request::getInstance()->getRequestUrl());
}

/**
 * 获取动态最新评论（倒序取 N 条后翻回正序展示）
 *
 * @param int $cid
 * @param int $num
 * @return array
 */
function fcLatestComments($cid, $num = 3)
{
    $cid = (int) $cid;
    $num = max(1, (int) $num);
    $cache = &fcSocialCache();
    if (isset($cache['comments'][$cid])) {
        $entry = $cache['comments'][$cid];
        // 缓存覆盖判定：已抓取条数足够（DESC 抓取必含最新 num 条），
        // 或缓存行数小于抓取上限（该文评论已全部取出）
        if ($num <= $entry['limit'] || count($entry['rows']) < $entry['limit']) {
            return array_slice($entry['rows'], -$num);
        }
    }
    $db = \Typecho\Db::get();
    $rows = $db->fetchAll(
        $db->select('coid', 'author', 'url', 'mail', 'text', 'created', 'parent')
            ->from('table.comments')
            ->where('cid = ?', $cid)
            ->where('status = ?', 'approved')
            ->order('created', \Typecho\Db::SORT_DESC)
            ->limit($num)
    );
    $rows = array_reverse($rows);
    $cache['comments'][$cid] = array('rows' => $rows, 'limit' => $num);
    return $rows;
}

/**
 * OwO 表情渲染：把评论中的 {:表情码:} 还原为主题根目录 OwO.json 对应的 <img>
 * （码表来自主题自带 JSON，替换 HTML 为白名单内容，无注入风险）
 *
 * @param string $html 已转义输出的评论文本 HTML
 * @return string
 */
function fcOwOReplace($html)
{
    static $map = null;
    if (null === $map) {
        $map = array();
        $file = __DIR__ . '/OwO.json';
        if (is_readable($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                foreach ($data as $group) {
                    if (empty($group['container']) || !is_array($group['container'])) {
                        continue;
                    }
                    foreach ($group['container'] as $item) {
                        if (!empty($item['text']) && !empty($item['icon'])) {
                            $map[$item['text']] = $item['icon'];
                        }
                    }
                }
            }
        }
    }
    if (!$map) {
        return $html;
    }
    return preg_replace_callback('/\{:([^:{}\s]{1,100}):\}/', function ($m) use ($map) {
        return isset($map[$m[1]]) ? $map[$m[1]] : $m[0];
    }, $html);
}

/**
 * 评论内容转纯文本 HTML（转义 + 换行 + 表情码还原）
 *
 * @param string $text
 * @return string
 */
function fcCommentText($text)
{
    return fcOwOReplace(nl2br(htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8')));
}

/**
 * 统计文章已通过审核的评论数
 *
 * @param int $cid
 * @return int
 */
function fcCommentsTotal($cid)
{
    $cid = (int) $cid;
    $cache = &fcSocialCache();
    if (!isset($cache['total'][$cid])) {
        $row = \Typecho\Db::get()->fetchRow(
            \Typecho\Db::get()->select('COUNT(coid) AS num')->from('table.comments')
                ->where('cid = ?', $cid)->where('status = ?', 'approved')
        );
        $cache['total'][$cid] = (int) ($row['num'] ?? 0);
    }
    return $cache['total'][$cid];
}

/**
 * 渲染平铺评论列表
 *
 * @param int $cid
 * @param string $permalink
 * @param int $commentsNum 已通过审核的评论总数
 * @param int|null $num 评论显示条数：null=主题设置默认；0=不截取（详情页全部）
 * @return string
 */
function fcInlineCommentsHtml($cid, $permalink, $commentsNum, $num = null)
{
    $options = Helper::options();
    $limit = null === $num
        ? max(1, (int) fcOption($options, 'fcInlineCommentsNum', 3))
        : max(1, (int) $num);
    $comments = fcLatestComments($cid, $limit);
    if (!$comments) {
        return '';
    }

    // 父级评论作者（回复显示「回复 xx」）：一次查询收集
    $parentIds = array();
    foreach ($comments as $comment) {
        if (!empty($comment['parent'])) {
            $parentIds[(int) $comment['parent']] = 1;
        }
    }
    $parentNames = array();
    if ($parentIds) {
        $rows = \Typecho\Db::get()->fetchAll(
            \Typecho\Db::get()->select('coid', 'author')->from('table.comments')
                ->where('coid IN ?', array_keys($parentIds))
        );
        foreach ($rows as $row) {
            $parentNames[(int) $row['coid']] = (string) $row['author'];
        }
    }

    $html = '<ul class="fc-comments">';
    foreach ($comments as $comment) {
        $coid = (int) $comment['coid'];
        $cAuthor = htmlspecialchars($comment['author'], ENT_QUOTES, 'UTF-8');
        $cUrl = trim((string) $comment['url']);
        if ('' !== $cUrl && preg_match('#^https?://#i', $cUrl)) {
            $cName = '<a href="' . htmlspecialchars($cUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank"'
                . ' rel="noopener noreferrer nofollow">' . $cAuthor . '</a>';
        } else {
            $cName = $cAuthor;
        }
        // 被回复者（父级评论可能已删除，删除时退化为普通展示）
        $replyTo = '';
        if (!empty($comment['parent']) && isset($parentNames[(int) $comment['parent']])) {
            $replyTo = '&nbsp;回复 <span class="fc-comment-name">'
                . htmlspecialchars($parentNames[(int) $comment['parent']], ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '<li data-coid="' . $coid . '" data-author="' . $cAuthor . '" title="' . _t('回复') . '">'
            . '<div class="fc-comment-body">'
            . '<span class="fc-comment-name">' . $cName . '</span>'
            . $replyTo
            . '&nbsp;：<span class="fc-comment-text">' . fcCommentText($comment['text']) . '</span>'
            . '</div></li>';
    }
    $html .= '</ul>';

    if ((int) $commentsNum > count($comments)) {
        $html .= '<a class="fc-comments-more" href="' . htmlspecialchars($permalink, ENT_QUOTES, 'UTF-8')
            . '#comments">查看更多评论</a>';
    }

    return $html;
}

/**
 * 渲染动态内联评论输入框（首页信息流与详情页共用）
 *
 * @param Widget\Archive $archive
 * @param mixed $options
 * @param bool $listAll 详情页传 true：评论接口提交后返回全部评论
 * @return string
 */
function fcInlineCommentForm($archive, $options, $listAll = false)
{
    // 评论关闭时不渲染输入框
    if (!$archive->allow('comment')) {
        return '<div class="fc-inline-form fc-inline-closed">' . _t('评论已关闭') . '</div>';
    }

    $user = \Widget\User::alloc();
    $hasLogin = $user->hasLogin();
    $permalink = htmlspecialchars($archive->path, ENT_QUOTES, 'UTF-8');
    $token = htmlspecialchars(fcCommentToken(), ENT_QUOTES, 'UTF-8');
    $guestAuthor = (string) \Typecho\Cookie::get('__typecho_remember_author');
    $guestMail = (string) \Typecho\Cookie::get('__typecho_remember_mail');
    $guestUrl = (string) \Typecho\Cookie::get('__typecho_remember_url');

    // 游客身份是否完备：未登录且无记忆信息时，前端先提交内容、再弹出信息窗口补充
    // （判定口径与 Feedback 服务端一致：称呼必填，邮箱按站点 commentsRequireMail 配置）
    $hasInfo = $hasLogin
        || ('' !== $guestAuthor && (!$options->commentsRequireMail || '' !== $guestMail));

    // 头像：登录用账号邮箱，游客用 Cookie 记忆邮箱（无法构造头像源时不显示）
    $avatarMail = $hasLogin ? (string) $user->mail : $guestMail;

    $html = '<form class="fc-inline-form" hidden method="post"'
        . ' data-cid="' . (int) $archive->cid . '"'
        . ' data-permalink="' . $permalink . '"'
        . ' data-url="' . htmlspecialchars(fcCommentsUrl(), ENT_QUOTES, 'UTF-8') . '"'
        . ' data-has-info="' . ($hasInfo ? '1' : '0') . '"'
        . ($listAll ? ' data-listall="1"' : '') . '>';
    $html .= '<input type="hidden" name="_" value="' . $token . '">';
    $html .= '<input type="hidden" name="parent" value="">';

    // 回复提示条：点击某条评论回复时由前端填充「回复 xx」（不区分登录/游客）
    $html .= '<p class="fc-reply-bar" hidden></p>';

    if (!$hasLogin) {
        // 游客身份字段不展示：提交时信息不全由前端弹窗补充后回填
        $html .= '<input type="hidden" name="author" value="' . htmlspecialchars($guestAuthor, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="mail" value="' . htmlspecialchars($guestMail, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="url" value="' . htmlspecialchars($guestUrl, ENT_QUOTES, 'UTF-8') . '">';
    }

    $html .= '<div class="fc-inline-row">';
    if ('' !== $avatarMail) {
        $html .= '<img class="fc-inline-avatar" src="'
            . htmlspecialchars(fcMailAvatar($avatarMail, $options), ENT_QUOTES, 'UTF-8') . '" alt="">';
    }
    // 评论输入框必须是 textarea（多行、回车换行、随行数自适应高度）
    $html .= '<textarea class="fc-inline-input" name="text" rows="1" placeholder="' . _t('评论') . '"></textarea>';
    // OwO 表情按钮（面板懒加载 OwO.json）
    $html .= '<button type="button" class="owo-trigger" aria-label="插入表情" title="插入表情"'
        . ' data-owo="' . htmlspecialchars($options->themeUrl . '/OwO.json', ENT_QUOTES, 'UTF-8') . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<circle cx="12" cy="12" r="9" />'
        . '<path d="M8.5 14.5s1.2 1.8 3.5 1.8 3.5-1.8 3.5-1.8" />'
        . '<line x1="9" y1="9.5" x2="9.01" y2="9.5" />'
        . '<line x1="15" y1="9.5" x2="15.01" y2="9.5" />'
        . '</svg></button>';
    // 发送按钮 type=button：提交只靠点击，任何输入框回车都不会误触发表单
    $html .= '<button type="button" class="fc-inline-send">' . _t('发送') . '</button>';
    $html .= '</div>';
    $html .= '<div class="owo-box" hidden></div>';
    $html .= '</form>';

    return $html;
}
