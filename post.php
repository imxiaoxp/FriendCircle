<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit;
// 详情页启用 Prism 代码高亮（header/footer 按此标记加载资源）
$GLOBALS['fcPrism'] = true;
$this->need('header.php');
?>

<div class="fc-detail-bar">
    <a class="fc-detail-back" href="<?php $this->options->siteUrl(); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
            stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6" />
        </svg>
        <?php _e('返回'); ?>
    </a>
    <span class="fc-detail-title"><?php _e('详情'); ?></span>
</div>

<?php fcMomentHtml($this, true); ?>

<?php $this->need('footer.php'); ?>
