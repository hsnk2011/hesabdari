// /js/work-office.js
// میز کار office work (modelled on mz7): cartable (payment requests that book
// one expense, letters with referral), ideas with votes and approval into a
// project, and staff files with records. One shared bottom sheet.

const OfficeUI = (function () {
    const esc = (v) => UI.escapeHtml(v == null ? '' : String(v));
    const money = (v) => UI.formatCurrency(Number(v) || 0);
    const faDate = (d) => (d ? UI.gregorianToPersian(String(d).slice(0, 10)) : '');
    const timeOf = (d) => String(d || '').slice(11, 16);
    const fa = (n) => Number(n || 0).toLocaleString('fa-IR');
    const emptyBox = (text, icon = 'bi-inbox') => `<div class="text-center text-muted py-4"><i class="bi ${icon} fs-3 d-block mb-1"></i>${esc(text)}</div>`;
    const can = (k) => (typeof App !== 'undefined' && App.hasPermission ? App.hasPermission(k) : false);

    const REQ_STATUS = { pending: 'در انتظار', approved: 'تأییدشده — منتظر پرداخت', rejected: 'ردشده', paid: 'پرداخت‌شده', done: 'انجام‌شده', cancelled: 'لغوشده' };
    const REQ_CLS = { pending: 'warning', approved: 'info', rejected: 'danger', paid: 'success', done: 'success', cancelled: 'secondary' };
    const ACT = {
        approve: ['تأیید', 'btn-success', 'bi-check2'], reject: ['رد', 'btn-outline-danger', 'bi-x-lg'], pay: ['ثبت پرداخت', 'btn-primary', 'bi-cash-coin'],
        refer: ['ارجاع', 'btn-outline-primary', 'bi-forward'], done: ['بستن با نتیجه', 'btn-success', 'bi-check2-all'],
        cancel: ['لغو', 'btn-outline-secondary', 'bi-slash-circle'], reopen: ['ارسال دوباره', 'btn-outline-primary', 'bi-arrow-repeat'], comment: ['یادداشت', 'btn-outline-secondary', 'bi-chat-left-text'],
    };
    const IDEA_STATUS = { pending: 'در انتظار تصمیم', approved: 'تأییدشده — پروژه شد', rejected: 'ردشده' };
    const IDEA_CLS = { pending: 'warning', approved: 'success', rejected: 'secondary' };

    let reqTab = 'mine_action';
    let categories = {};
    let current = null;     // { type: 'request'|'idea'|'hr', id, data }
    let people = [];
    let me = 0;

    const sheet = () => bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('office-sheet'));
    function openSheet(title, html) {
        $('#office-sheet-title').text(title);
        $('#office-sheet-body').html(html);
        if (!document.getElementById('office-sheet').classList.contains('show')) sheet().show();
    }

    async function loadPeople() {
        if (people.length) return people;
        const res = await Api.call('work_people', {}, false);
        people = (res && res.people) || [];
        me = Number(res && res.me) || 0;
        return people;
    }

    function show(pane) {
        if (pane === 'cartable') renderRequests();
        else if (pane === 'ideas') renderIdeas();
        else if (pane === 'hr') renderHr();
    }

    // ================= cartable =================

    async function renderRequests() {
        const res = await Api.call('office_requests', { tab: reqTab, kind: $('#office-req-kind').val() || '' }, false);
        if (!res?.success) return;
        categories = res.categories || {};
        const rows = res.requests || [];
        $('#office-req-list').html(rows.map(r => `
            <div class="card crm-card mb-2" role="button" data-open-request="${r.id}">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate"><i class="bi ${r.kind === 'payment' ? 'bi-cash-coin text-success' : 'bi-envelope-paper text-primary'} me-1"></i>${esc(r.title)}</div>
                            <div class="small text-muted">شماره ${fa(r.number)} · ${esc(r.created_by_name || '')} · ${esc(faDate(r.created_at))}${r.kind === 'letter' && r.assignee_name ? ` · نزد ${esc(r.assignee_name)}` : ''}${r.project_title ? ` · ${esc(r.project_title)}` : ''}</div>
                        </div>
                        <div class="text-end text-nowrap">
                            ${r.kind === 'payment' ? `<div class="fw-semibold">${money(r.amount)}</div>` : ''}
                            <span class="badge text-bg-${REQ_CLS[r.status] || 'secondary'}">${REQ_STATUS[r.status] || r.status}</span>
                        </div>
                    </div>
                </div>
            </div>`).join('') || emptyBox(reqTab === 'mine_action' ? 'کاری در کارتابل شما نیست.' : 'موردی نیست.', 'bi-inboxes'));
    }

    async function newPayment() {
        const opt = await Api.call('hr_options', {});
        if (!opt?.success) return;
        const catOpts = Object.entries(categories && Object.keys(categories).length ? categories : { ADVANCE: 'مساعده', SALARY: 'حقوق و پاداش', PURCHASE: 'خرید کالا و ملزومات', REPAIR: 'تعمیرات و خدمات', PETTY_CASH: 'شارژ تنخواه', TRANSPORT: 'حمل‌ونقل و کرایه', SUPPLIER: 'پرداخت به تأمین‌کننده', OTHER: 'سایر' })
            .map(([value, label]) => ({ value, label }));
        const none = [{ value: '', label: '—' }];
        const v = await UI.formDialog({
            title: 'درخواست پرداخت', submitText: 'ثبت درخواست',
            fields: [
                { name: 'title', label: 'عنوان', required: true, placeholder: 'مثلاً: خرید کارتریج' },
                { name: 'category', label: 'دسته', type: 'select', options: catOpts, value: 'PURCHASE' },
                { name: 'amount', label: 'مبلغ', type: 'money', required: true },
                { name: 'payee', label: 'گیرنده (نام / شماره کارت)', placeholder: 'اختیاری' },
                { name: 'personnel_id', label: 'برای کدام پرسنل (مساعده / حقوق)', type: 'select', options: none.concat((opt.personnel || []).map(p => ({ value: p.id, label: p.full_name }))), value: '' },
                { name: 'supplier_id', label: 'تأمین‌کننده', type: 'select', options: none.concat((opt.suppliers || []).map(s => ({ value: s.id, label: s.name }))), value: '' },
                { name: 'project_id', label: 'پروژه', type: 'select', options: none.concat((opt.projects || []).map(p => ({ value: p.id, label: p.title }))), value: '' },
                { name: 'due_date', label: 'تا تاریخ', type: 'date', allowEmpty: true },
                { name: 'body', label: 'شرح', type: 'textarea' },
            ],
        });
        if (!v) return;
        const res = await Api.call('office_request_save', Object.assign({ kind: 'payment' }, v));
        if (res?.success) { UI.showSuccess(`درخواست شماره ${fa(res.number)} ثبت شد و برای تأیید فرستاده شد.`); renderRequests(); openRequest(res.id); }
    }

    async function newLetter() {
        await loadPeople();
        const others = people.length ? people : [];
        if (!others.length) { UI.showError('هنوز عضوی در تیم نیست.'); return; }
        const v = await UI.formDialog({
            title: 'نامه / ارجاع', submitText: 'ارسال',
            fields: [
                { name: 'title', label: 'موضوع', required: true },
                { name: 'assignee_user_id', label: 'به', type: 'select', options: others.map(p => ({ value: p.user_id, label: p.full_name + (Number(p.user_id) === me ? ' (خودم)' : '') })), value: (others.find(p => Number(p.user_id) !== me) || others[0]).user_id },
                { name: 'due_date', label: 'مهلت', type: 'date', allowEmpty: true },
                { name: 'body', label: 'متن', type: 'textarea' },
            ],
        });
        if (!v) return;
        const res = await Api.call('office_request_save', Object.assign({ kind: 'letter' }, v));
        if (res?.success) { UI.showSuccess(`نامه شماره ${fa(res.number)} فرستاده شد.`); renderRequests(); openRequest(res.id); }
    }

    async function openRequest(id) {
        const res = await Api.call('office_request', { id });
        if (!res?.success) return;
        current = { type: 'request', id: Number(id), data: res };
        const r = res.request;
        const rows = [
            ['شماره', fa(r.number)],
            ['نوع', r.kind === 'payment' ? 'درخواست پرداخت' : 'نامه / ارجاع'],
            ['ثبت', `${esc(r.created_by_name)} · ${esc(faDate(r.created_at))} ${esc(timeOf(r.created_at))}`],
        ];
        if (r.kind === 'payment') {
            rows.push(['مبلغ', `<b>${money(r.amount)}</b>`], ['دسته', esc(r.category_label || '')]);
            if (r.payee) rows.push(['گیرنده', esc(r.payee)]);
            if (r.personnel_name) rows.push(['پرسنل', esc(r.personnel_name)]);
            if (r.supplier_name) rows.push(['تأمین‌کننده', esc(r.supplier_name)]);
        } else if (r.assignee_name) {
            rows.push(['نزد', esc(r.assignee_name)]);
        }
        if (r.project_title) rows.push(['پروژه', `<a href="#" data-office-link="./index.php#work/project/${r.project_id}">${esc(r.project_title)}</a>`]);
        if (r.due_date) rows.push(['مهلت', esc(faDate(r.due_date))]);
        if (r.expense_id) rows.push(['سند هزینه', `شماره ${fa(r.expense_id)}`]);
        const files = res.files || [];
        const html = `
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge text-bg-${REQ_CLS[r.status] || 'secondary'} fs-6">${REQ_STATUS[r.status] || r.status}</span>
            </div>
            <table class="table table-sm mb-2"><tbody>${rows.map(([k, v]) => `<tr><th class="text-muted fw-normal text-nowrap" style="width:1%">${k}</th><td>${v}</td></tr>`).join('')}</tbody></table>
            ${r.body ? `<div class="border rounded p-2 mb-2" style="white-space:pre-wrap">${esc(r.body)}</div>` : ''}
            ${r.result ? `<div class="alert alert-success py-2" style="white-space:pre-wrap"><b>نتیجه:</b> ${esc(r.result)}</div>` : ''}
            <div class="d-flex flex-wrap gap-2 mb-3">${(res.actions || []).map(a => `<button class="btn btn-sm ${ACT[a]?.[1] || 'btn-outline-secondary'}" data-req-act="${a}"><i class="bi ${ACT[a]?.[2] || 'bi-dot'} me-1"></i>${ACT[a]?.[0] || a}</button>`).join('')}</div>
            <div class="d-flex justify-content-between align-items-center"><h6 class="mb-1">پیوست‌ها</h6><button class="btn btn-sm btn-outline-primary" data-req-upload><i class="bi bi-paperclip me-1"></i>افزودن فایل</button></div>
            <div class="mb-3">${files.map(f => WorkManager.fileRow(f, { canDelete: false })).join('') || '<div class="small text-muted py-2">پیوستی نیست.</div>'}</div>
            <h6 class="mb-2">گردش کار</h6>
            <div class="work-timeline">${(res.events || []).map(ev => `
                <div class="d-flex gap-2 mb-2">
                    <i class="bi bi-circle-fill text-primary small mt-1"></i>
                    <div class="flex-grow-1">
                        <div class="small"><b>${esc(ev.actor_name)}</b> — ${esc(ev.label)}${ev.to_name ? ` به <b>${esc(ev.to_name)}</b>` : ''} <span class="text-muted">· ${esc(faDate(ev.created_at))} ${esc(timeOf(ev.created_at))}</span></div>
                        ${ev.note ? `<div class="small text-body-secondary" style="white-space:pre-wrap">${esc(ev.note)}</div>` : ''}
                    </div>
                </div>`).join('')}</div>
            `;
        openSheet(`${r.kind === 'payment' ? 'درخواست پرداخت' : 'نامه'}: ${r.title}`, html);
    }

    async function requestAction(action) {
        const r = current.data.request;
        let extra = {};
        if (action === 'pay') {
            const opt = await Api.call('office_pay_options', {});
            if (!opt?.success) return;
            if (!(opt.accounts || []).length) { UI.showError('اول یک حساب (صندوق یا بانک) تعریف کنید.'); return; }
            const v = await UI.formDialog({
                title: `ثبت پرداخت ${money(r.amount)}`, submitText: 'پرداخت و ثبت سند هزینه',
                message: '<div class="small text-muted">یک سند هزینه با همین مبلغ از حسابی که انتخاب می‌کنید ثبت می‌شود.</div>',
                fields: [
                    { name: 'account_id', label: 'از حساب', type: 'select', options: opt.accounts.map(a => ({ value: a.id, label: a.name })), value: opt.accounts[0].id },
                    { name: 'expense_category', label: 'دسته هزینه', type: 'select', options: (opt.categories || []).map(c => ({ value: c, label: c })), value: (opt.categories || [])[0] },
                    { name: 'date', label: 'تاریخ پرداخت', type: 'date' },
                    { name: 'note', label: 'یادداشت', type: 'textarea' },
                ],
            });
            if (!v) return;
            extra = v;
        } else if (action === 'refer') {
            await loadPeople();
            const opts = people.filter(p => Number(p.user_id) !== Number(r.assignee_user_id)).map(p => ({ value: p.user_id, label: p.full_name }));
            if (!opts.length) { UI.showError('همکار دیگری برای ارجاع نیست.'); return; }
            const v = await UI.formDialog({ title: 'ارجاع نامه', submitText: 'ارجاع', fields: [
                { name: 'to_user_id', label: 'به', type: 'select', options: opts, value: opts[0].value },
                { name: 'note', label: 'دستور / توضیح', type: 'textarea', required: true }] });
            if (!v) return;
            extra = v;
        } else if (['reject', 'done', 'comment'].includes(action)) {
            const label = action === 'reject' ? 'دلیل رد' : action === 'done' ? 'نتیجه' : 'یادداشت';
            const v = await UI.formDialog({ title: ACT[action][0], submitText: ACT[action][0], fields: [{ name: 'note', label, type: 'textarea', required: true }] });
            if (!v) return;
            extra = v;
        } else if (action === 'approve') {
            const v = await UI.formDialog({ title: `تأیید پرداخت ${money(r.amount)}`, submitText: 'تأیید', submitClass: 'btn-success', fields: [{ name: 'note', label: 'یادداشت (اختیاری)', type: 'textarea' }] });
            if (!v) return;
            extra = v;
        } else if (action === 'cancel') {
            if (!await UI.confirm('این مورد لغو شود؟')) return;
        } else if (action === 'reopen') {
            const v = await UI.formDialog({ title: 'ارسال دوباره', submitText: 'ارسال', fields: [{ name: 'note', label: 'توضیح (اختیاری)', type: 'textarea' }] });
            if (!v) return;
            extra = v;
        }
        const res = await Api.call('office_request_act', Object.assign({ id: current.id, action }, extra));
        if (res?.success) {
            UI.showSuccess(action === 'pay' ? 'پرداخت ثبت شد و سند هزینه ساخته شد.' : 'ثبت شد.');
            openRequest(current.id);
            if ($('#crm-pane-cartable').is(':visible')) renderRequests();
        }
    }

    // ================= ideas =================

    async function renderIdeas() {
        const res = await Api.call('ideas_list', {}, false);
        if (!res?.success) return;
        const rows = res.ideas || [];
        $('#office-ideas-list').html(rows.map(i => `
            <div class="card crm-card mb-2">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between gap-2" role="button" data-open-idea="${i.id}">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate"><i class="bi bi-lightbulb text-warning me-1"></i>${esc(i.title)}</div>
                            <div class="small text-muted">${i.author_name ? esc(i.author_name) : '<i class="bi bi-incognito me-1"></i>ناشناس'}${i.is_mine && i.is_anonymous ? ' (شما، ناشناس)' : ''} · ${esc(faDate(i.created_at))}</div>
                        </div>
                        <span class="badge text-bg-${IDEA_CLS[i.status]} align-self-start">${IDEA_STATUS[i.status]}</span>
                    </div>
                    <div class="d-flex gap-2 mt-2 align-items-center">
                        ${voteButtons(i)}
                        <span class="small text-muted ms-auto"><i class="bi bi-chat-left-text me-1"></i>${fa(i.comments)}</span>
                    </div>
                </div>
            </div>`).join('') || emptyBox('هنوز ایده‌ای ثبت نشده. اولین ایده را شما بنویسید!', 'bi-lightbulb'));
    }

    function voteButtons(i) {
        const dis = i.status !== 'pending' ? ' disabled' : '';
        return `<button class="btn btn-sm ${i.my_vote === 'agree' ? 'btn-success' : 'btn-outline-success'}" data-idea-vote="${i.id}" data-agree="1"${dis}><i class="bi bi-hand-thumbs-up me-1"></i>${fa(i.agree)}</button>
            <button class="btn btn-sm ${i.my_vote === 'disagree' ? 'btn-danger' : 'btn-outline-danger'}" data-idea-vote="${i.id}" data-agree="0"${dis}><i class="bi bi-hand-thumbs-down me-1"></i>${fa(i.disagree)}</button>`;
    }

    async function ideaDialog(idea = null) {
        const v = await UI.formDialog({
            title: idea ? 'ویرایش ایده' : 'ایده جدید', submitText: idea ? 'ذخیره' : 'ثبت ایده',
            fields: [
                { name: 'title', label: 'عنوان', required: true, value: idea?.title || '' },
                { name: 'body', label: 'شرح ایده', type: 'textarea', required: true, value: idea?.body || '' },
                { name: 'is_anonymous', label: 'بدون نام ثبت شود (نام شما به کسی نشان داده نمی‌شود)', type: 'checkbox', value: !!idea?.is_anonymous },
            ],
        });
        if (!v) return null;
        const res = await Api.call('idea_save', Object.assign({ id: idea ? idea.id : 0 }, v, { is_anonymous: v.is_anonymous ? 1 : 0 }));
        return res?.success ? res : null;
    }

    async function newIdea() {
        const res = await ideaDialog();
        if (res) { UI.showSuccess('ایده ثبت شد.'); goTo('ideas'); setTimeout(() => openIdea(res.id), 250); }
    }

    async function openIdea(id) {
        const res = await Api.call('idea_detail', { id });
        if (!res?.success) return;
        current = { type: 'idea', id: Number(id), data: res };
        const i = res.idea;
        const html = `
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="badge text-bg-${IDEA_CLS[i.status]}">${IDEA_STATUS[i.status]}</span>
                <span class="small text-muted">${i.author_name ? esc(i.author_name) : '<i class="bi bi-incognito me-1"></i>ناشناس'}${i.is_mine && i.is_anonymous ? ' <span class="badge text-bg-light border"><i class="bi bi-incognito me-1"></i>برای دیگران ناشناس</span>' : ''} · ${esc(faDate(i.created_at))}</span>
            </div>
            <div class="border rounded p-2 mb-2" style="white-space:pre-wrap">${esc(i.body)}</div>
            <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                ${voteButtons(i)}
                ${i.is_mine && i.status === 'pending' ? '<button class="btn btn-sm btn-outline-secondary ms-auto" data-idea-edit><i class="bi bi-pencil me-1"></i>ویرایش</button><button class="btn btn-sm btn-outline-danger" data-idea-del aria-label="حذف"><i class="bi bi-trash"></i></button>' : ''}
            </div>
            ${i.status !== 'pending' ? `<div class="alert ${i.status === 'approved' ? 'alert-success' : 'alert-secondary'} py-2">
                ${i.status === 'approved' ? 'تأیید شد' : 'رد شد'} توسط ${esc(i.decided_by_name || '')} · ${esc(faDate(i.decided_at))}
                ${i.decision_note ? `<div style="white-space:pre-wrap">${esc(i.decision_note)}</div>` : ''}
                ${res.project ? `<div class="mt-1"><a href="#" data-office-link="./index.php#work/project/${res.project.id}"><i class="bi bi-kanban me-1"></i>${esc(res.project.title)}</a></div>` : ''}
            </div>` : ''}
            ${res.can_decide && i.status === 'pending' ? `<div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-success" data-idea-decide="approve"><i class="bi bi-kanban me-1"></i>تأیید و ساخت پروژه</button>
                <button class="btn btn-sm btn-outline-danger" data-idea-decide="reject"><i class="bi bi-x-lg me-1"></i>رد</button></div>` : ''}
            <h6 class="mb-2">نظرها</h6>
            <div class="mb-2">${(res.comments || []).map(c => `<div class="border-bottom py-2"><div class="small"><b>${esc(c.author_name)}</b> <span class="text-muted">· ${esc(faDate(c.created_at))} ${esc(timeOf(c.created_at))}</span></div><div style="white-space:pre-wrap">${esc(c.body)}</div></div>`).join('') || '<div class="small text-muted">هنوز نظری نیست.</div>'}</div>
            <div class="d-flex gap-2"><textarea class="form-control form-control-sm" rows="1" id="office-idea-comment" placeholder="نظر شما…"></textarea><button class="btn btn-sm btn-primary" data-idea-comment>ارسال</button></div>`;
        openSheet('ایده: ' + i.title, html);
    }

    async function decideIdea(decision) {
        const i = current.data.idea;
        let v;
        if (decision === 'approve') {
            await loadPeople();
            v = await UI.formDialog({
                title: 'تأیید ایده و ساخت پروژه', submitText: 'ساخت پروژه', submitClass: 'btn-success',
                message: '<div class="small text-muted">پروژه‌ای با همین عنوان و شرح در وضعیت «برنامه‌ریزی» ساخته می‌شود؛ نویسنده ایده عضو آن است.</div>',
                fields: [{ name: 'note', label: 'یادداشت (اختیاری)', type: 'textarea' }]
                    .concat(people.filter(p => Number(p.user_id) !== me && Number(p.user_id) !== Number(i.author_user_id)).map(p => ({ name: 'm_' + p.user_id, label: 'عضو پروژه: ' + p.full_name, type: 'checkbox' }))),
            });
            if (!v) return;
            v.member_ids = people.filter(p => v['m_' + p.user_id]).map(p => p.user_id);
        } else {
            v = await UI.formDialog({ title: 'رد ایده', submitText: 'رد', submitClass: 'btn-danger', fields: [{ name: 'note', label: 'دلیل رد', type: 'textarea', required: true }] });
            if (!v) return;
        }
        const res = await Api.call('idea_decide', { id: i.id, decision, note: v.note, member_ids: v.member_ids || [] });
        if (res?.success) { UI.showSuccess(decision === 'approve' ? 'پروژه ساخته شد.' : 'ایده رد شد.'); openIdea(i.id); renderIdeas(); }
    }

    // ================= staff files =================

    async function renderHr() {
        const res = await Api.call('hr_list', { q: $('#office-hr-search').val() || '', inactive: $('#office-hr-inactive').is(':checked') ? 1 : 0 }, false);
        if (!res?.success) return;
        const rows = res.people || [];
        $('#office-hr-list').html(rows.map(p => `
            <div class="card crm-card mb-2 ${Number(p.is_active) ? '' : 'opacity-75'}" role="button" data-open-hr="${p.id}">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <div class="chat-avatar">${esc(String(p.full_name).charAt(0))}</div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">${esc(p.full_name)} ${Number(p.is_active) ? '' : '<span class="badge text-bg-secondary">غیرفعال</span>'}</div>
                        <div class="small text-muted text-truncate">${esc(p.job_title || 'بدون سمت')}${p.phone ? ' · ' + esc(p.phone) : ''}</div>
                    </div>
                    <span class="small text-muted text-nowrap">${fa(p.record_count)} سابقه</span>
                </div>
            </div>`).join('') || emptyBox('پرونده‌ای نیست. با «افزودن اعضای تیم» شروع کنید.', 'bi-person-badge'));
    }

    async function hrDialog(p = null) {
        const res = await Api.call('work_people', {}, false);
        const accounts = [{ value: '', label: 'بدون حساب کاربری' }].concat(((res && res.people) || []).map(x => ({ value: x.user_id, label: x.full_name })));
        const fields = [
            { name: 'full_name', label: 'نام و نام خانوادگی', required: true, value: p?.full_name || '' },
            { name: 'job_title', label: 'سمت', value: p?.job_title || '' },
            { name: 'phone', label: 'تلفن', value: p?.phone || '' },
            { name: 'national_id', label: 'کد ملی', value: p?.national_id || '' },
            { name: 'hired_at', label: 'تاریخ شروع همکاری', type: 'date', allowEmpty: true, value: p?.hired_at || '' },
            { name: 'user_id', label: 'حساب کاربری در سیستم', type: 'select', options: accounts, value: p?.user_id || '' },
            { name: 'note', label: 'یادداشت', type: 'textarea', value: p?.note || '' },
        ];
        if (p) fields.push({ name: 'is_active', label: 'فعال', type: 'checkbox', value: Number(p.is_active) === 1 });
        const v = await UI.formDialog({ title: p ? 'ویرایش پرونده' : 'پرونده پرسنلی جدید', submitText: 'ذخیره', fields });
        if (!v) return null;
        if (p) v.is_active = v.is_active ? 1 : 0;
        const out = await Api.call('hr_save', Object.assign({ id: p ? p.id : 0 }, v));
        return out?.success ? out : null;
    }

    async function openHr(id) {
        await loadPeople();
        const res = await Api.call('hr_detail', { id });
        if (!res?.success) return;
        current = { type: 'hr', id: Number(id), data: res };
        const p = res.person;
        const kinds = res.kinds || {};
        const chips = Object.entries(res.summary || {}).filter(([, s]) => s.count > 0)
            .map(([k, s]) => `<span class="badge text-bg-light border fw-normal">${esc(kinds[k]?.[0] || k)}: ${fa(s.count)}${kinds[k]?.[1] && s.total ? ' · ' + money(s.total) : ''}</span>`).join(' ');
        const html = `
            <div class="d-flex align-items-center gap-2 mb-2">
                <div class="chat-avatar">${esc(String(p.full_name).charAt(0))}</div>
                <div class="flex-grow-1"><div class="fw-semibold">${esc(p.full_name)} ${Number(p.is_active) ? '' : '<span class="badge text-bg-secondary">غیرفعال</span>'}</div>
                <div class="small text-muted">${esc(p.job_title || 'بدون سمت')}${p.username ? ' · کاربر ' + esc(p.username) : ''}</div></div>
                <button class="btn btn-sm btn-outline-secondary" data-hr-edit><i class="bi bi-pencil me-1"></i>ویرایش</button>
            </div>
            <div class="small mb-2">${p.phone ? `<a href="tel:${esc(p.phone)}" class="me-3"><i class="bi bi-telephone me-1"></i>${esc(p.phone)}</a>` : ''}${p.national_id ? `<span class="me-3">کد ملی ${esc(p.national_id)}</span>` : ''}${p.hired_at ? `<span>از ${esc(faDate(p.hired_at))}</span>` : ''}</div>
            ${p.note ? `<div class="border rounded p-2 mb-2 small" style="white-space:pre-wrap">${esc(p.note)}</div>` : ''}
            ${chips ? `<div class="d-flex flex-wrap gap-1 mb-3">${chips}</div>` : ''}
            <div class="d-flex justify-content-between align-items-center mb-1"><h6 class="mb-0">سوابق</h6><button class="btn btn-sm btn-primary" data-hr-add><i class="bi bi-plus-lg me-1"></i>ثبت سابقه</button></div>
            <div>${(res.records || []).map(r => `
                <div class="d-flex gap-2 py-2 border-bottom">
                    <div class="flex-grow-1 min-w-0">
                        <div><span class="badge text-bg-light border">${esc(r.kind_label)}</span> <span class="small text-muted">${esc(faDate(r.date))} · ${esc(r.created_by_name)}</span>${r.amount ? ` <b class="ms-1">${money(r.amount)}</b>` : ''}</div>
                        <div class="small" style="white-space:pre-wrap">${esc(r.note)}</div>
                        ${r.request_id ? `<a href="#" class="small" data-office-link="./index.php#work/request/${r.request_id}"><i class="bi bi-inboxes me-1"></i>از درخواست شماره ${fa(r.request_number)}</a>` : ''}
                    </div>
                    ${Number(r.created_by) === me && !r.request_id ? `<button class="btn btn-sm btn-link text-danger" data-hr-del="${r.id}" aria-label="حذف"><i class="bi bi-trash"></i></button>` : ''}
                </div>`).join('') || '<div class="small text-muted py-2">سابقه‌ای ثبت نشده.</div>'}</div>`;
        openSheet('پرونده: ' + p.full_name, html);
    }

    async function addHrRecord() {
        const kinds = current.data.kinds || {};
        const v = await UI.formDialog({
            title: 'ثبت سابقه', submitText: 'ثبت',
            fields: [
                { name: 'kind', label: 'نوع', type: 'select', options: Object.entries(kinds).map(([k, x]) => ({ value: k, label: x[0] })), value: 'commendation' },
                { name: 'date', label: 'تاریخ', type: 'date' },
                { name: 'amount', label: 'مبلغ (برای جریمه، مساعده و پاداش)', type: 'money' },
                { name: 'note', label: 'شرح', type: 'textarea', required: true },
            ],
        });
        if (!v) return;
        if (!kinds[v.kind]?.[1]) delete v.amount;
        const res = await Api.call('hr_record', Object.assign({ id: current.id }, v));
        if (res?.success) { openHr(current.id); renderHr(); }
    }

    // ================= routing / init =================

    function goTo(pane) {
        const tab = document.getElementById('crm-tab');
        if (tab && !tab.classList.contains('active')) bootstrap.Tab.getOrCreateInstance(tab).show();
        if (typeof CrmManager !== 'undefined') CrmManager.showPane(pane);
    }

    function route() {
        const m = /^#work\/(request|idea)\/(\d+)$/.exec(window.location.hash || '');
        if (!m) return;
        const perm = m[1] === 'request' ? 'use_cartable' : 'use_ideas';
        if (!can(perm)) return;
        history.replaceState(null, '', window.location.pathname + window.location.search);
        goTo(m[1] === 'request' ? 'cartable' : 'ideas');
        setTimeout(() => (m[1] === 'request' ? openRequest(Number(m[2])) : openIdea(Number(m[2]))), 250);
    }

    function init() {
        $('#office-req-tabs').on('click', '[data-tab]', function () {
            reqTab = $(this).data('tab');
            $('#office-req-tabs .nav-link').removeClass('active');
            $(this).addClass('active');
            renderRequests();
        });
        $('#office-req-kind').on('change', renderRequests);
        $('#office-req-list').on('click', '[data-open-request]', function () { openRequest(Number($(this).data('open-request'))); });
        $('#office-new-payment-btn').on('click', newPayment);
        $('#office-new-letter-btn').on('click', newLetter);
        $('#office-new-idea-btn, #office-new-idea-btn-2').on('click', newIdea);
        $('#office-ideas-list').on('click', '[data-open-idea]', function () { openIdea(Number($(this).data('open-idea'))); });
        $(document).on('click', '#office-ideas-list [data-idea-vote], #office-sheet-body [data-idea-vote]', async function () {
            const id = Number($(this).data('idea-vote'));
            const res = await Api.call('idea_vote', { id, agree: Number($(this).data('agree')) }, false);
            if (!res?.success) return;
            renderIdeas();
            if (current && current.type === 'idea' && current.id === id && document.getElementById('office-sheet').classList.contains('show')) openIdea(id);
        });
        let hrTimer = null;
        $('#office-hr-search').on('input', () => { clearTimeout(hrTimer); hrTimer = setTimeout(renderHr, 300); });
        $('#office-hr-inactive').on('change', renderHr);
        $('#office-hr-new').on('click', async () => { const r = await hrDialog(); if (r) { renderHr(); openHr(r.id); } });
        $('#office-hr-import').on('click', async () => {
            const res = await Api.call('hr_import_team', {});
            if (res?.success) { UI.showSuccess(res.made ? `${fa(res.made)} پرونده ساخته شد.` : 'همه اعضای تیم پرونده دارند.'); renderHr(); }
        });
        $('#office-hr-list').on('click', '[data-open-hr]', function () { openHr(Number($(this).data('open-hr'))); });

        const $b = $('#office-sheet-body');
        $b.on('click', '[data-req-act]', function () { requestAction($(this).data('req-act')); });
        $b.on('click', '[data-req-upload]', async () => {
            const res = await WorkManager.uploadDialog({ title: 'افزودن پیوست', action: 'office_request_files', fields: { id: current.id }, limit: current.data.upload_limit });
            if (res) openRequest(current.id);
        });
        $b.on('click', '[data-preview]', function () {
            const f = (current?.data?.files || []).find(x => Number(x.id) === Number($(this).data('preview')));
            if (f) WorkManager.previewFile(f);
        });
        $b.on('click', '[data-office-link]', function (e) {
            e.preventDefault();
            sheet().hide();
            WorkManager.openLink($(this).data('office-link'));
        });
        $b.on('click', '[data-idea-edit]', async () => { if (await ideaDialog(current.data.idea)) { openIdea(current.id); renderIdeas(); } });
        $b.on('click', '[data-idea-del]', async () => {
            if (!await UI.confirm('این ایده حذف شود؟')) return;
            if ((await Api.call('idea_delete', { id: current.id }))?.success) { sheet().hide(); renderIdeas(); }
        });
        $b.on('click', '[data-idea-decide]', function () { decideIdea($(this).data('idea-decide')); });
        $b.on('click', '[data-idea-comment]', async () => {
            const body = String($('#office-idea-comment').val() || '').trim();
            if (!body) return;
            if ((await Api.call('idea_comment', { id: current.id, body }))?.success) { openIdea(current.id); renderIdeas(); }
        });
        $b.on('click', '[data-hr-edit]', async () => { if (await hrDialog(current.data.person)) { openHr(current.id); renderHr(); } });
        $b.on('click', '[data-hr-add]', addHrRecord);
        $b.on('click', '[data-hr-del]', async function () {
            if (!await UI.confirm('این سابقه حذف شود؟')) return;
            if ((await Api.call('hr_record', { op: 'delete', record_id: Number($(this).data('hr-del')) }))?.success) { openHr(current.id); renderHr(); }
        });

        window.addEventListener('hashchange', route);
        const tryRoute = (n) => { if (can('use_cartable') || can('use_ideas')) route(); else if (n > 0) setTimeout(() => tryRoute(n - 1), 500); };
        if (/^#work\/(request|idea)\//.test(window.location.hash)) tryRoute(20);
    }

    return { init, show, openRequest, openIdea, openHr, newPayment, newLetter, newIdea };
})();

$(function () { OfficeUI.init(); });
