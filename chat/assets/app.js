/* Naš chat — klijentska logika: razgovori, polling, slanje, upload, prikaz. */
(function () {
    'use strict';

    const body = document.body;
    const ME = body.dataset.user;
    const MY_NAME = body.dataset.name;
    const IS_ADMIN = body.dataset.admin === '1';
    const CSRF = body.dataset.csrf;

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
    const $convAvatar = document.getElementById('convAvatar');
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
    let firstLoad = true;
    let serverNow = 0;
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
            el.className = 'conv-item' + (c.id === activeConv ? ' active' : '');
            const preview = c.last_body
                ? (c.last_sender && c.type !== 'dm' ? c.last_sender + ': ' : '') + c.last_body
                : 'No messages yet';
            el.innerHTML =
                '<div class="conv-avatar">' + convIcon(c.type) + '</div>' +
                '<div class="conv-mid">' +
                    '<div class="conv-name">' + escapeHtml(c.name || '') + '</div>' +
                    '<div class="conv-preview">' + escapeHtml(preview) + '</div>' +
                '</div>' +
                '<div class="conv-side">' +
                    (c.last_ts ? '<div class="conv-time">' + fmtTime(c.last_ts) + '</div>' : '') +
                    (c.unread > 0 ? '<div class="conv-badge">' + c.unread + '</div>' : '') +
                '</div>';
            el.addEventListener('click', () => openConv(c.id));
            $convList.appendChild(el);
        }
    }

    function updateTitle() {
        const total = convs.reduce((s, c) => s + (c.id === activeConv && document.visibilityState === 'visible' ? 0 : c.unread), 0);
        document.title = (total > 0 ? '(' + total + ') ' : '') + 'Our Chat';
    }

    // ---------- otvaranje razgovora ----------
    function openConv(id) {
        if (activeConv === id) { body.classList.add('in-chat'); return; }
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
        body.classList.add('in-chat');

        const c = convs.find(x => x.id === id);
        if (c) {
            $convName.textContent = c.name || '';
            $convAvatar.textContent = convIcon(c.type);
            activeType = c.type;
        }
        renderConvList();
        clearTimeout(pollTimer);
        poll();
    }

    $backBtn.addEventListener('click', () => body.classList.remove('in-chat'));

    // ---------- prikaz poruka ----------
    function renderMessage(m) {
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
        el.className = 'msg ' + (mine ? 'mine' : 'theirs');
        el.dataset.id = m.id;

        let html = '';
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
        html += '<div class="msg-meta">' + fmtTime(m.created_at);
        if (mine && activeType === 'dm') {
            const read = m.id <= partnerReadId;
            html += '<span class="ticks' + (read ? ' read' : '') + '">✓✓</span>';
        }
        html += '</div>';
        el.innerHTML = html;

        const img = el.querySelector('img');
        if (img) img.addEventListener('click', () => openLightbox('image', 'media.php?id=' + m.id));

        if (mine) myMessageEls.push({ id: m.id, el });
        $messages.appendChild(el);
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
        if (!activeConv) { $peerStatus.textContent = ''; $peerClock.hidden = true; return; }

        // sat sugovornika (samo dm, i samo ako je u drugoj zoni)
        if (activeType === 'dm' && partnerTime) {
            $peerClock.textContent = '🕐 ' + partnerTime.time;
            $peerClock.title = 'Local time for them (' + partnerTime.offset + ')';
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
                $messages.innerHTML = '';
                body.classList.remove('in-chat');
                await refresh();
            } catch (e) {}
        });
    });

    // ---------- polling ----------
    async function poll() {
        try {
            const visible = document.visibilityState === 'visible';
            let url = 'api.php?action=poll';
            if (activeConv) {
                url += '&conv=' + activeConv + '&since=' + lastId;
                if (visible && maxSeenId > 0) url += '&read=' + maxSeenId;
            }
            const res = await fetch(url, { cache: 'no-store' });
            if (res.status === 401) { location.href = 'login.php'; return; }
            if (res.status === 403 || res.status === 404) {
                // izbačen iz grupe ili razgovor ne postoji
                activeConv = null;
                $composer.hidden = true;
                $infoBtn.hidden = true;
                $messages.innerHTML = '';
                body.classList.remove('in-chat');
            }
            const data = await res.json();

            convs = data.convs || convs;
            users = data.users || users;
            serverNow = data.now || Math.floor(Date.now() / 1000);

            if (data.messages) {
                const loading = $messages.querySelector('.chat-loading');
                if (loading) loading.remove();
                const stick = isAtBottom() || firstLoad;
                for (const m of data.messages) {
                    renderMessage(m);
                    lastId = Math.max(lastId, m.id);
                    maxSeenId = Math.max(maxSeenId, m.id);
                }
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
            $peerStatus.textContent = 'connecting…';
            $peerStatus.classList.remove('online');
        } finally {
            pollTimer = setTimeout(poll, document.visibilityState === 'visible' ? 2500 : 15000);
        }
    }

    async function refresh() {
        clearTimeout(pollTimer);
        await poll();
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') refresh();
    });

    // ---------- slanje teksta ----------
    async function sendText() {
        const text = $input.value.trim();
        if (!text || !activeConv) return;
        $input.value = '';
        autosize();
        $sendBtn.disabled = true;
        try {
            await api('send', { conv: activeConv, body: text });
            await refresh();
        } catch (e) {
            $input.value = text; // vrati tekst da se ne izgubi
            alert('Message not sent — check your connection and try again.');
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
                    refresh();
                } else {
                    let msg = 'Upload failed.';
                    try {
                        const err = JSON.parse(xhr.responseText);
                        if (err.error === 'type') msg = 'That file type is not supported (send photos or videos).';
                        if (err.error === 'toobig' || err.error === 'nofile') msg = 'The file is too large for the server.';
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

    // MediaRecorder koristimo samo za mp4/AAC (Safari/iOS) — taj format server zna
    // transkribirati. Ostali preglednici (Chrome/Firefox → webm/opus) snimaju preko
    // Web Audio API-ja u WAV, koji whisper čita izravno.
    function pickAudioMime() {
        return window.MediaRecorder && MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' : '';
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
                if (blob.size > 1000) sendVoice(blob, 'm4a');
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
                '<div class="conv-avatar">' + convIcon(r.conv_type) + '</div>' +
                '<div class="conv-mid">' +
                    '<div class="conv-name">' + escapeHtml(r.conv_name) + ' <span class="modal-tag">' + escapeHtml(r.sender_name) + '</span></div>' +
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

    // ---------- push notifikacije ----------
    const VAPID = body.dataset.vapid;
    const $notifBtn = document.getElementById('notifBtn');

    function b64ToU8(base64) {
        const pad = '='.repeat((4 - base64.length % 4) % 4);
        const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, c => c.charCodeAt(0));
    }

    async function pushSetup() {
        if (!VAPID || !('serviceWorker' in navigator) || !window.isSecureContext) return;
        const reg = await navigator.serviceWorker.register('sw.js').catch(() => null);
        if (!reg || !('pushManager' in reg)) return;

        $notifBtn.hidden = false;
        const sub = await reg.pushManager.getSubscription();
        setBell(!!sub);

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
            } catch (e) {
                alert('Could not enable notifications. On iPhone: add the chat to your Home Screen (Share → Add to Home Screen), open it from there, then try again.');
            }
        });
    }

    function setBell(on) {
        $notifBtn.textContent = on ? '🔔' : '🔕';
        $notifBtn.title = on ? 'Notifications are on — tap to turn off'
                             : 'Turn on notifications';
    }

    pushSetup();

    // start
    poll();
})();
