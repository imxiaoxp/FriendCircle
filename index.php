<?php

/**
 * FriendCircle - 仿微信朋友圈 Typecho 主题
 *
 * @package FriendCircle
 * @author Xiao
 * @version 1.0.0
 * @link https://xiao.cn.mt
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$GLOBALS['fcPrism'] = true; // 首页也加载 Prism 代码高亮
$this->need('header.php');
?>

<?php if (!$this->is('index')): ?>
<!-- 归档页（搜索/分类/标签/作者等）顶部：返回主页 + 归档标题 -->
<div class="fc-detail-bar">
    <a class="fc-detail-back" href="<?php $this->options->siteUrl(); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
            stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6" />
        </svg>
        <?php _e('主页'); ?>
    </a>
    <span class="fc-detail-title"><?php $this->archiveTitle(array(
                'search'   => _t('搜索：%s'),
                'category' => _t('分类：%s'),
                'tag'      => _t('标签：%s'),
                'author'   => _t('%s 的动态'),
                'date'     => _t('%s'),
            ), '', ''); ?></span>
</div>
<?php endif; ?>

<div class="fc-list" id="fc-list">
    <?php while ($this->next()): ?>
        <?php fcMomentHtml($this); ?>
    <?php endwhile; ?>
</div>

<?php if (!$this->have()): ?>
    <div class="fc-empty">还没有动态，快去发布第一条吧</div>
<?php endif; ?>

<?php
// 抓取下一页链接供「加载更多」AJAX 使用
$fcNextHtml = '';
if ($this->have() && $this->getTotal() > $this->parameter->pageSize) {
    ob_start();
    $this->pageLink('&nbsp;', 'next');
    $fcNextHtml = ob_get_clean();
}
$fcNextUrl = '';
if (preg_match('/href="([^"]*)"/', $fcNextHtml, $fcNextMatches)) {
    $fcNextUrl = htmlspecialchars($fcNextMatches[1], ENT_QUOTES, 'UTF-8');
}
?>
<div class="footer">
    <span class="footer-text" id="fc-loadmore" data-next="<?php echo $fcNextUrl; ?>"><?php echo '' !== $fcNextUrl ? '加载更多..' : '没有更多了'; ?></span>
</div>

<?php $this->need('footer.php'); ?>
