<!DOCTYPE html>
<html lang="<?php echo $this->options->language; ?>"<?php if (!empty($GLOBALS['fcPrism'])): ?>
      data-prismjs-copy="复制"
      data-prismjs-copy-success="已复制"
      data-prismjs-copy-error="Press Ctrl+C to copy"<?php endif; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php $this->archiveTitle(array(
                'category' => _t('分类 %s'),
                'search'   => _t('搜索：%s'),
                'tag'      => _t('标签 %s'),
                'author'   => _t('%s 的动态'),
            ), '', ' - '); ?><?php $this->options->title(); ?></title>
    <script>
        (function () {
            var mode = <?php echo json_encode($this->options->fcDarkMode ?: 'auto'); ?>;
            try {
                var saved = localStorage.getItem('fc-theme-mode');
                var dark;
                if (mode === 'dark') {
                    dark = true;
                } else if (mode === 'light') {
                    dark = false;
                } else {
                    dark = saved ? saved === 'dark' : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
                }
                if (dark) document.documentElement.setAttribute('data-theme', 'dark');
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('style.min.css'); ?>?v=<?php echo filemtime(__DIR__ . '/style.min.css'); ?>">
    <?php if (!empty($GLOBALS['fcPrism'])): ?>
        <!-- Prism 代码高亮（CDN：核心 + 自动加载语言 + 行号 + 工具栏复制） -->
        <link rel="stylesheet" href="https://mirrors.sustech.edu.cn/cdnjs/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css">
        <script src="https://mirrors.sustech.edu.cn/cdnjs/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
        <script src="https://mirrors.sustech.edu.cn/cdnjs/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
        <script src="https://mirrors.sustech.edu.cn/cdnjs/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script>
        <script src="https://mirrors.sustech.edu.cn/cdnjs/ajax/libs/prism/1.29.0/plugins/toolbar/prism-toolbar.min.js"></script>
        <script src="https://mirrors.sustech.edu.cn/cdnjs/ajax/libs/prism/1.29.0/plugins/copy-to-clipboard/prism-copy-to-clipboard.min.js"></script>
    <?php endif; ?>
    <?php /* commentReply 置空禁用核心 TypechoComment 回复脚本（评论走主题内联容器）；传 0 会输出损坏的 <script src="0"> */ ?>
    <?php $this->header('commentReply='); ?>
</head>
<body>
<div class="centent">
    <main class="fc-page">
        <?php
        $cover = fcCoverImage($this->options);
        // 封面地址为视频扩展名时改用无声视频背景（自动播放、循环），不计入媒体互斥
        $coverVideo = '' !== $cover && in_array(
            strtolower((string) pathinfo((string) parse_url($cover, PHP_URL_PATH), PATHINFO_EXTENSION)),
            array('mp4', 'webm', 'ogv', 'mov', 'm4v'),
            true
        );
        ?>
        <div class="fc-header">
            <div class="fc-topbar" id="fc-topbar">
                <div class="fc-topbar-left">
                    <!-- 后台入口 -->
                    <a class="topbar-icon" href="<?php $this->options->adminUrl(); ?>" title="<?php _e('后台管理'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                    </a>
                    <?php $fcSongs = fcBgmSongs($this->options); ?>
                    <?php if ($fcSongs): ?>
                        <!-- 背景音乐播放器 -->
                        <div class="fc-music" id="fc-music"
                            data-songs="<?php echo htmlspecialchars(json_encode($fcSongs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="topbar-icon" id="fc-music-toggle" type="button" aria-label="播放/暂停">
                                <svg class="icon-play" viewBox="0 0 24 24" fill="currentColor" stroke="none">
                                    <path d="M7 4.5v15l13-7.5z" />
                                </svg>
                                <svg class="icon-pause" viewBox="0 0 24 24" fill="currentColor" stroke="none">
                                    <rect x="6" y="4" width="4.5" height="16" rx="1" />
                                    <rect x="13.5" y="4" width="4.5" height="16" rx="1" />
                                </svg>
                            </button>
                            <div class="fc-music-info" id="fc-music-info" hidden>
                                <span class="fc-music-eq"><i></i><i></i><i></i><i></i></span>
                                <span class="fc-music-name" id="fc-music-name"></span>
                            </div>
                            <button class="topbar-icon" id="fc-music-next" type="button" aria-label="换一首">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M9 18V5l12-2v13" />
                                    <circle cx="6" cy="18" r="3" />
                                    <circle cx="18" cy="16" r="3" />
                                </svg>
                            </button>
                            <audio id="fc-bgm" preload="none"></audio>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="fc-topbar-right">
                    <?php if (fcFriendList($this->options)): ?>
                        <!-- 友情链接入口 -->
                        <button class="topbar-icon" id="fc-flink-open" type="button" aria-label="友情链接">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                            </svg>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="fc-cover<?php echo '' === $cover ? ' fc-cover-gradient' : ''; ?>"
                <?php if ('' !== $cover && !$coverVideo): ?>style="background-image:url('<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>')"<?php endif; ?>>
                <?php if ($coverVideo): ?>
                    <video class="fc-cover-video"
                        src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                        muted autoplay loop playsinline></video>
                <?php endif; ?>
            </div>
        </div>

        <div class="fc-profile">
            <div class="fc-profile-row">
                <h4><?php echo htmlspecialchars(fcOption($this->options, 'fcNickname', $this->options->title), ENT_QUOTES, 'UTF-8'); ?></h4>
                <a href="javascript:;"><img src="<?php echo htmlspecialchars(fcHeaderAvatar($this->options), ENT_QUOTES, 'UTF-8'); ?>"
                        alt="<?php echo htmlspecialchars(fcOption($this->options, 'fcNickname', $this->options->title), ENT_QUOTES, 'UTF-8'); ?>"></a>
            </div>
            <?php $signature = (string) ($this->options->fcSignature ?? ''); ?>
            <?php if ('' !== $signature): ?>
                <div class="fc-signature"><p><?php echo htmlspecialchars($signature, ENT_QUOTES, 'UTF-8'); ?></p></div>
            <?php endif; ?>
        </div>
