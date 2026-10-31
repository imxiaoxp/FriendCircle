/* FriendCircle - 仿微信朋友圈主题前端脚本 */
(function () {
    'use strict';

    /* ============ 暗色模式切换 ============ */
    var themeToggle = document.getElementById('fc-theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            var el = document.documentElement;
            var dark = el.getAttribute('data-theme') !== 'dark';
            if (dark) {
                el.setAttribute('data-theme', 'dark');
            } else {
                el.removeAttribute('data-theme');
            }
            try {
                localStorage.setItem('fc-theme-mode', dark ? 'dark' : 'light');
            } catch (e) {}
        });
    }

    /* ============ 清除游客评论信息 ============ */
    /* 游客身份记忆：localStorage fc-commenter 优先，回退 Typecho 原生评论者记忆 cookie。
       仅在存在记录时显示按钮，清除后两者一并删除，资料区头像/昵称恢复默认（fcInitProfile） */
    var forgetBtn = document.getElementById('fc-forget');
    function fcSyncForgetBtn() {
        if (!forgetBtn) return;
        var has = false;
        try {
            has = !!fcRemembered().author;
        } catch (e) {}
        forgetBtn.hidden = !has;
    }
    if (forgetBtn) {
        forgetBtn.addEventListener('click', function () {
            try { localStorage.removeItem('fc-commenter'); } catch (e) {}
            /* 服务端删除记忆 cookie（含 JS 不可见的 HttpOnly，由 forget.php 下发过期
               Set-Cookie）；失败时退回 JS 本地删除可见部分，不影响后续本地清理 */
            try {
                var forgetUrl = forgetBtn.getAttribute('data-forget-url');
                if (forgetUrl) {
                    fetch(forgetUrl, { method: 'POST', credentials: 'same-origin' })
                        .catch(fcDropRememberCookies);
                } else {
                    fcDropRememberCookies();
                }
            } catch (e) {
                fcDropRememberCookies();
            }
            /* 同步清空页面上已展开/已填充的评论表单游客输入与头像、免弹窗标记，
               否则表单里仍残留此游客的昵称/邮箱/网址 */
            document.querySelectorAll('.fc-inline-form').forEach(function (form) {
                ['author', 'mail', 'url'].forEach(function (name) {
                    var input = form.querySelector('[name="' + name + '"]');
                    if (input) input.value = '';
                });
                form.removeAttribute('data-has-info');
                var av = form.querySelector('.fc-inline-avatar');
                if (av) av.remove();
            });
            fcInitProfile();
            fcInlineMsg(null, '已清除你的个人信息');
            forgetBtn.hidden = true;
        });
        fcSyncForgetBtn();
    }

    /* ============ 滚动联动：顶栏变色 + 返回顶部按钮显隐 ============ */
    /* 顶栏节点经 PJAX 移植保留，封面元素随页面替换——动态查询保证换页后判定仍正确 */
    var topbar = document.getElementById('fc-topbar');
    var backtop = document.getElementById('fc-backtop');
    if (topbar || backtop) {
        var onScroll = function () {
            var cover = document.querySelector('.fc-header');
            // 无封面的页面（详情页 / PJAX 骨架阶段）必须清除 solid，避免残留底色
            if (topbar) {
                topbar.classList.toggle('solid', !!cover && window.scrollY > cover.offsetHeight - 52);
            }
            if (backtop) {
                backtop.hidden = window.scrollY <= 300;
            }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ============ 全局媒体互斥：视频互斥 + 音频互斥，静音小窗视频可与音频共存 ============ */
    /* 以播放状态为准（paused 标志）：视频间、音频间各保留最近开始者，小窗静音视频可与音频共存，
       封面背景视频豁免；事件不冒泡需捕获监听（含同源 iframe），APlayer 游离 audio 经实例桥接 */
    (function () {
        function eachDoc(fn) {
            fn(document);
            document.querySelectorAll('iframe').forEach(function (frame) {
                try {
                    if (frame.contentDocument) fn(frame.contentDocument);
                } catch (err) {} /* 跨域 iframe 不可访问，跳过 */
            });
        }

        function isCoverVideo(m) {
            return m.tagName === 'VIDEO' && !!(m.closest && m.closest('.fc-cover'));
        }

        /* 收集当前所有播放中的媒体（DOM 内元素 + APlayer 游离 audio） */
        function playingMedia() {
            var list = [];
            eachDoc(function (doc) {
                doc.querySelectorAll('audio, video').forEach(function (m) {
                    if (!isCoverVideo(m) && !m.paused) list.push(m);
                });
            });
            (window.aplayers || []).forEach(function (ap) {
                if (ap && ap.audio && !ap.audio.paused) list.push(ap.audio);
            });
            return list;
        }

        function startedAtOf(m) {
            return typeof m.fcStartedAt === 'number' ? m.fcStartedAt : 0;
        }

        /* 组内保留最近开始播放的一个；
           开始时间并列（无时间戳）时保留收集顺序靠后的（后出现的视为新播放） */
        function newestOf(group) {
            var keep = group[group.length - 1];
            group.forEach(function (m) {
                if (startedAtOf(m) > startedAtOf(keep)) keep = m;
            });
            return keep;
        }

        /* 状态收敛（分互斥域）：视频之间、音频之间各自保留最近开始的一个；
           小窗静音视频（muted）与音频共存，非静音视频才与音频互斥（最近开始者胜出） */
        function syncPlayback() {
            var list = playingMedia();
            if (list.length < 2) return;
            var videos = [];
            var audios = [];
            list.forEach(function (m) {
                (m.tagName === 'VIDEO' ? videos : audios).push(m);
            });
            var keepVideo = videos.length ? newestOf(videos) : null;
            var keepAudio = audios.length ? newestOf(audios) : null;
            videos.forEach(function (m) {
                if (m !== keepVideo) {
                    try { m.pause(); } catch (err) {}
                }
            });
            audios.forEach(function (m) {
                if (m !== keepAudio) {
                    try { m.pause(); } catch (err) {}
                }
            });
            if (keepVideo && keepAudio && !keepVideo.muted) {
                if (startedAtOf(keepVideo) > startedAtOf(keepAudio)) {
                    try { keepAudio.pause(); } catch (err) {}
                } else {
                    try { keepVideo.pause(); } catch (err) {}
                }
            }
        }

        /* 媒体进入播放态：记录开始时间并立即收敛 */
        function markStarted(m) {
            if (m) m.fcStartedAt = Date.now();
            syncPlayback();
        }

        /* 暴露给播放器初始化（fcInitMedia）：APlayer 游离 audio 不在 DOM，
           须由初始化代码在 play 时手动标记 */
        window.fcMarkMediaStarted = markStarted;

        function bindPlay(doc) {
            doc.addEventListener('play', function (e) {
                var t = e.target;
                if (!t || !t.pause || isCoverVideo(t)) return;
                markStarted(t);
            }, true);
        }

        bindPlay(document);

        /* iframe 懒加载/延迟加载完成后补绑监听（load 不冒泡，捕获可拦截） */
        document.addEventListener('load', function (e) {
            var frame = e.target;
            if (!frame || frame.tagName !== 'IFRAME') return;
            try {
                var doc = frame.contentDocument;
                if (doc && !doc.fcMutexBound) {
                    doc.fcMutexBound = true;
                    bindPlay(doc);
                }
            } catch (err) {}
        }, true);

        eachDoc(function (doc) {
            if (doc !== document && !doc.fcMutexBound) {
                doc.fcMutexBound = true;
                bindPlay(doc);
            }
        });

        /* ---- APlayer 实例接入互斥 ---- */
        /* 极简化改造：播放键移至 .aplayer-body 右侧垂直居中，body 背景取当前曲目封面
           （--fc-apidata 经 MutationObserver 随换歌更新），按钮显隐由 APlayer 自身切换 */
        function fcMinifyAPlayer(ap) {
            try {
                var body = ap.container.querySelector('.aplayer-body');
                if (!body || body.fcMinified) return;
                body.fcMinified = true;
                var pic = body.querySelector('.aplayer-pic');
                ['.aplayer-play', '.aplayer-pause'].forEach(function (cls) {
                    var btn = pic && pic.querySelector(cls);
                    if (btn) {
                        body.appendChild(btn);
                        /* APlayer 未给按钮单独绑点击，播放/暂停靠点击冒泡到封面；
                           移出封面后按钮失去事件来源，自行接管（封面点击保留） */
                        btn.addEventListener('click', function (e) {
                            e.stopPropagation();
                            try { ap.toggle(); } catch (err) { /* 播放失败静默 */ }
                        });
                    }
                });
                var sync = function () {
                    var m = ((pic && pic.style.backgroundImage) || '').match(/url\(["']?([^"')]+)["']?\)/);
                    body.style.setProperty('--fc-apidata', m ? 'url("' + m[1] + '")' : 'none');
                };
                sync();
                if (pic && window.MutationObserver) {
                    new MutationObserver(sync).observe(pic, { attributes: true, attributeFilter: ['style'] });
                }
                /* 歌单模式：文字开关常驻 .aplayer-list 容器下方——
                   列表默认收起（高度置 0），按钮显示「展开列表」，点击切换开合并随状态换文案 */
                if (ap.container.classList.contains('aplayer-withlist')) {
                    var listEle = ap.container.querySelector('.aplayer-list');
                    if (listEle && !ap.container.querySelector('.fc-aplist-toggle')) {
                        var lb = document.createElement('button');
                        lb.type = 'button';
                        lb.className = 'fc-aplist-toggle';
                        lb.setAttribute('aria-label', '收起/展开播放列表');
                        lb.innerHTML = '<span class="fc-aplist-text-open">展开列表</span><span class="fc-aplist-text-close">关闭列表</span>';
                        lb.addEventListener('click', function (e) {
                            e.stopPropagation();
                            try {
                                var ol = listEle.querySelector('ol');
                                var opened = listEle.offsetHeight > 1;
                                listEle.style.height = opened ? '0px' : (ol ? ol.scrollHeight + 'px' : 'auto');
                                lb.classList.toggle('fc-aplist-opened', !opened);
                            } catch (err) { /* 开合失败不影响播放 */ }
                        });
                        /* 默认收起：官方歌单初始为展开态，主动置 0（按钮保持「展开列表」文案） */
                        if (listEle.offsetHeight > 1) {
                            listEle.style.height = '0px';
                        }
                        listEle.insertAdjacentElement('afterend', lb);
                    }
                }
                /* 多曲目（音乐列表）：上一首/下一首，右侧与播放键并排 */
                var audios = ap.options && ap.options.audio;
                if (Array.isArray(audios) && audios.length > 1) {
                    /* 多曲标记：CSS 据此把播放键移到上一首/下一首中间；单曲时播放键贴右侧边 */
                    ap.container.classList.add('fc-apmulti');
                    [['fc-apprev', '上一首', ap.skipBack, '<path d="M7 5h2.6v14H7zM20 5v14L10.8 12z"/>'],
                     ['fc-apnext', '下一首', ap.skipForward, '<path d="M14.4 5H17v14h-2.6zM4 5v14l9.2-7z"/>']]
                        .forEach(function (it) {
                            if (body.querySelector('.' + it[0])) return;
                            var nb = document.createElement('button');
                            nb.type = 'button';
                            nb.className = it[0];
                            nb.setAttribute('aria-label', it[1]);
                            nb.title = it[1];
                            nb.innerHTML = '<svg viewBox="0 0 24 24">' + it[3] + '</svg>';
                            nb.addEventListener('click', function (e) {
                                e.stopPropagation();
                                try { it[2].call(ap); } catch (err) { /* 切歌失败静默 */ }
                            });
                            body.appendChild(nb);
                        });
                }
            } catch (err) { /* 样式增强失败不影响播放 */ }
        }

        /* APlayer 的 audio 游离于 DOM 外，原生 play 事件到不了 document，且实例创建时机不定
           （Meting 对平台歌曲在 XHR 回调才 new），包装构造函数使任何时机创建的实例都被接入 */
        function bindAPlayer(ap) {
            if (!ap || ap.fcMutexBound) return;
            ap.fcMutexBound = true;
            fcMinifyAPlayer(ap);
            /* APlayer 1.10.1 防御：audios 与列表 DOM 失同步（同容器二次初始化等）时
               list.switch 会抛错中断跳曲，包守卫避免未捕获错误 */
            if (ap.list && typeof ap.list.switch === 'function' && !ap.list.fcSafeSwitch) {
                var rawSwitch = ap.list.switch.bind(ap.list);
                ap.list.fcSafeSwitch = true;
                ap.list.switch = function (i) {
                    try {
                        return rawSwitch(i);
                    } catch (err) { /* 容器已失同步，降级忽略 */ }
                };
            }
            /* 游离 audio 元素可直接监听原生事件，不依赖在 DOM 中 */
            if (ap.audio && typeof ap.audio.addEventListener === 'function') {
                ap.audio.addEventListener('play', function () {
                    markStarted(ap.audio);
                });
            }
            if (typeof ap.on === 'function') {
                ap.on('play', function () {
                    markStarted(ap.audio);
                });
            }
        }

        function wrapAPlayer() {
            var Raw = window.APlayer;
            if (typeof Raw !== 'function' || Raw.fcMutexWrapped) return;
            var Wrapped = function (options) {
                var inst = new Raw(options);
                bindAPlayer(inst);
                return inst;
            };
            Wrapped.prototype = Raw.prototype; /* 保持 instanceof 兼容 */
            for (var k in Raw) {
                if (Object.prototype.hasOwnProperty.call(Raw, k)) Wrapped[k] = Raw[k];
            }
            Wrapped.fcMutexWrapped = true;
            window.APlayer = Wrapped;
        }

        function attachAPlayers() {
            wrapAPlayer();
            (window.aplayers || []).forEach(bindAPlayer);
        }

        /* APlayer.min.js 经插件 header 钩子先于本文件输出，直接包装即可；
           DOMContentLoaded 后兜底再试一次并接入已创建实例 */
        wrapAPlayer();
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(attachAPlayers, 0);
            });
        } else {
            setTimeout(attachAPlayers, 0);
        }

        /* 状态轮询兜底：任何原因错过 play 事件时，最多 600ms 后自动收敛到单播放 */
        setInterval(syncPlayback, 600);
    })();

    if (backtop) {
        backtop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    /* ============ 背景音乐播放器 ============ */
    (function () {
        var music = document.getElementById('fc-music');
        var audio = document.getElementById('fc-bgm');
        if (!music || !audio) return;
        var info = document.getElementById('fc-music-info');
        var nameEl = document.getElementById('fc-music-name');
        var nextBtn = document.getElementById('fc-music-next');
        var songs = [];
        try {
            songs = JSON.parse(music.getAttribute('data-songs') || '[]') || [];
        } catch (e) {}
        if (!songs.length) return;

        var current = -1;

        function pickRandom() {
            if (songs.length === 1) return 0;
            var idx;
            do {
                idx = Math.floor(Math.random() * songs.length);
            } while (idx === current);
            return idx;
        }

        function show(song) {
            if (info) info.hidden = false;
            if (nameEl) {
                // 双层结构：内层承载文本，供溢出检测与跑马灯滚动
                // 注意：必须先显示 info 再检测溢出，display:none 下测宽恒为 0
                nameEl.textContent = '';
                var text = document.createElement('span');
                text.className = 'fc-music-name-text';
                text.textContent = song.name || '未知曲目';
                nameEl.appendChild(text);
                nameEl.classList.toggle('fc-overflow', text.scrollWidth > nameEl.clientWidth);
            }
        }

        function play(idx) {
            var song = songs[idx];
            if (!song) return;
            current = idx;
            // 同一首续播时不重设 src，避免进度归零
            if (audio.getAttribute('src') !== song.url) audio.src = song.url;
            show(song);
            var p = audio.play();
            if (p && p.catch) p.catch(function () {});
        }

        audio.addEventListener('play', function () {
            music.classList.add('playing');
        });
        audio.addEventListener('pause', function () {
            music.classList.remove('playing');
        });
        audio.addEventListener('ended', function () {
            play(pickRandom());
        });

        var toggle = document.getElementById('fc-music-toggle');
        if (toggle) {
            toggle.addEventListener('click', function () {
                if (audio.paused) {
                    play(current >= 0 ? current : pickRandom());
                } else {
                    audio.pause();
                }
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                play(pickRandom());
            });
        }
    })();

    /* ============ 全文按钮 ============ */
    // 「全文」为跳转详情页的链接，这里仅在内容不超限时隐藏按钮

    function fcSyncFulltextButtons() {
        document.querySelectorAll('.fc-card').forEach(function (item) {
            var text = item.querySelector('.fc-text.fc-fold');
            var toggle = item.querySelector('.fc-fulltext');
            if (!text || !toggle) return;
            // 内容不足 5 行时隐藏按钮
            toggle.hidden = text.scrollHeight <= text.clientHeight + 2;
        });
    }

    /* ============ 详情页悬浮返回按钮 ============ */
    /* 返回条吸顶后被 solid 顶栏遮挡时渐显；顶栏节点经 PJAX 移植保留（引用持续有效），
       bar/float 随页面替换，故动态查询 */
    (function () {
        var topbar = document.getElementById('fc-topbar');
        if (!topbar) return;
        var sync = function () {
            var bar = document.querySelector('.fc-detail-bar');
            var float = document.querySelector('.fc-back-float');
            if (!bar || !float) return;
            float.classList.toggle('visible',
                topbar.classList.contains('solid') && bar.getBoundingClientRect().top <= 0);
        };
        window.addEventListener('scroll', sync, { passive: true });
        sync();
    })();

    /* ============ 点赞（可取消） ============ */
    var likeBusy = false;

    /* 已赞文章集合：cookie 持久化（fc-liked=cid1,cid2,...），多文章状态互不覆盖，
       跨会话保持，直到点「取消」移除 */
    function fcLikedSet() {
        var m = document.cookie.match(/(?:^|;\s*)fc-liked=([^;]*)/);
        return new Set(m ? decodeURIComponent(m[1]).split(',').filter(Boolean) : []);
    }
    function fcSaveLiked(set) {
        document.cookie = 'fc-liked=' + encodeURIComponent(Array.from(set).join(',')) +
            '; path=/; max-age=31536000; SameSite=Lax';
    }

    /* 点赞按钮文字随状态：已赞显示「取消」 */
    function fcSyncLikeText(btn) {
        var pill = btn.querySelector('.pill-text');
        if (pill) pill.textContent = btn.classList.contains('liked') ? '取消' : '赞';
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.like-btn');
        if (!btn || likeBusy) return;
        var cid = btn.getAttribute('data-cid');
        var url = btn.getAttribute('data-url');
        if (!cid || !url) return;

        var liked = fcLikedSet().has(cid);
        likeBusy = true;
        var body = new URLSearchParams();
        body.set('cid', cid);
        body.set('cancel', liked ? '1' : '0');

        fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) { return res.json(); }).then(function (data) {
            if (typeof data.count !== 'number') return;
            var set = fcLikedSet();
            if (liked) set.delete(cid); else set.add(cid);
            fcSaveLiked(set);

            // 点赞/取消成功统一 toast 提示
            fcInlineMsg(null, liked ? '已取消点赞' : '点赞成功');

            var item = btn.closest('.fc-card');
            if (!item) return;
            btn.classList.toggle('liked', !liked);
            fcSyncLikeText(btn);

            var block = item.querySelector('.fc-likes');
            var count = item.querySelector('.like-count');
            if (block && count) {
                count.textContent = data.count;
                block.hidden = data.count <= 0;
                fcSyncZanp(item);
                fcScrollToZanp(item);
            }
        }).catch(function () {}).finally(function () {
            likeBusy = false;
        });
    });

    /* 初始化点赞按钮状态（cookie 持久记忆，兼容迁移旧 localStorage 单值） */
    function fcInitLikeState() {
        var set = fcLikedSet();
        try {
            var old = localStorage.getItem('fc-liked');
            if (old && !set.has(old)) { set.add(old); fcSaveLiked(set); }
            localStorage.removeItem('fc-liked');
        } catch (e) {}
        if (!set.size) return;
        document.querySelectorAll('.like-btn[data-cid]').forEach(function (btn) {
            if (set.has(btn.getAttribute('data-cid'))) {
                btn.classList.add('liked');
                fcSyncLikeText(btn);
            }
        });
    }
    fcInitLikeState();

    /* ============ 内联评论 ============ */

    /* 页面中部 toast 提示（渐显渐隐）：text 为空时立即隐藏 */
    var fcToastEl = null;

    function fcInlineMsg(form, text, isError) {
        if (fcToastEl && fcToastEl._hideTimer) {
            clearTimeout(fcToastEl._hideTimer);
            fcToastEl._hideTimer = null;
        }
        if (!text) return;
        if (!fcToastEl) {
            fcToastEl = document.createElement('div');
            fcToastEl.className = 'fc-toast';
            document.body.appendChild(fcToastEl);
        }
        fcToastEl.classList.toggle('fc-toast-error', !!isError);
        fcToastEl.textContent = text;
        fcToastEl.hidden = false;
        // 强制回流保证连续调用时过渡动画重新触发
        void fcToastEl.offsetWidth;
        fcToastEl.classList.add('show');
        fcToastEl._hideTimer = setTimeout(function () {
            fcToastEl.classList.remove('show');
            fcToastEl._hideTimer = setTimeout(function () {
                fcToastEl.hidden = true;
                fcToastEl._hideTimer = null;
            }, 300);
        }, isError ? 3000 : 2500);
    }

    /* 游客记忆有效期 30 天（与 Typecho 原生评论者记忆 cookie 一致），每次评论重新计时 */
    var FC_REMEMBER_TTL = 30 * 24 * 3600 * 1000;

    function fcRemembered() {
        var info = {};
        try {
            info = JSON.parse(localStorage.getItem('fc-commenter') || '{}') || {};
        } catch (e) {}
        /* 超过 30 天（或无时间戳的旧记录）视为过期：移除并回退 cookie；
           cookie 由服务端滑动续期，两侧时长保持一致 */
        if (info.author && (!info.ts || Date.now() - info.ts > FC_REMEMBER_TTL)) {
            try { localStorage.removeItem('fc-commenter'); } catch (e) {}
            info = {};
        }
        /* 本地无记录时回退读取 Typecho 原生评论者记忆 cookie（30 天有效期，
           评论成功后由服务端写入；cookie 名带 md5(rootUrl) 前缀，故用后缀匹配） */
        if (!info.author) {
            fcRememberCookies().forEach(function (c) {
                if (!info[c.field]) info[c.field] = c.value;
            });
        }
        return info;
    }

    /* 扫描 JS 可见的原生评论者记忆 cookie（HttpOnly 对 JS 不可见，删不到也读不到），
       返回 [{ name: 完整 cookie 名, field: author/mail/url, value: 解码后的值 }] */
    function fcRememberCookies() {
        var out = [];
        try {
            document.cookie.split(';').forEach(function (part) {
                var eq = part.indexOf('=');
                if (eq < 0) return;
                var name = part.slice(0, eq).trim();
                if (!name) return;
                ['author', 'mail', 'url'].forEach(function (field) {
                    var suffix = '__typecho_remember_' + field;
                    if (name === suffix || name.slice(-suffix.length) === suffix) {
                        var value = part.slice(eq + 1);
                        try { value = decodeURIComponent(value); } catch (err) {}
                        out.push({ name: name, field: field, value: value });
                    }
                });
            });
        } catch (e) {}
        return out;
    }

    /* JS 侧尽力删除可见的记忆 cookie：真实名带 md5(rootUrl) 前缀按扫描到的完整名删，
       带/不带 domain、逐级 path 各删一次；HttpOnly 部分由 forget.php 服务端兜底 */
    function fcDropRememberCookies() {
        try {
            var paths = ['/'];
            var segs = location.pathname.split('/').filter(Boolean);
            segs.pop();
            segs.forEach(function (seg, i) {
                paths.push('/' + segs.slice(0, i + 1).join('/') + '/');
            });
            fcRememberCookies().forEach(function (c) {
                paths.forEach(function (p) {
                    document.cookie = c.name +
                        '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=' + p;
                    document.cookie = c.name +
                        '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=' + p +
                        '; domain=' + location.hostname;
                });
            });
        } catch (e) {}
    }

    function fcSaveCommenter(form) {
        var info = { ts: Date.now() };
        ['author', 'mail', 'url'].forEach(function (name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (input && input.value.trim()) info[name] = input.value.trim();
        });
        try {
            localStorage.setItem('fc-commenter', JSON.stringify(info));
        } catch (e) {}
        /* 实时同步资料区头像/昵称 */
        fcInitProfile();
        /* 有游客信息记录后显示「清除游客信息」浮钮 */
        fcSyncForgetBtn();
    }

    /* 更新/创建评论输入框左侧头像（评论接口返回 avatar URL 时调用） */
    function fcSetFormAvatar(form, url) {
        if (!form || !url) return;
        var row = form.querySelector('.fc-inline-row');
        if (!row) return;
        var img = row.querySelector('.fc-inline-avatar');
        if (!img) {
            img = document.createElement('img');
            img.className = 'fc-inline-avatar';
            img.alt = '';
            row.insertBefore(img, row.querySelector('.fc-inline-input'));
        }
        img.src = url;
    }

    /* 同步 fc-panel 显隐：无可见内容（赞块隐藏、列表为空、表单收起）时隐藏容器；
       「暂无评论」占位仅在表单展开且无真实评论时显示，收起评论后一并隐藏 */
    function fcSyncZanp(item) {
        var zanp = item && item.querySelector('.fc-panel');
        if (!zanp) return;
        var zan = zanp.querySelector('.fc-likes:not([hidden])');
        var list = zanp.querySelector('.fc-inline-list');
        var form = zanp.querySelector('.fc-inline-form');
        var hasList = !!(list && list.querySelector('li:not(.fc-inline-empty)'));
        var formOpen = !!(form && !form.hidden && !form.classList.contains('fc-inline-closed'));
        var empty = list && list.querySelector('.fc-inline-empty');
        if (empty) empty.hidden = !formOpen;
        var hasContent = !!zan || hasList || formOpen;
        zanp.hidden = !hasContent;
    }

    /* 展开评论容器时若列表为空，显示占位提示 */
    function fcEnsureEmptyHint(card) {
        var list = card && card.querySelector('.fc-inline-list');
        if (!list || list.children.length) return;
        var div = document.createElement('div');
        div.className = 'fc-inline-empty';
        div.textContent = '暂无评论，来说两句吧';
        list.appendChild(div);
    }

    /* 点赞/评论操作后把焦点定位到评论区容器（已可见才滚动） */
    function fcScrollToZanp(card) {
        var zanp = card && card.querySelector('.fc-panel');
        if (zanp && !zanp.hidden) {
            zanp.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    /* 评论容器：首页与详情页均为内联表单 */
    function fcReplyContainer(card) {
        return card ? card.querySelector('.fc-inline-form') : null;
    }

    function fcEnsureParentInput(container) {
        var input = container.querySelector('[name="parent"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'parent';
            container.appendChild(input);
        }
        return input;
    }

    function fcCloseReply(container) {
        if (!container) return;
        container.hidden = true;
        var parentInput = container.querySelector('[name="parent"]');
        if (parentInput) parentInput.value = '';
        fcShowReplyBar(container, '');
        // 表单从评论条目间移回列表下方默认位置
        fcResetFormPosition(container);
    }

    /* 回复提示条：回复某条评论时在输入行上方显示「回复 xx」 */
    function fcShowReplyBar(container, author) {
        var bar = container && container.querySelector('.fc-reply-bar');
        if (!bar) return;
        bar.textContent = author ? '回复 ' + author : '';
        bar.hidden = !author;
    }

    /* 评论按钮：展开/收起评论容器（首页内联表单 / 详情页评论窗口） */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.comment-btn');
        if (!btn) return;
        var card = btn.closest('.fc-card');
        var container = fcReplyContainer(card);
        if (!container || container.classList.contains('fc-inline-closed')) return;

        if (container.hidden) {
            container.hidden = false;
            fcEnsureEmptyHint(card);
            var info = fcRemembered();
            ['author', 'mail', 'url'].forEach(function (name) {
                var input = container.querySelector('[name="' + name + '"]');
                if (input && !input.value && info[name]) input.value = info[name];
            });
            var text = container.querySelector('[name="text"]');
            if (text) {
                text.focus();
                fcAutoGrow(text);
            }
        } else {
            fcCloseReply(container);
        }
        fcSyncZanp(card);
        fcScrollToZanp(card);
    });

    /* 两个点按钮：展开/收起赞、评论按钮（同一时间只展开一张卡片） */
    document.addEventListener('click', function (e) {
        var dots = e.target.closest('.fc-action-dots');
        if (!dots) return;
        var item = dots.closest('.fc-card');
        var capsule = item && item.querySelector('.fc-action-capsule');
        if (!capsule) return;
        var willShow = !capsule.classList.contains('is-open');
        document.querySelectorAll('.fc-action-capsule.is-open').forEach(function (el) {
            el.classList.remove('is-open');
        });
        if (willShow) capsule.classList.add('is-open');
    });

    /* 表单复位到评论列表下方的默认位置 */
    function fcResetFormPosition(form) {
        if (!form) return;
        var card = form.closest('.fc-card');
        var list = card && card.querySelector('.fc-inline-list');
        // 已在默认位置（紧随列表之后）则跳过
        if (list && form.previousElementSibling === list) return;
        if (list) {
            list.parentElement.insertBefore(form, list.nextSibling);
        }
    }

    /* 点击评论：弹出评论容器并回复该条；再次点击同一条 = 隐藏容器 */
    document.addEventListener('click', function (e) {
        var li = e.target.closest('.fc-comments > li');
        if (!li || e.target.closest('a')) return;
        var coid = li.getAttribute('data-coid');
        if (!coid) return;
        var card = li.closest('.fc-card');
        var container = fcReplyContainer(card);
        if (!container || container.classList.contains('fc-inline-closed')) return;
        var parentInput = fcEnsureParentInput(container);

        if (!container.hidden && parentInput.value === coid) {
            // 已在回复该条：隐藏评论容器
            fcCloseReply(container);
            fcSyncZanp(card);
            return;
        }

        container.hidden = false;
        parentInput.value = coid;
        // 显示「回复 xx」提示条
        fcShowReplyBar(container, li.getAttribute('data-author'));
        // 表单移到该条评论下方
        li.parentElement.insertBefore(container, li.nextSibling);
        var text = container.querySelector('[name="text"]');
        if (text) {
            text.focus();
            fcAutoGrow(text);
        }
        fcEnsureEmptyHint(card);
        fcSyncZanp(card);
        fcScrollToZanp(card);
    });

    /* 提交内联评论：仅点击「发送」按钮触发，不监听 form submit，
       回车在 textarea 内自然换行，单行输入框也不会误提交 */
    document.addEventListener('click', function (e) {
        var send = e.target.closest('.fc-inline-send');
        if (!send) return;
        var form = send.closest('.fc-inline-form');
        if (!form) return;
        e.preventDefault();
        fcSubmitInlineComment(form);
    });

    /* 拦截原生表单提交：单行输入框回车的隐式提交（隐式 submit 会让页面跳到
       action 地址），拦截后回车不再有任何提交行为，textarea 换行不受影响 */
    document.addEventListener('submit', function (e) {
        if (e.target.closest && e.target.closest('.fc-inline-form')) {
            e.preventDefault();
        }
    });

    /* 评论输入框自适应高度 */
    function fcAutoGrow(ta) {
        if (!ta || ta.tagName !== 'TEXTAREA') return;
        ta.style.height = 'auto';
        ta.style.height = ta.scrollHeight + 'px';
    }

    document.addEventListener('input', function (e) {
        if (e.target.matches && e.target.matches('.fc-inline-form textarea[name="text"]')) {
            fcAutoGrow(e.target);
        }
    });

    function fcSubmitInlineComment(form) {
        if (form.dataset.busy === '1') return;

        var text = form.querySelector('[name="text"]');
        if (!text || !text.value.trim()) {
            fcInlineMsg(form, '请输入评论内容', true);
            return;
        }

        // 游客身份信息不全时先弹窗补充，确认后再提交
        var author = form.querySelector('[name="author"]');
        var mail = form.querySelector('[name="mail"]');
        var hasInfo = form.getAttribute('data-has-info') === '1';
        if (!hasInfo && (!author || !author.value.trim() || !mail || !mail.value.trim())) {
            fcOpenGuestModal(form);
            return;
        }

        form.dataset.busy = '1';
        var send = form.querySelector('.fc-inline-send');
        if (send) send.disabled = true;
        fcInlineMsg(form, '');

        var body = new URLSearchParams();
        body.set('cid', form.getAttribute('data-cid') || '');
        body.set('permalink', form.getAttribute('data-permalink') || '');
        body.set('type', 'comment');
        body.set('text', text.value);
        var token = form.querySelector('[name="_"]');
        if (token) body.set('_', token.value);
        var parent = form.querySelector('[name="parent"]');
        body.set('parent', parent && parent.value ? parent.value : '0');
        // 详情页表单：评论接口返回全部评论
        if (form.getAttribute('data-listall') === '1') body.set('listall', '1');
        ['author', 'mail', 'url'].forEach(function (name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (input) body.set(name, input.value);
        });

        fetch(form.getAttribute('data-url'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) { return res.json(); }).then(function (data) {
            if (data.error) {
                fcInlineMsg(form, data.error, true);
                return;
            }
            fcSaveCommenter(form);
            text.value = '';
            fcSetFormAvatar(form, data.avatar);
            if (data.waiting) {
                fcInlineMsg(form, '评论已提交，等待审核');
                return;
            }
            var item = form.closest('.fc-card');
            // 表单若嵌在评论条目下方，先复位，避免被下方列表整体替换时一并销毁；
            // 同时清空回复状态（parent 与提示条）
            fcCloseReply(form);
            var list = item && item.querySelector('.fc-inline-list');
            if (typeof data.list === 'string' && list) {
                list.innerHTML = data.list;
            }
            fcSyncZanp(item);
            // 评论成功：收起评论框（fcCloseReply 已置 hidden），toast 反馈
            fcInlineMsg(form, '评论成功');
        }).catch(function () {
            fcInlineMsg(form, '网络错误，请稍后重试', true);
        }).finally(function () {
            delete form.dataset.busy;
            if (send) send.disabled = false;
        });
    }

    /* 访客信息弹窗 */
    var fcGuestModal = null;
    var fcPendingForm = null;

    function fcEnsureGuestModal() {
        if (fcGuestModal) return fcGuestModal;
        fcGuestModal = document.createElement('div');
        fcGuestModal.className = 'fc-guest-modal';
        fcGuestModal.hidden = true;
        fcGuestModal.innerHTML = '<div class="fc-guest-modal-card">'
            + '<p class="fc-guest-modal-title">填写评论者信息</p>'
            + '<input name="author" class="fc-inline-input" placeholder="昵称" autocomplete="off">'
            + '<input name="mail" type="email" class="fc-inline-input" placeholder="邮箱" autocomplete="off">'
            + '<input name="url" class="fc-inline-input" placeholder="网站（可选）" autocomplete="off">'
            + '<div class="fc-guest-modal-actions">'
            + '<button type="button" class="fc-guest-cancel">取消</button>'
            + '<button type="button" class="fc-guest-ok">确定</button>'
            + '</div></div>';
        document.body.appendChild(fcGuestModal);
        fcGuestModal.addEventListener('click', function (e) {
            if (e.target === fcGuestModal) fcCloseGuestModal();
        });
        fcGuestModal.querySelector('.fc-guest-cancel').addEventListener('click', fcCloseGuestModal);
        fcGuestModal.querySelector('.fc-guest-ok').addEventListener('click', fcConfirmGuestModal);
        fcGuestModal.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                e.preventDefault();
                fcConfirmGuestModal();
            }
        });
        return fcGuestModal;
    }

    function fcOpenGuestModal(form) {
        fcPendingForm = form;
        var modal = fcEnsureGuestModal();
        var remembered = fcRemembered();
        ['author', 'mail', 'url'].forEach(function (name) {
            var input = modal.querySelector('[name="' + name + '"]');
            var field = form.querySelector('[name="' + name + '"]');
            input.value = remembered[name] || (field && field.value) || '';
        });
        modal.hidden = false;
        modal.querySelector('[name="author"]').focus();
    }

    function fcCloseGuestModal() {
        fcPendingForm = null;
        if (fcGuestModal) fcGuestModal.hidden = true;
    }

    function fcConfirmGuestModal() {
        if (!fcPendingForm) {
            fcCloseGuestModal();
            return;
        }
        var modal = fcGuestModal;
        var info = {
            author: modal.querySelector('[name="author"]').value.trim(),
            mail: modal.querySelector('[name="mail"]').value.trim(),
            url: modal.querySelector('[name="url"]').value.trim(),
            ts: Date.now()
        };
        if (!info.author) {
            modal.querySelector('[name="author"]').focus();
            return;
        }
        if (!info.mail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(info.mail)) {
            modal.querySelector('[name="mail"]').focus();
            return;
        }
        try {
            localStorage.setItem('fc-commenter', JSON.stringify(info));
        } catch (err) {}
        /* 实时同步资料区头像/昵称 */
        fcInitProfile();
        /* 有游客信息记录后显示「清除游客信息」浮钮 */
        fcSyncForgetBtn();
        ['author', 'mail', 'url'].forEach(function (name) {
            var field = fcPendingForm.querySelector('[name="' + name + '"]');
            if (field) field.value = info[name];
        });
        fcPendingForm.setAttribute('data-has-info', '1');
        var form = fcPendingForm;
        fcCloseGuestModal();
        fcSubmitInlineComment(form);
    }

    /* ============ OwO 表情面板 ============ */
    var fcOwOPromise = null;

    function fcLoadOwO(url) {
        if (!fcOwOPromise) {
            fcOwOPromise = fetch(url).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            }).catch(function (err) {
                fcOwOPromise = null; // 失败不缓存，下次点击可重试
                throw err;
            });
        }
        return fcOwOPromise;
    }

    /* 由 OwO.json 构建面板：多分组时顶部 Tab 切换，每组一页网格 */
    function fcBuildOwOPanel(box, data) {
        var groups = Object.keys(data).filter(function (name) {
            var g = data[name];
            return g && Array.isArray(g.container) && g.container.length;
        });
        box.innerHTML = '';
        if (!groups.length) {
            box.innerHTML = '<div class="owo-empty">暂无表情</div>';
            return;
        }
        if (groups.length > 1) {
            var tabs = document.createElement('div');
            tabs.className = 'owo-tabs';
            groups.forEach(function (name, i) {
                var tab = document.createElement('button');
                tab.type = 'button';
                tab.className = 'owo-tab' + (0 === i ? ' active' : '');
                tab.textContent = name;
                tabs.appendChild(tab);
            });
            box.appendChild(tabs);
        }
        var pages = document.createElement('div');
        pages.className = 'owo-pages';
        groups.forEach(function (name, i) {
            var group = data[name];
            var page = document.createElement('div');
            page.className = 'owo-page' + (0 === i ? ' active' : '');
            group.container.forEach(function (item) {
                if (!item || !item.text || !item.icon) return;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'owo-item';
                // icon 为主题自带 OwO.json 的白名单 HTML
                btn.innerHTML = item.icon;
                btn.dataset.text = item.text;
                if (group.type !== 'image') btn.dataset.raw = '1';
                page.appendChild(btn);
            });
            pages.appendChild(page);
        });
        box.appendChild(pages);
        if (groups.length > 1) {
            box.querySelector('.owo-tabs').addEventListener('click', function (e) {
                var tab = e.target.closest('.owo-tab');
                if (!tab) return;
                var idx = Array.prototype.indexOf.call(tab.parentElement.children, tab);
                box.querySelectorAll('.owo-tab').forEach(function (t, i) {
                    t.classList.toggle('active', i === idx);
                });
                box.querySelectorAll('.owo-page').forEach(function (p, i) {
                    p.classList.toggle('active', i === idx);
                });
            });
        }
    }

    function fcCloseOwO() {
        document.querySelectorAll('.owo-box:not([hidden])').forEach(function (box) {
            box.hidden = true;
            var trigger = box.parentElement.querySelector('.owo-trigger.active');
            if (trigger) trigger.classList.remove('active');
        });
    }

    /* fixed 定位坐标：宽度对齐输入行，默认向上弹出，上方放不下时翻到下方 */
    function fcPositionOwO(box) {
        var anchor = box.parentElement;
        if (!anchor) return;
        var rect = anchor.getBoundingClientRect();
        var width = rect.width;
        if (width <= 0) return;
        var h = box.offsetHeight;
        var above = rect.top;
        var below = window.innerHeight - rect.bottom;
        var top;
        if (above >= h + 8) top = rect.top - h - 8;
        else if (below >= h + 8) top = rect.bottom + 8;
        else top = above >= below ? rect.top - h - 8 : rect.bottom + 8;
        top = Math.max(8, Math.min(top, window.innerHeight - h - 8));
        box.style.width = width + 'px';
        box.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)) + 'px';
        box.style.top = top + 'px';
    }

    var fcRepositionOwO = function () {
        document.querySelectorAll('.owo-box:not([hidden])').forEach(fcPositionOwO);
    };
    window.addEventListener('scroll', fcRepositionOwO, { passive: true });
    window.addEventListener('resize', fcRepositionOwO);

    function fcInsertToInput(input, text) {
        if (!input) return;
        var s = input.selectionStart;
        var e = input.selectionEnd;
        if (null === s || undefined === s) s = input.value.length;
        if (null === e || undefined === e) e = input.value.length;
        if (input.setRangeText) input.setRangeText(text, s, e, 'end');
        else input.value = input.value.slice(0, s) + text + input.value.slice(e);
        fcAutoGrow(input);
        input.focus();
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.owo-trigger');
        if (trigger) {
            var form = trigger.closest('.fc-inline-form');
            var box = form && form.querySelector('.owo-box');
            if (!box) return;
            var opening = box.hidden;
            fcCloseOwO();
            if (opening) {
                box.hidden = false;
                fcPositionOwO(box);
                trigger.classList.add('active');
                if (!box.dataset.loaded && !box.dataset.loading) {
                    box.dataset.loading = '1';
                    fcLoadOwO(trigger.getAttribute('data-owo'))
                        .then(function (data) {
                            fcBuildOwOPanel(box, data);
                            box.dataset.loaded = '1';
                            delete box.dataset.loading;
                            fcPositionOwO(box);
                        })
                        .catch(function () {
                            box.innerHTML = '<div class="owo-empty">表情加载失败，请重试</div>';
                            delete box.dataset.loading;
                            fcPositionOwO(box);
                        });
                }
            }
            return;
        }
        var item = e.target.closest('.owo-item');
        if (item) {
            var itemBox = item.closest('.owo-box');
            var input = itemBox.closest('.fc-inline-form').querySelector('.fc-inline-input');
            // 图片表情插入 {:码:}（服务端渲染时还原为图片），文本表情直接插入
            fcInsertToInput(input, item.dataset.raw ? item.dataset.text : '{:' + item.dataset.text + ':}');
            return;
        }
        if (!e.target.closest('.owo-box')) fcCloseOwO();
    });

    /* ============ 友情链接弹窗 ============ */
    var flinkModal = document.getElementById('fc-flink-modal');
    var flinkOpen = document.getElementById('fc-flink-open');
    if (flinkModal && flinkOpen) {
        var fcCloseFlink = function () {
            flinkModal.hidden = true;
        };
        flinkOpen.addEventListener('click', function () {
            flinkModal.hidden = false;
        });
        var flinkCloseBtn = flinkModal.querySelector('.fc-flink-close');
        if (flinkCloseBtn) flinkCloseBtn.addEventListener('click', fcCloseFlink);
        flinkModal.addEventListener('click', function (e) {
            if (e.target === flinkModal) fcCloseFlink();
        });
    }

    /* ============ 搜索弹窗 ============ */
    var searchModal = document.getElementById('fc-search-modal');
    var searchOpen = document.getElementById('fc-search-open');
    if (searchModal && searchOpen) {
        var fcOpenSearch = function () {
            searchModal.classList.add('active');
            var input = searchModal.querySelector('.fc-search-input');
            if (input) {
                input.value = '';
                input.focus();
            }
        };
        var fcCloseSearch = function () {
            searchModal.classList.remove('active');
        };
        searchOpen.addEventListener('click', fcOpenSearch);
        var searchCloseBtn = searchModal.querySelector('.fc-search-close');
        if (searchCloseBtn) searchCloseBtn.addEventListener('click', fcCloseSearch);
        searchModal.addEventListener('click', function (e) {
            if (e.target === searchModal) fcCloseSearch();
        });
    }

    /* ============ 访问统计条（数字滚动 + 可选轮询） ============ */
    (function initCounter() {
        var bar = document.getElementById('fc-vc-counter');
        if (!bar) return;

        // 统计条在左下角（与悬浮按钮组同侧）时标记 body，按钮组上移避让
        if (bar.classList.contains('fc-vc-bottom-left')) {
            document.body.classList.add('fc-has-counter');
        }

        function buildStrip(digit) {
            var strip = document.createElement('span');
            strip.className = 'fc-vc-strip';
            for (var i = 0; i < 10; i++) {
                var o = document.createElement('span');
                o.textContent = i;
                strip.appendChild(o);
            }
            strip.style.transform = 'translateY(-' + 10 * digit + '%)';
            return strip;
        }

        function renderNumber(el) {
            var str = String(parseInt(el.dataset.value, 10) || 0);
            el.innerHTML = '';
            for (var j = 0; j < str.length; j++) {
                var d = document.createElement('span');
                d.className = 'fc-vc-digit';
                d.appendChild(buildStrip(0));
                el.appendChild(d);
            }
            void el.offsetWidth;
            el.querySelectorAll('.fc-vc-strip').forEach(function (strip, j) {
                strip.style.transform = 'translateY(-' + parseInt(str[j], 10) * 10 + '%)';
            });
        }

        bar.querySelectorAll('.fc-vc-number').forEach(renderNumber);

        var refresh = parseInt(bar.dataset.refresh, 10) || 0;
        if (refresh > 0 && bar.dataset.endpoint) {
            var timer = null;

            var fetchStats = function () {
                fetch(bar.dataset.endpoint, { cache: 'no-store' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data) return;
                        bar.querySelectorAll('.fc-vc-number').forEach(function (el) {
                            var v = parseInt(data[el.dataset.key], 10) || 0;
                            if (String(v) !== el.dataset.value) {
                                el.dataset.value = String(v);
                                renderNumber(el);
                            }
                        });
                    })
                    .catch(function () {});
            };
            var stopPolling = function () {
                if (timer) {
                    clearInterval(timer);
                    timer = null;
                }
            };
            var startPolling = function () {
                if (!timer) timer = setInterval(fetchStats, refresh * 1000);
            };

            startPolling();

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    stopPolling();
                } else {
                    fetchStats();
                    startPolling();
                }
            });
        }
    })();

    /* Esc 收起弹窗与评论容器 */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (searchModal && searchModal.classList.contains('active')) {
            fcCloseSearch();
            return;
        } else if (flinkModal && !flinkModal.hidden) {
            fcCloseFlink();
            return;
        }
        if (fcGuestModal && !fcGuestModal.hidden) {
            fcCloseGuestModal();
            return;
        }
        if (document.querySelector('.owo-box:not([hidden])')) {
            fcCloseOwO();
            return;
        }
        document.querySelectorAll('.fc-inline-form:not([hidden])').forEach(function (container) {
            fcCloseReply(container);
            fcSyncZanp(container.closest('.fc-card'));
        });
    });

    /* ============ 图片灯箱 ============ */
    (function () {
        var SEL = '.fc-photo > img, .fc-detail-text img, .fc-page-card img';
        var box = null, img = null, prevBtn = null, nextBtn = null, counter = null;
        var gallery = [], index = 0;
        var downX = 0, downY = 0, tracking = false, swiped = false, sliding = false;
        var ARROW = function (dir) {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
                + ' stroke-linecap="round" stroke-linejoin="round"><polyline points="'
                + (-1 === dir ? '15 18 9 12 15 6' : '9 18 15 12 9 6') + '"/></svg>';
        };
        var ensure = function () {
            if (box) return;
            box = document.createElement('div');
            box.className = 'lightbox';
            img = document.createElement('img');
            img.alt = '';
            prevBtn = document.createElement('button');
            prevBtn.type = 'button';
            prevBtn.className = 'lightbox-nav lightbox-prev';
            prevBtn.setAttribute('aria-label', '上一张');
            prevBtn.innerHTML = ARROW(-1);
            nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.className = 'lightbox-nav lightbox-next';
            nextBtn.setAttribute('aria-label', '下一张');
            nextBtn.innerHTML = ARROW(1);
            counter = document.createElement('div');
            counter.className = 'lightbox__counter';
            box.appendChild(img);
            box.appendChild(counter);
            box.appendChild(prevBtn);
            box.appendChild(nextBtn);
            document.body.appendChild(box);
            // 滑动切换：Pointer 事件统一处理触摸与鼠标拖拽；.lightbox 的 touch-action:pan-y
            // 让浏览器不接管水平滑动。拖动时图片跟手，松手按阈值滑出/回弹
            box.addEventListener('pointerdown', function (e) {
                if (sliding || gallery.length < 2) return;
                tracking = true;
                swiped = false;
                downX = e.clientX;
                downY = e.clientY;
            });
            box.addEventListener('pointermove', function (e) {
                if (!tracking || sliding) return;
                var dx = e.clientX - downX, dy = e.clientY - downY;
                if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 60) {
                    tracking = false; // 垂直手势，交给浏览器处理
                    return;
                }
                // 首/末张越界方向给阻力，模拟橡皮筋
                var atEdge = (0 === index && dx > 0) || (index === gallery.length - 1 && dx < 0);
                var ox = atEdge ? dx * 0.3 : dx;
                img.style.transition = 'none';
                img.style.transform = 'translateX(' + ox + 'px)';
            });
            window.addEventListener('pointerup', function (e) {
                if (!tracking) return;
                tracking = false;
                var dx = e.clientX - downX;
                var dir = dx < 0 ? 1 : -1;
                var atEdge = (0 === index && -1 === dir) || (index === gallery.length - 1 && 1 === dir);
                if (Math.abs(dx) > 60 && !atEdge) {
                    swiped = true;
                    slide(dir);
                } else {
                    // 未达阈值或在边界：回弹归位
                    img.style.transition = 'transform .25s ease';
                    img.style.transform = 'translateX(0)';
                }
            });
            box.addEventListener('dragstart', function (e) { e.preventDefault(); });
        };
        var render = function () {
            var t = gallery[index];
            img.src = t ? t.currentSrc || t.src : '';
            var single = gallery.length < 2;
            prevBtn.hidden = single;
            nextBtn.hidden = single;
            counter.hidden = single;
            if (!single) counter.textContent = (index + 1) + '/' + gallery.length;
            prevBtn.disabled = 0 === index;
            nextBtn.disabled = index === gallery.length - 1;
        };
        // 清除内联样式，恢复 CSS 的打开缩放动画
        var resetImg = function () {
            img.style.transition = '';
            img.style.transform = '';
        };
        // 切换动画：当前图滑出 → 换图 → 新图从另一侧滑入
        var slide = function (dir) {
            if (sliding) return;
            var next = index + dir;
            if (next < 0 || next >= gallery.length) return;
            sliding = true;
            var w = (box.clientWidth || 400) / 2;
            var d = 1 === dir ? -1 : 1; // 下一张向左滑出
            img.style.transition = 'transform .18s ease-in';
            img.style.transform = 'translateX(' + (d * w) + 'px)';
            setTimeout(function () {
                index = next;
                render();
                img.style.transition = 'none';
                img.style.transform = 'translateX(' + (-d * w) + 'px)';
                void img.offsetWidth;
                img.style.transition = 'transform .22s ease-out';
                img.style.transform = 'translateX(0)';
                sliding = false;
            }, 170);
        };
        var open = function (target) {
            ensure();
            // 打开时重新收集；同一张卡片内的图片成组，独立页取全文图片
            var root = target.closest('.fc-card') || document;
            gallery = Array.prototype.slice.call(root.querySelectorAll(SEL));
            index = Math.max(0, gallery.indexOf(target));
            resetImg();
            sliding = false;
            render();
            box.classList.add('show');
            document.documentElement.classList.add('lightbox-open');
        };
        var close = function () {
            if (!box) return;
            box.classList.remove('show');
            document.documentElement.classList.remove('lightbox-open');
            resetImg();
        };
        // 事件委托：对 AJAX 追加加载的卡片同样生效
        document.addEventListener('click', function (e) {
            var nav = e.target.closest('.lightbox-nav');
            if (nav) {
                slide(nav.classList.contains('lightbox-prev') ? -1 : 1);
                return;
            }
            var t = e.target.closest(SEL);
            if (t) {
                e.preventDefault();
                open(t);
                return;
            }
            if (e.target.closest('.lightbox')) {
                if (swiped) swiped = false; // 滑动结束后的合成点击，不关闭灯箱
                else close();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (!box || !box.classList.contains('show')) return;
            if ('Escape' === e.key) close();
            else if ('ArrowLeft' === e.key) slide(-1);
            else if ('ArrowRight' === e.key) slide(1);
        });
    })();

    /* ============ 短代码播放器初始化（APlayer / ArtPlayer） ============ */
    /* media.php 输出 .aplayer / .fc-video 容器，首次与 PJAX 切页后均需执行；
       APlayer 游离 audio 在 play 时手动标记互斥时间戳，ArtPlayer 的 video 在 DOM 内自动接管 */
    function fcInitMedia() {
        if (window.APlayer) {
            document.querySelectorAll('.aplayer').forEach(function (el) {
                if (el.fcApInited) return;
                /* Meting API 链接（单曲/歌单）：直链带时效签名不能固化进 HTML，
                   前端 fetch 实时解析，歌单数组逐项映射为 APlayer 音频列表 */
                if (el.dataset.api) {
                    el.fcApInited = true;
                    fetch(el.dataset.api).then(function (r) { return r.json(); }).then(function (data) {
                        /* fetch 期间页面可能已 PJAX 离开：容器已脱离文档时放弃构造，
                           避免产生游离实例（其出错跳曲会操作失同步的列表 DOM） */
                        if (!el.isConnected) return;
                        var list = Array.isArray(data) ? data : [data];
                        var audio = list.map(function (x) {
                            return {
                                name: x.name || x.title || '未命名曲目',
                                artist: x.artist || x.author || '',
                                url: x.url || '',
                                cover: x.pic || '',
                                lrc: x.lrc || ''
                            };
                        }).filter(function (a) { return a.url; });
                        if (!audio.length) return;
                        try {
                            var ap = new APlayer({
                                container: el,
                                audio: audio,
                                lrcType: 3,
                                loop: 'none',
                                preload: 'metadata',
                                mutex: false
                            });
                            (window.aplayers = window.aplayers || []).push(ap);
                            if (ap.audio && typeof window.fcMarkMediaStarted === 'function') {
                                ap.audio.addEventListener('play', function () {
                                    window.fcMarkMediaStarted(ap.audio);
                                });
                            }
                        } catch (err) {}
                    }).catch(function () {});
                    return;
                }
                if (!el.dataset.url) return;
                el.fcApInited = true;
                try {
                    var ap = new APlayer({
                        container: el,
                        audio: [{
                            name: el.dataset.name || '未命名曲目',
                            artist: el.dataset.artist || '',
                            url: el.dataset.url,
                            cover: el.dataset.cover || '',
                            lrc: el.dataset.lrc || ''
                        }],
                        lrcType: el.dataset.lrc ? 3 : 0,
                        loop: 'none',
                        preload: 'metadata',
                        mutex: false /* 互斥由主题统一管理 */
                    });
                    (window.aplayers = window.aplayers || []).push(ap);
                    if (ap.audio && typeof window.fcMarkMediaStarted === 'function') {
                        ap.audio.addEventListener('play', function () {
                            window.fcMarkMediaStarted(ap.audio);
                        });
                    }
                } catch (err) {}
            });
        }
        if (window.Artplayer) {
            document.querySelectorAll('.fc-video').forEach(function (el) {
                if (el.fcVideoInited || !el.dataset.url) return;
                el.fcVideoInited = true;
                try {
                    /* 分集（[play] 多地址）：完整列表来自容器 data-urls（\n 分隔），与分集条解耦
                       ——首页信息流剔除分集条后仍保留「下一集」控件可切换 */
                    var box = el.closest('.play-container');
                    var tabs = box ? box.querySelectorAll('.video-tab') : [];
                    var urls = (el.dataset.urls || el.dataset.url || '')
                        .split('\n').map(function (s) { return s.trim(); })
                        .filter(function (s) { return s !== ''; });
                    var switchTab = null;
                    if (urls.length > 1) {
                        el.fcTabIndex = 0;
                        switchTab = function (index) {
                            if (!urls[index] || index === el.fcTabIndex) return;
                            el.fcTabIndex = index;
                            tabs.forEach(function (t, i) { t.classList.toggle('active', i === index); });
                            art.switchUrl(urls[index]);
                            /* 最后一集隐藏「下一集」按钮（同插件） */
                            var last = index >= urls.length - 1;
                            var nextBtn = el.querySelector('.art-icon-next');
                            if (nextBtn) {
                                nextBtn.style.display = last ? 'none' : 'flex';
                                if (nextBtn.parentElement) nextBtn.parentElement.style.display = last ? 'none' : 'flex';
                            }
                        };
                        tabs.forEach(function (tab, i) {
                            tab.addEventListener('click', function () { switchTab(i); });
                        });
                    }
                    var art = new Artplayer({
                        container: el,
                        url: el.dataset.url,
                        poster: el.dataset.pic || '',
                        setting: true,
                        playbackRate: true,
                        fullscreen: true,
                        miniProgressBar: true,
                        mutex: false, /* 同上 */
                        lang: 'zh-cn',
                        /* 播放器内「下一集」控件（式样同 VideoCollector：左侧跳片尾图标） */
                        controls: urls.length > 1 ? [{
                            position: 'left',
                            index: 11,
                            html: '<i class="art-icon art-icon-next hint--rounded hint--top" aria-label="下一集" style="display: flex;"><svg width="22" height="22" viewBox="0 0 22 22" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z" fill="currentColor"></path></svg></i>',
                            click: function () {
                                var next = (el.fcTabIndex || 0) + 1;
                                if (next < urls.length) switchTab(next);
                            }
                        }] : []
                    });
                    /* 竖屏自适应（同 VideoCollector）：竖屏时包装器按真实宽高比显示
                       （40vh 上限由 CSS 控制），并联动窗口模式控件显隐 */
                    /* 单地址视频无 .play-container（[Video] 与单行 [play]），
                       包装器始终是 .fc-video 的直接父级，用 closest 定位才都能命中 */
                    var wrapper = el.closest('.play-player-wrapper');
                    var updatePortraitCompact = function () {
                        var compact = el.classList.contains('vc-portrait')
                            && !art.fullscreen && !el.fcLightbox;
                        el.classList.toggle('vc-portrait-compact', compact);
                    };
                    var applyOrientation = function () {
                        var v = art.template && art.template.$video;
                        if (!v || !v.videoWidth || !v.videoHeight) return;
                        var ratio = (v.videoWidth / v.videoHeight).toFixed(4);
                        if (v.videoHeight > v.videoWidth) {
                            if (wrapper) {
                                wrapper.style.setProperty('--vc-video-ratio', ratio);
                                wrapper.classList.add('vc-portrait');
                            }
                            el.classList.add('vc-portrait');
                        } else {
                            if (wrapper) {
                                wrapper.classList.remove('vc-portrait');
                                wrapper.style.removeProperty('--vc-video-ratio');
                            }
                            el.classList.remove('vc-portrait');
                        }
                        /* 宽高比同步写到容器上：容器被搬进灯箱后按真实比例自撑尺寸 */
                        el.style.setProperty('--vc-video-ratio', ratio);
                        updatePortraitCompact();
                    };
                    /* 时间显示：仅窗口模式按宽度隐藏（<400px）；
                       灯箱/系统全屏走原生控件逻辑，时间始终显示 */
                    var updateTimeVisibility = function () {
                        var w = (typeof art.width === 'number' && art.width > 0) ? art.width : (el.clientWidth || 0);
                        el.classList.toggle('vc-hide-time',
                            !el.fcLightbox && !art.fullscreen && w > 0 && w < 400);
                    };
                    var videoEl = art.template && art.template.$video;
                    if (videoEl) {
                        if (videoEl.videoWidth) applyOrientation();
                        videoEl.addEventListener('loadedmetadata', applyOrientation);
                    }
                    art.on('resize', updateTimeVisibility);
                    /* 容器状态标记：灯箱/系统全屏时给 .fc-video 加 fc-arts，CSS 据此切换控件显隐，
                       切换同时重估时间显隐 */
                    var syncArtState = function () {
                        el.classList.toggle('fc-arts', !!(art.fullscreen || el.fcLightbox));
                        updateTimeVisibility();
                    };
                    /* 声音状态机：灯箱/系统全屏一律有声、回容器一律静音续播，
                       两条路径走同一幂等函数，覆盖嵌套进出的全部路径组合 */
                    var syncMuted = function () {
                        if (art.fullscreen || el.fcLightbox) {
                            art.muted = false;
                            /* 切有声时向互斥体系登记开始时间（此转换无 play 事件，
                               不登记会与正在播放的音频同时出声） */
                            var v = art.template && art.template.$video;
                            if (v && typeof window.fcMarkMediaStarted === 'function') {
                                window.fcMarkMediaStarted(v);
                            }
                        } else {
                            art.muted = true;
                            if (art.paused) art.play();
                        }
                    };
                    art.on('fullscreen', function () {
                        updatePortraitCompact();
                        syncArtState();
                        syncMuted();
                    });
                    /* 视频灯箱：容器临时搬进 body 下浮层，播放不中断，点背景/×/Esc 关闭并搬回原位；
                       浮层挂 body 下不受卡片层叠上下文困锁，滚动锁定复用 html.lightbox-open */
                    var onLbKey = function (e) {
                        if ('Escape' === e.key) closeLightbox();
                    };
                    var openLightbox = function () {
                        if (el.fcLightbox) return;
                        var lb = document.querySelector('.fc-videolightbox');
                        if (!lb) {
                            lb = document.createElement('div');
                            lb.className = 'fc-videolightbox';
                            lb.innerHTML = '<button type="button" class="fc-videolightbox-close" aria-label="关闭">&times;</button>'
                                + '<div class="fc-videolightbox-box"></div>';
                            document.body.appendChild(lb);
                            /* 浮层全局唯一：关闭动作按次指向当前打开灯箱的播放器 */
                            lb.addEventListener('click', function (e) {
                                var t = e.target;
                                if (t === lb || (t.classList && t.classList.contains('fc-videolightbox-close'))) {
                                    if (typeof lb.fcClose === 'function') lb.fcClose();
                                }
                            });
                        }
                        el.fcLbParent = el.parentNode;
                        el.fcLbNext = el.nextSibling;
                        el.fcLightbox = true;
                        lb.querySelector('.fc-videolightbox-box').appendChild(el);
                        lb.classList.add('show');
                        document.documentElement.classList.add('lightbox-open');
                        lb.fcClose = closeLightbox;
                        document.addEventListener('keydown', onLbKey);
                        syncArtState();
                        syncMuted();
                    };
                    var closeLightbox = function () {
                        if (!el.fcLightbox) return;
                        el.fcLightbox = false;
                        document.removeEventListener('keydown', onLbKey);
                        if (el.fcLbParent) {
                            try { el.fcLbParent.insertBefore(el, el.fcLbNext); } catch (err) {}
                            el.fcLbParent = null;
                            el.fcLbNext = null;
                        }
                        var lb = document.querySelector('.fc-videolightbox');
                        if (lb) lb.classList.remove('show');
                        document.documentElement.classList.remove('lightbox-open');
                        syncArtState();
                        syncMuted();
                    };
                    /* 视频自动播放（后台开关 window.fcVideoAuto）：开启时露出≥一半自动静音播放、
                       滚出暂停（实例注册进单例观察器，全屏/灯箱不干预）；关闭时盖一次性透明蒙版，
                       点击静音播放并移除，之后点击播放器进灯箱 */
                    art.muted = true;
                    el.addEventListener('click', function (e) {
                        /* 灯箱/系统全屏：完全交还 Artplayer 原生交互（视频区点击切换播放/暂停、
                           控制条原生处理），配套 CSS 已让 .art-bottom 整层穿透、仅子控件可点 */
                        if (el.fcLightbox || art.fullscreen) return;
                        /* 蒙版点击放行给蒙版自己的监听（本捕获监听先于蒙版执行） */
                        if (e.target && e.target.classList && e.target.classList.contains('fc-video-mask')) return;
                        /* 窗口模式：屏蔽原生交互由主题接管——暂停→静音播放；播放中→开灯箱 */
                        e.stopPropagation();
                        if (art.paused) {
                            art.muted = true;
                            art.play();
                        } else {
                            openLightbox();
                        }
                    }, true);
                    if (window.fcVideoAuto) {
                        /* 单例观察器：fcInitMedia 会随分页加载反复执行，观察器全局只建一次；
                           observe() 会立即回调当前交叉状态，首屏在可视范围内的视频直接开播 */
                        if (!window.fcVideoAutoIO) {
                            window.fcVideoAutoIO = new IntersectionObserver(function (entries) {
                                entries.forEach(function (entry) {
                                    var target = entry.target;
                                    var inst = target.fcArt;
                                    if (!inst) return;
                                    /* 系统全屏/灯箱不干预（灯箱为有声播放，不能被重置回静音） */
                                    if (inst.fullscreen || target.fcLightbox) return;
                                    if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
                                        inst.muted = true;
                                        var p = inst.play();
                                        if (p && p.catch) p.catch(function () {});
                                    } else if (!inst.paused) {
                                        try { inst.pause(); } catch (err) {}
                                    }
                                });
                            }, { threshold: [0, 0.5] });
                        }
                        el.fcArt = art;
                        window.fcVideoAutoIO.observe(el);
                    } else {
                        var mask = document.createElement('div');
                        mask.className = 'fc-video-mask';
                        mask.addEventListener('click', function (e) {
                            /* 只小窗口静音播放，不放大；蒙版移除后点击播放器才进灯箱 */
                            e.stopPropagation();
                            art.muted = true;
                            var pp = art.play();
                            if (pp && pp.catch) pp.catch(function () {});
                            mask.remove();
                        });
                        el.appendChild(mask);
                    }
                    updateTimeVisibility();
                } catch (err) {}
            });
        }
    }
    window.fcInitMedia = fcInitMedia;

    /* ============ 个人资料区头像/昵称替换 ============ */
    /* 优先级：登录用户（PHP 输出 data-login-*）> 评论者信息（fcRemembered，头像 = md5(邮箱) 拼头像源模板）> 默认 */
    function fcInitProfile() {
        var row = document.querySelector('.fc-profile-row');
        if (!row) return;
        var name = '', avatar = '';
        if (row.dataset.loginMail !== undefined && row.dataset.loginMail !== null) {
            name = row.dataset.loginName || '';
            avatar = row.dataset.loginAvatar || '';
        } else {
            var info = fcRemembered();
            if (info.author) {
                name = info.author;
                if (info.mail && window.md5 && row.dataset.avatarPre) {
                    avatar = row.dataset.avatarPre + window.md5(info.mail.toLowerCase()) + row.dataset.avatarSuf;
                }
                /* 评论者填写了网站：头像点击新窗口打开；未填写则恢复无操作 */
                var link = row.querySelector('a');
                if (link) {
                    if (info.url) {
                        link.href = /^https?:\/\//i.test(info.url) ? info.url : 'https://' + info.url;
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                    } else {
                        link.href = 'javascript:;';
                        link.removeAttribute('target');
                        link.removeAttribute('rel');
                    }
                }
            } else {
                /* 无游客记录（含「清除游客信息」后）：即时恢复默认昵称/头像 */
                name = row.dataset.defaultName || '';
                avatar = row.dataset.defaultAvatar || '';
                var link = row.querySelector('a');
                if (link) {
                    link.href = 'javascript:;';
                    link.removeAttribute('target');
                    link.removeAttribute('rel');
                }
            }
        }
        if (!name && !avatar) return;
        if (name) {
            var h4 = row.querySelector('h4');
            if (h4) h4.textContent = name;
        }
        if (avatar) {
            var img = row.querySelector('img');
            if (img && img.src !== avatar) {
                img.src = avatar;
                img.alt = name || img.alt;
            }
        }
    }
    window.fcInitProfile = fcInitProfile;

    /* ============ 发布时间相对化（逻辑参照 WeiBo-X 主题） ============ */
    /* <60s 刚刚；<30天 N分钟/小时/天前；30天外绝对时间；PHP 输出 data-time（Unix 秒）兜底，
       每 30 秒重算一次 */
    function fcFormatTime(ts) {
        var diff = Math.floor(Date.now() / 1000) - ts;
        if (isNaN(diff) || diff < 0) return '';
        if (diff < 60) return '刚刚';
        if (diff < 2592000) {
            var steps = [
                { label: '天前', value: 86400 },
                { label: '小时前', value: 3600 },
                { label: '分钟前', value: 60 }
            ];
            for (var i = 0; i < steps.length; i++) {
                if (diff >= steps[i].value) return Math.floor(diff / steps[i].value) + steps[i].label;
            }
            return Math.floor(diff / 60) + '分钟前';
        }
        return new Intl.DateTimeFormat('zh-CN', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', hour12: false
        }).format(new Date(ts * 1000)).replace(/\//g, '-');
    }
    function fcInitTime() {
        document.querySelectorAll('.fc-card-time span[data-time]').forEach(function (el) {
            var ts = parseInt(el.getAttribute('data-time'), 10);
            if (!isNaN(ts) && ts > 0) {
                var text = fcFormatTime(ts);
                if (text) el.textContent = text;
            }
        });
    }
    window.fcInitTime = fcInitTime;
    setInterval(function () {
        if (document.querySelector('.fc-card-time span[data-time]')) fcInitTime();
    }, 30000);

    /* ============ 页面级初始化（PJAX 切页后由 PJAX 模块重跑） ============ */
    /* 包含随页面替换而需重新绑定/重扫的部分；document 级委托交互与静态元素
       （顶栏、音乐、浮钮、弹窗、灯箱）均一次性绑定，不在此列 */
    window.fcInitPage = function () {
        fcInitProfile();
        fcInitTime();
        fcInitMedia();
        var loadmore = document.getElementById('fc-loadmore');
        if (loadmore) {
            /* 骨架屏模板：仿卡片结构的灰色占位（shimmer 扫光），加载中插在列表尾部 */
            var fcSkTpl = '<div class="fc-card fc-skeleton" aria-hidden="true">' +
                '<div class="fc-sk-avatar"></div>' +
                '<div class="fc-sk-main">' +
                    '<div class="fc-sk-line" style="width:40%"></div>' +
                    '<div class="fc-sk-line" style="width:25%"></div>' +
                    '<div class="fc-sk-line" style="width:100%"></div>' +
                    '<div class="fc-sk-line" style="width:88%"></div>' +
                    '<div class="fc-sk-grid"><div class="fc-sk-cell"></div><div class="fc-sk-cell"></div><div class="fc-sk-cell"></div></div>' +
                    '<div class="fc-sk-line" style="width:30%"></div>' +
                '</div>' +
            '</div>';
            var fcShowSkeleton = function () {
                var list = document.getElementById('fc-list');
                if (!list || list.querySelector('.fc-skeleton')) return;
                var html = fcSkTpl + fcSkTpl;
                list.insertAdjacentHTML('beforeend', html);
            };
            var fcHideSkeleton = function () {
                document.querySelectorAll('.fc-skeleton').forEach(function (n) { n.remove(); });
            };

            var fcLoadNext = function () {
                var next = loadmore.getAttribute('data-next');
                if (!next || loadmore.dataset.loading === '1') return;
                delete loadmore.dataset.failed;
                loadmore.dataset.loading = '1';
                loadmore.textContent = '加载中..';
                fcShowSkeleton();

                fetch(next, { credentials: 'same-origin' })
                    .then(function (res) { return res.text(); })
                    .then(function (html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        var list = document.getElementById('fc-list');
                        var items = doc.querySelectorAll('#fc-list .fc-card');
                        fcHideSkeleton();
                        items.forEach(function (node) {
                            list.appendChild(document.adoptNode(node));
                        });

                        // 继承下一页链接（下一页页面中「加载更多」的 data-next 已是再下一页）
                        var nextNode = doc.getElementById('fc-loadmore');
                        var nextNext = nextNode ? nextNode.getAttribute('data-next') : '';
                        if (nextNext) {
                            loadmore.setAttribute('data-next', nextNext);
                            loadmore.textContent = '加载更多..';
                        } else {
                            loadmore.removeAttribute('data-next');
                            loadmore.textContent = '没有更多了';
                        }
                        fcSyncFulltextButtons();

                        // 重初始化新卡片内的播放器（VideoCollector/APlayer 插件注入的全局函数，未启用时跳过）
                        if (typeof window.initVideoCollectors === 'function') window.initVideoCollectors();
                        if (typeof window.loadMeting === 'function') window.loadMeting();
                        // 主题短代码播放器（.aplayer / .fc-video）：内建幂等标记可安全重复调用，
                        // 漏调会导致下拉加载的第二页视频/音频不初始化
                        if (typeof window.fcInitMedia === 'function') window.fcInitMedia();
                        // 新卡片节点已并入 document，全文扫描即可（已有语言标注的会跳过）
                        fcAutoDetectCode(document);
                        // 新卡片发布时间相对化
                        fcInitTime();
                    })
                    .catch(function () {
                        fcHideSkeleton();
                        loadmore.textContent = '加载失败，点击重试';
                        loadmore.dataset.failed = '1';
                    })
                    .finally(function () {
                        delete loadmore.dataset.loading;
                        // 仍在自动触发范围内且本次未失败：链式继续加载下一页
                        // （必须放在 finally 里，loading 标记此时才已清除，递归才会真正执行）
                        if (!loadmore.dataset.failed
                            && loadmore.getAttribute('data-next')
                            && loadmore.getBoundingClientRect().top < window.innerHeight + 400) {
                            fcLoadNext();
                        }
                    });
            };

            // 触发点进入视口前 400px 即自动加载；失败后仍可点击重试
            // （PJAX 每次换页重建 observer；旧 disconnect 防止实例累积）
            if ('IntersectionObserver' in window) {
                if (window._fcLoader) window._fcLoader.disconnect();
                var fcLoader = new IntersectionObserver(function (entries) {
                    entries.forEach(function (en) {
                        if (en.isIntersecting) fcLoadNext();
                    });
                }, { rootMargin: '400px 0px' });
                fcLoader.observe(loadmore);
                window._fcLoader = fcLoader;
            }
            loadmore.addEventListener('click', fcLoadNext);
        }

        fcSyncFulltextButtons();
        fcInitLikeState();
        fcEnableLineNumbers(document);
        fcAutoDetectCode(document);
    };

    /* ============ Prism 行号 ============ */
    // line-numbers 插件仅处理带 line-numbers 类的块，这里统一给正文代码块开启；
    // 显式标注语言的块可能已被 Prism 核心的 DOMContentLoaded 高亮先处理，需补高亮一次生成行号
    function fcEnableLineNumbers(root) {
        if (!window.Prism) return;
        (root || document).querySelectorAll('.fc-detail-text pre > code, .fc-text pre > code').forEach(function (code) {
            var pre = code.parentElement;
            if (!pre || pre.classList.contains('line-numbers')) return;
            pre.classList.add('line-numbers');
            if (/(^|\s)language-\w+/.test(code.className) && !pre.querySelector('.line-numbers-rows')) {
                Prism.highlightElement(code);
            }
        });
    }

    /* ============ Prism：未标注语言的代码块自动识别语言 ============ */
    // Prism 本身无语言检测；autoloader 只按需加载已标注语言。
    // 这里按内容特征猜测语言后补上 language-* 类，再交给 Prism/autoloader 高亮
    function fcDetectLanguage(code) {
        if (/^\s*<\?php|<\?=\s|\$\w+\s*=|->\w+\(/.test(code)) return 'php';
        if (/^\s*#!\/(usr\/bin\/env\s+)?(ba)?sh\b/.test(code)) return 'bash';
        if (/^\s*(SELECT\s|INSERT\s+INTO|UPDATE\s|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE)\b/i.test(code)) return 'sql';
        if (/\b(public|private|protected)\s+(static\s+)?(class|void|String|int)\b/.test(code)) return 'java';
        if (/^\s*(def|class)\s+\w+.*:\s*$|^\s*(import|from)\s+\w+/m.test(code)) return 'python';
        if (/\b(function\s+\w*\s*\(|const\s|let\s|var\s|=>|document\.|console\.)\b/.test(code)) return 'javascript';
        if (/^\s*[.#\w\-\[:]+\s*\{[^}]*:[^}]*;?\s*\}/.test(code) && !/^\s*</.test(code)) return 'css';
        if (/^\s*<\/?[a-z][\w-]*[^>]*>/.test(code)) return 'markup';
        if (/^\s*[[{][\s\S]*[\]}]\s*$/.test(code) && /"[^"]*"\s*:/.test(code)) return 'json';
        return null;
    }

    function fcAutoDetectCode(root) {
        if (!window.Prism) return;
        (root || document).querySelectorAll('.fc-detail-text pre > code, .fc-text pre > code').forEach(function (code) {
            // 已显式指定语言（或已识别）的不重复处理
            if (/(^|\s)language-(?!none\b)\w+/.test(code.className)) return;
            var lang = fcDetectLanguage(code.textContent || '');
            if (!lang) return;
            code.classList.add('language-' + lang);
            if (code.parentElement) code.parentElement.classList.add('language-' + lang);
            // 语言定义缺失时 autoloader 会异步加载并自动重高亮
            Prism.highlightElement(code);
        });
    }

    // Prism 核心在 head 先注册了 DOMContentLoaded 高亮，此处后注册自然排在其后
    document.addEventListener('DOMContentLoaded', function () {
        window.fcInitPage();
    });
})();

/* ============ PJAX：站内无刷新跳转 ============ */
/* fetch 新页面替换 .fc-page 主体（顶栏节点移植保留背景音乐），head 资源按需补载，
   完成后重跑页面级初始化并重载 APlayer/VideoCollector，任一环节失败整页跳转兜底 */
(function () {
    if (!window.history || !window.history.pushState || window.fcPjaxEnabled === false) return;

    var SKIP_EXT = /\.(png|jpe?g|gif|webp|svg|ico|css|js|mjs|zip|rar|7z|tar|gz|mp3|mp4|m4a|wav|ogg|flac|avi|mkv|pdf|txt|xml|rss|json|woff2?|ttf|eot)(\?|#|$)/i;
    var SKIP_PATH = /(\/admin(\/|$)|\/login\b|\/logout\b|\/action\/|\/xmlrpc\.php|\/feed\b)/i;
    var navigating = false;

    function assetUrl(node) {
        var raw = node.getAttribute('href') || node.getAttribute('src') || '';
        if (!raw) return '';
        try {
            return new URL(raw, location.href).href;
        } catch (e) {
            return '';
        }
    }

    /* 当前 head 已有资源集合（含 VideoCollector 的 meta referrer） */
    function currentAssets() {
        var have = {};
        document.head.querySelectorAll('link[href], script[src], meta[name="referrer"]').forEach(function (n) {
            have[n.tagName === 'META' ? 'meta:referrer' : assetUrl(n)] = true;
        });
        return have;
    }

    /* 新页面 head 缺失的样式/脚本/meta 动态补载（等全部 onload，避免裸样式与未定义函数） */
    function pendingAssets(doc, have) {
        var tasks = [];
        doc.head.querySelectorAll('link[rel="stylesheet"], script[src], meta[name="referrer"]').forEach(function (n) {
            if (n.tagName === 'META') {
                if (!have['meta:referrer']) {
                    document.head.appendChild(document.importNode(n, true));
                    have['meta:referrer'] = true;
                }
                return;
            }
            var url = assetUrl(n);
            if (!url || have[url]) return;
            have[url] = true;
            tasks.push(new Promise(function (resolve) {
                var el = document.createElement(n.tagName === 'LINK' ? 'link' : 'script');
                if (n.tagName === 'LINK') {
                    el.rel = 'stylesheet';
                    el.href = url;
                } else {
                    el.src = url;
                }
                el.onload = el.onerror = resolve;
                setTimeout(resolve, 8000); // CDN 缓慢时不阻塞跳转
                document.head.appendChild(el);
            }));
        });
        return Promise.all(tasks);
    }

    function replacePage(doc) {
        var oldPage = document.querySelector('.fc-page');
        var fresh = doc.querySelector('.fc-page');
        if (!oldPage || !fresh) return false; // 非主题页面（后台等）→ 交给调用方整页跳转

        // 停掉正文内媒体播放；APlayer 旧实例按插件官方 PJAX 方案逐个销毁
        // （aplayers 为 meting.js 全局实例数组，未启用插件时跳过；背景音乐 #fc-bgm 随节点移植保留，不打断）
        if (typeof aplayers !== 'undefined') {
            for (var i = 0; i < aplayers.length; i++) {
                try {
                    aplayers[i].destroy();
                } catch (e) {}
            }
        }
        document.querySelectorAll('video, audio').forEach(function (m) {
            // 背景音乐（节点移植）与封面背景视频（header 保留）均豁免，切页不打断
            if (m.id !== 'fc-bgm' && !m.closest('.fc-cover')) {
                try {
                    m.pause();
                } catch (e) {}
            }
        });

        // 背景音乐与封面/头像区跨页保留：.fc-header（含顶栏、封面视频）与
        // .fc-profile 整体原位不动，只替换其下方内容节点——媒体不打断、不重启
        var freshTopbar = fresh.querySelector('#fc-topbar');
        if (freshTopbar) freshTopbar.remove();
        var freshHeader = fresh.querySelector('.fc-header');
        if (freshHeader) freshHeader.remove();
        var freshProfile = fresh.querySelector('.fc-profile');
        if (freshProfile) freshProfile.remove();

        // 同步详情页的 Prism 文案属性（进入详情补上、离开移除）
        Array.prototype.forEach.call(document.documentElement.attributes, function (attr) {
            if (attr.name.indexOf('data-prismjs') === 0 && !doc.documentElement.hasAttribute(attr.name)) {
                document.documentElement.removeAttribute(attr.name);
            }
        });
        Array.prototype.forEach.call(doc.documentElement.attributes, function (attr) {
            if (attr.name.indexOf('data-prismjs') === 0) {
                document.documentElement.setAttribute(attr.name, attr.value);
            }
        });

        document.title = doc.title;

        // 旧 main：清除 header/profile 之外的所有子节点，再搬入新页内容节点
        Array.prototype.slice.call(oldPage.children).forEach(function (child) {
            if (child.classList.contains('fc-header') || child.classList.contains('fc-profile')) return;
            child.remove();
        });
        Array.prototype.slice.call(fresh.children).forEach(function (node) {
            oldPage.appendChild(document.adoptNode(node));
        });
        window.scrollTo(0, 0);
        return true;
    }

    /* PJAX 切页骨架：点击链接后立即把 header/profile 下方内容替换为灰色占位
       （详情卡式骨架）。封面/头像区与顶栏原位保留、全程可见，背景音乐不中断 */
    function fcShowPjaxSkeleton() {
        var page = document.querySelector('.fc-page');
        if (!page) return;
        window.scrollTo(0, 0);
        Array.prototype.slice.call(page.children).forEach(function (child) {
            if (child.classList.contains('fc-header') || child.classList.contains('fc-profile')) return;
            child.remove();
        });
        page.insertAdjacentHTML('beforeend',
            '<div class="fc-pjax-skeleton" aria-hidden="true">' +
            '<div class="fc-sk-bar"></div>' +
            '<div class="fc-card fc-skeleton">' +
                '<div class="fc-sk-avatar"></div>' +
                '<div class="fc-sk-main">' +
                    '<div class="fc-sk-line" style="width:55%;height:18px"></div>' +
                    '<div class="fc-sk-line" style="width:30%"></div>' +
                    '<div class="fc-sk-grid"><div class="fc-sk-cell"></div><div class="fc-sk-cell"></div><div class="fc-sk-cell"></div></div>' +
                    '<div class="fc-sk-line" style="width:100%"></div>' +
                    '<div class="fc-sk-line" style="width:100%"></div>' +
                    '<div class="fc-sk-line" style="width:72%"></div>' +
                    '<div class="fc-sk-line" style="width:36%;margin-top:16px"></div>' +
                '</div>' +
            '</div>' +
        '</div>');
    }

    function navigate(url, push) {
        if (navigating) return;
        navigating = true;
        if (window.NProgress) NProgress.start();
        fcShowPjaxSkeleton();
        var have = currentAssets();
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error(res.status);
                return res.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                return pendingAssets(doc, have).then(function () {
                    return doc;
                });
            })
            .then(function (doc) {
                if (!replacePage(doc)) {
                    location.href = url;
                    return;
                }
                if (push) history.pushState({ pjax: true }, '', url);
                // 主题页面级交互重跑 + 插件重载（APlayer / VideoCollector）
                if (typeof window.fcInitPage === 'function') window.fcInitPage();
                // Prism 全量高亮新页面：预标注语言块不会被自动识别流程处理，
                // 且 Prism 自身的 DOMContentLoaded 高亮在 PJAX 后不会重跑（幂等，可安全重复）
                if (window.Prism) {
                    var fcPageEl = document.querySelector('.fc-page');
                    if (fcPageEl) Prism.highlightAllUnder(fcPageEl);
                }
                if (typeof window.loadMeting === 'function') window.loadMeting();
                if (typeof window.initVideoCollectors === 'function') window.initVideoCollectors();
                // 后台「PJAX 自定义重载函数」：每行一条语句依次执行（单行失败不影响后续）
                if (Array.isArray(window.fcPjaxReloadLines) && window.fcPjaxReloadLines.length) {
                    window.fcPjaxReloadLines.forEach(function (line) {
                        try {
                            new Function(line)();
                        } catch (err) {
                            console.error('[FriendCircle] PJAX 重载语句执行失败:', line, err);
                        }
                    });
                }
                if (window.NProgress) NProgress.done();
                navigating = false;
            })
            .catch(function () {
                location.href = url; // PJAX 失败整页跳转兜底
            });
    }

    function shouldIntercept(a, e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
        if ((a.target && a.target !== '_self') || a.hasAttribute('download')) return false;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript|data):/i.test(href)) return false;
        if (SKIP_EXT.test(href) || SKIP_PATH.test(href)) return false;
        var url;
        try {
            url = new URL(href, location.href);
        } catch (err) {
            return false;
        }
        if (url.origin !== location.origin) return false;
        // 同页锚点（如评论「查看更多」的 permalink#comments）：原生定位
        if (url.hash && url.pathname + url.search === location.pathname + location.search) return false;
        return true;
    }

    /* 搜索表单无刷新提交：GET 整页跳转改为 PJAX，换页后关闭搜索弹窗；
       空关键词仅关弹窗；URL 构造异常退回原生提交 */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.classList || !form.classList.contains('fc-search-bar')) return;
        e.preventDefault();
        var input = form.querySelector('input[name="s"]');
        var kw = input ? input.value.trim() : '';
        var modal = document.getElementById('fc-search-modal');
        if (modal) modal.classList.remove('active');
        if (!kw) return;
        var url;
        try {
            url = new URL('?s=' + encodeURIComponent(kw), form.getAttribute('action') || location.href).href;
        } catch (err) {
            form.submit();
            return;
        }
        navigate(url, true);
    });

    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (!a || !shouldIntercept(a, e)) return;
        e.preventDefault();
        navigate(a.href, true);
    });

    window.addEventListener('popstate', function () {
        navigate(location.href, false);
    });

    history.replaceState({ pjax: true }, '', location.href);
})();
