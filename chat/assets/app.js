/* Naš chat — klijentska logika: razgovori, polling, slanje, upload, prikaz. */
(function () {
    'use strict';

    const body = document.body;
    const ME = body.dataset.user;
    const MY_NAME = body.dataset.name;
    const IS_ADMIN = body.dataset.admin === '1';
    const CSRF = body.dataset.csrf;
    const ANY_CODEC = body.dataset.anycodec === '1';  // server ima ffmpeg
    const REACTIONS = (body.dataset.reactions || '').split(' ').filter(Boolean);
    let reactionsByMsg = {};   // {messageId: [{emoji, n, mine, who}]}
    let pendingReactions = null;

    const $convList = document.getElementById('convList');
    const $messages = document.getElementById('messages');
    const $emptyHint = document.getElementById('emptyHint');
    const $input = document.getElementById('input');
    const $sendBtn = document.getElementById('sendBtn');
    const $fileInput = document.getElementById('fileInput');
    const $composer = document.getElementById('composer');
    const $peerStatus = document.getElementById('peerStatus');
    const $convName = document.getElementById('convName');
    const $peerClock = document.getElementById('peerClock');
    const $infoBtn = document.getElementById('infoBtn');
    const $backBtn = document.getElementById('backBtn');
    const $newBtn = document.getElementById('newBtn');
    const $uploadBar = document.getElementById('uploadBar');
    const $uploadFill = document.getElementById('uploadFill');
    const $uploadText = document.getElementById('uploadText');
    const $lightbox = document.getElementById('lightbox');
    const $lightboxContent = document.getElementById('lightboxContent');
    const $modal = document.getElementById('modal');
    const $modalCard = document.getElementById('modalCard');

    let convs = [];          // popis razgovora (sa servera)
    let users = [];          // svi aktivni korisnici
    let activeConv = null;   // id otvorenog razgovora
    let activeType = '';     // dm | group | channel
    let lastId = 0;          // zadnja primljena poruka u otvorenom razgovoru
    let maxSeenId = 0;       // do koje smo poruke "pročitali"
    let partnerReadId = 0;   // dm: do koje je poruke partner pročitao
    let partnerUser = null;  // dm: korisničko ime sugovornika
    let partnerTime = null;  // dm: {time, offset} kod sugovornika, ako ima drugu zonu
    let lastDayKey = '';
    let pollTimer = null;
    let polling = false;      // teče li osvježavanje (spriječi preklapanje)
    let pollAgain = false;    // stigao zahtjev dok je jedno već teklo
    let firstLoad = true;
    let serverNow = 0;
    let tzReported = false;   // zonu uređaja javljamo serveru samo jednom
    let canDeleteAny = false; // smijem li brisati tuđe (osnivač grupe/kanala ili admin)
    const deletedIds = new Set();
    const flaggedIds = new Set();   // moje označene poruke u otvorenom razgovoru
    const myMessageEls = []; // za osvježavanje kvačica

    // ---------- pomoćne ----------
    const pad = n => String(n).padStart(2, '0');
    const fmtTime = ts => { const d = new Date(ts * 1000); return pad(d.getHours()) + ':' + pad(d.getMinutes()); };
    const dayKey = ts => { const d = new Date(ts * 1000); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };

    function dayLabel(ts) {
        const d = new Date(ts * 1000), now = new Date();
        const today = dayKey(Math.floor(now.getTime() / 1000));
        const yesterday = dayKey(Math.floor(now.getTime() / 1000) - 86400);
        const k = dayKey(ts);
        if (k === today) return 'Today';
        if (k === yesterday) return 'Yesterday';
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    }

    function lastSeenLabel(ts) {
        if (!ts) return '';
        const d = new Date(ts * 1000), now = new Date();
        const k = dayKey(ts);
        if (k === dayKey(Math.floor(now.getTime() / 1000))) return 'last seen today at ' + fmtTime(ts);
        return 'last seen ' + d.toLocaleDateString('en-GB', { day: 'numeric', month: 'numeric' }) + ' at ' + fmtTime(ts);
    }

    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function linkify(escaped) {
        return escaped.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
    }

    // Stabilna boja za ime pošiljatelja u grupama
    function senderColor(username) {
        let h = 0;
        for (let i = 0; i < username.length; i++) h = (h * 31 + username.charCodeAt(i)) % 360;
        return 'hsl(' + h + ', 55%, 40%)';
    }

    function convIcon(type) {
        return type === 'channel' ? '#' : (type === 'group' ? '👥' : '👤');
    }

    function isAtBottom() {
        return $messages.scrollHeight - $messages.scrollTop - $messages.clientHeight < 80;
    }
    function scrollToBottom() {
        $messages.scrollTop = $messages.scrollHeight;
    }

    async function api(action, params) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(params || {})) fd.append(k, v);
        const res = await fetch('api.php?action=' + action, {
            method: 'POST',
            headers: { 'X-CSRF': CSRF },
            body: fd,
        });
        if (res.status === 401) { location.href = 'login.php'; throw new Error('auth'); }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw Object.assign(new Error(data.error || 'api'), { data });
        return data;
    }

    // ---------- popis razgovora ----------
    function renderConvList() {
        if (searchMode) return; // pretraga upravo koristi ovaj prostor
        $convList.innerHTML = '';
        if (!convs.length) {
            $convList.innerHTML = '<div class="chat-loading">No conversations — start one (✏️)</div>';
            return;
        }
        for (const c of convs) {
            const el = document.createElement('button');
            el.className = 'conv-item' + (c.id === activeConv ? ' active' : '') + (c.pinned ? ' pinned' : '');
            const preview = c.last_body
                ? (c.last_sender && c.type !== 'dm' ? c.last_sender + ': ' : '') + c.last_body
                : 'No messages yet';
            el.innerHTML =
                '<div class="conv-mid">' +
                    '<div class="conv-name">'
                        + (c.pinned ? '<span class="conv-pin">📌</span> ' : '')
                        + (c.type !== 'dm' ? '<span class="conv-kind">' + convIcon(c.type) + '</span> ' : '')
                        + escapeHtml(c.name || '') + '</div>' +
                    '<div class="conv-preview">' + escapeHtml(preview) + '</div>' +
                '</div>' +
                '<div class="conv-side">' +
                    (c.last_ts ? '<div class="conv-time">' + fmtTime(c.last_ts) + '</div>' : '') +
                    (c.unread > 0 ? '<div class="conv-badge">' + c.unread + '</div>' : '') +
                '</div>';
            el.addEventListener('click', () => openConv(c.id));
            attachConvMenu(el, c);
            $convList.appendChild(el);
        }
    }

    /** Dugi pritisak / desni klik na razgovor: prikvači i posloži redoslijed. */
    function attachConvMenu(el, c) {
        let timer = null;
        const offer = e => {
            if (e) e.preventDefault();
            openConvMenu(c);
        };
        el.addEventListener('contextmenu', offer);
        el.addEventListener('touchstart', () => {
            clearTimeout(timer);
            timer = setTimeout(() => offer(null), 550);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach(ev =>
            el.addEventListener(ev, () => clearTimeout(timer), { passive: true }));
    }

    function openConvMenu(c) {
        const pinnedList = convs.filter(x => x.pinned);
        const idx = pinnedList.findIndex(x => x.id === c.id);

        let html = '<h2>' + (c.type !== 'dm' ? escapeHtml(convIcon(c.type)) + ' ' : '')
            + escapeHtml(c.name || '') + '</h2>'
            + '<button class="modal-row btn" id="convPin">'
            + (c.pinned ? '📌 Unpin' : '📌 Pin to top') + '</button>';

        if (c.pinned) {
            if (idx > 0) html += '<button class="modal-row btn" id="convUp">↑ Move up</button>';
            if (idx > -1 && idx < pinnedList.length - 1) html += '<button class="modal-row btn" id="convDown">↓ Move down</button>';
        }
        html += '<button class="modal-close" id="modalCloseBtn">Cancel</button>';
        openModal(html);

        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        document.getElementById('convPin').addEventListener('click', () => {
            closeModal();
            api('pin', { conv: c.id }).then(refresh).catch(() => alert('Could not pin the conversation.'));
        });
        for (const [id, dir] of [['convUp', 'up'], ['convDown', 'down']]) {
            const b = document.getElementById(id);
            if (b) b.addEventListener('click', () => {
                closeModal();
                api('pin_move', { conv: c.id, dir }).then(refresh).catch(() => {});
            });
        }
    }

    function updateTitle() {
        const openAndVisible = id => id === activeConv
            && document.visibilityState === 'visible' && document.hasFocus();
        const total = convs.reduce((s, c) => s + (openAndVisible(c.id) ? 0 : c.unread), 0);
        document.title = (total > 0 ? '(' + total + ') ' : '') + 'Our Chat';

        // broj na ikoni aplikacije (radi u instaliranoj aplikaciji: dock / home screen)
        if ('setAppBadge' in navigator) {
            try {
                total > 0 ? navigator.setAppBadge(total) : navigator.clearAppBadge();
            } catch (e) {}
        }

        // Na mobitelu popis razgovora nije vidljiv dok si u razgovoru — zato
        // gumb za povratak nosi broj nepročitanih iz OSTALIH razgovora.
        const others = convs.reduce((s, c) => s + (c.id === activeConv ? 0 : c.unread), 0);
        $backBtn.dataset.unread = others > 0 ? (others > 99 ? '99+' : String(others)) : '';
        $backBtn.classList.toggle('has-unread', others > 0);
    }

    // ---------- otvaranje razgovora ----------
    function openConv(id) {
        if (activeConv === id) { body.classList.add('in-chat'); return; }
        activeTopic = null;
        if ($alsoBar) $alsoBar.hidden = true;
        body.classList.remove('in-topic');
        activeConv = id;
        lastId = 0;
        maxSeenId = 0;
        partnerReadId = 0;
        partnerUser = null;
        partnerTime = null;
        lastDayKey = '';
        firstLoad = true;
        myMessageEls.length = 0;
        $messages.innerHTML = '<div class="chat-loading">Loading messages…</div>';
        $composer.hidden = false;
        $infoBtn.hidden = false;
        document.getElementById('filesBtn').hidden = false;
        document.getElementById('topicsBtn').hidden = false;
        body.classList.add('in-chat');

        const c = convs.find(x => x.id === id);
        if (c) {
            $convName.textContent = (c.type === 'dm' ? '' : convIcon(c.type) + ' ') + (c.name || '');
            activeType = c.type;
        }
        renderConvList();
        clearTimeout(pollTimer);
        poll();
    }

    $backBtn.addEventListener('click', () => {
        if (activeTopic) { leaveTopic(); return; }   // iz teme natrag u razgovor
        body.classList.remove('in-chat');
    });

    // ---------- prikaz poruka ----------
    function renderMessage(m) {
        // Ista poruka može stići kroz dva preklopljena osvježavanja — ne crtaj je dvaput.
        if ($messages.querySelector('[data-id="' + m.id + '"]')) return;

        const k = dayKey(m.created_at);
        if (k !== lastDayKey) {
            lastDayKey = k;
            const sep = document.createElement('div');
            sep.className = 'day-sep';
            sep.textContent = dayLabel(m.created_at);
            $messages.appendChild(sep);
        }

        const mine = m.sender === ME;
        const el = document.createElement('div');
        el.className = 'msg ' + (mine ? 'mine' : 'theirs')
            + (flaggedIds.has(m.id) ? ' flagged' : '');
        el.dataset.id = m.id;

        let html = '';
        if (m.reply) html += replyBlockHtml(m.reply);
        if (!mine && activeType !== 'dm') {
            html += '<div class="msg-sender" style="color:' + senderColor(m.sender) + '">'
                + escapeHtml(m.sender_name || m.sender) + '</div>';
        }
        if (m.type === 'image') {
            html += '<div class="msg-media"><img loading="lazy" src="media.php?id=' + m.id + '" alt=""></div>';
        } else if (m.type === 'video') {
            html += '<div class="msg-media"><video preload="metadata" src="media.php?id=' + m.id + '" controls playsinline></video></div>';
        } else if (m.type === 'audio') {
            html += '<div class="msg-audio"><audio preload="metadata" src="media.php?id=' + m.id + '" controls></audio></div>';
            html += '<div class="msg-transcript" data-transcript-id="' + m.id + '">'
                + transcriptHtml(m.transcript) + '</div>';
        }
        if (m.body) {
            html += '<div class="msg-body">' + linkify(escapeHtml(m.body)) + '</div>';
        }
        const t = !activeTopic && topicsByRoot[m.id];
        if (t) {
            html += '<button class="msg-topic" data-topic="' + t.id + '">🧵 '
                + (t.replies ? t.replies + (t.replies === 1 ? ' reply' : ' replies') : 'Topic')
                + '</button>';
        }
        html += '<div class="msg-meta">'
            + (m.edited_at ? '<span class="msg-edited">edited</span>' : '')
            + fmtTime(m.created_at);
        if (mine && activeType === 'dm') {
            const read = m.id <= partnerReadId;
            html += '<span class="ticks' + (read ? ' read' : '') + '">✓✓</span>';
        }
        html += '</div>';
        el.innerHTML = html;

        const img = el.querySelector('img');
        if (img) img.addEventListener('click', () => openLightbox('image', 'media.php?id=' + m.id));

        const topicBtn = el.querySelector('[data-topic]');
        if (topicBtn) topicBtn.addEventListener('click', e => {
            e.stopPropagation();
            const info = topicsByRoot[m.id];
            enterTopic({ id: parseInt(topicBtn.dataset.topic, 10), title: info ? info.title : 'Topic', conv: activeConv });
        });

        const quote = el.querySelector('[data-jump]');
        if (quote) quote.addEventListener('click', e => {
            e.stopPropagation();
            const target = $messages.querySelector('[data-id="' + quote.dataset.jump + '"]');
            if (target) {
                target.scrollIntoView({ block: 'center' });
                target.classList.add('jumped');
                setTimeout(() => target.classList.remove('jumped'), 1600);
            }
        });

        if (mine) myMessageEls.push({ id: m.id, el });
        attachDeleteGesture(el, m, mine);
        $messages.appendChild(el);
    }

    /** Dugi pritisak (mobitel) ili desni klik (računalo) otvara izbornik poruke. */
    function attachDeleteGesture(el, m, mine) {
        let timer = null;
        const offer = e => {
            if (e) e.preventDefault();
            openMessageMenu(m, el, mine);
        };

        el.addEventListener('contextmenu', offer);
        el.addEventListener('touchstart', () => {
            clearTimeout(timer);
            timer = setTimeout(() => offer(null), 550);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach(ev =>
            el.addEventListener(ev, () => clearTimeout(timer), { passive: true }));
    }

    /** Izbornik nad porukom: označavanje i (ako smije) brisanje. */
    function openMessageMenu(m, el, mine) {
        const isFlagged = flaggedIds.has(m.id);
        const canDelete = mine || canDeleteAny;

        const mineRx = (reactionsByMsg[m.id] || []).filter(r => r.mine).map(r => r.emoji);
        let html = '<h2>Message</h2>'
            + '<div class="modal-section"><p class="modal-hint">'
            + escapeHtml((m.sender_name || '') + ' · ' + fmtDate(m.created_at)) + '</p>'
            + '<div class="rx-picker">'
            + REACTIONS.map(e => '<button class="rx-pick' + (mineRx.includes(e) ? ' on' : '')
                + '" data-emoji="' + escapeHtml(e) + '">' + e + '</button>').join('')
            + '</div></div>'
            + (activeTopic ? '' : '<button class="modal-row btn" id="msgTopic">🧵 '
                + (topicsByRoot[m.id] ? 'Open topic (' + topicsByRoot[m.id].replies + ')' : 'Open topic')
                + '</button>')
            + (mine && m.type === 'text'
                ? '<button class="modal-row btn" id="msgEdit">✏️ Edit</button>' : '')
            + '<button class="modal-row btn" id="msgReply">↩︎ Reply</button>'
            + '<button class="modal-row btn" id="msgFlag">'
            + (isFlagged ? '☆ Remove highlight' : '⭐ Highlight this message') + '</button>';
        if (canDelete) {
            html += '<button class="modal-row btn danger-row" id="msgDelete">🗑 Delete for everyone</button>';
        }
        html += '<button class="modal-close" id="modalCloseBtn">Cancel</button>';
        openModal(html);

        $modalCard.querySelectorAll('[data-emoji]').forEach(b => b.addEventListener('click', () => {
            closeModal();
            api('react', { id: m.id, emoji: b.dataset.emoji })
                .then(refresh)
                .catch(() => alert('Could not add the reaction.'));
        }));

        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        const topicBtn = document.getElementById('msgTopic');
        if (topicBtn) topicBtn.addEventListener('click', () => {
            closeModal();
            openTopicForMessage(m);
        });
        const editBtn = document.getElementById('msgEdit');
        if (editBtn) editBtn.addEventListener('click', () => { closeModal(); startEdit(m); });

        document.getElementById('msgReply').addEventListener('click', () => {
            closeModal();
            startReply(m);
        });
        document.getElementById('msgFlag').addEventListener('click', () => {
            closeModal();
            api('flag', { id: m.id })
                .then(r => {
                    if (r.flagged) { flaggedIds.add(m.id); el.classList.add('flagged'); }
                    else { flaggedIds.delete(m.id); el.classList.remove('flagged'); }
                })
                .catch(() => alert('Could not update the highlight.'));
        });
        const del = document.getElementById('msgDelete');
        if (del) del.addEventListener('click', () => { closeModal(); askDelete(m, el); });
    }

    function askDelete(m, el) {
        const what = m.type === 'text' ? 'this message'
            : (m.type === 'image' ? 'this photo' : m.type === 'video' ? 'this video' : 'this voice message');
        const who = m.sender === ME ? '' : ' from ' + (m.sender_name || m.sender);
        if (!confirm('Delete ' + what + who + '? This removes it for everyone.')) return;

        api('delete_message', { id: m.id })
            .then(() => {
                el.remove();
                deletedIds.add(m.id);
                refresh();
            })
            .catch(() => alert('Could not delete the message.'));
    }

    /** Oznaka "🧵 N replies" na porukama koje imaju otvorenu temu. */
    function applyTopics() {
        if (activeTopic) return;
        $messages.querySelectorAll('.msg').forEach(el => {
            const t = topicsByRoot[el.dataset.id];
            let btn = el.querySelector('.msg-topic');
            if (!t) { if (btn) btn.remove(); return; }

            const label = '🧵 ' + (t.replies
                ? t.replies + (t.replies === 1 ? ' reply' : ' replies') : 'Topic');
            if (!btn) {
                btn = document.createElement('button');
                btn.className = 'msg-topic';
                btn.dataset.topic = t.id;
                const meta = el.querySelector('.msg-meta');
                meta ? el.insertBefore(btn, meta) : el.appendChild(btn);
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    enterTopic({ id: t.id, title: t.title, conv: activeConv });
                });
            }
            btn.textContent = label;
        });
    }

    /** Reakcije se crtaju kao traka ispod poruke; klik na svoju je miče. */
    function applyReactions(map) {
        if (!map) return;
        reactionsByMsg = map;
        $messages.querySelectorAll('.msg').forEach(el => {
            const list = map[el.dataset.id] || [];
            let bar = el.querySelector('.rx-bar');
            if (!list.length) { if (bar) bar.remove(); return; }
            if (!bar) {
                bar = document.createElement('div');
                bar.className = 'rx-bar';
                const meta = el.querySelector('.msg-meta');
                meta ? el.insertBefore(bar, meta) : el.appendChild(bar);
            }
            bar.innerHTML = list.map(r =>
                '<button class="rx' + (r.mine ? ' mine' : '') + '" data-rx="' + escapeHtml(r.emoji)
                + '" title="' + escapeHtml(r.who) + '">' + r.emoji
                + '<span class="rx-n">' + r.n + '</span></button>').join('');
            bar.querySelectorAll('[data-rx]').forEach(b => b.addEventListener('click', e => {
                e.stopPropagation();
                api('react', { id: parseInt(el.dataset.id, 10), emoji: b.dataset.rx })
                    .then(refresh).catch(() => {});
            }));
        });
    }

    function applyFlags(ids) {
        if (!Array.isArray(ids)) return;
        flaggedIds.clear();
        ids.forEach(id => flaggedIds.add(id));
        $messages.querySelectorAll('.msg').forEach(el => {
            el.classList.toggle('flagged', flaggedIds.has(parseInt(el.dataset.id, 10)));
        });
    }

    function applyDeletions(ids) {
        for (const id of ids || []) {
            if (deletedIds.has(id)) continue;
            deletedIds.add(id);
            const el = $messages.querySelector('[data-id="' + id + '"]');
            if (el) el.remove();
        }
    }

    function refreshTicks() {
        for (const { id, el } of myMessageEls) {
            const t = el.querySelector('.ticks');
            if (t && id <= partnerReadId) t.classList.add('read');
        }
    }

    // transkript: null/undefined = još se radi, '' = nije uspjelo/tišina
    function transcriptHtml(tr) {
        if (tr === null || tr === undefined) return '<span class="transcribing">Transcribing…</span>';
        if (tr === '') return '';
        return escapeHtml(tr);
    }

    function applyTranscripts(map) {
        for (const [id, tr] of Object.entries(map || {})) {
            const el = $messages.querySelector('[data-transcript-id="' + id + '"]');
            if (el && el.dataset.done !== '1') {
                el.innerHTML = transcriptHtml(tr);
                if (tr !== null) el.dataset.done = '1';
            }
        }
    }

    // ---------- status (online / članovi) ----------
    function updateStatus() {
        if (activeTopic) return;   // u temi zaglavlje pokazuje naslov teme
        if (!activeConv) { $peerStatus.textContent = ''; $peerClock.hidden = true; return; }

        // sat sugovornika ispod statusa (samo dm, i samo ako je u drugoj zoni)
        if (activeType === 'dm' && partnerTime) {
            $peerClock.textContent = '🕐 ' + partnerTime.time + ' their time (' + partnerTime.offset + ')';
            $peerClock.hidden = false;
        } else {
            $peerClock.hidden = true;
        }

        if (activeType === 'dm') {
            const partner = users.find(u => u.username === partnerUser);
            if (partner && serverNow - partner.last_active < 15) {
                $peerStatus.textContent = 'online';
                $peerStatus.classList.add('online');
            } else {
                $peerStatus.textContent = partner ? lastSeenLabel(partner.last_active) : '';
                $peerStatus.classList.remove('online');
            }
        } else {
            const online = users.filter(u => u.username !== ME && serverNow - u.last_active < 15).length;
            $peerStatus.textContent = online ? online + ' online' : '';
            $peerStatus.classList.remove('online');
        }
    }

    // ---------- lightbox ----------
    function openLightbox(type, src) {
        $lightboxContent.innerHTML = type === 'image'
            ? '<img src="' + src + '" alt="">'
            : '<video src="' + src + '" controls autoplay playsinline></video>';
        $lightbox.hidden = false;
    }
    function closeLightbox() {
        $lightbox.hidden = true;
        $lightboxContent.innerHTML = '';
    }
    document.getElementById('lightboxClose').addEventListener('click', closeLightbox);
    $lightbox.addEventListener('click', e => { if (e.target === $lightbox) closeLightbox(); });

    // ---------- modali ----------
    function openModal(html) {
        $modalCard.innerHTML = html;
        $modal.hidden = false;
    }
    function closeModal() {
        $modal.hidden = true;
        $modalCard.innerHTML = '';
    }
    $modal.addEventListener('click', e => { if (e.target === $modal) closeModal(); });

    function userCheckboxes(exclude) {
        return users
            .filter(u => u.username !== ME && !(exclude || []).includes(u.username))
            .map(u => '<label class="modal-row"><input type="checkbox" value="' + escapeHtml(u.username) + '"> '
                + escapeHtml(u.name) + ' <span class="modal-tag">@' + escapeHtml(u.username) + '</span></label>')
            .join('') || '<p class="modal-hint">No other users.</p>';
    }

    $newBtn.addEventListener('click', () => {
        openModal(
            '<h2>New conversation</h2>' +
            '<div class="modal-section"><h3>👤 Direct message</h3><div id="dmUsers">' +
            users.filter(u => u.username !== ME).map(u =>
                '<button class="modal-row btn" data-dm="' + escapeHtml(u.username) + '">'
                + escapeHtml(u.name) + ' <span class="modal-tag">@' + escapeHtml(u.username) + '</span></button>'
            ).join('') +
            '</div></div>' +
            '<div class="modal-section"><h3>👥 New group</h3>' +
            '<input id="groupName" placeholder="Group name" maxlength="50">' +
            '<div id="groupUsers">' + userCheckboxes() + '</div>' +
            '<button class="modal-primary" id="createGroupBtn">Create group</button></div>' +
            (IS_ADMIN
                ? '<div class="modal-section"><h3># New channel <span class="modal-tag">members you choose</span></h3>' +
                  '<input id="channelName" placeholder="Channel name" maxlength="50">' +
                  '<div id="channelUsers">' + userCheckboxes() + '</div>' +
                  '<button class="modal-primary" id="createChannelBtn">Create channel</button></div>'
                : '') +
            '<button class="modal-close" id="modalCloseBtn">Close</button>'
        );

        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        $modalCard.querySelectorAll('[data-dm]').forEach(btn => btn.addEventListener('click', async () => {
            try {
                const r = await api('create_dm', { user: btn.dataset.dm });
                closeModal();
                await refresh();
                openConv(r.id);
            } catch (e) { alert('Something went wrong — try again.'); }
        }));
        document.getElementById('createGroupBtn').addEventListener('click', async () => {
            const name = document.getElementById('groupName').value.trim();
            if (!name) { alert('Enter a group name.'); return; }
            const members = [...$modalCard.querySelectorAll('#groupUsers input:checked')].map(i => i.value);
            try {
                const r = await api('create_group', { name, members: JSON.stringify(members) });
                closeModal();
                await refresh();
                openConv(r.id);
            } catch (e) { alert('Something went wrong — try again.'); }
        });
        const chBtn = document.getElementById('createChannelBtn');
        if (chBtn) chBtn.addEventListener('click', async () => {
            const name = document.getElementById('channelName').value.trim();
            if (!name) { alert('Enter a channel name.'); return; }
            const members = [...$modalCard.querySelectorAll('#channelUsers input:checked')].map(i => i.value);
            try {
                const r = await api('create_channel', { name, members: JSON.stringify(members) });
                closeModal();
                await refresh();
                openConv(r.id);
            } catch (e) { alert('Something went wrong — try again.'); }
        });
    });

    // ---------- galerija: sve datoteke iz razgovora ----------
    document.getElementById('filesBtn').addEventListener('click', async () => {
        if (!activeConv) return;
        openModal('<h2>🗂 Files</h2><p class="modal-hint">Loading…</p>');
        let data;
        try {
            const res = await fetch('api.php?action=files&conv=' + activeConv, { cache: 'no-store' });
            data = await res.json();
        } catch (e) {
            openModal('<h2>🗂 Files</h2><p class="modal-hint">Could not load files.</p>'
                + '<button class="modal-close" id="modalCloseBtn">Close</button>');
            document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
            return;
        }

        const g = data.groups || {};
        const sections = [
            ['image', '📷 Photos', g.image || []],
            ['video', '🎬 Videos', g.video || []],
            ['audio', '🎤 Voice messages', g.audio || []],
        ];
        const total = sections.reduce((s, [, , list]) => s + list.length, 0);

        let html = '<h2>🗂 Files</h2>';
        if (!total) {
            html += '<p class="modal-hint">Nothing shared in this conversation yet.</p>';
        }
        for (const [kind, label, list] of sections) {
            if (!list.length) continue;
            html += '<div class="modal-section"><h3>' + label + ' <span class="modal-tag">'
                + list.length + '</span></h3>';

            if (kind === 'image') {
                html += '<div class="file-grid">';
                for (const f of list) {
                    html += '<button class="file-tile" data-open="' + f.id + '" title="'
                        + escapeHtml(f.sender_name + ' · ' + fmtDate(f.created_at)) + '">'
                        + '<img loading="lazy" src="media.php?id=' + f.id + '" alt=""></button>';
                }
                html += '</div>';
            } else {
                for (const f of list) {
                    const sub = kind === 'audio' && f.transcript
                        ? escapeHtml(f.transcript)
                        : escapeHtml(f.sender_name + ' · ' + fmtDate(f.created_at) + ' · ' + humanSize(f.size));
                    html += '<button class="modal-row btn" data-open="' + f.id + '" data-kind="' + kind + '">'
                        + '<span class="file-line">' + (kind === 'video' ? '🎬' : '🎤')
                        + ' <strong>' + escapeHtml(f.sender_name) + '</strong>'
                        + ' <span class="modal-tag">' + fmtDate(f.created_at) + '</span>'
                        + '<span class="file-sub">' + sub + '</span></span></button>';
                }
            }
            html += '</div>';
        }
        html += '<button class="modal-close" id="modalCloseBtn">Close</button>';
        openModal(html);

        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        $modalCard.querySelectorAll('[data-open]').forEach(el => el.addEventListener('click', () => {
            const id = el.dataset.open;
            const kind = el.dataset.kind || 'image';
            if (kind === 'audio') {
                // glasovnu poruku pusti odmah, bez zatvaranja popisa
                const a = new Audio('media.php?id=' + id);
                a.play().catch(() => {});
                return;
            }
            closeModal();
            openLightbox(kind === 'video' ? 'video' : 'image', 'media.php?id=' + id);
        }));
    });

    function fmtDate(ts) {
        const d = new Date(ts * 1000);
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ' ' + fmtTime(ts);
    }

    $infoBtn.addEventListener('click', async () => {
        if (!activeConv) return;
        let info;
        try {
            const res = await fetch('api.php?action=conv_info&conv=' + activeConv, { cache: 'no-store' });
            info = await res.json();
        } catch (e) { return; }

        let html = '<h2>' + convIcon(info.type) + ' ' + escapeHtml(info.name || '') + '</h2>';
        html += '<div class="modal-section"><h3>Members</h3>';
        for (const m of info.members) {
            html += '<div class="modal-row">' + escapeHtml(m.name)
                + ' <span class="modal-tag">@' + escapeHtml(m.username) + '</span>'
                + (m.local_time ? ' <span class="modal-tag">🕐 ' + escapeHtml(m.local_time.time) + '</span>' : '')
                + (m.username === info.created_by ? ' <span class="modal-tag">owner</span>' : '')
                + (info.can_manage && m.username !== info.created_by
                    ? ' <button class="modal-mini danger" data-remove="' + escapeHtml(m.username) + '">remove</button>'
                    : '')
                + '</div>';
        }
        html += '</div>';
        if (info.can_manage) {
            const inGroup = info.members.map(m => m.username);
            const candidates = users.filter(u => !inGroup.includes(u.username));
            if (candidates.length) {
                html += '<div class="modal-section"><h3>Add member</h3>'
                    + candidates.map(u => '<button class="modal-row btn" data-add="' + escapeHtml(u.username) + '">'
                        + escapeHtml(u.name) + ' <span class="modal-tag">@' + escapeHtml(u.username) + '</span></button>').join('')
                    + '</div>';
            }
        }
        if (info.type !== 'dm' && info.created_by !== ME) {
            html += '<button class="modal-mini danger" id="leaveBtn">Leave ' + (info.type === 'channel' ? 'channel' : 'group') + '</button>';
        }
        html += '<button class="modal-close" id="modalCloseBtn">Close</button>';
        openModal(html);

        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        $modalCard.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Remove @' + b.dataset.remove + ' from the group?')) return;
            try { await api('remove_member', { conv: activeConv, user: b.dataset.remove }); closeModal(); } catch (e) {}
        }));
        $modalCard.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', async () => {
            try { await api('add_member', { conv: activeConv, user: b.dataset.add }); closeModal(); } catch (e) {}
        }));
        const leaveBtn = document.getElementById('leaveBtn');
        if (leaveBtn) leaveBtn.addEventListener('click', async () => {
            if (!confirm('Leave this conversation?')) return;
            try {
                await api('leave', { conv: activeConv });
                closeModal();
                activeConv = null;
                $composer.hidden = true;
                $infoBtn.hidden = true;
                document.getElementById("filesBtn").hidden = true;
                $messages.innerHTML = '';
                body.classList.remove('in-chat');
                await refresh();
            } catch (e) {}
        });
    });

    // ---------- polling ----------
    async function poll() {
        // Dva paralelna osvježavanja dohvate isti raspon poruka (isti `since`) i
        // prikažu ih dvaput. Zato uvijek teče najviše jedno; ako u međuvremenu
        // stigne zahtjev za novim, pokrene se čim ovo završi.
        if (polling) { pollAgain = true; return; }
        polling = true;
        try {
            // "Gledam chat" znači da je prozor i vidljiv i u fokusu — inače bi
            // prozor otvoren iza drugih odmah označio sve pročitanim (i gutao push).
            const watching = document.visibilityState === 'visible' && document.hasFocus();
            let url = 'api.php?action=poll' + (watching ? '&visible=1' : '');
            if (activeConv) {
                url += '&conv=' + activeConv + '&since=' + lastId;
                if (watching && maxSeenId > 0) url += '&read=' + maxSeenId;
            }
            const res = await fetch(url, { cache: 'no-store' });
            if (res.status === 401) { location.href = 'login.php'; return; }
            if (res.status === 403 || res.status === 404) {
                // izbačen iz grupe ili razgovor ne postoji
                activeConv = null;
                $composer.hidden = true;
                $infoBtn.hidden = true;
                document.getElementById("filesBtn").hidden = true;
                $messages.innerHTML = '';
                body.classList.remove('in-chat');
            }
            const data = await res.json();

            if (data.offline) { showOfflineState(); return; }   // odgovor iz lokalne kopije
            showOfflineState();

            convs = data.convs || convs;
            users = data.users || users;
            serverNow = data.now || Math.floor(Date.now() / 1000);

            // Prvi put: javi serveru zonu ovog uređaja da sugovornici odmah
            // vide koliko je kod nas sati (u postavkama se može promijeniti).
            if (data.me && !data.me.timezone && !tzReported) {
                tzReported = true;
                try {
                    const guess = Intl.DateTimeFormat().resolvedOptions().timeZone;
                    if (guess) api('set_timezone', { timezone: guess }).catch(() => {});
                } catch (e) {}
            }

            if (data.topics) topicsByRoot = data.topics;

            // dok smo u temi, glavni razgovor se ne crta preko nje
            if (data.messages && !activeTopic) {
                const loading = $messages.querySelector('.chat-loading');
                if (loading) loading.remove();
                canDeleteAny = !!data.can_delete_any;
                applyDeletions(data.deleted);
                applyFlags(data.flagged);
                pendingReactions = data.reactions;
                const stick = isAtBottom() || firstLoad;
                for (const m of data.messages) {
                    if (deletedIds.has(m.id)) { lastId = Math.max(lastId, m.id); continue; }
                    renderMessage(m);
                    lastId = Math.max(lastId, m.id);
                    maxSeenId = Math.max(maxSeenId, m.id);
                }
                applyTopics();
                applyReactions(pendingReactions);   // tek kad su nove poruke iscrtane
                if (data.messages.length && stick) scrollToBottom();
                if (firstLoad) { scrollToBottom(); firstLoad = false; }
                if (typeof data.partner_read === 'number') {
                    partnerReadId = data.partner_read;
                    refreshTicks();
                }
                if (data.partner) partnerUser = data.partner;
                partnerTime = data.partner_time || null;
                applyTranscripts(data.transcripts);
            }

            renderConvList();
            updateStatus();
            updateTitle();
        } catch (e) {
            // mreža je pukla — probat ćemo opet u idućem krugu
            showOfflineState();
            $peerStatus.textContent = 'connecting…';
            $peerStatus.classList.remove('online');
        } finally {
            polling = false;
            clearTimeout(pollTimer);
            if (pollAgain) {
                pollAgain = false;
                pollTimer = setTimeout(poll, 50);
            } else {
                pollTimer = setTimeout(poll, document.visibilityState === 'visible' ? 2500 : 15000);
            }
        }
    }

    async function refresh() {
        clearTimeout(pollTimer);
        await poll();
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') refresh();
    });
    // povratak u prozor: odmah označi pročitano i osvježi brojeve
    window.addEventListener('focus', () => refresh());
    window.addEventListener('blur', () => updateTitle());

    // ---------- slanje teksta ----------
    async function sendText() {
        const text = $input.value.trim();
        if (!text || !activeConv) return;

        // uređivanje postojeće poruke umjesto slanja nove
        if (editTarget) {
            const target = editTarget;
            $sendBtn.disabled = true;
            try {
                await api('edit_message', { id: target.id, body: text });
                cancelEdit();
                activeTopic ? await loadTopic() : await refresh();
            } catch (e) {
                alert('Could not save the change.');
            } finally {
                $sendBtn.disabled = false;
                $input.focus();
            }
            return;
        }

        const params = { conv: activeConv, body: text };
        if (replyTarget) params.reply_to = replyTarget.id;
        if (activeTopic) {
            params.topic = activeTopic.id;
            if ($alsoConv.checked) params.also_conv = '1';
        }

        $input.value = '';
        autosize();
        cancelReply();

        // bez mreže poruka čeka u redu umjesto da se izgubi
        if (!navigator.onLine) {
            setOutbox(outbox().concat([{ params, text }]));
            $input.focus();
            return;
        }

        $sendBtn.disabled = true;
        try {
            await api('send', params);
            activeTopic ? await loadTopic() : await refresh();
        } catch (e) {
            if (!navigator.onLine) {
                setOutbox(outbox().concat([{ params, text }]));
            } else {
                $input.value = text; // vrati tekst da se ne izgubi
                // demo limit nije problem s mrežom — ponavljanje ne bi pomoglo
                alert(e.data && e.data.error === 'demo_rate'
                    ? 'Demo limit reached — this account can post again in a little while.'
                    : 'Message not sent — check your connection and try again.');
            }
        } finally {
            $sendBtn.disabled = false;
            $input.focus();
        }
    }

    $sendBtn.addEventListener('click', sendText);
    $input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendText();
        }
    });

    function autosize() {
        $input.style.height = 'auto';
        $input.style.height = Math.min($input.scrollHeight, 120) + 'px';
    }
    $input.addEventListener('input', autosize);

    // ---------- upload slika/videa ----------
    $fileInput.addEventListener('change', async () => {
        const files = Array.from($fileInput.files || []);
        $fileInput.value = '';
        for (const file of files) {
            await uploadFile(file);
        }
    });

    function uploadFile(file, kind) {
        return new Promise(resolve => {
            if (!activeConv) { resolve(); return; }
            $uploadBar.hidden = false;
            $uploadFill.style.width = '0%';
            $uploadText.textContent = kind === 'audio' ? 'Sending voice message…' : 'Sending: ' + file.name;

            const fd = new FormData();
            fd.append('file', file);
            fd.append('conv', activeConv);
            if (activeTopic) fd.append('topic', activeTopic.id);
            if (kind) fd.append('kind', kind);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'api.php?action=upload');
            xhr.setRequestHeader('X-CSRF', CSRF);
            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) {
                    $uploadFill.style.width = Math.round(e.loaded / e.total * 100) + '%';
                }
            });
            xhr.addEventListener('load', () => {
                $uploadBar.hidden = true;
                if (xhr.status === 200) {
                    activeTopic ? loadTopic() : refresh();
                } else {
                    let msg = 'Upload failed.';
                    try {
                        const err = JSON.parse(xhr.responseText);
                        if (err.error === 'type') msg = 'That file type is not supported (send photos or videos).';
                        if (err.error === 'toobig' || err.error === 'nofile') msg = 'The file is too large for the server.';
                        if (err.error === 'demo_rate') msg = 'Demo limit reached — this account can post again in a little while.';
                    } catch (_) {}
                    alert(msg);
                }
                resolve();
            });
            xhr.addEventListener('error', () => {
                $uploadBar.hidden = true;
                alert('Upload failed — check your connection.');
                resolve();
            });
            xhr.send(fd);
        });
    }

    // ---------- teme (niti) ----------
    let activeTopic = null;     // {id, title, conv} kad smo unutar teme
    let topicsByRoot = {};      // {rootMessageId: {id, title, replies}}

    /** Otvori temu vezanu uz poruku (kreira je ako ne postoji). */
    async function openTopicForMessage(m) {
        try {
            const t = await api('topic_open', { message_id: m.id });
            enterTopic({ id: t.id, title: t.title, conv: activeConv });
        } catch (e) {
            alert('Could not open the topic.');
        }
    }

    function enterTopic(topic) {
        activeTopic = topic;
        cancelReply();
        cancelEdit();
        clearStaged();
        $alsoConv.checked = false;
        $alsoBar.hidden = false;   // ponuda "pošalji i u razgovor"
        $messages.innerHTML = '<div class="chat-loading">Loading topic…</div>';
        $convName.textContent = '🧵 ' + topic.title;
        $peerStatus.textContent = 'Topic';
        $peerClock.hidden = true;
        body.classList.add('in-topic');
        loadTopic();
    }

    function leaveTopic() {
        activeTopic = null;
        $alsoBar.hidden = true;
        body.classList.remove('in-topic');
        const id = activeConv;
        activeConv = null;          // natjeraj openConv da ponovno učita razgovor
        openConv(id);
    }

    async function loadTopic() {
        if (!activeTopic) return;
        let data;
        try {
            const res = await fetch('api.php?action=topic_messages&topic=' + activeTopic.id, { cache: 'no-store' });
            if (!res.ok) throw new Error('load');
            data = await res.json();
        } catch (e) {
            $messages.innerHTML = '<div class="chat-empty">Could not load this topic.</div>';
            return;
        }

        $messages.innerHTML = '';
        lastDayKey = '';
        myMessageEls.length = 0;

        if (data.root) {
            const wrap = document.createElement('div');
            wrap.className = 'topic-root';
            wrap.innerHTML = '<div class="topic-root-label">Topic started from</div>';
            $messages.appendChild(wrap);
            renderMessage({ ...data.root, reply: null });
            const sep = document.createElement('div');
            sep.className = 'day-sep';
            sep.textContent = data.messages.length
                ? data.messages.length + (data.messages.length === 1 ? ' reply' : ' replies')
                : 'No replies yet';
            $messages.appendChild(sep);
        }
        // odgovori se nastavljaju na isti dan — bez ponovnog datuma odmah ispod
        for (const m of data.messages) renderMessage(m);
        scrollToBottom();
    }

    document.getElementById('topicsBtn').addEventListener('click', async () => {
        if (!activeConv) return;
        openModal('<h2>🧵 Topics</h2><p class="modal-hint">Loading…</p>');
        let data;
        try {
            const res = await fetch('api.php?action=topics&conv=' + activeConv, { cache: 'no-store' });
            data = await res.json();
        } catch (e) { data = { topics: [] }; }

        const list = data.topics || [];
        let html = '<h2>🧵 Topics</h2>';
        if (!list.length) {
            html += '<p class="modal-hint">No topics yet. Long-press a message (or right-click) '
                + 'and choose “Open topic” to start one.</p>';
        }
        for (const t of list) {
            html += '<button class="modal-row btn" data-topic="' + t.id + '">'
                + '<span class="file-line"><strong>' + escapeHtml(t.title) + '</strong>'
                + '<span class="file-sub">' + t.replies
                + (t.replies === 1 ? ' reply' : ' replies') + ' · ' + fmtDate(t.last_at) + '</span></span></button>';
        }
        html += '<button class="modal-close" id="modalCloseBtn">Close</button>';
        openModal(html);

        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        $modalCard.querySelectorAll('[data-topic]').forEach(el => el.addEventListener('click', () => {
            const t = list.find(x => x.id === parseInt(el.dataset.topic, 10));
            closeModal();
            enterTopic({ id: t.id, title: t.title, conv: activeConv });
        }));
    });

    // ---------- rad bez mreže ----------
    const $offlineBar = document.getElementById('offlineBar');
    const $offlineText = document.getElementById('offlineText');
    const QUEUE_KEY = 'chat.outbox';

    function outbox() {
        try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); } catch (e) { return []; }
    }
    function setOutbox(list) {
        try { localStorage.setItem(QUEUE_KEY, JSON.stringify(list)); } catch (e) {}
        showOfflineState();
    }

    function showOfflineState() {
        const pending = outbox().length;
        if (!navigator.onLine) {
            $offlineText.textContent = pending
                ? "You're offline — " + pending + (pending === 1 ? ' message' : ' messages')
                  + ' will send when you\'re back.'
                : "You're offline — showing the last messages loaded.";
            $offlineBar.hidden = false;
        } else if (pending) {
            $offlineText.textContent = 'Sending ' + pending
                + (pending === 1 ? ' queued message…' : ' queued messages…');
            $offlineBar.hidden = false;
        } else {
            $offlineBar.hidden = true;
        }
    }

    /** Poruke napisane bez mreže čekaju u redu i šalju se čim se veza vrati. */
    async function flushOutbox() {
        if (!navigator.onLine) return;
        let list = outbox();
        while (list.length) {
            const item = list[0];
            try {
                await api('send', item.params);
            } catch (e) {
                if (!navigator.onLine) return;   // i dalje bez mreže — probaj kasnije
            }
            list = outbox().slice(1);
            setOutbox(list);
        }
        if (activeConv) activeTopic ? loadTopic() : refresh();
    }

    window.addEventListener('online', () => { showOfflineState(); flushOutbox(); });
    window.addEventListener('offline', showOfflineState);

    // ---------- uređivanje poruke ----------
    const $editBar = document.getElementById('editBar');
    let editTarget = null;

    function startEdit(m) {
        cancelReply();
        editTarget = m;
        document.getElementById('editOriginal').textContent = (m.body || '').slice(0, 120);
        $editBar.hidden = false;
        $input.value = m.body || '';
        autosize();
        $input.focus();
    }

    function cancelEdit() {
        if (!editTarget) return;
        editTarget = null;
        $editBar.hidden = true;
        $input.value = '';
        autosize();
    }
    document.getElementById('editCancel').addEventListener('click', cancelEdit);

    // ---------- odgovor na poruku ----------
    const $replyBar = document.getElementById('replyBar');
    const $alsoBar = document.getElementById('alsoBar');
    const $alsoConv = document.getElementById('alsoConv');
    let replyTarget = null;

    function startReply(m) {
        replyTarget = m;
        const preview = m.type === 'text' ? (m.body || '')
            : m.type === 'image' ? '📷 Photo'
            : m.type === 'video' ? '🎬 Video'
            : '🎤 ' + (m.transcript || 'Voice message');
        document.getElementById('replyTo').textContent = 'Replying to ' + (m.sender_name || m.sender);
        document.getElementById('replyText').textContent = preview.slice(0, 120);
        $replyBar.hidden = false;
        $input.focus();
    }

    function cancelReply() {
        replyTarget = null;
        $replyBar.hidden = true;
    }
    document.getElementById('replyCancel').addEventListener('click', cancelReply);

    /** Citat iznad poruke — klik skače na original. */
    function replyBlockHtml(reply) {
        return '<button class="msg-reply" data-jump="' + reply.id + '">'
            + '<span class="msg-reply-who">' + escapeHtml(reply.sender) + '</span>'
            + '<span class="msg-reply-text">' + escapeHtml(reply.text || '') + '</span></button>';
    }

    // ---------- lijepljenje (Ctrl/Cmd+V) i povlačenje datoteka ----------
    const $pasteBar = document.getElementById('pasteBar');
    const $pasteThumb = document.getElementById('pasteThumb');
    const $pasteInfo = document.getElementById('pasteInfo');
    let pendingFiles = [];

    function humanSize(b) {
        return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    }

    /** Pokaži pregled prije slanja — da slučajno zalijepljeno ne ode odmah. */
    function stageFiles(files) {
        pendingFiles = files;
        if (!files.length) return;
        const first = files[0];
        if ($pasteThumb.dataset.url) URL.revokeObjectURL($pasteThumb.dataset.url);
        if (first.type.startsWith('image/')) {
            const url = URL.createObjectURL(first);
            $pasteThumb.src = url;
            $pasteThumb.dataset.url = url;
            $pasteThumb.hidden = false;
        } else {
            $pasteThumb.hidden = true;
        }
        $pasteInfo.textContent = files.length === 1
            ? (first.type.startsWith('video/') ? '🎬 ' : first.type.startsWith('image/') ? '📷 ' : '📎 ')
              + (first.name || 'pasted file') + ' · ' + humanSize(first.size)
            : files.length + ' files · ' + humanSize(files.reduce((s, f) => s + f.size, 0));
        $pasteBar.hidden = false;
    }

    function clearStaged() {
        pendingFiles = [];
        $pasteBar.hidden = true;
        if ($pasteThumb.dataset.url) {
            URL.revokeObjectURL($pasteThumb.dataset.url);
            delete $pasteThumb.dataset.url;
        }
        $pasteThumb.removeAttribute('src');
    }

    async function sendStaged() {
        const files = pendingFiles;
        clearStaged();
        for (const f of files) await uploadFile(f);
    }

    document.getElementById('pasteSend').addEventListener('click', sendStaged);
    document.getElementById('pasteCancel').addEventListener('click', clearStaged);

    document.addEventListener('paste', e => {
        if (!activeConv) return;
        const items = (e.clipboardData && e.clipboardData.items) || [];
        const files = [];
        for (const it of items) {
            if (it.kind !== 'file') continue;
            const f = it.getAsFile();
            if (!f) continue;
            // zalijepljene slike često nemaju ime — dodijeli ga da upload prođe
            files.push(f.name ? f : new File([f], 'pasted.' + (f.type.split('/')[1] || 'png'), { type: f.type }));
        }
        if (!files.length) return;   // običan tekst — pusti normalno lijepljenje
        e.preventDefault();
        stageFiles(files);
    });

    // povlačenje datoteke u prozor razgovora
    ['dragover', 'drop'].forEach(ev => document.addEventListener(ev, e => {
        if (!activeConv) return;
        e.preventDefault();
        if (ev === 'drop') {
            const files = Array.from(e.dataTransfer && e.dataTransfer.files || []);
            if (files.length) stageFiles(files);
        }
    }));

    // Enter u polju za pisanje šalje pripremljenu datoteku ako je ima
    $input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey && pendingFiles.length && !$input.value.trim()) {
            e.preventDefault();
            sendStaged();
        }
    });

    // ---------- glasovne poruke ----------
    const $micBtn = document.getElementById('micBtn');
    let recorder = null;
    let recChunks = [];
    let recTimer = null;
    let recStart = 0;
    let recMime = '';

    function recSupported() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia
            && (window.MediaRecorder || window.AudioContext || window.webkitAudioContext));
    }

    // MediaRecorder koristimo samo za AAC u mp4 kontejneru (Safari/iOS) — jedini
    // komprimirani format koji server sigurno zna dekodirati. Kodek se traži
    // izričito jer Chrome pod "audio/mp4" snima Opus, koji macOS ne otvara.
    // Svi ostali snimaju preko Web Audio API-ja u WAV, koji whisper čita izravno.
    function pickAudioMime() {
        if (!window.MediaRecorder) return '';
        // AAC je siguran izbor svugdje; ostale (Opus) uzimamo samo ako server ima
        // ffmpeg koji ih zna dekodirati — inače bi glasovna ostala bez transkripta.
        const candidates = ANY_CODEC
            ? ['audio/mp4;codecs=mp4a.40.2', 'audio/aac', 'audio/webm;codecs=opus', 'audio/mp4']
            : ['audio/mp4;codecs=mp4a.40.2', 'audio/aac'];
        for (const t of candidates) {
            if (MediaRecorder.isTypeSupported(t)) return t;
        }
        return '';
    }

    let pcmRec = null; // {stream, ctx, source, proc, chunks, rate}

    function startPcm(stream) {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const source = ctx.createMediaStreamSource(stream);
        const proc = ctx.createScriptProcessor(4096, 1, 1);
        const chunks = [];
        proc.onaudioprocess = e => chunks.push(new Float32Array(e.inputBuffer.getChannelData(0)));
        source.connect(proc);
        proc.connect(ctx.destination); // bez spoja na izlaz Chrome ne pokreće obradu
        pcmRec = { stream, ctx, source, proc, chunks, rate: ctx.sampleRate };
    }

    function finishPcm() {
        const r = pcmRec;
        pcmRec = null;
        r.proc.disconnect();
        r.source.disconnect();
        r.stream.getTracks().forEach(t => t.stop());
        r.ctx.close();
        const total = r.chunks.reduce((s, c) => s + c.length, 0);
        const merged = new Float32Array(total);
        let off = 0;
        for (const c of r.chunks) { merged.set(c, off); off += c.length; }
        const blob = encodeWav(downsampleTo16k(merged, r.rate), 16000);
        if (blob.size > 2000) sendVoice(blob, 'wav');
    }

    // prosjek po prozoru — dovoljno dobro za govor (whisper ionako radi na 16 kHz)
    function downsampleTo16k(input, fromRate) {
        if (fromRate === 16000) return input;
        const ratio = fromRate / 16000;
        const out = new Float32Array(Math.floor(input.length / ratio));
        for (let i = 0; i < out.length; i++) {
            const a = Math.floor(i * ratio), b = Math.min(Math.floor((i + 1) * ratio), input.length);
            let sum = 0;
            for (let j = a; j < b; j++) sum += input[j];
            out[i] = b > a ? sum / (b - a) : 0;
        }
        return out;
    }

    function encodeWav(samples, rate) {
        const buf = new ArrayBuffer(44 + samples.length * 2);
        const v = new DataView(buf);
        const ws = (o, s) => { for (let i = 0; i < s.length; i++) v.setUint8(o + i, s.charCodeAt(i)); };
        ws(0, 'RIFF'); v.setUint32(4, 36 + samples.length * 2, true); ws(8, 'WAVE');
        ws(12, 'fmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
        v.setUint32(24, rate, true); v.setUint32(28, rate * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true);
        ws(36, 'data'); v.setUint32(40, samples.length * 2, true);
        for (let i = 0; i < samples.length; i++) {
            const s = Math.max(-1, Math.min(1, samples[i]));
            v.setInt16(44 + i * 2, s < 0 ? s * 0x8000 : s * 0x7fff, true);
        }
        return new Blob([buf], { type: 'audio/wav' });
    }

    function fmtRec(s) {
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    async function startRecording() {
        if (!activeConv) return;
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            alert('Microphone access was denied. Allow it in the settings for this app and try again.');
            return;
        }
        recMime = pickAudioMime();
        if (recMime) {
            recorder = new MediaRecorder(stream, { mimeType: recMime });
            recChunks = [];
            recorder.addEventListener('dataavailable', e => { if (e.data.size) recChunks.push(e.data); });
            recorder.addEventListener('stop', () => {
                stream.getTracks().forEach(t => t.stop());
                const blob = new Blob(recChunks, { type: recMime });
                if (blob.size > 1000) sendVoice(blob, 'auto');
                resetRecUi();
            });
            recorder.start();
        } else {
            startPcm(stream);
        }
        recStart = Date.now();
        $micBtn.textContent = '⏹';
        $micBtn.classList.add('recording');
        $input.disabled = true;
        recTimer = setInterval(() => {
            const s = Math.floor((Date.now() - recStart) / 1000);
            $input.placeholder = '● Recording… ' + fmtRec(s);
            if (s >= 180) stopRecording(); // limit: 3 minute
        }, 250);
        $input.placeholder = '● Recording… 0:00';
    }

    function stopRecording() {
        if (recorder && recorder.state !== 'inactive') recorder.stop();
        else if (pcmRec) { finishPcm(); resetRecUi(); }
    }

    function resetRecUi() {
        clearInterval(recTimer);
        recorder = null;
        $micBtn.textContent = '🎤';
        $micBtn.classList.remove('recording');
        $input.disabled = false;
        $input.placeholder = 'Message';
    }

    function sendVoice(blob, ext) {
        if (ext === 'auto') {
            const t = blob.type || '';
            ext = t.includes('webm') ? 'webm' : t.includes('mp4') || t.includes('aac') ? 'm4a' : 'wav';
        }
        const file = new File([blob], 'voice.' + ext, { type: blob.type });
        uploadFile(file, 'audio');
    }

    if (recSupported()) {
        $micBtn.addEventListener('click', () => {
            if (recorder || pcmRec) stopRecording();
            else startRecording();
        });
    } else {
        $micBtn.hidden = true;
    }

    // ---------- pretraga ----------
    const $searchBtn = document.getElementById('searchBtn');
    const $searchBar = document.getElementById('searchBar');
    const $searchInput = document.getElementById('searchInput');
    const $searchClose = document.getElementById('searchClose');
    let searchMode = false;
    let searchTimer = null;

    function openSearch() {
        searchMode = true;
        $searchBar.hidden = false;
        $searchInput.focus();
        renderSearchResults([]);
    }

    function closeSearch() {
        searchMode = false;
        $searchBar.hidden = true;
        $searchInput.value = '';
        renderConvList();
    }

    function renderSearchResults(results, q) {
        if (!searchMode) return;
        $convList.innerHTML = '';
        if (!q) {
            $convList.innerHTML = '<div class="chat-loading">Type at least 2 characters…</div>';
            return;
        }
        if (!results.length) {
            $convList.innerHTML = '<div class="chat-loading">No results for “' + escapeHtml(q) + '”</div>';
            return;
        }
        for (const r of results) {
            const el = document.createElement('button');
            el.className = 'conv-item';
            const icon = r.type === 'audio' ? '🎤 ' : (r.type === 'image' ? '📷 ' : (r.type === 'video' ? '🎬 ' : ''));
            const d = new Date(r.created_at * 1000);
            el.innerHTML =
                '<div class="conv-mid">' +
                    '<div class="conv-name">'
                        + (r.conv_type !== 'dm' ? '<span class="conv-kind">' + convIcon(r.conv_type) + '</span> ' : '')
                        + escapeHtml(r.conv_name) + ' <span class="modal-tag">' + escapeHtml(r.sender_name) + '</span></div>' +
                    '<div class="conv-preview">' + icon + highlight(r.snippet, q) + '</div>' +
                '</div>' +
                '<div class="conv-side"><div class="conv-time">' +
                    d.toLocaleDateString('en-GB', { day: 'numeric', month: 'numeric' }) + '</div></div>';
            el.addEventListener('click', () => { closeSearch(); openConv(r.conv); });
            $convList.appendChild(el);
        }
    }

    function highlight(text, q) {
        const esc = escapeHtml(text);
        const eq = escapeHtml(q).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return esc.replace(new RegExp('(' + eq + ')', 'ig'), '<mark>$1</mark>');
    }

    async function runSearch() {
        const q = $searchInput.value.trim();
        if (q.length < 2) { renderSearchResults([], ''); return; }
        try {
            const res = await fetch('api.php?action=search&q=' + encodeURIComponent(q), { cache: 'no-store' });
            const data = await res.json();
            if ($searchInput.value.trim() === q) renderSearchResults(data.results || [], q);
        } catch (e) { /* mreža — ignoriramo, novi pokušaj kod idućeg tipkanja */ }
    }

    $searchBtn.addEventListener('click', () => searchMode ? closeSearch() : openSearch());
    $searchClose.addEventListener('click', closeSearch);
    $searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 300);
    });
    $searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') closeSearch(); });

    // ---------- pregled označenih poruka ----------
    document.getElementById('flagBtn').addEventListener('click', async () => {
        openModal('<h2>⭐ Highlighted</h2><p class="modal-hint">Loading…</p>');
        let data;
        try {
            const res = await fetch('api.php?action=flagged', { cache: 'no-store' });
            data = await res.json();
        } catch (e) { data = { results: [] }; }

        const list = data.results || [];
        let html = '<h2>⭐ Highlighted</h2>';
        if (!list.length) {
            html += '<p class="modal-hint">Nothing highlighted yet. Long-press a message '
                + '(or right-click on a computer) and choose “Highlight”.</p>';
        }
        for (const r of list) {
            const icon = r.type === 'audio' ? '🎤 ' : r.type === 'image' ? '📷 ' : r.type === 'video' ? '🎬 ' : '';
            html += '<button class="modal-row btn" data-goto="' + r.conv + '" data-msg="' + r.id + '">'
                + '<span class="file-line"><strong>'
                + (r.conv_type !== 'dm' ? escapeHtml(convIcon(r.conv_type)) + ' ' : '')
                + escapeHtml(r.conv_name) + '</strong> '
                + '<span class="modal-tag">' + escapeHtml(r.sender_name) + ' · ' + fmtDate(r.created_at) + '</span>'
                + '<span class="file-sub">' + icon + escapeHtml(r.snippet || '(no text)') + '</span></span></button>';
        }
        html += '<button class="modal-close" id="modalCloseBtn">Close</button>';
        openModal(html);

        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        $modalCard.querySelectorAll('[data-goto]').forEach(el => el.addEventListener('click', () => {
            const conv = parseInt(el.dataset.goto, 10);
            const msgId = el.dataset.msg;
            closeModal();
            openConv(conv);
            // pričekaj da se poruke učitaju pa skoči na označenu
            let tries = 0;
            const jump = setInterval(() => {
                const target = $messages.querySelector('[data-id="' + msgId + '"]');
                if (target) {
                    clearInterval(jump);
                    target.scrollIntoView({ block: 'center' });
                    target.classList.add('jumped');
                    setTimeout(() => target.classList.remove('jumped'), 1600);
                } else if (++tries > 20) clearInterval(jump);
            }, 250);
        }));
    });

    // ---------- push notifikacije ----------
    const VAPID = body.dataset.vapid;
    const $notifBtn = document.getElementById('notifBtn');

    function b64ToU8(base64) {
        const pad = '='.repeat((4 - base64.length % 4) % 4);
        const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, c => c.charCodeAt(0));
    }

    /** Pretplati uređaj i javi serveru. Baca ako ne uspije. */
    async function pushSubscribe(reg) {
        const s = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: b64ToU8(VAPID),
        });
        const j = s.toJSON();
        await api('push_subscribe', {
            endpoint: s.endpoint,
            p256dh: j.keys.p256dh,
            auth: j.keys.auth,
        });
        setBell(true);
    }

    async function pushSetup() {
        if (!VAPID || !('serviceWorker' in navigator) || !window.isSecureContext) return;
        // verzija u adresi: CDN kešira sw.js, a ovako nova verzija stiže odmah
        const swUrl = 'sw.js' + (body.dataset.swv ? '?v=' + body.dataset.swv : '');
        const reg = await navigator.serviceWorker.register(swUrl).catch(() => null);
        if (!reg || !('pushManager' in reg) || !('Notification' in window)) return;

        $notifBtn.hidden = false;
        let sub = await reg.pushManager.getSubscription();
        setBell(!!sub);

        // Dozvola je već dana (npr. s drugog uređaja ili ranije) — uključi bez pitanja.
        if (!sub && Notification.permission === 'granted') {
            try { await pushSubscribe(reg); sub = await reg.pushManager.getSubscription(); } catch (e) {}
        }

        // Pretplata zna tiho propasti (preglednik je zamijeni, server je pročisti).
        // Zato je pri svakom otvaranju ponovno prijavimo — server je samo osvježi.
        if (sub) {
            const j = sub.toJSON();
            api('push_subscribe', {
                endpoint: sub.endpoint,
                p256dh: j.keys.p256dh,
                auth: j.keys.auth,
            }).catch(() => {});
        }

        // Nikad pitano: preglednik dopušta pitanje samo iz korisnikove geste,
        // pa umjesto tihog zvonca pokažemo jasan poziv s jednim klikom.
        if (!sub && Notification.permission === 'default' && !localStorage.getItem('notifDismissed')) {
            showNotifBanner(reg);
        }

        $notifBtn.addEventListener('click', async () => {
            try {
                const existing = await reg.pushManager.getSubscription();
                if (existing) {
                    await api('push_unsubscribe', { endpoint: existing.endpoint });
                    await existing.unsubscribe();
                    setBell(false);
                    return;
                }
                const perm = await Notification.requestPermission();
                if (perm !== 'granted') {
                    alert('Notifications were declined. If you change your mind, allow them in the settings for this app.');
                    return;
                }
                await pushSubscribe(reg);
            } catch (e) {
                alert('Could not enable notifications. On iPhone: add the chat to your Home Screen (Share → Add to Home Screen), open it from there, then try again.');
            }
        });
    }

    function showNotifBanner(reg) {
        const bar = document.getElementById('notifBanner');
        if (!bar) return;
        bar.hidden = false;
        document.getElementById('notifEnable').addEventListener('click', async () => {
            try {
                const perm = await Notification.requestPermission();
                if (perm === 'granted') await pushSubscribe(reg);
            } catch (e) {}
            bar.hidden = true;
        });
        document.getElementById('notifLater').addEventListener('click', () => {
            localStorage.setItem('notifDismissed', '1');
            bar.hidden = true;
        });
    }

    function setBell(on) {
        $notifBtn.textContent = on ? '🔔' : '🔕';
        $notifBtn.title = on ? 'Notifications are on — tap to turn off'
                             : 'Turn on notifications';
    }

    pushSetup();

    // ---------- iOS: tipkovnica ne smije odgurati zaglavlje ----------
    (function keepLayoutStable() {
        const vv = window.visualViewport;
        if (!vv) return;
        const apply = () => {
            document.documentElement.style.setProperty('--app-h', vv.height + 'px');
            // iOS zna ostaviti stranicu odscrollanu nakon zatvaranja tipkovnice
            if (window.scrollY !== 0) window.scrollTo(0, 0);
        };
        vv.addEventListener('resize', apply);
        vv.addEventListener('scroll', apply);
        window.addEventListener('orientationchange', () => setTimeout(apply, 300));
        apply();

        // kad se tipkovnica otvori, zadrži pogled na zadnjoj poruci
        $input.addEventListener('focus', () => setTimeout(() => {
            apply();
            if (isAtBottom()) scrollToBottom();
        }, 350));
    })();

    // prečac s ikone aplikacije (?go=search / ?go=flagged)
    (function handleShortcut() {
        const go = new URLSearchParams(location.search).get('go');
        if (!go) return;
        history.replaceState(null, '', location.pathname);
        setTimeout(() => {
            if (go === 'search') $searchBtn.click();
            else if (go === 'flagged') document.getElementById('flagBtn').click();
        }, 400);
    })();

    // start
    showOfflineState();
    flushOutbox();
    poll();
})();
