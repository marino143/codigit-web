/* Naš chat — klijentska logika: polling, slanje, upload, prikaz. */
(function () {
    'use strict';

    const body = document.body;
    const ME = body.dataset.user;
    const PARTNER_NAME = body.dataset.partnerName;
    const CSRF = body.dataset.csrf;

    const $messages = document.getElementById('messages');
    const $loading = document.getElementById('loading');
    const $input = document.getElementById('input');
    const $sendBtn = document.getElementById('sendBtn');
    const $fileInput = document.getElementById('fileInput');
    const $peerStatus = document.getElementById('peerStatus');
    const $uploadBar = document.getElementById('uploadBar');
    const $uploadFill = document.getElementById('uploadFill');
    const $uploadText = document.getElementById('uploadText');
    const $lightbox = document.getElementById('lightbox');
    const $lightboxContent = document.getElementById('lightboxContent');

    let lastId = 0;           // zadnja primljena poruka
    let maxSeenId = 0;        // do koje smo poruke "pročitali"
    let partnerReadId = 0;    // do koje je poruke partner pročitao
    let lastDayKey = '';
    let unread = 0;
    let pollTimer = null;
    let firstLoad = true;
    const myMessageEls = [];  // za osvježavanje kvačica

    // ---------- pomoćne ----------
    const pad = n => String(n).padStart(2, '0');
    const fmtTime = ts => { const d = new Date(ts * 1000); return pad(d.getHours()) + ':' + pad(d.getMinutes()); };
    const dayKey = ts => { const d = new Date(ts * 1000); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };

    function dayLabel(ts) {
        const d = new Date(ts * 1000), now = new Date();
        const today = dayKey(Math.floor(now.getTime() / 1000));
        const yesterday = dayKey(Math.floor(now.getTime() / 1000) - 86400);
        const k = dayKey(ts);
        if (k === today) return 'Danas';
        if (k === yesterday) return 'Jučer';
        return d.toLocaleDateString('hr-HR', { day: 'numeric', month: 'long', year: 'numeric' });
    }

    function lastSeenLabel(ts) {
        if (!ts) return '';
        const d = new Date(ts * 1000), now = new Date();
        const k = dayKey(ts);
        if (k === dayKey(Math.floor(now.getTime() / 1000))) return 'zadnje viđeno danas u ' + fmtTime(ts);
        return 'zadnje viđeno ' + d.toLocaleDateString('hr-HR', { day: 'numeric', month: 'numeric' }) + ' u ' + fmtTime(ts);
    }

    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function linkify(escaped) {
        return escaped.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
    }

    function isAtBottom() {
        return $messages.scrollHeight - $messages.scrollTop - $messages.clientHeight < 80;
    }
    function scrollToBottom() {
        $messages.scrollTop = $messages.scrollHeight;
    }

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
        if (m.type === 'image') {
            html += '<div class="msg-media"><img loading="lazy" src="media.php?id=' + m.id + '" alt=""></div>';
        } else if (m.type === 'video') {
            html += '<div class="msg-media"><video preload="metadata" src="media.php?id=' + m.id + '" controls playsinline></video></div>';
        }
        if (m.body) {
            html += '<div class="msg-body">' + linkify(escapeHtml(m.body)) + '</div>';
        }
        html += '<div class="msg-meta">' + fmtTime(m.created_at);
        if (mine) {
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

    function updateTitle() {
        document.title = (unread > 0 ? '(' + unread + ') ' : '') + 'Naš chat';
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

    // ---------- polling ----------
    async function poll() {
        try {
            const visible = document.visibilityState === 'visible';
            let url = 'api.php?action=poll&since=' + lastId;
            if (visible && maxSeenId > 0) {
                url += '&read=' + maxSeenId;
                unread = 0;
            }
            const res = await fetch(url, { cache: 'no-store' });
            if (res.status === 401) { location.href = 'login.php'; return; }
            const data = await res.json();

            if ($loading) $loading.remove();

            const stick = isAtBottom() || firstLoad;
            for (const m of data.messages) {
                renderMessage(m);
                lastId = Math.max(lastId, m.id);
                maxSeenId = Math.max(maxSeenId, m.id);
                if (m.sender !== ME && !visible) unread++;
            }
            if (data.messages.length && stick) scrollToBottom();
            if (firstLoad) { scrollToBottom(); firstLoad = false; }

            const p = data.partner;
            partnerReadId = p.last_read_id || 0;
            refreshTicks();
            if (p.online) {
                $peerStatus.textContent = 'online';
                $peerStatus.classList.add('online');
            } else {
                $peerStatus.textContent = lastSeenLabel(p.last_active);
                $peerStatus.classList.remove('online');
            }
            updateTitle();
        } catch (e) {
            // mreža je pukla — probat ćemo opet u idućem krugu
            $peerStatus.textContent = 'povezivanje…';
            $peerStatus.classList.remove('online');
        } finally {
            pollTimer = setTimeout(poll, document.visibilityState === 'visible' ? 2500 : 15000);
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            clearTimeout(pollTimer);
            poll();
        }
    });

    // ---------- slanje teksta ----------
    async function sendText() {
        const text = $input.value.trim();
        if (!text) return;
        $input.value = '';
        autosize();
        $sendBtn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('body', text);
            const res = await fetch('api.php?action=send', {
                method: 'POST',
                headers: { 'X-CSRF': CSRF },
                body: fd,
            });
            if (!res.ok) throw new Error('send failed');
            clearTimeout(pollTimer);
            await poll();
        } catch (e) {
            $input.value = text; // vrati tekst da se ne izgubi
            alert('Poruka nije poslana — provjeri internet pa pokušaj opet.');
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

    function uploadFile(file) {
        return new Promise(resolve => {
            $uploadBar.hidden = false;
            $uploadFill.style.width = '0%';
            $uploadText.textContent = 'Šaljem: ' + file.name;

            const fd = new FormData();
            fd.append('file', file);

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
                    clearTimeout(pollTimer);
                    poll();
                } else {
                    let msg = 'Slanje nije uspjelo.';
                    try {
                        const err = JSON.parse(xhr.responseText);
                        if (err.error === 'type') msg = 'Taj tip datoteke nije podržan (šalji slike ili video).';
                        if (err.error === 'toobig' || err.error === 'nofile') msg = 'Datoteka je prevelika za server.';
                    } catch (_) {}
                    alert(msg);
                }
                resolve();
            });
            xhr.addEventListener('error', () => {
                $uploadBar.hidden = true;
                alert('Slanje nije uspjelo — provjeri internet.');
                resolve();
            });
            xhr.send(fd);
        });
    }

    // start
    poll();
})();
