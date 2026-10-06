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

    /* ============ 滚动联动：顶栏变色 + 返回顶部按钮显隐 ============ */
    /* 顶栏节点经 PJAX 移植保留，封面元素随页面替换——动态查询保证换页后判定仍正确 */
    var topbar = document.getElementById('fc-topbar');
    var backtop = document.getElementById('fc-backtop');
    if (topbar || backtop) {
        var onScroll = function () {
            var cover = document.querySelector('.fc-header');
            if (topbar && cover) {
                topbar.classList.toggle('solid', window.scrollY > cover.offsetHeight - 52);
            }
            if (backtop) {
                backtop.hidden = window.scrollY <= 300;
            }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ============ 全局媒体互斥：同一时间仅允许一个媒体播放 ============ */
    /* 任一媒体（背景音乐 / APlayer / VideoCollector 等）开始播放时，
       自动暂停其它正在播放的媒体。
       - 媒体事件不冒泡，需捕获阶段监听；VideoCollector 等插件把播放器渲染进 iframe，
         对同源 iframe 的 contentDocument 同样绑定监听，暂停时也遍历 iframe 内媒体；
       - APlayer 的 audio 元素游离于 DOM 之外（不 appendChild），原生 play 事件
         到不了 document，须通过实例 on('play') 桥接、实例 pause() 接口暂停；
       - 直接对元素 pause，插件（APlayer/ArtPlayer）UI 会随 pause 事件自行同步 */
    (function () {
        function eachDoc(fn) {
            fn(document);
            document.querySelectorAll('iframe').forEach(function (frame) {
                try {
                    if (frame.contentDocument) fn(frame.contentDocument);
                } catch (err) {} /* 跨域 iframe 不可访问，跳过 */
            });
        }

        function pauseOthers(target) {
            eachDoc(function (doc) {
                doc.querySelectorAll('audio, video').forEach(function (m) {
                    if (m !== target && !m.paused) {
                        try {
                            m.pause();
                        } catch (err) {}
                    }
                });
            });
            /* APlayer 实例（audio 不在 DOM，DOM 查询不可见） */
            (window.aplayers || []).forEach(function (ap) {
                if (ap && ap !== target && ap.audio && ap.audio !== target && !ap.audio.paused) {
                    try {
                        ap.pause();
                    } catch (err) {}
                }
            });
        }

        function bindPlay(doc) {
            doc.addEventListener('play', function (e) {
                if (e.target && e.target.pause) pauseOthers(e.target);
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
        /* APlayer 的 audio 游离于 DOM 之外，原生 play 事件到不了 document。
           实例创建时机不定：Meting 对直链歌曲同步创建，对平台歌曲（data-id）
           是 XHR 回调里才 new APlayer，因此包装 APlayer 构造函数，
           任何时机创建的实例（首次加载 / 异步回调 / PJAX 重载）都会被接入 */
        function bindAPlayer(ap) {
            if (!ap || ap.fcMutexBound) return;
            ap.fcMutexBound = true;
            /* 游离 audio 元素可直接监听原生事件，不依赖在 DOM 中 */
            if (ap.audio && typeof ap.audio.addEventListener === 'function') {
                ap.audio.addEventListener('play', function () {
                    pauseOthers(ap.audio);
                });
            }
            if (typeof ap.on === 'function') {
                ap.on('play', function () {
                    pauseOthers(ap.audio || ap);
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
           DOMContentLoaded 后兜底再试一次（防脚本顺序变化），
           并接入此时已创建的实例 */
        wrapAPlayer();
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(attachAPlayers, 0);
            });
        } else {
            setTimeout(attachAPlayers, 0);
        }
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

        var liked = false;
        try {
            liked = localStorage.getItem('fc-liked') === cid;
        } catch (err) {}

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
            try {
                if (liked) {
                    localStorage.removeItem('fc-liked');
                } else {
                    localStorage.setItem('fc-liked', cid);
                }
            } catch (err) {}

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

    /* 初始化点赞按钮状态（本地记忆） */
    (function () {
        var likedCid = null;
        try {
            likedCid = localStorage.getItem('fc-liked');
        } catch (e) {}
        if (likedCid) {
            document.querySelectorAll('.like-btn[data-cid="' + likedCid + '"]').forEach(function (btn) {
                btn.classList.add('liked');
                fcSyncLikeText(btn);
            });
        }
    })();

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

    function fcRemembered() {
        try {
            return JSON.parse(localStorage.getItem('fc-commenter') || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function fcSaveCommenter(form) {
        var info = {};
        ['author', 'mail', 'url'].forEach(function (name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (input && input.value.trim()) info[name] = input.value.trim();
        });
        try {
            localStorage.setItem('fc-commenter', JSON.stringify(info));
        } catch (e) {}
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

    /* 同步 fc-panel 显隐：无可见内容（赞块隐藏、列表为空、表单收起）时隐藏容器 */
    function fcSyncZanp(item) {
        var zanp = item && item.querySelector('.fc-panel');
        if (!zanp) return;
        var zan = zanp.querySelector('.fc-likes:not([hidden])');
        var list = zanp.querySelector('.fc-inline-list');
        var form = zanp.querySelector('.fc-inline-form');
        var hasList = !!(list && list.querySelector('li:not(.fc-inline-empty)'));
        var hasContent = !!zan
            || hasList
            || !!(form && !form.hidden && !form.classList.contains('fc-inline-closed'));
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
            // 提交成功后回到独立评论状态：清空 parent 与回复提示条
            var parentInput = form.querySelector('[name="parent"]');
            if (parentInput) parentInput.value = '';
            fcShowReplyBar(form, '');
            // 表单若嵌在评论条目下方，先复位，避免被下方列表整体替换时一并销毁
            fcResetFormPosition(form);
            var list = item && item.querySelector('.fc-inline-list');
            if (typeof data.list === 'string' && list) {
                list.innerHTML = data.list;
            }
            fcSyncZanp(item);
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
            url: modal.querySelector('[name="url"]').value.trim()
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

        // 统计条在右下角时标记 body，悬浮按钮组上移避让
        if (bar.classList.contains('fc-vc-bottom-right')) {
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

    /* ============ 页面级初始化（PJAX 切页后由 PJAX 模块重跑） ============ */
    /* 包含随页面替换而需重新绑定/重扫的部分；document 级委托交互与静态元素
       （顶栏、音乐、浮钮、弹窗、灯箱）均一次性绑定，不在此列 */
    window.fcInitPage = function () {
        var loadmore = document.getElementById('fc-loadmore');
        if (loadmore) {
            var fcLoadNext = function () {
                var next = loadmore.getAttribute('data-next');
                if (!next || loadmore.dataset.loading === '1') return;
                delete loadmore.dataset.failed;
                loadmore.dataset.loading = '1';
                loadmore.textContent = '加载中..';

                fetch(next, { credentials: 'same-origin' })
                    .then(function (res) { return res.text(); })
                    .then(function (html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        var list = document.getElementById('fc-list');
                        var items = doc.querySelectorAll('#fc-list .fc-card');
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
                        // 新卡片节点已并入 document，全文扫描即可（已有语言标注的会跳过）
                        fcAutoDetectCode(document);
                    })
                    .catch(function () {
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
        fcAutoDetectCode(document);
    };

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
/* fetch 新页面替换 .fc-page 主体（顶栏节点移植保留背景音乐与监听），head 中
   主题/插件资源按需补载；完成后重跑页面级初始化，并重载 APlayer（loadMeting）
   与 VideoCollector（initVideoCollectors）。任一环节失败整页跳转兜底。 */
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
            if (m.id !== 'fc-bgm') {
                try {
                    m.pause();
                } catch (e) {}
            }
        });

        // 背景音乐保持播放：旧顶栏在同一文档内摘出暂挂 body，换页后插回。
        // 媒体元素同文档移动不打断播放；跨文档 adoptNode 会重置媒体状态
        // （paused=true、进度清零），故顶栏节点绝不离开主文档。
        var oldTopbar = document.getElementById('fc-topbar');
        if (oldTopbar) {
            oldTopbar.hidden = true;
            document.body.appendChild(oldTopbar);
        }
        // 丢弃新页面自带顶栏（避免出现两个 #fc-bgm）
        var freshTopbar = fresh.querySelector('#fc-topbar');
        if (freshTopbar) freshTopbar.remove();

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
        oldPage.replaceWith(document.adoptNode(fresh));

        // 顶栏插回新页面头部（同文档移动，音乐持续、监听延续）
        var page = document.querySelector('.fc-page');
        if (oldTopbar && page) {
            var freshHeader = page.querySelector('.fc-header');
            if (freshHeader) {
                freshHeader.insertBefore(oldTopbar, freshHeader.firstChild);
            } else {
                page.insertBefore(oldTopbar, page.firstChild);
            }
            oldTopbar.hidden = false;
        }
        window.scrollTo(0, 0);
        return true;
    }

    function navigate(url, push) {
        if (navigating) return;
        navigating = true;
        if (window.NProgress) NProgress.start();
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
