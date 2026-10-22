// /js/work-chat.js
// میز کار messenger (modelled on mz7): conversation list, conversation view
// (desktop column / phone sheet), polling sync, replies, reactions, files,
// voice notes, refs to customers / projects / tasks / meetings, groups.

const ChatUI = (function () {
    const esc = (v) => UI.escapeHtml(v == null ? '' : String(v));
    const faDate = (d) => (d ? UI.gregorianToPersian(String(d).slice(0, 10)) : '');
    const timeOf = (d) => String(d || '').slice(11, 16);
    const fileUrl = (id) => `${AppConfig.API_URL}?action=work_file&id=${Number(id)}`;
    const fa = (n) => Number(n || 0).toLocaleString('fa-IR');
    const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '👏', '✅'];
    const REF_ICON = { customer: 'bi-person-vcard', project: 'bi-kanban', project_task: 'bi-check2-square', meeting: 'bi-calendar-event' };
    const REF_LABEL = { customer: 'مشتری', project: 'پروژه', project_task: 'کار پروژه', meeting: 'جلسه' };

    let me = 0;
    let people = [];
    let convs = [];
    let cur = null;          // { id, data (conversation), members, cursor, messages: Map, pinned, hasMore }
    let replyTo = null;
    let pollTimer = null, listTimer = null, unreadTimer = null;
    let recorder = null, recChunks = [], recStart = 0, recTimer = null;
    let active = false;      // chat pane visible

    const can = (k) => (typeof App !== 'undefined' && App.hasPermission ? App.hasPermission(k) : false);
    const isPhone = () => !window.matchMedia('(min-width: 768px)').matches;
    const host = () => (isPhone() ? $('#chat-view-mobile') : $('#chat-view-desktop'));
    const sheet = () => bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('chat-sheet'));
    const renderBody = (b) => esc(b).replace(/@\[([^\]]{1,150})\]\(user:(\d+)\)/g, '<span class="badge text-bg-primary bg-opacity-75">@$1</span>')
        .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');

    async function loadPeople() {
        const res = await Api.call('work_people', {}, false);
        people = (res && res.people) || [];
        me = Number(res && res.me) || me;
    }

    // ---------- unread badges ----------

    async function refreshUnread() {
        if (!can('use_messenger') || document.visibilityState !== 'visible') return;
        const res = await Api.call('chat_unread', {}, false);
        const n = (res && res.total) || 0;
        $('#chat-unread-badge').text(fa(n)).toggleClass('d-none', n === 0);
        let $tb = $('#crm-tab .work-tab-badge');
        if (!$tb.length) $tb = $('<span class="badge text-bg-danger rounded-pill ms-1 work-tab-badge d-none"></span>').appendTo('#crm-tab');
        $tb.text(fa(n)).toggleClass('d-none', n === 0);
    }

    // ---------- list ----------

    // Desktop: the chat fills the window below the sub-nav, so the composer is always in view.
    function fitShell() {
        const el = document.querySelector('#crm-pane-chat .chat-shell');
        if (!el || isPhone() || !active) { if (el) el.style.height = ''; return; }
        el.style.height = Math.max(420, window.innerHeight - el.getBoundingClientRect().top - 12) + 'px';
    }

    async function show() {
        active = true;
        fitShell();
        if (!people.length) await loadPeople();
        await renderList();
        clearInterval(listTimer);
        listTimer = setInterval(() => { if (active && document.visibilityState === 'visible') renderList(); }, 10000);
    }

    function hide() {
        active = false;
        clearInterval(listTimer);
    }

    async function renderList() {
        const res = await Api.call('chat_list', {}, false);
        if (!res?.success) return;
        convs = res.conversations || [];
        me = Number(res.me) || me;
        drawList();
    }

    function drawList() {
        const q = String($('#chat-list-search').val() || '').trim();
        const rows = convs.filter(c => !q || String(c.title).includes(q));
        $('#chat-list').html(rows.map(c => `
            <div class="chat-item d-flex gap-2 align-items-center px-2 py-2 border-bottom ${cur && Number(cur.id) === Number(c.id) ? 'active' : ''}" role="button" data-open-chat="${c.id}">
                <div class="chat-avatar ${c.type === 'group' ? 'group' : ''}">${c.type === 'group' ? '<i class="bi bi-people-fill"></i>' : esc(String(c.title || '?').trim().charAt(0))}</div>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between gap-1">
                        <span class="fw-semibold text-truncate">${c.pinned ? '<i class="bi bi-pin-angle-fill text-muted me-1"></i>' : ''}${esc(c.title)}</span>
                        <span class="small text-muted text-nowrap">${esc(timeOrDate(c.last_message_at))}</span>
                    </div>
                    <div class="d-flex justify-content-between gap-1">
                        <span class="small text-muted text-truncate">${c.preview_mine ? 'شما: ' : ''}${esc(c.preview || '')}</span>
                        <span class="text-nowrap">${c.muted ? '<i class="bi bi-bell-slash text-muted small"></i>' : ''}${c.unread ? `<span class="badge ${c.muted ? 'text-bg-secondary' : 'text-bg-primary'} rounded-pill ms-1">${fa(c.unread)}</span>` : ''}</span>
                    </div>
                </div>
            </div>`).join('') || `<div class="text-center text-muted small p-4">${q ? 'گفتگویی پیدا نشد.' : 'هنوز گفتگویی ندارید. با دکمه بالا شروع کنید.'}</div>`);
    }

    function timeOrDate(dt) {
        if (!dt) return '';
        const d = String(dt).slice(0, 10);
        const t = new Date(); const today = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
        return d === today ? timeOf(dt) : faDate(dt);
    }

    // ---------- conversation ----------

    async function open(id, aroundId = null) {
        if (!people.length) await loadPeople();
        const res = await Api.call('chat_messages', aroundId ? { id, around_id: aroundId } : { id });
        if (!res?.success) return;
        cur = { id: Number(id), data: res.conversation, members: res.members, cursor: res.cursor, messages: new Map(), pinned: res.pinned, hasMore: res.has_more };
        res.messages.forEach(m => cur.messages.set(Number(m.id), m));
        replyTo = null;
        drawConversation();
        if (isPhone()) sheet().show();
        scrollToBottom(true);
        if (aroundId) setTimeout(() => flash(aroundId), 300);
        Api.call('chat_read', { id }, false).then(() => { renderList(); refreshUnread(); });
        startPolling();
    }

    function close() {
        stopPolling();
        cur = null;
        if (isPhone()) sheet().hide();
        $('#chat-view-desktop').html('<div class="m-auto text-muted text-center p-4"><i class="bi bi-chat-dots fs-1 d-block mb-2"></i>یک گفتگو را انتخاب کنید.</div>');
        drawList();
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(poll, 3000);
    }

    function stopPolling() { clearInterval(pollTimer); pollTimer = null; }

    async function poll() {
        if (!cur || document.visibilityState !== 'visible') return;
        if (!isPhone() && !active) return;
        const res = await Api.call('chat_messages', { id: cur.id, since: cur.cursor }, false);
        if (!res?.success || !cur || Number(cur.id) !== Number(res.conversation.id)) return;
        const pinChanged = (cur.pinned && cur.pinned.id) !== (res.pinned && res.pinned.id);
        cur.cursor = res.cursor;
        cur.pinned = res.pinned;
        cur.data = res.conversation;
        cur.members = res.members;
        if (!res.messages.length) { if (pinChanged) drawMessages(); return; }
        const nearBottom = isNearBottom();
        let fresh = false;
        res.messages.forEach(m => { if (!cur.messages.has(Number(m.id))) fresh = true; cur.messages.set(Number(m.id), m); });
        drawMessages();
        if (fresh && nearBottom) scrollToBottom();
        if (fresh) Api.call('chat_read', { id: cur.id }, false).then(refreshUnread);
    }

    function header() {
        const c = cur.data;
        const title = c.type === 'direct'
            ? (cur.members.find(m => Number(m.user_id) !== me)?.full_name || 'گفتگو')
            : c.title;
        return `<div class="chat-header d-flex align-items-center gap-2 p-2 border-bottom">
            <button class="btn btn-sm btn-link d-md-none" data-chat-back aria-label="بازگشت"><i class="bi bi-arrow-right fs-5"></i></button>
            <div class="chat-avatar ${c.type === 'group' ? 'group' : ''}">${c.type === 'group' ? '<i class="bi bi-people-fill"></i>' : esc(String(title).charAt(0))}</div>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold text-truncate">${esc(title)}</div>
                <div class="small text-muted">${c.type === 'group' ? `${fa(cur.members.length)} عضو` : 'گفتگوی خصوصی'}</div>
            </div>
            <button class="btn btn-sm btn-outline-secondary" data-chat-search aria-label="جستجو"><i class="bi bi-search"></i></button>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-label="گزینه‌ها"><i class="bi bi-three-dots-vertical"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item" data-chat-act="mute">${c.muted ? '<i class="bi bi-bell me-2"></i>صدادار کردن' : '<i class="bi bi-bell-slash me-2"></i>بی‌صدا کردن'}</button></li>
                    <li><button class="dropdown-item" data-chat-act="pin">${c.pinned ? '<i class="bi bi-pin me-2"></i>برداشتن سنجاق گفتگو' : '<i class="bi bi-pin-angle me-2"></i>سنجاق گفتگو'}</button></li>
                    ${c.type === 'group' ? `<li><button class="dropdown-item" data-chat-act="info"><i class="bi bi-info-circle me-2"></i>اعضا و اطلاعات گروه</button></li>
                    <li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger" data-chat-act="leave"><i class="bi bi-box-arrow-right me-2"></i>ترک گروه</button></li>` : ''}
                </ul>
            </div>
            <button class="btn btn-sm btn-link d-none d-md-inline" data-chat-close aria-label="بستن"><i class="bi bi-x-lg"></i></button>
        </div>`;
    }

    function drawConversation() {
        const $h = host();
        $h.html(`${header()}
            <div class="chat-pinned px-3 py-1 border-bottom small d-none" role="button"></div>
            <div class="chat-messages flex-grow-1 overflow-auto px-2 py-2"></div>
            <div class="chat-composer border-top p-2">
                <div class="chat-reply-bar small d-none mb-1"></div>
                <div class="chat-rec-bar small d-none mb-1 text-danger"></div>
                <div class="d-flex gap-1 align-items-end">
                    <label class="btn btn-outline-secondary btn-sm mb-0" title="فایل" aria-label="فایل"><i class="bi bi-paperclip"></i><input type="file" multiple class="d-none chat-file-input"></label>
                    <button class="btn btn-outline-secondary btn-sm" data-chat-ref title="پیوست مورد (مشتری، پروژه، جلسه)" aria-label="پیوست مورد"><i class="bi bi-link-45deg"></i></button>
                    <textarea class="form-control form-control-sm chat-input" rows="1" placeholder="پیام…" aria-label="متن پیام"></textarea>
                    <button class="btn btn-outline-danger btn-sm" data-chat-voice title="پیام صوتی" aria-label="پیام صوتی"><i class="bi bi-mic"></i></button>
                    <button class="btn btn-primary btn-sm" data-chat-send aria-label="ارسال"><i class="bi bi-send"></i></button>
                </div>
            </div>`);
        drawMessages();
        drawList();
    }

    function sortedMessages() {
        return [...cur.messages.values()].sort((a, b) => Number(a.id) - Number(b.id));
    }

    function attachmentHtml(a) {
        const url = fileUrl(a.id);
        if (a.is_voice || a.kind === 'audio') return `<audio controls preload="none" src="${url}" class="chat-audio"></audio>`;
        if (a.kind === 'image') return `<img src="${url}" class="chat-img rounded" alt="${esc(a.file_name)}" loading="lazy" data-chat-img="${url}">`;
        if (a.kind === 'video') return `<video controls preload="metadata" playsinline src="${url}" class="chat-video rounded"></video>`;
        return `<a class="btn btn-sm btn-light border chat-file" href="${url}" download="${esc(a.file_name)}"><i class="bi bi-file-earmark me-1"></i>${esc(a.file_name)}</a>`;
    }

    function refLink(type, id, projectId) {
        if (type === 'customer') return `#crm/customer/${id}`;
        if (type === 'project') return `#work/project/${id}`;
        if (type === 'meeting') return `#work/meeting/${id}`;
        if (type === 'project_task' && projectId) return `#work/project/${projectId}/task/${id}`;
        return null;
    }

    function messageHtml(m, prev) {
        if (m.kind === 'system') return `<div class="text-center my-2"><span class="badge text-bg-light text-wrap fw-normal">${esc(m.body)} · ${esc(timeOf(m.created_at))}</span></div>`;
        const showName = cur.data.type === 'group' && !m.is_mine && (!prev || prev.sender_id !== m.sender_id || prev.kind === 'system');
        const dayBreak = !prev || String(prev.created_at).slice(0, 10) !== String(m.created_at).slice(0, 10);
        const day = dayBreak ? `<div class="text-center my-2"><span class="badge text-bg-secondary bg-opacity-25 text-body fw-normal">${esc(faDate(m.created_at))}</span></div>` : '';
        if (m.deleted) {
            return `${day}<div class="chat-row ${m.is_mine ? 'mine' : ''}" id="message-${m.id}"><div class="chat-bubble deleted"><i class="bi bi-slash-circle me-1"></i>پیام حذف شد</div></div>`;
        }
        const ref = m.ref_type ? `<a href="#" class="chat-ref d-flex align-items-center gap-2 rounded p-2 mb-1" data-ref-type="${esc(m.ref_type)}" data-ref-id="${m.ref_id}" data-ref-project="${m.ref_project_id || ''}">
            <i class="bi ${REF_ICON[m.ref_type] || 'bi-link'} fs-5"></i><span class="min-w-0"><span class="small text-muted d-block">${REF_LABEL[m.ref_type] || ''}</span><span class="text-truncate d-block">${esc(m.ref_title)}</span></span></a>` : '';
        return `${day}<div class="chat-row ${m.is_mine ? 'mine' : ''}" id="message-${m.id}" data-mid="${m.id}">
            <div class="chat-bubble">
                ${showName ? `<div class="small fw-semibold text-primary">${esc(m.sender_name)}</div>` : ''}
                ${m.forwarded_from ? `<div class="small text-muted"><i class="bi bi-forward me-1"></i>بازارسال از ${esc(m.forwarded_from)}</div>` : ''}
                ${m.reply_to ? `<div class="chat-quote small rounded px-2 py-1 mb-1" role="button" data-goto-msg="${m.reply_to.id}"><b>${esc(m.reply_to.sender_name)}</b><div class="text-truncate">${esc(m.reply_to.snippet)}</div></div>` : ''}
                ${ref}
                ${m.attachments.length ? `<div class="d-flex flex-column gap-1 mb-1">${m.attachments.map(attachmentHtml).join('')}</div>` : ''}
                ${m.body ? `<div class="chat-text">${renderBody(m.body)}</div>` : ''}
                <div class="d-flex align-items-center gap-1 justify-content-end chat-meta">
                    ${m.edited_at ? '<span class="small text-muted">ویرایش‌شده</span>' : ''}
                    <span class="small text-muted">${esc(timeOf(m.created_at))}</span>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-link p-0 text-muted" data-bs-toggle="dropdown" aria-label="منوی پیام"><i class="bi bi-chevron-down"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li class="px-2 pb-1 d-flex flex-wrap gap-1">${REACTIONS.map(r => `<button class="btn btn-sm btn-light border px-1 py-0" data-react="${r}" data-mid="${m.id}">${r}</button>`).join('')}</li>
                            <li><button class="dropdown-item" data-msg-act="reply" data-mid="${m.id}"><i class="bi bi-reply me-2"></i>پاسخ</button></li>
                            ${m.body ? `<li><button class="dropdown-item" data-msg-act="copy" data-mid="${m.id}"><i class="bi bi-clipboard me-2"></i>کپی متن</button></li>` : ''}
                            <li><button class="dropdown-item" data-msg-act="forward" data-mid="${m.id}"><i class="bi bi-forward me-2"></i>بازارسال</button></li>
                            ${m.is_mine && m.body ? `<li><button class="dropdown-item" data-msg-act="edit" data-mid="${m.id}"><i class="bi bi-pencil me-2"></i>ویرایش</button></li>` : ''}
                            ${cur.data.type === 'direct' || cur.data.is_admin ? `<li><button class="dropdown-item" data-msg-act="pin" data-mid="${m.id}"><i class="bi bi-pin-angle me-2"></i>سنجاق</button></li>` : ''}
                            ${m.is_mine || (cur.data.type === 'group' && cur.data.is_admin) ? `<li><button class="dropdown-item text-danger" data-msg-act="delete" data-mid="${m.id}"><i class="bi bi-trash me-2"></i>حذف برای همه</button></li>` : ''}
                        </ul>
                    </div>
                </div>
                ${m.reactions.length ? `<div class="d-flex flex-wrap gap-1 mt-1">${m.reactions.map(r => `<button class="btn btn-sm py-0 px-1 ${r.mine ? 'btn-primary' : 'btn-light border'}" data-react="${r.emoji}" data-mid="${m.id}" title="${esc(r.users.join('، '))}">${r.emoji} ${fa(r.users.length)}</button>`).join('')}</div>` : ''}
            </div>
        </div>`;
    }

    function drawMessages() {
        const $box = host().find('.chat-messages');
        const list = sortedMessages();
        const older = cur.hasMore ? '<div class="text-center my-2"><button class="btn btn-sm btn-outline-secondary" data-chat-older>پیام‌های قبلی</button></div>' : '';
        $box.html(older + (list.map((m, i) => messageHtml(m, list[i - 1])).join('') || '<div class="text-center text-muted small mt-4">اولین پیام را بفرستید.</div>'));
        const $pin = host().find('.chat-pinned');
        if (cur.pinned) {
            $pin.removeClass('d-none').attr('data-goto-msg', cur.pinned.id).html(`<i class="bi bi-pin-angle-fill text-primary me-1"></i><b>${esc(cur.pinned.sender_name)}:</b> ${esc(String(cur.pinned.body || cur.pinned.ref_title || 'پیوست').slice(0, 90))}
                ${cur.data.type === 'direct' || cur.data.is_admin ? '<button class="btn btn-sm btn-link p-0 ms-2" data-unpin aria-label="برداشتن سنجاق"><i class="bi bi-x"></i></button>' : ''}`);
        } else {
            $pin.addClass('d-none');
        }
    }

    const isNearBottom = () => { const el = host().find('.chat-messages')[0]; return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120; };
    const scrollToBottom = (instant) => { const el = host().find('.chat-messages')[0]; if (el) el.scrollTo({ top: el.scrollHeight, behavior: instant ? 'auto' : 'smooth' }); };
    function flash(mid) {
        const el = document.getElementById(`message-${mid}`);
        if (el) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.classList.add('work-flash'); setTimeout(() => el.classList.remove('work-flash'), 2000); }
    }

    // ---------- sending ----------

    function setReply(m) {
        replyTo = m;
        const $bar = host().find('.chat-reply-bar');
        if (!m) { $bar.addClass('d-none').empty(); return; }
        $bar.removeClass('d-none').html(`<div class="d-flex align-items-center gap-2 border-start border-3 border-primary ps-2"><div class="flex-grow-1 min-w-0"><b>پاسخ به ${esc(m.sender_name)}</b><div class="text-truncate text-muted">${esc(m.body || m.ref_title || 'پیوست')}</div></div><button class="btn btn-sm btn-link p-0" data-reply-cancel aria-label="لغو پاسخ"><i class="bi bi-x"></i></button></div>`);
        host().find('.chat-input').focus();
    }

    function upload(form) {
        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', `${AppConfig.API_URL}?action=chat_send`);
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
            xhr.onload = () => { let d = null; try { d = JSON.parse(xhr.responseText); } catch (e) { d = { error: 'پاسخ سرور نامعتبر است.' }; } resolve(d); };
            xhr.onerror = () => resolve({ error: 'اتصال برقرار نشد.' });
            xhr.send(form);
        });
    }

    async function send(extra = {}, files = null, voice = false) {
        if (!cur) return;
        const $in = host().find('.chat-input');
        const body = String($in.val() || '').trim();
        if (!body && !files && !extra.ref_type) return;
        let res;
        if (files) {
            const fd = new FormData();
            fd.append('id', cur.id);
            fd.append('body', body);
            if (replyTo) fd.append('reply_to_id', replyTo.id);
            if (voice) fd.append('voice', '1');
            Array.from(files).forEach(f => fd.append('file[]', f, f.name));
            res = await upload(fd);
            if (res && res.error) UI.showError(res.error);
        } else {
            res = await Api.call('chat_send', Object.assign({ id: cur.id, body, reply_to_id: replyTo ? replyTo.id : null }, extra));
        }
        if (res?.success && cur) {
            cur.messages.set(Number(res.message.id), res.message);
            $in.val('').css('height', '');
            setReply(null);
            drawMessages();
            scrollToBottom();
            renderList();
        }
    }

    // Voice: MediaRecorder (needs HTTPS or localhost; iPhone records mp4).
    async function toggleVoice() {
        if (recorder) { recorder.stop(); return; }
        if (!window.isSecureContext || !navigator.mediaDevices || !window.MediaRecorder) {
            UI.showError('ضبط صدا فقط روی نشانی امن (HTTPS) و مرورگرهای جدید ممکن است.');
            return;
        }
        let stream;
        try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
        catch (e) { UI.showError('اجازه دسترسی به میکروفون داده نشد.'); return; }
        const type = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find(t => MediaRecorder.isTypeSupported(t)) || '';
        recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
        recChunks = [];
        recorder.ondataavailable = (e) => { if (e.data.size) recChunks.push(e.data); };
        recorder.onstop = async () => {
            stream.getTracks().forEach(t => t.stop());
            clearInterval(recTimer);
            const cancelled = recorder._cancel;
            const mime = recorder.mimeType || type || 'audio/webm';
            recorder = null;
            host().find('[data-chat-voice]').removeClass('btn-danger').addClass('btn-outline-danger');
            host().find('.chat-rec-bar').addClass('d-none');
            if (cancelled || !recChunks.length || Date.now() - recStart < 700) return;
            const ext = mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm';
            await send({}, [new File(recChunks, `voice.${ext}`, { type: mime.split(';')[0] })], true);
        };
        recorder.start();
        recStart = Date.now();
        host().find('[data-chat-voice]').removeClass('btn-outline-danger').addClass('btn-danger');
        const $bar = host().find('.chat-rec-bar').removeClass('d-none');
        const tick = () => { const s = Math.floor((Date.now() - recStart) / 1000); $bar.html(`<i class="bi bi-record-circle me-1"></i>در حال ضبط ${fa(Math.floor(s / 60))}:${String(s % 60).padStart(2, '0')} — دوباره روی میکروفون بزنید تا ارسال شود <button class="btn btn-sm btn-link p-0 ms-2" data-rec-cancel>لغو</button>`); };
        tick();
        recTimer = setInterval(tick, 1000);
    }

    // ---------- refs / sharing ----------

    async function pickRef() {
        const types = [];
        if (can('use_crm')) types.push({ value: 'customer', label: 'مشتری' });
        if (can('use_projects')) types.push({ value: 'project', label: 'پروژه' }, { value: 'meeting', label: 'جلسه' });
        if (!types.length) { UI.showError('موردی برای پیوست در دسترس نیست.'); return; }
        const t = await UI.formDialog({ title: 'پیوست مورد', fields: [{ name: 'type', label: 'نوع', type: 'select', options: types, value: types[0].value }], submitText: 'ادامه' });
        if (!t) return;
        let options = [];
        if (t.type === 'customer') options = ((App.getCache && App.getCache().customers) || []).map(c => ({ value: c.id, label: c.name }));
        if (t.type === 'project') options = ((await Api.call('work_projects', { filter: 'mine' }, false))?.projects || []).map(p => ({ value: p.id, label: p.title }));
        if (t.type === 'meeting') {
            const up = (await Api.call('work_meetings', { filter: 'upcoming' }, false))?.items || [];
            const past = (await Api.call('work_meetings', { filter: 'past' }, false))?.items || [];
            options = up.concat(past).map(m => ({ value: m.id, label: `${m.title} — ${faDate(m.starts_at)}` }));
        }
        if (!options.length) { UI.showError('موردی پیدا نشد.'); return; }
        const v = await UI.formDialog({ title: 'انتخاب ' + REF_LABEL[t.type], fields: [{ name: 'id', label: REF_LABEL[t.type], type: 'select', options, value: options[0].value }], submitText: 'ارسال در گفتگو' });
        if (v) await send({ ref_type: t.type, ref_id: Number(v.id) });
    }

    /** «ارسال در گفتگو» from a project task, meeting or customer file. */
    async function shareRef(type, id) {
        if (!can('use_messenger')) return;
        if (!people.length) await loadPeople();
        const list = (await Api.call('chat_list', {}, false))?.conversations || [];
        const opts = list.map(c => ({ value: 'c' + c.id, label: (c.type === 'group' ? 'گروه: ' : '') + c.title }))
            .concat(people.filter(p => Number(p.user_id) !== me && !list.some(c => c.type === 'direct' && Number(c.other_user_id) === Number(p.user_id)))
                .map(p => ({ value: 'u' + p.user_id, label: 'گفتگوی جدید با ' + p.full_name })));
        if (!opts.length) { UI.showError('هنوز همکاری برای گفتگو نیست.'); return; }
        const v = await UI.formDialog({ title: 'ارسال در گفتگو', fields: [
            { name: 'to', label: 'به', type: 'select', options: opts, value: opts[0].value },
            { name: 'body', label: 'پیام همراه (اختیاری)', type: 'textarea' }], submitText: 'ارسال' });
        if (!v) return;
        let cid = v.to.startsWith('c') ? Number(v.to.slice(1)) : null;
        if (!cid) { const r = await Api.call('chat_open_direct', { user_id: Number(v.to.slice(1)) }); if (!r?.success) return; cid = r.id; }
        const res = await Api.call('chat_send', { id: cid, body: v.body || '', ref_type: type, ref_id: id });
        if (res?.success) UI.showSuccess('در گفتگو فرستاده شد.');
    }

    // ---------- groups ----------

    async function newGroup() {
        if (!people.length) await loadPeople();
        const fields = [{ name: 'title', label: 'نام گروه', required: true }, { name: 'description', label: 'توضیح', type: 'textarea' }]
            .concat(people.filter(p => Number(p.user_id) !== me).map(p => ({ name: 'm_' + p.user_id, label: 'عضو: ' + p.full_name, type: 'checkbox', value: true })));
        const v = await UI.formDialog({ title: 'گروه جدید', fields, submitText: 'ساختن گروه' });
        if (!v) return;
        const res = await Api.call('chat_create_group', { title: v.title, description: v.description, member_ids: people.filter(p => v['m_' + p.user_id]).map(p => p.user_id) });
        if (res?.success) { goToChat(res.id); }
    }

    async function newDirect() {
        if (!people.length) await loadPeople();
        const others = people.filter(p => Number(p.user_id) !== me);
        if (!others.length) { UI.showError('هنوز همکار دیگری در تیم نیست.'); return; }
        const v = await UI.formDialog({ title: 'گفتگوی جدید', fields: [{ name: 'user_id', label: 'با', type: 'select', options: others.map(p => ({ value: p.user_id, label: p.full_name })), value: others[0].user_id }], submitText: 'شروع گفتگو' });
        if (!v) return;
        const res = await Api.call('chat_open_direct', { user_id: Number(v.user_id) });
        if (res?.success) goToChat(res.id);
    }

    async function groupInfo() {
        const isAdmin = cur.data.is_admin;
        const rows = cur.members.map(m => `<div class="d-flex align-items-center gap-2 py-1 border-bottom">
            <span class="flex-grow-1">${esc(m.full_name || '')} ${m.is_admin ? '<span class="badge text-bg-light">مدیر</span>' : ''}${Number(m.user_id) === me ? ' <span class="text-muted small">(شما)</span>' : ''}</span>
            ${isAdmin && Number(m.user_id) !== me ? `<button class="btn btn-sm btn-outline-secondary" data-gm-admin="${m.user_id}" data-val="${m.is_admin ? 0 : 1}">${m.is_admin ? 'برداشتن مدیر' : 'مدیر کردن'}</button>
                <button class="btn btn-sm btn-outline-danger" data-gm-remove="${m.user_id}" aria-label="حذف"><i class="bi bi-x"></i></button>` : ''}
        </div>`).join('');
        const addable = people.filter(p => !cur.members.some(m => Number(m.user_id) === Number(p.user_id)));
        const $m = $(`<div class="modal fade" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">${esc(cur.data.title)}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">${cur.data.description ? `<p class="small">${esc(cur.data.description)}</p>` : ''}${rows}
                ${isAdmin ? `<div class="d-flex gap-2 mt-3"><button class="btn btn-sm btn-outline-primary" data-g-edit>ویرایش نام و توضیح</button>
                ${addable.length ? `<select class="form-select form-select-sm" data-g-add-sel>${addable.map(p => `<option value="${p.user_id}">${esc(p.full_name)}</option>`).join('')}</select><button class="btn btn-sm btn-primary text-nowrap" data-g-add>افزودن</button>` : ''}</div>` : ''}
            </div></div></div></div>`).appendTo('body');
        const refresh = async () => { bootstrap.Modal.getInstance($m[0]).hide(); await open(cur.id); };
        $m.on('click', '[data-gm-admin]', async function () { if ((await Api.call('chat_members', { op: 'admin', id: cur.id, user_id: $(this).data('gm-admin'), is_admin: $(this).data('val') }))?.success) refresh(); });
        $m.on('click', '[data-gm-remove]', async function () { if ((await Api.call('chat_members', { op: 'remove', id: cur.id, user_id: $(this).data('gm-remove') }))?.success) refresh(); });
        $m.on('click', '[data-g-add]', async function () { if ((await Api.call('chat_members', { op: 'add', id: cur.id, user_ids: [Number($m.find('[data-g-add-sel]').val())] }))?.success) refresh(); });
        $m.on('click', '[data-g-edit]', async function () {
            bootstrap.Modal.getInstance($m[0]).hide();
            const v = await UI.formDialog({ title: 'ویرایش گروه', fields: [{ name: 'title', label: 'نام گروه', required: true, value: cur.data.title }, { name: 'description', label: 'توضیح', type: 'textarea', value: cur.data.description || '' }], submitText: 'ذخیره' });
            if (v && (await Api.call('chat_update_group', { id: cur.id, title: v.title, description: v.description }))?.success) open(cur.id);
        });
        $m.on('hidden.bs.modal', () => $m.remove());
        new bootstrap.Modal($m[0]).show();
    }

    function goToChat(id) {
        const tab = document.getElementById('crm-tab');
        if (tab && !tab.classList.contains('active')) bootstrap.Tab.getOrCreateInstance(tab).show();
        if (typeof CrmManager !== 'undefined') CrmManager.showPane('chat');
        setTimeout(() => open(id), 200);
    }

    function route() {
        const m = /^#work\/chat\/(\d+)(?:\/message\/(\d+))?$/.exec(window.location.hash || '');
        if (!m || !can('use_messenger')) return;
        history.replaceState(null, '', window.location.pathname + window.location.search);
        goToChat(Number(m[1]));
    }

    // ---------- init ----------

    function init() {
        $('#chat-list').on('click', '[data-open-chat]', function () { open(Number($(this).data('open-chat'))); });
        $('#chat-list-search').on('input', drawList);
        $('#chat-new-direct-btn').on('click', newDirect);
        $('#chat-new-group-btn').on('click', newGroup);
        const $hosts = $('#chat-view-desktop, #chat-view-mobile');
        $hosts.on('click', '[data-chat-send]', () => send());
        $hosts.on('keydown', '.chat-input', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !isPhone()) { e.preventDefault(); send(); }
        });
        $hosts.on('input', '.chat-input', function () { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 140) + 'px'; });
        $hosts.on('change', '.chat-file-input', function () { if (this.files.length) send({}, this.files); this.value = ''; });
        $hosts.on('click', '[data-chat-ref]', pickRef);
        $hosts.on('click', '[data-chat-voice]', toggleVoice);
        $hosts.on('click', '[data-rec-cancel]', () => { if (recorder) { recorder._cancel = true; recorder.stop(); } });
        $hosts.on('click', '[data-chat-back]', close);
        $hosts.on('click', '[data-chat-close]', close);
        $hosts.on('click', '[data-reply-cancel]', () => setReply(null));
        $hosts.on('click', '[data-goto-msg]', function (e) { if ($(e.target).closest('[data-unpin]').length) return; flash(Number($(this).attr('data-goto-msg'))); });
        $hosts.on('click', '[data-unpin]', async (e) => { e.stopPropagation(); if ((await Api.call('chat_pin', { id: cur.id, message_id: 0 }))?.success) { cur.pinned = null; drawMessages(); } });
        $hosts.on('click', '[data-chat-img]', function () {
            const $m = $(`<div class="modal fade" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-body p-1 text-center"><img src="${esc($(this).data('chat-img'))}" class="img-fluid rounded"></div></div></div></div>`).appendTo('body');
            $m.on('hidden.bs.modal', () => $m.remove()); new bootstrap.Modal($m[0]).show();
        });
        $hosts.on('click', '[data-chat-older]', async () => {
            const first = sortedMessages()[0];
            const res = await Api.call('chat_messages', { id: cur.id, before_id: first ? first.id : 0 });
            if (!res?.success) return;
            const el = host().find('.chat-messages')[0]; const h = el.scrollHeight;
            res.messages.forEach(m => cur.messages.set(Number(m.id), m));
            cur.hasMore = res.has_more;
            drawMessages();
            el.scrollTop = el.scrollHeight - h;
        });
        $hosts.on('click', '[data-chat-search]', async () => {
            const v = await UI.formDialog({ title: 'جستجو در گفتگو', fields: [{ name: 'q', label: 'متن', required: true }], submitText: 'جستجو' });
            if (!v) return;
            const res = await Api.call('chat_messages', { id: cur.id, search: v.q });
            const found = res?.messages || [];
            if (!found.length) { UI.showError('پیامی پیدا نشد.'); return; }
            const pick = await UI.formDialog({ title: 'نتیجه جستجو', fields: [{ name: 'mid', label: `${fa(found.length)} پیام`, type: 'select', options: found.map(m => ({ value: m.id, label: `${faDate(m.created_at)} ${m.sender_name}: ${String(m.body).slice(0, 60)}` })), value: found[0].id }], submitText: 'رفتن به پیام' });
            if (pick) open(cur.id, Number(pick.mid));
        });
        $hosts.on('click', '[data-chat-act]', async function () {
            const act = $(this).data('chat-act');
            if (act === 'mute') { await Api.call('chat_prefs', { id: cur.id, muted: cur.data.muted ? 0 : 1 }); open(cur.id); renderList(); refreshUnread(); }
            else if (act === 'pin') { await Api.call('chat_prefs', { id: cur.id, pinned: cur.data.pinned ? 0 : 1 }); open(cur.id); renderList(); }
            else if (act === 'info') groupInfo();
            else if (act === 'leave') {
                if (!await UI.confirm('از این گروه خارج می‌شوید؟')) return;
                if ((await Api.call('chat_leave', { id: cur.id }))?.success) { close(); renderList(); }
            }
        });
        $hosts.on('click', '[data-react]', async function () {
            const mid = Number($(this).data('mid'));
            if ((await Api.call('chat_react', { message_id: mid, emoji: $(this).data('react') }))?.success) poll();
        });
        $hosts.on('click', '[data-msg-act]', async function () {
            const mid = Number($(this).data('mid'));
            const m = cur.messages.get(mid);
            const act = $(this).data('msg-act');
            if (!m) return;
            if (act === 'reply') setReply(m);
            else if (act === 'copy') { try { await navigator.clipboard.writeText(m.body || ''); UI.showSuccess('کپی شد.'); } catch (e) { /* clipboard blocked */ } }
            else if (act === 'edit') {
                const v = await UI.formDialog({ title: 'ویرایش پیام', fields: [{ name: 'body', label: 'متن', type: 'textarea', required: true, value: m.body }], submitText: 'ذخیره' });
                if (v) { const r = await Api.call('chat_edit', { message_id: mid, body: v.body }); if (r?.success) { cur.messages.set(mid, r.message); drawMessages(); } }
            } else if (act === 'delete') {
                if (!await UI.confirm('این پیام برای همه حذف شود؟ قابل بازگشت نیست.')) return;
                if ((await Api.call('chat_delete', { message_id: mid }))?.success) poll();
            } else if (act === 'pin') {
                if ((await Api.call('chat_pin', { id: cur.id, message_id: mid }))?.success) poll();
            } else if (act === 'forward') {
                const opts = convs.filter(c => Number(c.id) !== Number(cur.id)).map(c => ({ value: c.id, label: (c.type === 'group' ? 'گروه: ' : '') + c.title }));
                if (!opts.length) { UI.showError('گفتگوی دیگری ندارید.'); return; }
                const v = await UI.formDialog({ title: 'بازارسال به', fields: [{ name: 'to', label: 'گفتگو', type: 'select', options: opts, value: opts[0].value }], submitText: 'بازارسال' });
                if (v && (await Api.call('chat_forward', { message_id: mid, to_id: Number(v.to) }))?.success) { UI.showSuccess('بازارسال شد.'); renderList(); }
            }
        });
        $hosts.on('click', '.chat-ref', async function (e) {
            e.preventDefault();
            const type = $(this).data('ref-type'), id = Number($(this).data('ref-id'));
            if (isPhone()) sheet().hide();
            const link = refLink(type, id, Number($(this).data('ref-project')) || 0);
            if (link) { if (window.location.hash === link) window.dispatchEvent(new HashChangeEvent('hashchange')); else window.location.hash = link; }
        });
        document.getElementById('chat-sheet').addEventListener('hidden.bs.offcanvas', () => { if (isPhone()) { stopPolling(); cur = null; drawList(); } });
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { refreshUnread(); if (cur) poll(); } });
        window.addEventListener('hashchange', route);
        window.addEventListener('resize', fitShell);
        // Leaving «میز کار» for another main tab stops the desktop chat polling.
        $('#crm-tab').on('hidden.bs.tab', hide);
        const tryRoute = (n) => { if (can('use_messenger')) route(); else if (n > 0) setTimeout(() => tryRoute(n - 1), 500); };
        if (/^#work\/chat/.test(window.location.hash)) tryRoute(20);
        // Global unread badge (tab + sub-nav) every 20 seconds.
        setTimeout(refreshUnread, 3000);
        unreadTimer = setInterval(refreshUnread, 20000);
    }

    return { init, show, hide, open, shareRef, refreshUnread };
})();

$(function () { ChatUI.init(); });
