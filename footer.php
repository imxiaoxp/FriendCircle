</main>
</div>
<?php /* 访问统计 */
if (($this->options->fcCounterEnabled ?? '0') == '1' && ($this->options->fcCounterPosition ?: 'bottom-right') !== '') {
    echo renderCounterBar(fcOption($this->options, 'fcCounterPosition', 'bottom-right'));
} ?>
<!-- 右下角悬浮按钮组：搜索 / 明暗切换 / 返回顶部 -->
<div class="fc-float">
    <button class="fc-float-btn" id="fc-backtop" type="button" aria-label="返回顶部" hidden>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
            stroke-linejoin="round">
            <polyline points="18 15 12 9 6 15" />
        </svg>
    </button>
    <button class="fc-float-btn" id="fc-search-open" type="button" aria-label="搜索">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
            stroke-linejoin="round">
            <circle cx="11" cy="11" r="7" />
            <line x1="21" y1="21" x2="16.5" y2="16.5" />
        </svg>
    </button>
    <?php if (($this->options->fcDarkMode ?: 'auto') === 'auto'): ?>
        <button class="fc-float-btn" id="fc-theme-toggle" type="button" aria-label="切换暗色模式">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
            </svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="4" />
                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
            </svg>
        </button>
    <?php endif; ?>
</div>
<?php $fcFriends = fcFriendList($this->options); ?>
<?php if ($fcFriends): ?>
    <!-- 友情链接弹窗 -->
    <div class="fc-flink-modal" id="fc-flink-modal" hidden>
        <div class="fc-flink-card">
            <div class="fc-flink-head">
                <span class="fc-flink-title"><?php _e('联系人'); ?></span>
                <button class="fc-flink-close" type="button" aria-label="关闭">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="fc-flink-list">
                <?php foreach ($fcFriends as $fcFriend): ?>
                    <a class="fc-flink-item" href="<?php echo htmlspecialchars($fcFriend['url'], ENT_QUOTES, 'UTF-8'); ?>"
                        target="_blank" rel="noopener noreferrer nofollow">
                        <img class="fc-flink-avatar"
                            src="<?php echo htmlspecialchars('' !== $fcFriend['avatar'] ? $fcFriend['avatar'] : $this->options->themeUrl . '/assets/img/avatar.svg', ENT_QUOTES, 'UTF-8'); ?>"
                            alt="" loading="lazy"
                            onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($this->options->themeUrl . '/assets/img/avatar.svg', ENT_QUOTES, 'UTF-8'); ?>';">
                        <span class="fc-flink-name"><?php echo htmlspecialchars($fcFriend['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <p class="fc-flink-count"><?php echo _t('共'); ?> <?php echo count($fcFriends); ?> <?php echo _t('个朋友'); ?></p>
        </div>
    </div>
<?php endif; ?>
<!-- 搜索弹窗 -->
<div class="fc-search-modal" id="fc-search-modal">
    <form class="fc-search-bar" method="get" action="<?php $this->options->siteUrl(); ?>">
        <svg class="fc-search-bar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8" />
            <line x1="21" y1="21" x2="16.65" y2="16.65" />
        </svg>
        <input class="fc-search-input" type="text" name="s" placeholder="<?php _e('搜索文章...'); ?>"
            autocomplete="off" maxlength="100">
        <button class="fc-search-close" type="button" aria-label="关闭">&times;</button>
    </form>
</div>
<script>window.fcPjaxEnabled = <?php echo (int) fcOption($this->options, 'fcPjax', '0') ? 'true' : 'false'; ?>;</script>
<script>console.log("\n %c FriendCircle v<?php echo FC_VERSION; ?> %c https://github.com/imxiaoxp/FriendCircle \n", "color:#fadfa3;background:#030307;padding:5px 0;font-weight:bold;", "color:#fff;background:#4a5b6a;padding:5px 0;");</script>
<?php
/* 后台「PJAX 自定义重载函数」：按行拆分，去掉空行与 // 注释行 */
$_fcReloadLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) fcOption($this->options, 'fcPjaxReload', ''))), function ($l) {
    return $l !== '' && strpos($l, '//') !== 0;
}));
?>
<script>window.fcPjaxReloadLines = <?php echo json_encode($_fcReloadLines, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;</script>
<script src="<?php $this->options->themeUrl('app.js'); ?>?v=<?php echo filemtime(__DIR__ . '/app.js'); ?>"></script>
<?php $this->footer(); ?>
</body>
</html>
