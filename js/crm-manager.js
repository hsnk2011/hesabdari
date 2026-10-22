// /js/crm-manager.js
// CRM for a small team (partners + employees), built for phones: my tasks,
// team tasks, debtors, leads, team members, a customer/lead "file" with the
// full history, and a quick "how did the call go?" sheet after tapping call.

const CrmManager = (function () {
    const esc = (v) => UI.escapeHtml(v == null ? '' : String(v));
    const money = (v) => UI.formatCurrency(Number(v) || 0);
    const num = (v) => Number(UI.toEnglishDigits(String(v ?? '')).replace(/[,٬\s]/g, '')) || 0;
    const faDate = (d) => (d ? UI.gregorianToPersian(String(d).slice(0, 10)) : '');
    const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const todayYmd = () => ymd(new Date());
    const dayOffset = (s) => {
        const [y, m, d] = String(s).slice(0, 10).split('-').map(Number);
        const t = new Date(); t.setHours(0, 0, 0, 0);
        return Math.round((new Date(y, m - 1, d) - t) / 86400000);
    };
    const whenLabel = (dt) => {
        if (!dt) return '';
        const off = dayOffset(dt);
        const time = String(dt).length > 10 ? String(dt).slice(11, 16) : '';
        const day = off === 0 ? 'امروز' : off === 1 ? 'فردا' : off === -1 ? 'دیروز' : faDate(dt);
        return time && time !== '00:00' ? `${day} ساعت ${time}` : day;
    };
    const agoLabel = (dt) => {
        if (!dt) return '';
        const off = -dayOffset(dt);
        if (off <= 0) return 'امروز';
        if (off === 1) return 'دیروز';
        if (off < 30) return `${off} روز پیش`;
        return faDate(dt);
    };
    const firstPhone = (raw) => {
        const parts = UI.toEnglishDigits(String(raw || '')).split(/\s*[,\/،|]\s*/).map(p => p.replace(/[^\d+]/g, '')).filter(p => p.length >= 5);
        return parts[0] || '';
    };

    const KIND = { call_out: 'تماس گرفتیم', call_in: 'تماس گرفت', meeting: 'جلسه', visit: 'مراجعه حضوری', note: 'یادداشت' };
    const KIND_ICON = { call_out: 'bi-telephone-outbound', call_in: 'bi-telephone-inbound', meeting: 'bi-people', visit: 'bi-shop', note: 'bi-sticky' };
    const OUTCOME = { no_answer: 'جواب نداد', talked: 'صحبت شد', promised: 'قول پرداخت', callback: 'بعداً تماس بگیریم', interested: 'علاقه‌مند', not_interested: 'منصرف', sms: 'پیامک' };
    const STAGE = { new: 'جدید', contacted: 'تماس گرفته شد', quoted: 'پیش‌فاکتور داده شد', won: 'خرید کرد', lost: 'منصرف شد' };
    const TASK_KIND = { followup: 'پیگیری', collection: 'وصول', sales: 'فروش', other: 'سایر' };
    const TASK_ICON = { followup: 'bi-arrow-repeat', collection: 'bi-cash-coin', sales: 'bi-bag-check', other: 'bi-check2-square' };
    const TL_ICON = { sales_invoice: 'bi-receipt', return_invoice: 'bi-arrow-counterclockwise', payment: 'bi-cash-stack', installment: 'bi-calendar-event', quotation: 'bi-file-earmark-text', sms: 'bi-chat-left-text', activity: 'bi-telephone', task: 'bi-check2-circle' };

    let team = { members: [], me: 0, can_manage: false, users: [], partners: [] };
    let pane = 'my';
    let debtors = [];
    let leadsBoard = null;
    let leadStage = 'new';
    let file = null;          // {type: 'customer'|'lead', id, page, data}
    let logTarget = null;     // {type, id, name}
    let pendingCall = null;   // {type, id, name, at}
    let loaded = false;

    const teamUsers = () => team.members.filter(m => Number(m.user_id) > 0 && Number(m.is_active) === 1);
    const memberName = (userId) => (team.members.find(m => Number(m.user_id) === Number(userId)) || {}).full_name || '';
    const assigneeOptions = (selected) => teamUsers().map(m =>
        `<option value="${m.user_id}" ${Number(m.user_id) === Number(selected) ? 'selected' : ''}>${esc(m.full_name)}${Number(m.user_id) === Number(team.me) ? ' (من)' : ''}</option>`).join('');

    async function loadTeam() {
        if (App.hasPermission('use_crm')) {
            const res = await Api.call('crm_list_team', { include_inactive: 1 });
            if (res?.success) team = res;
        } else {
            // Projects-only users: the shared people list is enough (no CRM endpoints).
            const res = await Api.call('work_people', {}, false);
            if (res?.success) {
                team = { members: res.people.map(p => ({ ...p, is_active: 1 })), me: res.me, can_manage: false, users: [], partners: [] };
            }
        }
        const meIsMember = teamUsers().some(m => Number(m.user_id) === Number(team.me));
        $('#crm-no-team-hint').toggleClass('d-none', meIsMember);
        $('#crm-team-filter-member').html('<option value="">همه اعضا</option>' + assigneeOptions(null));
    }

    async function load() {
        await loadTeam();
        loaded = true;
        refreshPushButton();
        fillCustomerSearch();
        await showPane(pane);
    }

    async function showPane(name) {
        pane = name;
        $('#crm-subnav .nav-link').removeClass('active').filter(`[data-crm-pane="${name}"]`).addClass('active');
        $('.crm-pane').addClass('d-none');
        $(`#crm-pane-${name}`).removeClass('d-none');
        if (typeof ChatUI !== 'undefined' && name !== 'chat') ChatUI.hide();
        if (name === 'chat') { if (typeof ChatUI !== 'undefined') await ChatUI.show(); }
        else if (name === 'cartable' || name === 'ideas' || name === 'hr') { if (typeof OfficeUI !== 'undefined') OfficeUI.show(name); }
        else if (name === 'my' || name === 'projects' || name === 'meetings') {
            // «کارهای من» merges projects, meetings and customer follow-ups (WorkManager).
            if (typeof WorkManager !== 'undefined') await WorkManager.show(name);
        }
        else if (name === 'team') await renderTeamTasks();
        else if (name === 'debtors') await renderDebtors();
        else if (name === 'leads') await renderLeads();
        else if (name === 'members') renderMembers();
    }

    // ---------- tasks ----------

    function taskCard(t, opts = {}) {
        const overdue = t.status === 'open' && dayOffset(t.due_at) < 0;
        const phone = firstPhone(t.person_phone);
        const person = t.person_type && t.person_id
            ? `<a href="#" class="crm-open-person small" data-type="${esc(t.person_type)}" data-id="${t.person_id}"><i class="bi bi-person me-1"></i>${esc(t.person_name || '')}</a>` : '';
        const done = t.status !== 'open';
        return `<div class="card crm-card mb-2 ${overdue ? 'border-danger' : ''} ${done ? 'opacity-75' : ''}" data-task-id="${t.id}">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-start gap-2">
                    ${done ? `<i class="bi ${t.status === 'done' ? 'bi-check-circle-fill text-success' : 'bi-x-circle text-muted'} fs-4"></i>`
                        : `<button class="btn btn-outline-success btn-sm rounded-circle crm-task-done" title="انجام شد" aria-label="انجام شد"><i class="bi bi-check-lg"></i></button>`}
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold crm-task-edit" role="button">${esc(t.title)}</div>
                        <div class="small text-muted d-flex flex-wrap gap-2 mt-1">
                            <span class="${overdue ? 'text-danger fw-semibold' : ''}"><i class="bi bi-clock me-1"></i>${esc(whenLabel(t.due_at))}</span>
                            <span><i class="bi ${TASK_ICON[t.kind] || 'bi-check2-square'} me-1"></i>${esc(TASK_KIND[t.kind] || '')}</span>
                            ${opts.showAssignee ? `<span><i class="bi bi-person-badge me-1"></i>${esc(t.assignee_name || '')}</span>` : ''}
                            ${person}
                        </div>
                        ${t.description ? `<div class="small mt-1 text-body-secondary">${esc(t.description)}</div>` : ''}
                    </div>
                    ${phone && !done ? `<a class="btn btn-sm btn-outline-primary crm-call" href="tel:${esc(phone)}" data-type="${esc(t.person_type)}" data-id="${t.person_id}" data-name="${esc(t.person_name || '')}" aria-label="تماس"><i class="bi bi-telephone"></i></a>` : ''}
                </div>
            </div>
        </div>`;
    }

    const emptyBox = (text) => `<div class="text-center text-muted py-4"><i class="bi bi-emoji-smile fs-3 d-block mb-1"></i>${esc(text)}</div>`;

    async function renderTeamTasks() {
        const res = await Api.call('crm_team_tasks', {
            assignee_user_id: $('#crm-team-filter-member').val() || 0,
            status: $('#crm-team-filter-status').val() || 'open',
        });
        if (!res?.success) return;
        $('#crm-team-list').html((res.tasks || []).map(t => taskCard(t, { showAssignee: true })).join('') || emptyBox('کاری پیدا نشد.'));
    }

    async function taskDialog(task = null, person = null) {
        if (!teamUsers().length) {
            UI.showError('اول در زبانه «تیم» اعضایی با کاربر سیستم تعریف کنید.');
            return;
        }
        const due = task ? task.due_at : `${ymd(new Date(Date.now() + 86400000))} 10:00:00`;
        const fields = [
            { name: 'title', label: 'عنوان', required: true, value: task ? task.title : (person ? `پیگیری ${person.name}` : '') },
            { name: 'due_date', label: 'تاریخ', type: 'date', value: String(due).slice(0, 10) },
            { name: 'due_time', label: 'ساعت', value: String(due).slice(11, 16) || '10:00', placeholder: '10:00' },
            { name: 'assignee_user_id', label: 'مسئول', type: 'select', value: task ? task.assignee_user_id : team.me,
              options: teamUsers().map(m => ({ value: m.user_id, label: m.full_name + (Number(m.user_id) === Number(team.me) ? ' (من)' : '') })) },
            { name: 'kind', label: 'نوع', type: 'select', value: task ? task.kind : (person && person.type === 'lead' ? 'sales' : 'followup'),
              options: Object.entries(TASK_KIND).map(([value, label]) => ({ value, label })) },
            { name: 'description', label: 'توضیح', type: 'textarea', value: task ? (task.description || '') : '' },
        ];
        const v = await UI.formDialog({ title: task ? 'ویرایش کار' : 'کار جدید', fields, submitText: 'ذخیره' });
        if (!v) return;
        const time = UI.toEnglishDigits(v.due_time || '10:00').match(/^(\d{1,2}):(\d{2})$/);
        const payload = {
            id: task ? task.id : undefined,
            title: v.title, description: v.description, kind: v.kind, assignee_user_id: v.assignee_user_id,
            due_at: `${v.due_date || todayYmd()} ${time ? time[1].padStart(2, '0') + ':' + time[2] : '10:00'}:00`,
        };
        if (task && task.person_type) { payload.person_type = task.person_type; payload.person_id = task.person_id; }
        if (!task && person) { payload.person_type = person.type; payload.person_id = person.id; }
        const res = await Api.call('crm_save_task', payload);
        if (res?.success) {
            UI.showSuccess('کار ذخیره شد.');
            await refreshAfterChange();
        }
    }

    async function openTask(id) {
        const res = await Api.call('crm_get_task', { id });
        if (!res?.success) return;
        const t = res.task;
        if (t.status !== 'open') {
            if (t.person_type && t.person_id) openPerson(t.person_type, t.person_id);
            return;
        }
        await taskDialog(t);
    }

    async function setTaskStatus(id, status) {
        const res = await Api.call('crm_set_task_status', { id, status });
        if (res?.success) {
            UI.showSuccess(status === 'done' ? 'انجام شد.' : 'به‌روز شد.');
            await refreshAfterChange();
        }
    }

    async function refreshAfterChange() {
        if ($('#crm').hasClass('active')) await showPane(pane);
        if (file) await reloadFile();
    }

    // ---------- debtors ----------

    async function renderDebtors() {
        const res = await Api.call('crm_debtors');
        if (!res?.success) return;
        debtors = res.debtors || [];
        drawDebtors();
    }

    function drawDebtors() {
        const q = UI.toEnglishDigits(($('#crm-debtors-search').val() || '').trim());
        const rows = q ? debtors.filter(d => String(d.name).includes(q) || UI.toEnglishDigits(String(d.phone || '')).includes(q)) : debtors;
        const total = rows.reduce((s, d) => s + d.net_balance, 0);
        $('#crm-debtors-summary').text(`${rows.length.toLocaleString('fa-IR')} مشتری — جمع ${money(total)}`);
        $('#crm-debtors-list').html(rows.map(d => {
            const badges = [];
            if (d.promise_status === 'broken') badges.push(`<span class="badge text-bg-danger">قول عمل نشد</span>`);
            else if (d.promise_late) badges.push(`<span class="badge text-bg-danger">موعد قول گذشته</span>`);
            else if (d.promise_amount) badges.push(`<span class="badge text-bg-info">قول ${money(d.promise_amount)} تا ${esc(faDate(d.promise_date))}</span>`);
            if (d.bounced_checks > 0) badges.push(`<span class="badge text-bg-danger">${d.bounced_checks} چک برگشتی</span>`);
            if (d.overdue_installments > 0) badges.push(`<span class="badge text-bg-warning">قسط معوق ${money(d.overdue_installments)}</span>`);
            if (d.checks_due_soon > 0) badges.push(`<span class="badge text-bg-secondary">چک نزدیک سررسید ${money(d.checks_due_soon)}</span>`);
            const last = d.last_activity_at
                ? `${esc(agoLabel(d.last_activity_at))}${d.last_activity_user ? ' — ' + esc(d.last_activity_user) : ''}${d.last_activity_outcome ? ' — ' + esc(OUTCOME[d.last_activity_outcome] || d.last_activity_outcome) : ''}`
                : '<span class="text-danger">هنوز پیگیری نشده</span>';
            const phone = firstPhone(d.phone);
            return `<div class="card crm-card mb-2">
                <div class="card-body py-2 px-3 d-flex align-items-start gap-2">
                    <div class="flex-grow-1 min-w-0 crm-open-person" role="button" data-type="customer" data-id="${d.id}">
                        <div class="d-flex justify-content-between gap-2"><span class="fw-semibold text-truncate">${esc(d.name)}</span><span class="text-danger fw-semibold text-nowrap">${money(d.net_balance)}</span></div>
                        <div class="small text-muted mt-1"><i class="bi bi-chat-dots me-1"></i>${last}${d.owner_name ? ` · مسئول: ${esc(d.owner_name)}` : ''}</div>
                        ${badges.length ? `<div class="d-flex flex-wrap gap-1 mt-1">${badges.join('')}</div>` : ''}
                    </div>
                    ${phone ? `<a class="btn btn-sm btn-outline-primary crm-call" href="tel:${esc(phone)}" data-type="customer" data-id="${d.id}" data-name="${esc(d.name)}" aria-label="تماس"><i class="bi bi-telephone"></i></a>` : ''}
                </div>
            </div>`;
        }).join('') || emptyBox('بدهکاری نداریم.'));
    }

    // ---------- leads ----------

    async function renderLeads() {
        const res = await Api.call('crm_list_leads', { q: $('#crm-leads-search').val() || '' });
        if (!res?.success) return;
        leadsBoard = res.stages;
        drawLeads();
    }

    function leadCard(l) {
        const phone = firstPhone(l.phone);
        return `<div class="card crm-card mb-2" data-lead-id="${l.id}">
            <div class="card-body py-2 px-3">
                <div class="d-flex gap-2 align-items-start">
                    <div class="flex-grow-1 min-w-0 crm-open-person" role="button" data-type="lead" data-id="${l.id}">
                        <div class="fw-semibold text-truncate">${esc(l.name)}</div>
                        ${l.interest ? `<div class="small">${esc(l.interest)}</div>` : ''}
                        <div class="small text-muted d-flex flex-wrap gap-2 mt-1">
                            ${l.source ? `<span><i class="bi bi-megaphone me-1"></i>${esc(l.source)}</span>` : ''}
                            ${l.owner_name ? `<span><i class="bi bi-person-badge me-1"></i>${esc(l.owner_name)}</span>` : ''}
                            ${l.last_activity_at ? `<span><i class="bi bi-chat-dots me-1"></i>${esc(agoLabel(l.last_activity_at))}${l.last_activity_outcome ? ' — ' + esc(OUTCOME[l.last_activity_outcome] || l.last_activity_outcome) : ''}</span>` : ''}
                            ${l.stage === 'lost' && l.lost_reason ? `<span class="text-danger">${esc(l.lost_reason)}</span>` : ''}
                        </div>
                    </div>
                    ${phone ? `<a class="btn btn-sm btn-outline-primary crm-call" href="tel:${esc(phone)}" data-type="lead" data-id="${l.id}" data-name="${esc(l.name)}" aria-label="تماس"><i class="bi bi-telephone"></i></a>` : ''}
                </div>
            </div>
        </div>`;
    }

    function drawLeads() {
        if (!leadsBoard) return;
        const stages = Object.keys(STAGE);
        $('#crm-stage-pills').html(stages.map(s =>
            `<li class="nav-item"><button class="nav-link py-1 px-2 small ${s === leadStage ? 'active' : ''}" type="button" data-stage="${s}">${STAGE[s]} <span class="badge text-bg-light">${(leadsBoard[s] || []).length}</span></button></li>`).join(''));
        $('#crm-leads-board').html(stages.map(s => `
            <div class="col-md crm-stage-col ${s === leadStage ? '' : 'd-none d-md-block'}" data-stage="${s}">
                <div class="small fw-bold text-muted mb-2 d-none d-md-block">${STAGE[s]} <span class="badge text-bg-light">${(leadsBoard[s] || []).length}</span></div>
                ${(leadsBoard[s] || []).map(leadCard).join('') || '<div class="small text-muted text-center py-3 border rounded">—</div>'}
            </div>`).join(''));
    }

    async function leadDialog(lead = null) {
        const fields = [
            { name: 'name', label: 'نام', required: true, value: lead ? lead.name : '' },
            { name: 'phone', label: 'شماره تماس', value: lead ? (lead.phone || '') : '' },
            { name: 'interest', label: 'به چه چیزی علاقه دارد؟', value: lead ? (lead.interest || '') : '' },
            { name: 'source', label: 'از کجا آشنا شد؟', value: lead ? (lead.source || '') : '', placeholder: 'اینستاگرام، معرفی، حضوری...' },
            { name: 'owner_user_id', label: 'مسئول', type: 'select', value: lead ? (lead.owner_user_id || '') : team.me,
              options: [{ value: '', label: '—' }].concat(teamUsers().map(m => ({ value: m.user_id, label: m.full_name }))) },
            { name: 'notes', label: 'یادداشت', type: 'textarea', value: lead ? (lead.notes || '') : '' },
        ];
        const v = await UI.formDialog({ title: lead ? 'ویرایش سرنخ' : 'سرنخ جدید', fields, submitText: 'ذخیره' });
        if (!v) return null;
        const res = await Api.call('crm_save_lead', Object.assign({ id: lead ? lead.id : undefined }, v));
        if (res?.success) {
            UI.showSuccess('سرنخ ذخیره شد.');
            if (pane === 'leads') await renderLeads();
            return res.id;
        }
        return null;
    }

    async function changeLeadStage(lead) {
        const v = await UI.formDialog({
            title: 'تغییر مرحله',
            fields: [
                { name: 'stage', label: 'مرحله', type: 'select', value: lead.stage,
                  options: Object.entries(STAGE).filter(([k]) => k !== 'won').map(([value, label]) => ({ value, label })) },
                { name: 'lost_reason', label: 'دلیل انصراف (برای «منصرف شد»)', value: lead.lost_reason || '' },
            ],
            submitText: 'ذخیره',
        });
        if (!v) return;
        const res = await Api.call('crm_set_lead_stage', { id: lead.id, stage: v.stage, lost_reason: v.lost_reason });
        if (res?.success) { await reloadFile(); if (pane === 'leads') await renderLeads(); }
    }

    async function convertLead(lead) {
        if (!App.hasPermission('manage_customers')) {
            UI.showError('برای تبدیل به مشتری، دسترسی مدیریت مشتریان لازم است.');
            return;
        }
        const v = await UI.formDialog({
            title: 'تبدیل به مشتری',
            message: '<div class="small text-muted">سابقه تماس‌ها و کارهای این سرنخ به پرونده مشتری منتقل می‌شود.</div>',
            fields: [
                { name: 'name', label: 'نام مشتری', required: true, value: lead.name },
                { name: 'phone', label: 'شماره تماس', value: lead.phone || '' },
                { name: 'nationalId', label: 'کد ملی / شناسه (اختیاری)', value: '' },
                { name: 'address', label: 'آدرس (اختیاری)', type: 'textarea', value: '' },
            ],
            submitText: 'تبدیل',
            submitClass: 'btn-success',
        });
        if (!v) return;
        const res = await Api.call('crm_convert_lead', { id: lead.id, customer: v });
        if (res?.success) {
            UI.showSuccess('مشتری ساخته شد.');
            try { await App.updateCache(['customers']); } catch (e) { /* cache refresh is best effort */ }
            fillCustomerSearch(true);
            if (pane === 'leads') await renderLeads();
            await openPerson('customer', res.customer_id);
        }
    }

    // ---------- members ----------

    function renderMembers() {
        const rows = team.members.map(m => `
            <div class="card crm-card mb-2 ${Number(m.is_active) ? '' : 'opacity-50'}">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="bi ${m.kind === 'partner' ? 'bi-person-vcard' : 'bi-person-workspace'} fs-4 text-primary"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold">${esc(m.full_name)} <span class="badge text-bg-light">${m.kind === 'partner' ? 'شریک' : 'کارمند'}</span>${Number(m.is_active) ? '' : ' <span class="badge text-bg-secondary">غیرفعال</span>'}</div>
                        <div class="small text-muted">${m.username ? `کاربر: ${esc(m.username)}` : '<span class="text-warning">بدون کاربر سیستم (کار و یادآوری نمی‌گیرد)</span>'}${m.mobile ? ' · ' + esc(m.mobile) : ''}${m.partner_name ? ' · ' + esc(m.partner_name) : ''}</div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary crm-member-edit" data-id="${m.id}" aria-label="ویرایش"><i class="bi bi-pencil"></i></button>
                    ${Number(m.is_active) ? `<button class="btn btn-sm btn-outline-danger crm-member-off" data-id="${m.id}" aria-label="غیرفعال"><i class="bi bi-person-dash"></i></button>` : ''}
                </div>
            </div>`).join('');
        $('#crm-members-list').html(rows || emptyBox('هنوز عضوی تعریف نشده است.'));
    }

    async function memberDialog(m = null) {
        const v = await UI.formDialog({
            title: m ? 'ویرایش عضو تیم' : 'عضو جدید تیم',
            fields: [
                { name: 'full_name', label: 'نام', required: true, value: m ? m.full_name : '' },
                { name: 'kind', label: 'نقش', type: 'select', value: m ? m.kind : 'employee', options: [{ value: 'partner', label: 'شریک' }, { value: 'employee', label: 'کارمند' }] },
                { name: 'user_id', label: 'کاربر سیستم', type: 'select', value: m ? (m.user_id || '') : '', help: 'برای سپردن کار و دریافت یادآوری لازم است.',
                  options: [{ value: '', label: '— بدون کاربر —' }].concat((team.users || []).map(u => ({ value: u.id, label: u.username }))) },
                { name: 'partner_id', label: 'ردیف شریک در حسابداری (برای شرکا)', type: 'select', value: m ? (m.partner_id || '') : '',
                  options: [{ value: '', label: '—' }].concat((team.partners || []).map(p => ({ value: p.id, label: p.name }))) },
                { name: 'mobile', label: 'موبایل', value: m ? (m.mobile || '') : '' },
                { name: 'is_active', label: 'فعال', type: 'checkbox', value: m ? Number(m.is_active) === 1 : true },
            ],
            submitText: 'ذخیره',
        });
        if (!v) return;
        const res = await Api.call('crm_save_team_member', Object.assign({ id: m ? m.id : undefined }, v, { is_active: v.is_active ? 1 : 0 }));
        if (res?.success) {
            UI.showSuccess('ذخیره شد.');
            await loadTeam();
            renderMembers();
        }
    }

    // ---------- customer / lead file ----------

    function fileSheet() {
        return bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('crm-file'));
    }

    async function openPerson(type, id) {
        if (!loaded) await loadTeam();
        file = { type, id: Number(id), page: 1 };
        $('#crm-file-head, #crm-file-actions, #crm-file-tasks, #crm-file-timeline').empty();
        $('#crm-file-title').text('...');
        fileSheet().show();
        await reloadFile();
    }

    async function reloadFile() {
        if (!file) return;
        file.page = 1;
        if (file.type === 'customer') await loadCustomerFile(false);
        else if (file.type === 'lead') await loadLeadFile();
    }

    function actionButtons(person, phone) {
        const btn = (cls, icon, label, extra = '') => `<button class="btn ${cls} ${extra}"><i class="bi ${icon} me-1"></i>${label}</button>`;
        return [
            phone ? `<a class="btn btn-primary crm-call" href="tel:${esc(phone)}" data-type="${person.type}" data-id="${person.id}" data-name="${esc(person.name)}"><i class="bi bi-telephone me-1"></i>تماس</a>` : '',
            btn('btn-outline-primary', 'bi-pencil-square', 'ثبت تماس / یادداشت', 'crm-file-log'),
            btn('btn-outline-secondary', 'bi-alarm', 'پیگیری', 'crm-file-task'),
            phone ? btn('btn-outline-secondary', 'bi-chat-left-text', 'پیامک', 'crm-file-sms') : '',
            person.type === 'customer' && App.hasPermission('use_messenger') ? `<button class="btn btn-outline-secondary" data-share-ref="customer" data-id="${person.id}"><i class="bi bi-chat-dots me-1"></i>ارسال در گفتگو</button>` : '',
        ].join('');
    }

    function fileTasksHtml(tasks) {
        if (!tasks || !tasks.length) return '';
        return `<h6 class="text-muted small fw-bold">کارهای باز</h6>` + tasks.map(t => taskCard(t, { showAssignee: true })).join('');
    }

    function timelineItem(it) {
        let title = it.title || '';
        // Server text carries Gregorian dates (check due dates, promise dates); show them Jalali.
        let detail = String(it.detail || '').replace(/\b\d{4}-\d{2}-\d{2}\b/g, (d) => faDate(d));
        if (it.type === 'activity') {
            const [kind, outcome] = String(title).split('|');
            title = (outcome === 'sms' ? 'پیامک' : (KIND[kind] || 'یادداشت')) + (outcome && outcome !== 'sms' ? ' — ' + (OUTCOME[outcome] || outcome) : '');
        }
        const icon = it.type === 'activity' ? (KIND_ICON[String(it.title).split('|')[0]] || 'bi-sticky') : (TL_ICON[it.type] || 'bi-dot');
        const amount = it.amount != null && it.amount !== '' && Number(it.amount) !== 0
            ? `<span class="text-nowrap ${Number(it.amount) < 0 ? 'text-danger' : ''}">${money(Math.abs(Number(it.amount)))}</span>` : '';
        const crm = it.type === 'activity' || it.type === 'task';
        return `<div class="crm-tl-item d-flex gap-2 py-2 border-bottom">
            <i class="bi ${icon} ${crm ? 'text-primary' : 'text-muted'} fs-5"></i>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex justify-content-between gap-2"><span class="${crm ? 'fw-semibold' : ''}">${esc(title)}</span>${amount}</div>
                ${detail ? `<div class="small text-body-secondary">${esc(detail)}</div>` : ''}
                <div class="small text-muted">${esc(faDate(it.date))}${it.user_name ? ' · ' + esc(it.user_name) : ''}</div>
            </div>
        </div>`;
    }

    async function loadCustomerFile(append) {
        const res = await Api.call('crm_customer_timeline', { customer_id: file.id, page: file.page, limit: 30 });
        if (!res?.success) return;
        if (!append) {
            const h = res.header;
            file.data = h;
            file.name = h.name;
            const bal = Number(h.net_balance) || 0;
            const balHtml = Math.abs(bal) < 0.01 ? '<span class="text-muted">تسویه</span>'
                : bal > 0 ? `<span class="text-danger fw-semibold">بدهکار ${money(bal)}</span>` : `<span class="text-success fw-semibold">بستانکار ${money(-bal)}</span>`;
            const promise = h.open_promise
                ? `<div class="small mt-1 ${h.open_promise.promise_status === 'broken' ? 'text-danger' : 'text-info'}"><i class="bi bi-hand-thumbs-up me-1"></i>قول ${money(h.open_promise.promise_amount)} تا ${esc(faDate(h.open_promise.promise_date))}${h.open_promise.promise_status === 'broken' ? ' — عمل نشد' : ''}</div>` : '';
            const last = h.last_activity ? `<div class="small text-muted mt-1">آخرین پیگیری: ${esc(agoLabel(h.last_activity.created_at))}${h.last_activity.user_name ? ' — ' + esc(h.last_activity.user_name) : ''}${h.last_activity.outcome ? ' — ' + esc(OUTCOME[h.last_activity.outcome] || h.last_activity.outcome) : ''}</div>` : '';
            $('#crm-file-title').text(h.name);
            $('#crm-file-head').html(`
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>${balHtml}<div class="small text-muted">${esc(h.phone || '')}</div>${promise}${last}</div>
                    <div style="min-width: 160px"><label class="form-label small mb-0 text-muted">مسئول</label>
                        <select class="form-select form-select-sm" id="crm-file-owner"><option value="">—</option>${assigneeOptions(h.owner_user_id)}</select></div>
                </div>`);
            $('#crm-file-actions').html(actionButtons({ type: 'customer', id: file.id, name: h.name }, firstPhone(h.phone)));
            $('#crm-file-tasks').html(fileTasksHtml(h.open_tasks));
            $('#crm-file-timeline').empty();
        }
        $('#crm-file-timeline').append((res.items || []).map(timelineItem).join('') || (append ? '' : '<div class="text-muted small">سابقه‌ای ثبت نشده.</div>'));
        $('#crm-file-more').toggleClass('d-none', !res.has_more);
    }

    async function loadLeadFile() {
        const [leadRes, actRes] = await Promise.all([
            Api.call('crm_get_lead', { id: file.id }),
            Api.call('crm_person_activities', { person_type: 'lead', person_id: file.id }),
        ]);
        if (!leadRes?.success) return;
        const l = leadRes.lead;
        file.data = l;
        file.name = l.name;
        $('#crm-file-title').text(l.name);
        $('#crm-file-head').html(`
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="badge text-bg-primary">${esc(STAGE[l.stage] || l.stage)}</span>
                ${l.phone ? `<span class="small text-muted">${esc(l.phone)}</span>` : ''}
                ${l.source ? `<span class="small text-muted"><i class="bi bi-megaphone me-1"></i>${esc(l.source)}</span>` : ''}
            </div>
            ${l.interest ? `<div class="mt-1">${esc(l.interest)}</div>` : ''}
            ${l.notes ? `<div class="small text-body-secondary mt-1">${esc(l.notes)}</div>` : ''}
            ${l.converted_customer_id ? `<a href="#" class="small crm-open-person" data-type="customer" data-id="${l.converted_customer_id}">رفتن به پرونده مشتری</a>` : ''}`);
        const extra = l.converted_customer_id ? '' : `
            <button class="btn btn-outline-secondary crm-lead-stage"><i class="bi bi-signpost-split me-1"></i>مرحله</button>
            <button class="btn btn-outline-secondary crm-lead-edit"><i class="bi bi-pencil me-1"></i>ویرایش</button>
            <button class="btn btn-success crm-lead-convert"><i class="bi bi-person-check me-1"></i>تبدیل به مشتری</button>`;
        $('#crm-file-actions').html(actionButtons({ type: 'lead', id: l.id, name: l.name }, firstPhone(l.phone)) + extra);
        const tasks = (actRes?.tasks || []).filter(t => t.status === 'open');
        $('#crm-file-tasks').html(fileTasksHtml(tasks));
        const items = (actRes?.activities || []).map(a => ({
            type: 'activity', title: `${a.kind}|${a.outcome || ''}`, date: a.created_at, amount: a.promise_amount, detail: a.body, user_name: a.user_name,
        }));
        $('#crm-file-timeline').html(items.map(timelineItem).join('') || '<div class="text-muted small">هنوز تماسی ثبت نشده.</div>');
        $('#crm-file-more').addClass('d-none');
    }

    async function smsDialog() {
        const v = await UI.formDialog({
            title: `پیامک به ${file.name}`,
            fields: [{ name: 'message', label: 'متن پیامک', type: 'textarea', required: true }],
            submitText: 'ارسال',
        });
        if (!v) return;
        const res = await Api.call('crm_send_sms', { person_type: file.type, person_id: file.id, message: v.message, user_confirmed: true });
        if (res?.success) { UI.showSuccess('پیامک ارسال شد.'); await reloadFile(); }
    }

    // ---------- call / note sheet ----------

    function logSheet() {
        return bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('crm-log-sheet'));
    }

    function openLog(target, kind = 'call_out') {
        logTarget = target;
        $('#crm-log-title').text(`ثبت نتیجه — ${target.name || ''}`);
        $('#crm-log-kinds').html(Object.entries(KIND).map(([k, label]) =>
            `<button type="button" class="btn btn-sm rounded-pill ${k === kind ? 'btn-primary' : 'btn-outline-secondary'}" data-kind="${k}"><i class="bi ${KIND_ICON[k]} me-1"></i>${label}</button>`).join(''));
        const outcomes = target.type === 'lead'
            ? ['no_answer', 'talked', 'interested', 'callback', 'not_interested']
            : ['no_answer', 'talked', 'promised', 'callback'];
        $('#crm-log-outcomes').html(outcomes.map(o =>
            `<button type="button" class="btn btn-sm rounded-pill btn-outline-secondary" data-outcome="${o}">${OUTCOME[o]}</button>`).join(''));
        $('#crm-log-body').val('');
        $('#crm-log-promise').addClass('d-none');
        $('#crm-log-promise-amount').val('');
        $('#crm-log-promise-date').val(UI.gregorianToPersian(ymd(new Date(Date.now() + 3 * 86400000))));
        $('#crm-log-next-on').prop('checked', false);
        $('#crm-log-next').addClass('d-none');
        $('#crm-log-next-title').val(`پیگیری ${target.name || ''}`);
        $('#crm-log-next-date').val(UI.gregorianToPersian(ymd(new Date(Date.now() + 86400000))));
        $('#crm-log-next-time').val('10:00');
        $('#crm-log-next-assignee').html(assigneeOptions(team.me));
        $('#crm-log-error').addClass('d-none');
        UI.initializeDatepickers();
        logSheet().show();
    }

    function pickOutcome(o) {
        $('#crm-log-outcomes [data-outcome]').removeClass('btn-success').addClass('btn-outline-secondary');
        $(`#crm-log-outcomes [data-outcome="${o}"]`).removeClass('btn-outline-secondary').addClass('btn-success');
        $('#crm-log-promise').toggleClass('d-none', o !== 'promised');
        // No answer / call back later → suggest a follow-up right away.
        if (o === 'no_answer' || o === 'callback' || o === 'interested') {
            $('#crm-log-next-on').prop('checked', true);
            $('#crm-log-next').removeClass('d-none');
        }
    }

    async function saveLog() {
        const kind = $('#crm-log-kinds .btn-primary').data('kind') || 'note';
        const outcome = $('#crm-log-outcomes .btn-success').data('outcome') || '';
        const payload = { person_type: logTarget.type, person_id: logTarget.id, kind, outcome, body: $('#crm-log-body').val() };
        if (outcome === 'promised') {
            payload.promise_amount = num($('#crm-log-promise-amount').val());
            payload.promise_date = UI.toGregorian('#crm-log-promise-date');
            if (!payload.promise_amount || !payload.promise_date) {
                $('#crm-log-error').text('مبلغ و تاریخ قول را وارد کنید.').removeClass('d-none');
                return;
            }
        }
        if ($('#crm-log-next-on').is(':checked')) {
            const d = UI.toGregorian('#crm-log-next-date') || todayYmd();
            const t = UI.toEnglishDigits($('#crm-log-next-time').val() || '10:00');
            payload.next_task = {
                title: $('#crm-log-next-title').val() || `پیگیری ${logTarget.name || ''}`,
                due_at: `${d} ${/^\d{2}:\d{2}$/.test(t) ? t : '10:00'}:00`,
                assignee_user_id: $('#crm-log-next-assignee').val(),
            };
        }
        if (!payload.outcome && !String(payload.body || '').trim()) {
            $('#crm-log-error').text('نتیجه را انتخاب کنید یا چیزی بنویسید.').removeClass('d-none');
            return;
        }
        const res = await Api.call('crm_log_activity', payload);
        if (res?.success) {
            logSheet().hide();
            UI.showSuccess('ثبت شد.');
            await refreshAfterChange();
        } else if (res?.error) {
            $('#crm-log-error').text(res.error).removeClass('d-none');
        }
    }

    // After tapping "call", coming back to the app asks how it went.
    function onVisibility() {
        if (document.visibilityState !== 'visible' || !pendingCall) return;
        const age = Date.now() - pendingCall.at;
        const call = pendingCall;
        pendingCall = null;
        if (age > 3000 && age < 30 * 60000) openLog(call, 'call_out');
    }

    // ---------- push ----------

    async function refreshPushButton() {
        if (!window.Pwa) return;
        const state = await Pwa.pushState();
        const $btn = $('#crm-push-btn');
        const $hint = $('#crm-push-hint');
        $btn.toggleClass('d-none', state !== 'off');
        if (state === 'ios-install') $hint.text('برای دریافت نوتیفیکیشن روی آیفون، از دکمه «اشتراک‌گذاری» گزینه «افزودن به صفحه اصلی» را بزنید و برنامه را از آیکون آن باز کنید.').removeClass('d-none');
        else if (state === 'denied') $hint.text('نوتیفیکیشن برای این سایت در تنظیمات مرورگر مسدود شده است.').removeClass('d-none');
        else $hint.addClass('d-none');
    }

    async function enablePush() {
        const ok = await Pwa.enablePush();
        if (ok) {
            UI.showSuccess('نوتیفیکیشن روی این دستگاه فعال شد.');
            Api.call('push_test');
        } else {
            UI.showError('فعال‌سازی نوتیفیکیشن انجام نشد.');
        }
        refreshPushButton();
    }

    // ---------- customer search ----------

    function fillCustomerSearch(force = false) {
        const $sel = $('#crm-customer-search');
        if ($sel.data('filled') && !force) return;
        const customers = (App.getCache && App.getCache().customers) || [];
        $sel.html('<option></option>' + customers.map(c => `<option value="${c.id}">${esc(c.name)}${c.phone ? ' — ' + esc(c.phone) : ''}</option>`).join(''));
        if ($sel.data('select2')) $sel.select2('destroy');
        $sel.select2({ theme: 'bootstrap-5', dir: 'rtl', placeholder: 'باز کردن پرونده مشتری...', allowClear: true, width: '100%' });
        $sel.data('filled', customers.length > 0);
    }

    // ---------- deep links: #crm, #crm/task/ID, #crm/customer/ID, #crm/lead/ID ----------

    function routeHash() {
        const m = /^#crm(?:\/(task|customer|lead)\/(\d+))?$/.exec(window.location.hash || '');
        if (!m || !App.hasPermission('use_crm')) return;
        const tab = document.getElementById('crm-tab');
        if (tab && !tab.classList.contains('active')) bootstrap.Tab.getOrCreateInstance(tab).show();
        if (m[1] === 'task') openTask(Number(m[2]));
        else if (m[1]) openPerson(m[1], Number(m[2]));
        history.replaceState(null, '', window.location.pathname + window.location.search);
    }

    function init() {
        $('#crm-tab').on('shown.bs.tab', load);
        $('#crm-subnav').on('click', '[data-crm-pane]', function () { showPane($(this).data('crm-pane')); });
        $('#crm-team-filter-member, #crm-team-filter-status').on('change', renderTeamTasks);
        $('#crm-debtors-search').on('input', drawDebtors);
        let leadTimer = null;
        $('#crm-leads-search').on('input', () => { clearTimeout(leadTimer); leadTimer = setTimeout(renderLeads, 300); });
        $('#crm-stage-pills').on('click', '[data-stage]', function () { leadStage = $(this).data('stage'); drawLeads(); });
        $('#crm-new-task-btn').on('click', () => taskDialog());
        $('#crm-new-lead-btn').on('click', async () => { const id = await leadDialog(); if (id) openPerson('lead', id); });
        $('#crm-push-btn').on('click', enablePush);
        $('#crm-add-member-btn').on('click', () => memberDialog());
        $('#crm-members-list').on('click', '.crm-member-edit', function () { memberDialog(team.members.find(m => Number(m.id) === Number($(this).data('id')))); });
        $('#crm-members-list').on('click', '.crm-member-off', async function () {
            if (!await UI.confirm('این عضو غیرفعال شود؟ کارهای فعلی‌اش باقی می‌ماند.')) return;
            const res = await Api.call('crm_deactivate_team_member', { id: $(this).data('id') });
            if (res?.success) { await loadTeam(); renderMembers(); }
        });
        $('#crm-customer-search').on('select2:select', function (e) {
            const id = e.params.data.id;
            $(this).val(null).trigger('change');
            if (id) openPerson('customer', id);
        });

        // Shared handlers (cards appear in panes and in the file sheet)
        $(document).on('click', '.crm-open-person', function (e) {
            if ($(e.target).closest('.crm-call').length) return;
            e.preventDefault();
            openPerson($(this).data('type'), $(this).data('id'));
        });
        $(document).on('click', '.crm-task-done', function () { setTaskStatus($(this).closest('[data-task-id]').data('task-id'), 'done'); });
        $(document).on('click', '.crm-task-edit', function () { openTask($(this).closest('[data-task-id]').data('task-id')); });
        $(document).on('click', '.crm-call', function () {
            pendingCall = { type: $(this).data('type'), id: $(this).data('id'), name: $(this).data('name'), at: Date.now() };
        });
        $(document).on('click', '.btn-crm-file', function () {
            const tab = document.getElementById('crm-tab');
            openPerson('customer', $(this).data('customer-id'));
            if (tab && !loaded) loadTeam();
        });
        $(document).on('click', '.notification-item[data-crm-task]', function (e) {
            if ($(e.target).closest('.dismiss-notification-header').length) return;
            openTask(Number($(this).data('crm-task')));
        });

        // File sheet
        $('#crm-file-actions').on('click', '.crm-file-log', () => openLog({ type: file.type, id: file.id, name: file.name }, 'note'));
        $('#crm-file-actions').on('click', '.crm-file-task', () => taskDialog(null, { type: file.type, id: file.id, name: file.name }));
        $('#crm-file-actions').on('click', '.crm-file-sms', smsDialog);
        $('#crm-file-actions').on('click', '.crm-lead-stage', () => changeLeadStage(file.data));
        $('#crm-file-actions').on('click', '.crm-lead-edit', async () => { if (await leadDialog(file.data)) reloadFile(); });
        $('#crm-file-actions').on('click', '.crm-lead-convert', () => convertLead(file.data));
        $('#crm-file-head').on('change', '#crm-file-owner', async function () {
            const res = await Api.call('crm_set_customer_owner', { customer_id: file.id, owner_user_id: $(this).val() || 0 });
            if (res?.success) UI.showSuccess('مسئول تعیین شد.');
        });
        $('#crm-file-more').on('click', async () => { file.page++; await loadCustomerFile(true); });
        document.getElementById('crm-file').addEventListener('hidden.bs.offcanvas', () => { file = null; });

        // Log sheet
        $('#crm-log-kinds').on('click', '[data-kind]', function () {
            $('#crm-log-kinds .btn').removeClass('btn-primary').addClass('btn-outline-secondary');
            $(this).removeClass('btn-outline-secondary').addClass('btn-primary');
        });
        $('#crm-log-outcomes').on('click', '[data-outcome]', function () { pickOutcome($(this).data('outcome')); });
        $('#crm-log-next-on').on('change', function () { $('#crm-log-next').toggleClass('d-none', !this.checked); });
        $('#crm-log-save').on('click', saveLog);

        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('hashchange', routeHash);
        // Wait for login/permissions before following a deep link opened from a notification.
        const tryRoute = (n) => { if (App.hasPermission('use_crm')) routeHash(); else if (n > 0) setTimeout(() => tryRoute(n - 1), 500); };
        if (/^#crm/.test(window.location.hash)) tryRoute(20);
    }

    return { init, load, showPane, setTaskStatus, openPerson, openCustomer: (id) => openPerson('customer', id), openLead: (id) => openPerson('lead', id), openTask };
})();

$(function () { CrmManager.init(); });
