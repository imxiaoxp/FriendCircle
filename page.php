<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit;
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
    <span class="fc-detail-title"><?php $this->title(); ?></span>
</div>

<article class="fc-card fc-page-card">
    <div class="fc-card-body">
        <div class="fc-card-head">
            <div class="fc-card-name"><p><?php $this->title(); ?></p></div>
            <span class="fc-text fc-page-text"><?php echo fcLinkBlank($this->content); ?></span>
        </div>
    </div>
</article>

<?php $this->need('footer.php'); ?>
