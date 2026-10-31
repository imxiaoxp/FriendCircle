<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * FriendCircle 原生音/视频短代码（不依赖 Meting / VideoCollector 插件）
 *
 * 支持格式：
 *   [Music title="歌名" author="歌手" url="音频地址" pic="封面" lrc="歌词"/]     → APlayer
 *   [Video url="视频地址" pic="封面"/] 或 [play]视频地址[/play]（可多行多个地址） → ArtPlayer
 *   兼容 [Meting][Music .../][/Meting] 包裹格式（替代同名插件）
 *
 * 工作机制：
 *   前台渲染：fcMomentHtml() / page.php 输出正文前调用 FC_Media::parse()，
 *             不走 contentEx 钩子（核心在渲染前已预取 excerpt，主题钩子注册晚于首次渲染）
 *   编辑器按钮：admin/write-post.php、admin/write-page.php 的 bottom 钩子注入
 *   （插入音乐 / 插入视频 / 插入位置——位置走 location.php 解析 IPLocation 插件归属地）
 *   前端初始化：assets/lib/aplayer.min.js、artplayer.min.js + app.js 的 fcInitMedia()
 */
class FC_Media
{
    /** 属性解析（简化版 shortcode_parse_atts，支持单双引号与裸值） */
    private static function parseAtts($text)
    {
        $atts = array();
        preg_match_all(
            '/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s\'"]+)/',
            (string) $text,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            if ($m[1] !== '') {
                $atts[$m[1]] = $m[2];
            } elseif ($m[3] !== '') {
                $atts[$m[3]] = $m[4];
            } elseif ($m[5] !== '') {
                $atts[$m[5]] = $m[6];
            }
        }
        return $atts;
    }

    /** 属性值清洗：还原 JSON 转义残留（https:\/\/...）+ 去除 markdown 转换产生的标签与实体 */
    private static function cleanValue($value)
    {
        $value = str_replace('\\/', '/', (string) $value);
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));
    }

    private static function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 短代码解析入口：[Music]/[Video]/[play] → 播放器容器 HTML
     *
     * @param string $html 已渲染的文章内容（含短代码）
     * @return string
     */
    public static function parse($html)
    {
        $html = (string) $html;
        if (false === strpos($html, '[')) {
            return $html;
        }

        /* JSON 形式（最新 Meting 格式）：[Music {...}/] 与 [Music]{...}[/Music] */
        $html = preg_replace_callback('/\[Music\s*(\{[\s\S]*?\})\s*\/\]/i', array('FC_Media', 'renderMusicJson'), $html);
        $html = preg_replace_callback('/\[Music\s*\]\s*(\{[\s\S]*?\})\s*\[\/Music\]/i', array('FC_Media', 'renderMusicJson'), $html);
        /* Meting API 链接（单曲/歌单，前端实时解析）：[Music api="..."/] 与 [Music]链接[/Music]
           （捕获放宽为非空白非方括号，\/ 转义由 cleanValue 统一还原） */
        $html = preg_replace_callback('/\[Music[^\]]*api="([^"]+)"[^\]]*\/\]/i', array('FC_Media', 'renderMusicApi'), $html);
        $html = preg_replace_callback('/\[Music\s*\]\s*([^\[\]\s]+)\s*\[\/Music\]/i', array('FC_Media', 'renderMusicApi'), $html);
        /* 旧属性式：[Music title=".." .../] */
        $html = preg_replace_callback('/\[Music([^\]]*)\]/i', array('FC_Media', 'renderMusic'), $html);
        $html = preg_replace_callback(
            '/\[(?:play|video)\]([\s\S]*?)\[\/(?:play|video)\]/i',
            array('FC_Media', 'renderPlay'),
            $html
        );
        $html = preg_replace_callback('/\[Video([^\]]*)\]/i', array('FC_Media', 'renderVideo'), $html);

        /* 清理 [Meting] 包裹标记 */
        $html = preg_replace('/\[\/?Meting\]/i', '', $html);

        return $html;
    }

    /**
     * contentEx / excerptEx 钩子入口：($text, $widget, $lastResult)
     * 兜底路径（feed 输出等）；主渲染由 fcMomentHtml / page.php 调用 parse()，
     * parse() 幂等，双重解析无副作用
     */
    public static function render($text, $widget, $last = null)
    {
        $html = (null !== $last && '' !== $last) ? (string) $last : (string) $text;
        if (!($widget instanceof \Widget\Archive)) {
            return $html;
        }
        return self::parse($html);
    }

    /** [Music title=".." author=".." url=".." pic=".." lrc=".."/] → APlayer 容器 */
    private static function renderMusic($m)
    {
        $a = self::parseAtts($m[1]);
        $url = isset($a['url']) ? self::cleanValue($a['url']) : '';
        if ('' === $url) {
            return $m[0];
        }
        return '<div class="aplayer fc-aplayer"'
            . ' data-name="' . self::esc(isset($a['title']) ? self::cleanValue($a['title']) : '') . '"'
            . ' data-artist="' . self::esc(isset($a['author']) ? self::cleanValue($a['author']) : '') . '"'
            . ' data-url="' . self::esc($url) . '"'
            . ' data-cover="' . self::esc(isset($a['pic']) ? self::cleanValue($a['pic']) : '') . '"'
            . ' data-lrc="' . self::esc(isset($a['lrc']) ? self::cleanValue($a['lrc']) : '') . '"></div>';
    }

    /** 最新 Meting JSON 格式：[Music {name,artist,url,pic,lrc}/] 或 [Music]{...}[/Music] */
    private static function renderMusicJson($m)
    {
        $data = json_decode(self::cleanValue($m[1]), true);
        if (!is_array($data)) {
            return $m[0];
        }
        $url = isset($data['url']) ? self::cleanValue($data['url']) : '';
        if ('' === $url) {
            return $m[0];
        }
        /* Meting API 字段：name/artist/url/pic/lrc（兼容 title/author 别名） */
        return '<div class="aplayer fc-aplayer"'
            . ' data-name="' . self::esc(isset($data['name']) ? self::cleanValue($data['name']) : (isset($data['title']) ? self::cleanValue($data['title']) : '')) . '"'
            . ' data-artist="' . self::esc(isset($data['artist']) ? self::cleanValue($data['artist']) : (isset($data['author']) ? self::cleanValue($data['author']) : '')) . '"'
            . ' data-url="' . self::esc($url) . '"'
            . ' data-cover="' . self::esc(isset($data['pic']) ? self::cleanValue($data['pic']) : '') . '"'
            . ' data-lrc="' . self::esc(isset($data['lrc']) ? self::cleanValue($data['lrc']) : '') . '"></div>';
    }

    /** Meting API 链接（单曲 song / 歌单 playlist）：前端 fetch 实时解析（音频直链有时效，不能固化进 HTML） */
    private static function renderMusicApi($m)
    {
        $api = self::cleanValue($m[1]);
        if ('' === $api || !preg_match('#^https?://#i', $api)) {
            return $m[0];
        }
        return '<div class="aplayer fc-aplayer fc-aplayer-api" data-api="' . self::esc($api) . '"></div>';
    }

    /** [Video url=".." pic=".."/] → ArtPlayer 容器 */
    private static function renderVideo($m)
    {
        $a = self::parseAtts($m[1]);
        $url = isset($a['url']) ? self::cleanValue($a['url']) : '';
        if ('' === $url) {
            return $m[0];
        }
        $pic = isset($a['pic']) ? self::cleanValue($a['pic']) : '';
        /* 包装器承载 16:9 / 竖屏自适应比例，播放器填充其中 */
        return '<div class="play-player-wrapper"><div class="fc-video" data-url="' . self::esc($url) . '"'
            . ('' === $pic ? '' : ' data-pic="' . self::esc($pic) . '"') . '></div></div>';
    }

    /** [play]地址[/play]（VideoCollector 兼容，支持多行多个地址）
     *  单地址 → 一个 ArtPlayer 容器；多地址 → 一个播放器 + 分集切换
     *  （复刻原插件结构：首页提取后剔除 video-tabs 只播第一集，详情页可切换） */
    private static function renderPlay($m)
    {
        $inner = preg_replace('/<br\s*\/?>/i', "\n", $m[1]);

        $urls = array();
        foreach (preg_split('/\s*\n\s*/', trim($inner)) as $url) {
            $url = self::cleanValue($url);
            if ('' !== $url) {
                $urls[] = $url;
            }
        }
        if (empty($urls)) {
            return $m[0];
        }

        /* data-urls：完整分集列表（\n 分隔，同插件 .video-urls），
           供播放器内「下一集」控件使用——首页剔除分集条后仍可切换 */
        $urlsAttr = self::esc(implode("\n", $urls));

        if (1 === count($urls)) {
            return '<div class="play-player-wrapper"><div class="fc-video" data-url="' . self::esc($urls[0]) . '" data-urls="' . $urlsAttr . '"></div></div>';
        }

        $out = '<div class="play-container"><div class="play-player-wrapper">'
            . '<div class="fc-video" data-url="' . self::esc($urls[0]) . '" data-urls="' . $urlsAttr . '"></div></div>'
            . '<div class="video-tabs">';
        foreach ($urls as $i => $u) {
            $out .= '<span class="video-tab' . (0 === $i ? ' active' : '')
                . '" data-url="' . self::esc($u) . '">' . ($i + 1) . '</span>';
        }
        return $out . '</div></div>';
    }

    /** 后台编辑器按钮：插入音乐 / 插入视频 / 插入位置（bottom 钩子，$post 为内容编辑组件） */
    public static function editorButton($post = null)
    {
        /* 「插入位置」端点：受主题设置「插入位置按钮」开关控制（默认关，未开启不下发端点与按钮） */
        if ('1' === (string) fcOption(\Widget\Options::alloc(), 'fcLocation', '0')) {
            echo '<script>window.fcLocationEndpoint = '
                . json_encode(\Widget\Options::alloc()->themeUrl . '/location.php')
                . ';</script>';
        }
        echo <<<'EOT'
<script>
(function () {
    function init() {
    var row = document.getElementById('wmd-button-row');
    if (!row || row.dataset.fcMedia) return;
    row.dataset.fcMedia = '1';

    function insertText(text) {
        var field = document.getElementById('text');
        if (!field) return;
        var start = field.selectionStart || 0;
        var end = field.selectionEnd || 0;
        field.value = field.value.substring(0, start) + text + field.value.substring(end, field.value.length);
        field.selectionStart = field.selectionEnd = start + text.length;
        field.focus();
    }

    function panel(title, fields, onOk) {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;display:flex;align-items:center;justify-content:center;';
        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.25);padding:20px 24px;width:420px;max-width:92vw;font-size:14px;';
        var head = document.createElement('p');
        head.style.cssText = 'margin:0 0 14px;font-weight:600;';
        head.textContent = title;
        box.appendChild(head);
        var inputs = [];
        fields.forEach(function (f) {
            var label = document.createElement('label');
            label.style.cssText = 'display:block;margin:0 0 10px;font-size:12px;color:#666;';
            label.textContent = f.label + (f.required ? '（必填）' : '（选填）');
            var input = f.multiline ? document.createElement('textarea') : document.createElement('input');
            if (f.multiline) {
                input.rows = 5;
                input.style.cssText = 'width:100%;box-sizing:border-box;margin-top:4px;padding:6px 8px;border:1px solid #d9d9d9;border-radius:4px;font-size:13px;resize:vertical;';
            } else {
                input.type = 'text';
                input.style.cssText = 'width:100%;box-sizing:border-box;margin-top:4px;padding:6px 8px;border:1px solid #d9d9d9;border-radius:4px;font-size:13px;';
            }
            label.appendChild(input);
            box.appendChild(label);
            inputs.push(input);
        });
        var bar = document.createElement('div');
        bar.style.cssText = 'text-align:right;margin-top:14px;';
        var ok = document.createElement('button');
        ok.type = 'button'; ok.className = 'btn btn-s primary'; ok.textContent = '确定';
        ok.style.cssText = 'margin-right:8px;';
        var cancel = document.createElement('button');
        cancel.type = 'button'; cancel.className = 'btn btn-s'; cancel.textContent = '取消';
        bar.appendChild(ok); bar.appendChild(cancel); box.appendChild(bar);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
        inputs[0].focus();
        function close() { overlay.remove(); }
        cancel.onclick = close;
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        ok.onclick = function () {
            var values = inputs.map(function (i) { return i.value.trim(); });
            for (var i = 0; i < fields.length; i++) {
                if (fields[i].required && !values[i]) { inputs[i].focus(); return; }
            }
            insertText(onOk(values));
            close();
        };
    }

    function makeButton(title, label, fields, onOk) {
        var li = document.createElement('li');
        li.className = 'wmd-button';
        li.title = title;
        li.innerHTML = '<span style="display:flex;align-items:center;justify-content:center;height:20px;font-size:14px;line-height:20px;">' + label + '</span>';
        li.onclick = function () { panel(title, fields, onOk); };
        row.appendChild(li);
    }

    /* 「插入位置」：查询编辑者 IP 归属地并填充「所在位置」自定义字段 */
    function makeLocationButton(title, label) {
        var li = document.createElement('li');
        li.className = 'wmd-button';
        li.title = title;
        li.innerHTML = '<span style="display:flex;align-items:center;justify-content:center;height:20px;font-size:14px;line-height:20px;">' + label + '</span>';
        li.onclick = function () {
            if (!window.fcLocationEndpoint) { alert('位置接口不可用'); return; }
            li.style.opacity = '.5';
            fetch(window.fcLocationEndpoint, { method: 'POST' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    li.style.opacity = '';
                    if (data.error) { alert('获取位置失败：' + data.error); return; }
                    if (!data.location) { alert('无法识别当前位置（内网地址或归属地未收录）'); return; }
                    var input = document.querySelector('input[name="fields[location]"]');
                    if (!input) { alert('未找到「所在位置」字段'); return; }
                    input.value = data.location;
                    li.title = title + '（上次插入：' + data.location + '）';
                })
                .catch(function () {
                    li.style.opacity = '';
                    alert('请求失败，请重试');
                });
        };
        row.appendChild(li);
    }

    makeButton('插入音乐（APlayer）', '♫', [
        { label: 'Meting 歌曲/歌单链接（选填，如 https://api.injahow.cn/meting/?type=song&id=xxx）', required: false },
        { label: '歌曲名', required: false },
        { label: '歌手', required: false },
        { label: '音频文件地址', required: false },
        { label: '封面图地址', required: false },
        { label: '歌词文件地址', required: false }
    ], function (v) {
        /* 填了 Meting 链接则优先：单曲/歌单由前端实时解析（音频直链有时效） */
        if (v[0]) {
            return '[Music api="' + v[0] + '"/]';
        }
        if (!v[3]) return '';
        var tag = '[Music title="' + v[1] + '" author="' + v[2] + '" url="' + v[3] + '"';
        if (v[4]) tag += ' pic="' + v[4] + '"';
        if (v[5]) tag += ' lrc="' + v[5] + '"';
        return tag + '/]';
    });

    makeButton('插入视频（ArtPlayer）', '▶', [
        { label: '视频文件地址（每行一个，多个将生成分集播放器）', required: true, multiline: true }
    ], function (v) {
        /* 统一输出 [play] 格式：单地址一个播放器，多地址自动分集 */
        var lines = v[0].split(/\s*\n\s*/).filter(function (s) { return s; });
        return '[play]\n' + lines.join('\n') + '\n[/play]';
    });

    if (window.fcLocationEndpoint) {
        makeLocationButton('插入当前位置（IP 归属地，填充「所在位置」字段）', '📍');
    }
    }

    /* 编辑器工具栏由 pagedown.js 在 DOM ready 中构建，按钮注入须晚于其完成；
       非 Markdown 旧文章需点击「使用 Markdown 编辑」后才出现工具栏，观察器兜底 */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    if (!document.getElementById('wmd-button-row')) {
        new MutationObserver(function (m, obs) {
            if (document.getElementById('wmd-button-row')) {
                obs.disconnect();
                init();
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
</script>
EOT;
    }
}

/* 后台文章 / 独立页面编辑器按钮 */
Typecho\Plugin::factory('admin/write-post.php')->bottom = array('FC_Media', 'editorButton');
Typecho\Plugin::factory('admin/write-page.php')->bottom = array('FC_Media', 'editorButton');

/* feed 等延迟渲染路径兜底（详情页 excerpt 已被核心预取缓存，钩子对主渲染无效） */
Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = array('FC_Media', 'render');
Typecho\Plugin::factory('Widget\Base\Contents')->excerptEx = array('FC_Media', 'render');
