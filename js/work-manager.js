// /js/work-manager.js
// میز کار: «کارهای من», projects (overview, tasks, board, done log, documents,
// reports) and meetings, modelled on mz7. Phone first: sheets, named buttons
// instead of drag-only, deep links from notifications (#work/...).

const WorkManager = (function () {
    const esc = (v) => UI.escapeHtml(v == null ? '' : String(v));
    const money = (v) => UI.formatCurrency(Number(v) || 0);
    const faDate = (d) => (d ? UI.gregorianToPersian(String(d).slice(0, 10)) : '');
    const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const fa = (n) => Number(n || 0).toLocaleString('fa-IR');
    const dayOffset = (s) => {
        const [y, m, d] = String(s).slice(0, 10).split('-').map(Number);
        const t = new Date(); t.setHours(0, 0, 0, 0);
        return Math.round((new Date(y, m - 1, d) - t) / 86400000);
    };
    const dueLabel = (d) => {
        if (!d) return '';
        const off = dayOffset(d);
        const time = String(d).length > 10 ? String(d).slice(11, 16) : '';
        const day = off === 0 ? 'امروز' : off === 1 ? 'فردا' : off === -1 ? 'دیروز' : faDate(d);
        return time && time !== '00:00' ? `${day} ساعت ${time}` : day;
    };
    const timeOf = (dt) => String(dt || '').slice(11, 16);
    const fileUrl = (id) => `${AppConfig.API_URL}?action=work_file&id=${Number(id)}`;
    const sizeText = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
    const emptyBox = (text, icon = 'bi-inbox') => `<div class="text-center text-muted py-4"><i class="bi ${icon} fs-3 d-block mb-1"></i>${esc(text)}</div>`;

    const STATUS = { planning: 'برنامه‌ریزی', active: 'در حال اجرا', on_hold: 'متوقف', done: 'تکمیل‌شده', cancelled: 'لغوشده' };
    const STATUS_CLS = { planning: 'secondary', active: 'primary', on_hold: 'warning', done: 'success', cancelled: 'dark' };
    const PRIORITY = { low: 'کم', medium: 'معمولی', high: 'زیاد', urgent: 'فوری' };
    const TASK_STATUS = { todo: 'انجام نشده', in_progress: 'در حال انجام', review: 'منتظر تأیید', done: 'انجام شد' };
    const TASK_CLS = { todo: 'secondary', in_progress: 'info', review: 'warning', done: 'success' };
    const KIND_ICON = { image: 'bi-file-earmark-image', video: 'bi-file-earmark-play', audio: 'bi-file-earmark-music', pdf: 'bi-file-earmark-pdf', office: 'bi-file-earmark-text', zip: 'bi-file-earmark-zip' };

    let people = null;      // [{user_id, full_name}]
    let me = 0;
    let pane = 'my';
    let projectFilter = 'active';
    let meetingFilter = 'upcoming';
    let proj = null;        // project detail from work_project
    let projTab = 'overview';
    let openTaskId = null;
    let docFilter = 'all';
    let mtg = null;         // meeting detail

    const can = (k) => (typeof App !== 'undefined' && App.hasPermission ? App.hasPermission(k) : false);

    async function loadPeople(force = false) {
        if (people && !force) return people;
        const res = await Api.call('work_people', {}, false);
        people = (res && res.people) || [];
        me = Number(res && res.me) || 0;
        return people;
    }
    const personName = (id) => (people || []).find(p => Number(p.user_id) === Number(id))?.full_name || '';

    // Multipart upload with progress (Api.call is JSON only).
    function upload(action, form, onProgress) {
        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', `${AppConfig.API_URL}?action=${action}`);
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.upload.onprogress = (e) => { if (e.lengthComputable && onProgress) onProgress(Math.round(e.loaded * 100 / e.total)); };
            xhr.onload = () => {
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { data = { error: 'پاسخ سرور نامعتبر است.' }; }
                if (xhr.status >= 400 && !data.error) data.error = 'ارسال ناموفق بود.';
                resolve(data);
            };
            xhr.onerror = () => resolve({ error: 'اتصال برقرار نشد.' });
            xhr.send(form);
        });
    }

    // A small modal with file input (+ optional title/description) and a progress bar.
    function uploadDialog({ title, action, fields = {}, withMeta = false, limit = 0 }) {
        return new Promise((resolve) => {
            const id = 'work-up-' + Date.now();
            const html = `
<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">${esc(title)}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    ${withMeta ? `<div class="mb-2"><label class="form-label small">عنوان (اختیاری)</label><input class="form-control" data-f="title" placeholder="مثلاً: قرارداد با تأمین‌کننده"></div>
    <div class="mb-2"><label class="form-label small">توضیح (اختیاری)</label><textarea class="form-control" data-f="description" rows="2"></textarea></div>` : ''}
    <input type="file" class="form-control" multiple data-f="file">
    <div class="form-text">تصویر، PDF، Word/Excel/PowerPoint، zip، صوت و ویدئو — حداکثر ۱۰ فایل${limit ? `، هر فایل تا ${fa(Math.floor(limit / 1048576))} مگابایت` : ''}.</div>
    <div class="progress mt-2 d-none" style="height:6px"><div class="progress-bar" style="width:0%"></div></div>
    <div class="alert alert-danger py-2 small mt-2 d-none" data-f="error"></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button type="button" class="btn btn-primary" data-f="go">ارسال</button></div>
</div></div></div>`;
            const $m = $(html).appendTo('body');
            let result = null;
            $m.find('[data-f=go]').on('click', async function () {
                const files = $m.find('[data-f=file]')[0].files;
                const $err = $m.find('[data-f=error]').addClass('d-none');
                if (!files.length) { $err.text('فایلی انتخاب نکرده‌اید.').removeClass('d-none'); return; }
                if (limit) {
                    const big = Array.from(files).find(f => f.size > limit && !/^video\//.test(f.type));
                    if (big) { $err.text(`حجم «${big.name}» بیش از حد مجاز است.`).removeClass('d-none'); return; }
                }
                const fd = new FormData();
                Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
                if (withMeta) { fd.append('title', $m.find('[data-f=title]').val()); fd.append('description', $m.find('[data-f=description]').val()); }
                Array.from(files).forEach(f => fd.append('file[]', f, f.name));
                $(this).prop('disabled', true);
                const $bar = $m.find('.progress').removeClass('d-none').find('.progress-bar');
                const res = await upload(action, fd, (p) => $bar.css('width', p + '%'));
                $(this).prop('disabled', false);
                if (res && res.success) { result = res; bootstrap.Modal.getInstance($m[0]).hide(); }
                else { $err.text(res?.error || 'ارسال ناموفق بود.').removeClass('d-none'); $m.find('.progress').addClass('d-none'); }
            });
            $m.on('hidden.bs.modal', () => { $m.remove(); resolve(result); });
            new bootstrap.Modal($m[0]).show();
        });
    }

    function previewFile(f) {
        const url = fileUrl(f.id);
        if (f.kind === 'pdf' || f.kind === 'office' || f.kind === 'zip') { window.open(url, '_blank', 'noopener'); return; }
        const body = f.kind === 'image' ? `<img src="${url}" class="img-fluid rounded" alt="${esc(f.title)}">`
            : f.kind === 'video' ? `<video src="${url}" controls playsinline class="w-100 rounded"></video>`
            : `<audio src="${url}" controls class="w-100"></audio>`;
        const $m = $(`<div class="modal fade" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
            <div class="modal-header"><h6 class="modal-title text-truncate">${esc(f.title || f.file_name)}</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center">${body}</div>
            <div class="modal-footer"><a class="btn btn-outline-primary" href="${url}" download="${esc(f.file_name)}"><i class="bi bi-download me-1"></i>دانلود</a></div>
        </div></div></div>`).appendTo('body');
        $m.on('hidden.bs.modal', () => $m.remove());
        new bootstrap.Modal($m[0]).show();
    }

    function fileRow(f, { canDelete, deleteAction }) {
        return `<div class="d-flex align-items-center gap-2 py-2 border-bottom work-file" id="doc-${f.id}">
            <i class="bi ${KIND_ICON[f.kind] || 'bi-file-earmark'} fs-4 text-primary"></i>
            <div class="flex-grow-1 min-w-0" role="button" data-preview="${f.id}">
                <div class="text-truncate">${esc(f.title || f.file_name)}</div>
                <div class="small text-muted text-truncate">${esc(f.file_name)} · ${sizeText(Number(f.size))}${f.uploader_name ? ' · ' + esc(f.uploader_name) : ''} · ${esc(faDate(f.created_at))}</div>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="${fileUrl(f.id)}" download="${esc(f.file_name)}" aria-label="دانلود"><i class="bi bi-download"></i></a>
            ${canDelete ? `<button class="btn btn-sm btn-outline-danger" data-del-file="${f.id}" data-action="${deleteAction}" aria-label="حذف"><i class="bi bi-trash"></i></button>` : ''}
        </div>`;
    }

    // ---------- routing ----------

    function openLink(link) {
        const hash = String(link || '').replace(/^[^#]*/, '');
        if (!hash) return;
        if (window.location.hash === hash) route(hash); else window.location.hash = hash;
    }

    function showWorkTab() {
        const tab = document.getElementById('crm-tab');
        if (tab && !tab.classList.contains('active')) bootstrap.Tab.getOrCreateInstance(tab).show();
    }

    function route(hash) {
        const h = hash || window.location.hash || '';
        let m;
        if (h === '#work') {
            showWorkTab();
            if (typeof CrmManager !== 'undefined') CrmManager.showPane('my');
        } else if ((m = /^#work\/project\/(\d+)(?:\/(task|comment|doc)\/(\d+)|\/(members))?$/.exec(h))) {
            showWorkTab();
            openProject(Number(m[1]), m[2] ? { type: m[2], id: Number(m[3]) } : (m[4] ? { type: 'members' } : null));
        } else if ((m = /^#work\/meeting\/(\d+)(?:\/action\/(\d+))?$/.exec(h))) {
            showWorkTab();
            openMeeting(Number(m[1]), m[2] ? Number(m[2]) : null);
        } else {
            return false;
        }
        history.replaceState(null, '', window.location.pathname + window.location.search);
        return true;
    }

    // ---------- «کارهای من» ----------

    async function show(name) {
        pane = name;
        await loadPeople();
        if (name === 'my') return renderMyWork();
        if (name === 'projects') return renderProjects();
        if (name === 'meetings') return renderMeetings();
    }

    const SOURCE = { project: ['bi-kanban', 'پروژه'], meeting: ['bi-calendar-event', 'جلسه'], crm: ['bi-person-lines-fill', 'مشتری'] };

    function myItem(it) {
        const [icon, label] = SOURCE[it.source] || ['bi-dot', ''];
        const overdue = it.due && dayOffset(it.due) < 0;
        const doneBtn = it.source === 'project'
            ? `<button class="btn btn-sm btn-outline-success" data-my-complete="${it.id}" title="انجام شد"><i class="bi bi-check-lg"></i></button>`
            : `<button class="btn btn-sm btn-outline-success" data-my-done="${it.source}:${it.id}" title="انجام شد"><i class="bi bi-check-lg"></i></button>`;
        return `<div class="card crm-card mb-2 ${overdue ? 'border-danger' : ''}">
            <div class="card-body py-2 px-3 d-flex align-items-start gap-2">
                <div class="flex-grow-1 min-w-0" role="button" data-link="${esc(it.link)}">
                    <div class="fw-semibold">${esc(it.title)}</div>
                    <div class="small text-muted d-flex flex-wrap gap-2 mt-1">
                        <span><i class="bi ${icon} me-1"></i>${label}${it.context ? ': ' + esc(it.context) : ''}</span>
                        ${it.due ? `<span class="${overdue ? 'text-danger fw-semibold' : ''}"><i class="bi bi-clock me-1"></i>${esc(dueLabel(it.due))}</span>` : ''}
                        ${it.status === 'in_progress' ? '<span class="badge text-bg-info">در حال انجام</span>' : ''}
                    </div>
                </div>
                ${doneBtn}
            </div>
        </div>`;
    }

    async function renderMyWork() {
        const res = await Api.call('work_my');
        if (!res?.success) return;
        const badge = (res.overdue || []).length + (res.today || []).length + (res.awaiting_review || []).length + (res.cartable || []).length;
        $('#crm-my-badge').text(fa(badge)).toggleClass('d-none', badge === 0);
        let html = '';
        if ((res.awaiting_review || []).length) {
            html += `<h6 class="small fw-bold text-warning mt-2">منتظر تأیید شما <span class="badge text-bg-light">${fa(res.awaiting_review.length)}</span></h6>`;
            html += res.awaiting_review.map(t => `<div class="card crm-card mb-2 border-warning"><div class="card-body py-2 px-3">
                <div class="fw-semibold" role="button" data-link="${esc(t.link)}">${esc(t.title)}</div>
                <div class="small text-muted">${esc(t.context)}${t.by ? ' · انجام‌دهنده: ' + esc(t.by) : ''}</div>
                ${t.note ? `<div class="small mt-1">«${esc(t.note)}»</div>` : ''}
                <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-success" data-approve="${t.id}">تأیید نهایی</button><button class="btn btn-sm btn-outline-secondary" data-return="${t.id}">برگرداندن</button></div>
            </div></div>`).join('');
        }
        if ((res.cartable || []).length) {
            html += `<h6 class="small fw-bold text-success mt-3">کارتابل <span class="badge text-bg-light">${fa(res.cartable.length)}</span></h6>` + res.cartable.map(r => `
                <div class="card crm-card mb-2"><div class="card-body py-2 px-3" role="button" data-link="${esc(r.link)}">
                    <i class="bi ${r.kind === 'payment' ? 'bi-cash-coin text-success' : 'bi-envelope-paper text-primary'} me-1"></i><span class="fw-semibold">${esc(r.title)}</span>
                    <div class="small text-muted">${esc(r.context || '')}${r.amount ? ' · ' + money(r.amount) : ''}${r.status === 'approved' ? ' · تأییدشده، منتظر پرداخت' : ''}${r.due ? ' · مهلت ' + esc(dueLabel(r.due)) : ''}</div>
                </div></div>`).join('');
        }
        if ((res.meetings_today || []).length) {
            html += `<h6 class="small fw-bold text-primary mt-3">جلسات امروز</h6>` + res.meetings_today.map(m => `
                <div class="card crm-card mb-2"><div class="card-body py-2 px-3" role="button" data-link="./index.php#work/meeting/${m.id}">
                    <i class="bi bi-calendar-event me-1 text-primary"></i><span class="fw-semibold">${esc(m.title)}</span>
                    <span class="small text-muted ms-2">ساعت ${esc(timeOf(m.starts_at))}${m.location ? ' · ' + esc(m.location) : ''}</span>
                </div></div>`).join('');
        }
        [['overdue', 'عقب‌افتاده', 'text-danger'], ['today', 'امروز', 'text-primary'], ['upcoming', 'بعداً', 'text-muted']].forEach(([k, label, cls]) => {
            if (!(res[k] || []).length) return;
            html += `<h6 class="small fw-bold ${cls} mt-3">${label} <span class="badge text-bg-light">${fa(res[k].length)}</span></h6>` + res[k].map(myItem).join('');
        });
        $('#crm-pane-my').html(html || emptyBox('کار بازی ندارید.', 'bi-emoji-smile'));
    }

    // ---------- projects list ----------

    async function renderProjects() {
        $('#work-project-filters .nav-link').removeClass('active').filter(`[data-filter="${projectFilter}"]`).addClass('active');
        const res = await Api.call('work_projects', { filter: projectFilter });
        if (!res?.success) return;
        const list = res.projects || [];
        $('#work-projects-list').html(list.map(p => {
            const names = (p.members || []).map(m => m.full_name).filter(Boolean);
            return `<div class="col-md-6 col-xl-4"><div class="card crm-card h-100" role="button" data-open-project="${p.id}">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2 mb-1">
                        <span class="fw-semibold text-truncate">${esc(p.title)}</span>
                        <span class="badge text-bg-${STATUS_CLS[p.status] || 'secondary'}">${STATUS[p.status] || ''}</span>
                    </div>
                    ${p.priority === 'high' || p.priority === 'urgent' ? `<span class="badge text-bg-${p.priority === 'urgent' ? 'danger' : 'warning'} mb-1">اولویت ${PRIORITY[p.priority]}</span>` : ''}
                    <div class="progress my-2" style="height:6px" aria-label="پیشرفت پروژه"><div class="progress-bar" style="width:${Number(p.progress.percent)}%"></div></div>
                    <div class="small text-muted d-flex flex-wrap gap-2">
                        <span>${fa(p.progress.done)} از ${fa(p.progress.total)} کار</span>
                        ${p.due_date ? `<span class="${p.overdue ? 'text-danger fw-semibold' : ''}">${p.overdue ? 'مهلت گذشته: ' : 'مهلت: '}${esc(faDate(p.due_date))}</span>` : ''}
                        ${Number(p.my_open_tasks) ? `<span class="text-primary">${fa(p.my_open_tasks)} کار من</span>` : ''}
                    </div>
                    <div class="small text-muted mt-1 text-truncate"><i class="bi bi-people me-1"></i>${esc(names.join('، '))}</div>
                </div>
            </div></div>`;
        }).join('') || `<div class="col-12">${emptyBox(res.can_create ? 'اولین پروژه را از دکمه «جدید» تعریف کنید.' : 'وقتی شما را به پروژه‌ای اضافه کنند، اینجا نمایش داده می‌شود.', 'bi-kanban')}</div>`);
    }

    async function projectDialog(p = null) {
        await loadPeople();
        const fields = [
            { name: 'title', label: 'عنوان پروژه', required: true, value: p ? p.title : '' },
            { name: 'description', label: 'شرح', type: 'textarea', value: p ? (p.description || '') : '' },
            { name: 'status', label: 'وضعیت', type: 'select', value: p ? p.status : 'active', options: Object.entries(STATUS).map(([value, label]) => ({ value, label })) },
            { name: 'priority', label: 'اولویت', type: 'select', value: p ? p.priority : 'medium', options: Object.entries(PRIORITY).map(([value, label]) => ({ value, label })) },
            { name: 'start_date', label: 'شروع', type: 'date', allowEmpty: true, value: p ? (p.start_date || '') : '' },
            { name: 'due_date', label: 'مهلت پایان', type: 'date', allowEmpty: true, value: p ? (p.due_date || '') : '' },
            { name: 'budget', label: 'بودجه (اختیاری)', type: 'money', value: p && p.budget != null ? Number(p.budget) : '' },
        ];
        if (!p) {
            people.filter(x => Number(x.user_id) !== me).forEach(x => fields.push({ name: 'm_' + x.user_id, label: `عضو: ${x.full_name}`, type: 'checkbox', value: false }));
        }
        const v = await UI.formDialog({ title: p ? 'ویرایش پروژه' : 'پروژه جدید', fields, submitText: 'ذخیره' });
        if (!v) return null;
        const payload = { id: p ? p.id : undefined, title: v.title, description: v.description, status: v.status, priority: v.priority,
            start_date: v.start_date || '', due_date: v.due_date || '', budget: v.budget ? v.budget : '' };
        if (!p) payload.members = people.filter(x => v['m_' + x.user_id]).map(x => ({ user_id: x.user_id, role: 'member' }));
        const res = await Api.call('work_save_project', payload);
        if (res?.success) { UI.showSuccess('پروژه ذخیره شد.'); return res.id; }
        return null;
    }

    // ---------- project sheet ----------

    const projectSheet = () => bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('work-project-sheet'));

    async function openProject(id, target = null) {
        await loadPeople();
        const res = await Api.call('work_project', { id });
        if (!res?.success) return;
        const sameProject = proj && Number(proj.project.id) === Number(id);
        proj = res;
        if (!sameProject) { projTab = 'overview'; openTaskId = null; docFilter = 'all'; }
        if (target) {
            if (target.type === 'task') { projTab = 'tasks'; openTaskId = target.id; }
            else if (target.type === 'comment') projTab = 'reports';
            else if (target.type === 'doc') projTab = 'documents';
            else if (target.type === 'members') projTab = 'overview';
        }
        renderProjectShell();
        projectSheet().show();
        if (target) {
            setTimeout(() => {
                const el = document.getElementById(target.type === 'task' ? `task-${target.id}` : target.type === 'comment' ? `comment-${target.id}` : target.type === 'doc' ? `doc-${target.id}` : 'work-members');
                if (el) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.classList.add('work-flash'); setTimeout(() => el.classList.remove('work-flash'), 2000); }
            }, 350);
        }
    }

    async function reloadProject() {
        if (!proj) return;
        const res = await Api.call('work_project', { id: proj.project.id });
        if (res?.success) { proj = res; renderProjectShell(); }
        if (pane === 'projects') renderProjects();
        if (pane === 'my') renderMyWork();
    }

    function renderProjectShell() {
        const p = proj.project;
        $('#work-project-title').text(p.title);
        $('#work-project-sub').html(`<span class="badge text-bg-${STATUS_CLS[p.status]}">${STATUS[p.status]}</span>
            <div class="progress flex-grow-1" style="height:6px;max-width:220px"><div class="progress-bar" style="width:${proj.progress.percent}%"></div></div>
            <span class="text-muted">${fa(proj.progress.percent)}٪</span>`);
        $('#work-project-menu').toggleClass('d-none', !proj.can_manage);
        const open = proj.tasks.filter(t => t.status !== 'done').length;
        const counts = { tasks: open, done: proj.progress.done, documents: proj.files.length, reports: proj.comments.length };
        $('#work-project-tabs [data-count]').each(function () { const n = counts[$(this).data('count')]; $(this).text(n ? fa(n) : ''); });
        $('#work-project-tabs .nav-link').removeClass('active').filter(`[data-tab="${projTab}"]`).addClass('active');
        const body = { overview: overviewHtml, tasks: tasksHtml, board: boardHtml, done: doneHtml, documents: documentsHtml, reports: reportsHtml }[projTab]();
        $('#work-project-body').html(body);
        UI.initializeDatepickers();
    }

    const isMember = () => !!proj.is_member;
    const canWrite = () => proj.can_manage || isMember();

    function overviewHtml() {
        const p = proj.project;
        const b = proj.budget;
        const mine = proj.tasks.filter(t => Number(t.assignee_user_id) === me && t.status !== 'done');
        const recent = [
            ...proj.comments.slice(0, 5).map(c => ({ at: c.created_at, text: `${c.author_name || ''}: گزارش نوشت` })),
            ...proj.tasks.filter(t => t.completed_at).slice(0, 5).map(t => ({ at: t.completed_at, text: `«${t.title}» انجام شد` })),
            ...proj.files.slice(0, 5).map(f => ({ at: f.created_at, text: `${f.uploader_name || ''}: فایل «${f.title || f.file_name}»` })),
        ].sort((a, c) => String(c.at).localeCompare(String(a.at))).slice(0, 6);
        const members = proj.members.map(m => `<div class="d-flex align-items-center gap-2 py-1">
            <i class="bi ${m.role === 'manager' ? 'bi-person-gear text-primary' : 'bi-person'}"></i>
            <span class="flex-grow-1">${esc(m.full_name || 'کاربر #' + m.user_id)} ${m.role === 'manager' ? '<span class="badge text-bg-light">مدیر پروژه</span>' : ''}</span>
            ${proj.can_manage ? `<select class="form-select form-select-sm w-auto" data-member-role="${m.user_id}"><option value="member" ${m.role === 'member' ? 'selected' : ''}>عضو</option><option value="manager" ${m.role === 'manager' ? 'selected' : ''}>مدیر</option></select>
                <button class="btn btn-sm btn-outline-danger" data-member-remove="${m.user_id}" aria-label="برداشتن"><i class="bi bi-x"></i></button>` : ''}
        </div>`).join('');
        const addable = (people || []).filter(x => !proj.members.some(m => Number(m.user_id) === Number(x.user_id)));
        return `<div class="row g-3">
            <div class="col-lg-7">
                <div class="card crm-card mb-3"><div class="card-body">
                    <h6 class="fw-bold">درباره پروژه</h6>
                    <div class="small" style="white-space:pre-wrap">${p.description ? esc(p.description) : '<span class="text-muted">شرحی نوشته نشده.</span>'}</div>
                    <dl class="row small mb-0 mt-2">
                        <dt class="col-4 text-muted">اولویت</dt><dd class="col-8">${PRIORITY[p.priority]}</dd>
                        <dt class="col-4 text-muted">شروع</dt><dd class="col-8">${p.start_date ? esc(faDate(p.start_date)) : '—'}</dd>
                        <dt class="col-4 text-muted">مهلت پایان</dt><dd class="col-8">${p.due_date ? esc(faDate(p.due_date)) : '—'}</dd>
                    </dl>
                </div></div>
                <div class="card crm-card mb-3"><div class="card-body">
                    <h6 class="fw-bold">کارهای من</h6>
                    ${mine.length ? mine.map(t => `<div class="py-1" role="button" data-goto-task="${t.id}"><i class="bi bi-dot"></i>${esc(t.title)} ${t.due_date ? `<span class="small text-muted">— ${esc(dueLabel(t.due_date))}</span>` : ''}</div>`).join('') : '<div class="small text-muted">کار بازی به شما سپرده نشده است.</div>'}
                </div></div>
                <div class="card crm-card"><div class="card-body">
                    <h6 class="fw-bold">آخرین فعالیت‌ها</h6>
                    ${recent.length ? recent.map(r => `<div class="small py-1"><span class="text-muted">${esc(faDate(r.at))}</span> ${esc(r.text)}</div>`).join('') : '<div class="small text-muted">هنوز فعالیتی ثبت نشده است.</div>'}
                </div></div>
            </div>
            <div class="col-lg-5">
                <div class="card crm-card mb-3"><div class="card-body">
                    <h6 class="fw-bold">بودجه و هزینه</h6>
                    <div class="d-flex justify-content-between small"><span class="text-muted">بودجه</span><span>${b.budget != null ? money(b.budget) : 'تعیین نشده'}</span></div>
                    <div class="d-flex justify-content-between small"><span class="text-muted">هزینه‌شده</span><span>${money(b.spent)}</span></div>
                    ${b.budget != null ? `<div class="d-flex justify-content-between small"><span class="text-muted">باقی‌مانده</span><span class="${b.over ? 'text-danger fw-semibold' : ''}">${money(b.remaining)}</span></div>
                    <div class="progress mt-2" style="height:6px"><div class="progress-bar ${b.over ? 'bg-danger' : ''}" style="width:${Math.min(100, b.budget ? Math.round(b.spent * 100 / b.budget) : 0)}%"></div></div>` : ''}
                    ${b.over ? '<div class="small text-danger mt-1">هزینه از بودجه بیشتر شده است.</div>' : ''}
                    <div class="form-text">هزینه‌هایی که در «تراکنشات ← هزینه» به این پروژه وصل شده‌اند.</div>
                </div></div>
                <div class="card crm-card" id="work-members"><div class="card-body">
                    <h6 class="fw-bold">اعضا</h6>
                    ${members}
                    ${proj.can_manage && addable.length ? `<div class="d-flex gap-2 mt-2"><select class="form-select form-select-sm" id="work-add-member">${addable.map(x => `<option value="${x.user_id}">${esc(x.full_name)}</option>`).join('')}</select>
                        <button class="btn btn-sm btn-outline-primary text-nowrap" data-member-add>افزودن</button></div>` : ''}
                </div></div>
            </div>
        </div>`;
    }

    // ---------- tasks ----------

    function taskPerm(t) {
        const full = proj.can_manage || Number(t.created_by) === me;
        const move = full || (isMember() && (!t.assignee_user_id || Number(t.assignee_user_id) === me));
        return { full, move };
    }

    // Named actions for a task (list, board on phones, sheet).
    function taskActions(t) {
        const { full, move } = taskPerm(t);
        const needsApproval = !proj.can_manage && proj.members.some(m => m.role === 'manager');
        const a = [];
        if (move && t.status === 'todo') a.push(['start', 'شروع کردم', 'btn-outline-info']);
        if (move && (t.status === 'todo' || t.status === 'in_progress')) a.push(['complete', needsApproval ? 'انجام شد (ارسال برای تأیید مدیر)' : 'انجام شد', 'btn-success']);
        if (t.status === 'review' && proj.can_manage) { a.push(['approve', 'تأیید نهایی', 'btn-success']); a.push(['return', 'برگرداندن', 'btn-outline-secondary']); }
        if (t.status === 'review' && !proj.can_manage && Number(t.completed_by) === me) a.push(['withdraw', 'پس گرفتن درخواست تأیید', 'btn-outline-secondary']);
        if (t.status === 'done' && (proj.can_manage || Number(t.completed_by) === me)) a.push(['reopen', 'باز کردن دوباره کار', 'btn-outline-secondary']);
        if (full) { a.push(['edit', 'ویرایش', 'btn-outline-primary']); a.push(['delete', 'حذف', 'btn-outline-danger']); }
        return a;
    }

    function taskCard(t, { board = false } = {}) {
        const overdue = t.due_date && t.status !== 'done' && dayOffset(t.due_date) < 0;
        const expanded = !board && Number(openTaskId) === Number(t.id);
        const { move } = taskPerm(t);
        return `<div class="card crm-card mb-2 work-task ${overdue ? 'border-danger' : ''}" id="task-${t.id}" data-task="${t.id}" ${board && move && window.matchMedia('(min-width: 768px)').matches ? 'draggable="true"' : ''}>
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-start gap-2" role="button" data-toggle-task="${t.id}">
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold">${esc(t.title)}</div>
                        <div class="small text-muted d-flex flex-wrap gap-2 mt-1">
                            ${!board ? `<span class="badge text-bg-${TASK_CLS[t.status]}">${TASK_STATUS[t.status]}</span>` : ''}
                            <span><i class="bi bi-person me-1"></i>${esc(t.assignee_name || 'بدون مسئول')}</span>
                            ${t.due_date ? `<span class="${overdue ? 'text-danger fw-semibold' : ''}"><i class="bi bi-clock me-1"></i>${overdue ? 'مهلت گذشته: ' : ''}${esc(dueLabel(t.due_date))}</span>` : ''}
                            ${t.checks_total ? `<span><i class="bi bi-list-check me-1"></i>${fa(t.checks_done)}/${fa(t.checks_total)}</span>` : ''}
                            ${t.blocked_by && t.blocked_by.length ? `<span class="text-warning"><i class="bi bi-lock me-1"></i>منتظر پیش‌نیاز</span>` : ''}
                        </div>
                    </div>
                </div>
                ${t.review_note && t.status !== 'done' ? `<div class="small mt-1 text-warning-emphasis"><b>برگشت از مدیر پروژه: </b>${esc(t.review_note)}</div>` : ''}
                ${board ? `<div class="d-flex flex-wrap gap-1 mt-2 d-md-none">${taskActions(t).filter(([k]) => k !== 'edit' && k !== 'delete').map(([k, label, cls]) => `<button class="btn btn-sm ${cls}" data-task-act="${k}" data-id="${t.id}">${label}</button>`).join('')}</div>` : ''}
                ${expanded ? taskDetailHtml(t) : ''}
            </div>
        </div>`;
    }

    function taskDetailHtml(t) {
        const { move } = taskPerm(t);
        const deps = (t.depends_on || []).map(id => proj.tasks.find(x => Number(x.id) === Number(id))).filter(Boolean);
        return `<div class="border-top mt-2 pt-2">
            ${t.description ? `<div class="small mb-2" style="white-space:pre-wrap">${esc(t.description)}</div>` : ''}
            ${t.completion_note ? `<div class="small mb-2"><b>گزارش انجام: </b>${esc(t.completion_note)}</div>` : ''}
            ${deps.length ? `<div class="small mb-2"><b>پیش‌نیازها: </b>${deps.map(d => `<span class="badge text-bg-${d.status === 'done' ? 'success' : 'light'}">${esc(d.title)}</span>`).join(' ')}</div>` : ''}
            <div class="small fw-bold mb-1">چک‌لیست</div>
            ${(t.checks || []).map(c => `<div class="form-check d-flex align-items-center gap-2">
                <input class="form-check-input" type="checkbox" data-check="${c.id}" ${Number(c.done) ? 'checked' : ''} ${move ? '' : 'disabled'} id="chk-${c.id}">
                <label class="form-check-label small flex-grow-1 ${Number(c.done) ? 'text-decoration-line-through text-muted' : ''}" for="chk-${c.id}">${esc(c.text)}${Number(c.done) && c.done_by_name ? ` <span class="text-muted">(${esc(c.done_by_name)})</span>` : ''}</label>
                ${move ? `<button class="btn btn-sm btn-link text-danger p-0" data-check-del="${c.id}" aria-label="حذف"><i class="bi bi-x"></i></button>` : ''}
            </div>`).join('') || '<div class="small text-muted">موردی ندارد.</div>'}
            ${move ? `<div class="input-group input-group-sm mt-1"><input class="form-control" placeholder="مورد جدید چک‌لیست…" data-check-new="${t.id}"><button class="btn btn-outline-secondary" data-check-add="${t.id}">افزودن</button></div>` : ''}
            <div class="d-flex flex-wrap gap-2 mt-2">${taskActions(t).map(([k, label, cls]) => `<button class="btn btn-sm ${cls}" data-task-act="${k}" data-id="${t.id}">${label}</button>`).join('')}
                ${can('use_messenger') ? `<button class="btn btn-sm btn-outline-secondary" data-share-ref="project_task" data-id="${t.id}"><i class="bi bi-chat-dots me-1"></i>ارسال در گفتگو</button>` : ''}</div>
        </div>`;
    }

    function tasksHtml() {
        const open = proj.tasks.filter(t => t.status !== 'done');
        const quick = canWrite() ? `<div class="d-flex flex-wrap gap-2 mb-3">
            <input class="form-control flex-grow-1" style="min-width:180px" id="work-quick-title" placeholder="کار جدید را بنویسید و Enter بزنید…" aria-label="عنوان کار جدید">
            <select class="form-select w-auto" id="work-quick-assignee" aria-label="مسئول کار"><option value="">بدون مسئول</option>${proj.members.map(m => `<option value="${m.user_id}" ${Number(m.user_id) === me ? 'selected' : ''}>${esc(m.full_name || '')}</option>`).join('')}</select>
            <button class="btn btn-primary" data-quick-add>افزودن</button>
            <button class="btn btn-outline-secondary" data-task-new title="افزودن با توضیح، مهلت و پیش‌نیاز">با جزئیات</button>
        </div>` : '';
        return quick + (open.map(t => taskCard(t)).join('') || emptyBox(proj.tasks.length ? 'همه کارها انجام شده‌اند 🎉' : 'هنوز کاری تعریف نشده. اولین کار را بالا اضافه کنید.', 'bi-list-check'));
    }

    function boardHtml() {
        const lanes = ['todo', 'in_progress', 'review', 'done'];
        const done = proj.tasks.filter(t => t.status === 'done').sort((a, b) => String(b.completed_at).localeCompare(String(a.completed_at)));
        return `<div class="row g-2 work-board">${lanes.map(l => {
            const list = l === 'done' ? done.slice(0, 30) : proj.tasks.filter(t => t.status === l);
            return `<div class="col-12 col-md-3"><div class="work-lane rounded p-2 h-100" data-lane="${l}">
                <div class="small fw-bold mb-2"><span class="badge text-bg-${TASK_CLS[l]} me-1">${fa(list.length)}</span>${TASK_STATUS[l]}</div>
                ${list.map(t => taskCard(t, { board: true })).join('') || '<div class="small text-muted text-center py-3 border rounded border-dashed">—</div>'}
            </div></div>`;
        }).join('')}</div>
        <div class="small text-muted mt-2 d-none d-md-block">کارت‌ها را بین ستون‌ها بکشید؛ برای انجام‌شدن، تأیید مدیر پروژه پرسیده می‌شود.</div>`;
    }

    function doneHtml() {
        if (!proj.done_log.length) return emptyBox('هنوز کاری انجام نشده است.', 'bi-check2-all');
        return proj.done_log.map(g => `<h6 class="small fw-bold text-muted mt-3">${esc(faDate(g.day))}</h6>` + g.tasks.map(t => `
            <div class="card crm-card mb-2"><div class="card-body py-2 px-3 d-flex align-items-start gap-2">
                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold">${esc(t.title)}</div>
                    <div class="small text-muted">${t.completed_by_name ? 'انجام: ' + esc(t.completed_by_name) : ''}${t.reviewed_by_name ? ' · تأیید: ' + esc(t.reviewed_by_name) : ''} · ساعت ${esc(timeOf(t.completed_at))}</div>
                    ${t.completion_note ? `<div class="small mt-1"><b>گزارش انجام: </b>${esc(t.completion_note)}</div>` : ''}
                </div>
                ${proj.can_manage || Number(t.completed_by) === me ? `<button class="btn btn-sm btn-outline-secondary" data-task-act="reopen" data-id="${t.id}"><span class="d-none d-sm-inline">بازگرداندن</span><i class="bi bi-arrow-counterclockwise d-sm-none"></i></button>` : ''}
            </div></div>`).join('')).join('');
    }

    async function taskDialog(t = null) {
        const others = proj.tasks.filter(x => x.status !== 'done' && (!t || Number(x.id) !== Number(t.id)));
        const fields = [
            { name: 'title', label: 'عنوان کار', required: true, value: t ? t.title : '' },
            { name: 'description', label: 'توضیح', type: 'textarea', value: t ? (t.description || '') : '' },
            { name: 'assignee_user_id', label: 'مسئول', type: 'select', value: t ? (t.assignee_user_id || '') : me,
              options: [{ value: '', label: 'بدون مسئول' }].concat(proj.members.map(m => ({ value: m.user_id, label: m.full_name || '' }))) },
            { name: 'due_date', label: 'مهلت', type: 'date', allowEmpty: true, value: t ? (t.due_date || '') : '' },
        ];
        others.forEach(o => fields.push({ name: 'd_' + o.id, label: `پیش‌نیاز: ${o.title}`, type: 'checkbox', value: t ? (t.depends_on || []).map(Number).includes(Number(o.id)) : false }));
        const v = await UI.formDialog({ title: t ? 'ویرایش کار' : 'کار جدید', fields, submitText: 'ذخیره' });
        if (!v) return;
        const res = await Api.call('work_task_save', {
            id: t ? t.id : undefined, project_id: proj.project.id, title: v.title, description: v.description,
            assignee_user_id: v.assignee_user_id || null, due_date: v.due_date || '',
            depends_on: others.filter(o => v['d_' + o.id]).map(o => o.id),
        });
        if (res?.success) { UI.showSuccess('کار ذخیره شد.'); openTaskId = res.id; await reloadProject(); }
    }

    async function completeDialog(t) {
        const needsApproval = !proj.can_manage && proj.members.some(m => m.role === 'manager');
        const v = await UI.formDialog({
            title: 'انجام شد',
            message: `<div class="small">${needsApproval ? 'با تأیید شما، کار برای تأیید نهایی به مدیر پروژه فرستاده می‌شود و تا آن موقع «منتظر تأیید مدیر» می‌ماند.' : 'با تأیید شما، کار به وضعیت «انجام شد» تغییر می‌کند.'}</div>`,
            fields: [{ name: 'note', label: 'گزارش کوتاه (اختیاری)', type: 'textarea', placeholder: 'نتیجه کار یا نکته‌ای که لازم است مدیر پروژه و بقیه بدانند…' }],
            submitText: needsApproval ? 'بله، برای تأیید مدیر بفرست' : 'بله، انجام شد', submitClass: 'btn-success',
        });
        if (!v) return false;
        const res = await Api.call('work_task_complete', { id: t.id, note: v.note });
        if (res?.success) { UI.showSuccess(res.status === 'review' ? 'برای تأیید مدیر پروژه فرستاده شد.' : 'انجام شد.'); return true; }
        return false;
    }

    async function decideDialog(taskId, approve) {
        const v = await UI.formDialog({
            title: approve ? 'تأیید نهایی انجام کار' : 'برگرداندن کار برای ادامه',
            fields: [{ name: 'note', label: approve ? 'توضیح شما (اختیاری)' : 'دلیل برگرداندن (اختیاری)', type: 'textarea', placeholder: approve ? 'نکته یا بازخورد برای انجام‌دهنده…' : 'چه چیزی باید اصلاح یا کامل شود؟' }],
            submitText: approve ? 'تأیید نهایی' : 'برگردان', submitClass: approve ? 'btn-success' : 'btn-warning',
        });
        if (!v) return false;
        const res = await Api.call(approve ? 'work_task_approve' : 'work_task_return', approve ? { id: taskId, note: v.note } : { id: taskId, reason: v.note });
        if (res?.success) { UI.showSuccess(approve ? 'تأیید شد.' : 'برگشت داده شد.'); return true; }
        return false;
    }

    async function taskAction(act, id) {
        const t = proj ? proj.tasks.find(x => Number(x.id) === Number(id)) : null;
        let ok = false;
        if (act === 'start') ok = !!(await Api.call('work_task_status', { id, status: 'in_progress' }))?.success;
        else if (act === 'withdraw') ok = !!(await Api.call('work_task_status', { id, status: 'in_progress' }))?.success;
        else if (act === 'complete' && t) ok = await completeDialog(t);
        else if (act === 'approve') ok = await decideDialog(id, true);
        else if (act === 'return') ok = await decideDialog(id, false);
        else if (act === 'reopen') ok = !!(await Api.call('work_task_reopen', { id }))?.success;
        else if (act === 'edit' && t) return taskDialog(t);
        else if (act === 'delete') {
            if (!await UI.confirm('این کار حذف شود؟')) return;
            ok = !!(await Api.call('work_task_delete', { id }))?.success;
        }
        if (ok) await reloadProject();
    }

    // Board drag & drop (desktop). Moves into review/done go through the same dialogs.
    let dragId = null;
    async function dropOnLane(lane, beforeId) {
        const t = proj.tasks.find(x => Number(x.id) === Number(dragId));
        if (!t) return;
        if (lane === t.status) {
            const ids = proj.tasks.filter(x => x.status === lane && Number(x.id) !== Number(t.id)).map(x => Number(x.id));
            const at = beforeId ? ids.indexOf(Number(beforeId)) : -1;
            ids.splice(at < 0 ? ids.length : at, 0, Number(t.id));
            const rest = proj.tasks.filter(x => x.status !== lane).map(x => Number(x.id));
            const res = await Api.call('work_task_order', { project_id: proj.project.id, ids: ids.concat(rest) });
            if (res?.success) await reloadProject();
            return;
        }
        if (lane === 'todo' || lane === 'in_progress') {
            if (t.status === 'done') { if (!(await Api.call('work_task_reopen', { id: t.id }))?.success) return; }
            if (lane === 'todo' || t.status !== 'done') await Api.call('work_task_status', { id: t.id, status: lane });
        } else if (lane === 'done' && t.status === 'review') {
            if (!proj.can_manage) { UI.showError('فقط مدیر پروژه می‌تواند انجام کار را تأیید کند.'); return; }
            await decideDialog(t.id, true);
        } else if (lane === 'review' || lane === 'done') {
            if (t.status === 'review') { UI.showError('این کار منتظر تأیید است.'); return; }
            await completeDialog(t);
        }
        await reloadProject();
    }

    // ---------- documents ----------

    function documentsHtml() {
        const q = String($('#work-doc-search').val() || '').trim();
        const kindOk = (f) => docFilter === 'all' || (docFilter === 'docs' ? ['pdf', 'office', 'zip'].includes(f.kind) : f.kind === docFilter);
        const list = proj.files.filter(f => kindOk(f) && (!q || String(f.title + ' ' + f.file_name).includes(q)));
        const chips = [['all', 'همه'], ['docs', 'اسناد'], ['image', 'تصویر'], ['video', 'ویدئو']].map(([k, l]) =>
            `<button class="btn btn-sm rounded-pill ${docFilter === k ? 'btn-primary' : 'btn-outline-secondary'}" data-doc-filter="${k}">${l}</button>`).join('');
        return `<div class="d-flex flex-wrap gap-2 align-items-center mb-2">${chips}
            <input type="search" class="form-control form-control-sm ms-auto" style="max-width:220px" id="work-doc-search" placeholder="جستجو در مستندات…" value="${esc(q)}">
            ${canWrite() ? '<button class="btn btn-sm btn-primary" data-doc-upload><i class="bi bi-upload me-1"></i>افزودن</button>' : ''}</div>
            ${list.map(f => fileRow(f, { canDelete: proj.can_manage || Number(f.uploaded_by) === me, deleteAction: 'work_doc_delete' })).join('')
                || emptyBox(proj.files.length ? 'فایلی با این مشخصات یافت نشد.' : 'هنوز مستندی ثبت نشده. قرارداد، طرح، عکس، ویدئو یا هر فایل پروژه را اینجا نگه دارید.', 'bi-folder2-open')}`;
    }

    // ---------- reports (with @mentions) ----------

    const renderBody = (body) => esc(body).replace(/@\[([^\]]{1,150})\]\(user:(\d+)\)/g, '<span class="badge text-bg-primary bg-opacity-75">@$1</span>');

    function reportsHtml() {
        const composer = canWrite() ? `<div class="card crm-card mb-3"><div class="card-body">
            <div class="position-relative">
                <textarea class="form-control" id="work-report-body" rows="3" placeholder="چه کاری انجام شد؟ به کجا رسیدیم؟ چه مشکلی هست و قدم بعدی چیست؟ (با @ همکار را نام ببرید)"></textarea>
                <div class="dropdown-menu" id="work-mention-menu" style="max-height:200px;overflow:auto"></div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                <input type="file" class="form-control form-control-sm" id="work-report-files" multiple style="max-width:300px">
                <button class="btn btn-primary ms-auto" data-report-send>ثبت گزارش</button>
            </div>
            <div class="progress mt-2 d-none" style="height:6px" id="work-report-progress"><div class="progress-bar"></div></div>
        </div></div>` : '';
        const items = proj.comments.map(c => {
            const files = proj.files.filter(f => Number(f.comment_id) === Number(c.id));
            const mine = Number(c.author_user_id) === me;
            return `<div class="card crm-card mb-2" id="comment-${c.id}"><div class="card-body py-2 px-3">
                <div class="d-flex justify-content-between gap-2 small text-muted">
                    <span><b class="text-body">${esc(c.author_name || '')}</b> · ${esc(faDate(c.created_at))} ${esc(timeOf(c.created_at))}${c.edited_at ? ' · ویرایش‌شده' : ''}</span>
                    <span class="d-flex gap-1">
                        ${mine ? `<button class="btn btn-sm btn-link p-0" data-report-edit="${c.id}" aria-label="ویرایش"><i class="bi bi-pencil"></i></button>` : ''}
                        ${mine || proj.can_manage ? `<button class="btn btn-sm btn-link text-danger p-0" data-report-del="${c.id}" aria-label="حذف گزارش"><i class="bi bi-trash"></i></button>` : ''}
                    </span>
                </div>
                <div class="mt-1" style="white-space:pre-wrap">${renderBody(c.body)}</div>
                ${files.length ? `<div class="d-flex flex-wrap gap-2 mt-2">${files.map(f => f.kind === 'image'
                    ? `<img src="${fileUrl(f.id)}" alt="${esc(f.title)}" class="rounded border work-thumb" role="button" data-preview="${f.id}">`
                    : `<button class="btn btn-sm btn-outline-secondary" data-preview="${f.id}"><i class="bi ${KIND_ICON[f.kind]} me-1"></i>${esc(f.title || f.file_name)}</button>`).join('')}</div>` : ''}
            </div></div>`;
        }).join('');
        return composer + (items || emptyBox('هنوز گزارشی نوشته نشده است.', 'bi-chat-square-text'));
    }

    function mentionQuery(textarea) {
        const upto = textarea.value.slice(0, textarea.selectionStart);
        const m = /@([^\s@\[\]()]{0,30})$/.exec(upto);
        return m ? m[1] : null;
    }

    function updateMentionMenu() {
        const ta = document.getElementById('work-report-body');
        const $menu = $('#work-mention-menu');
        const q = ta ? mentionQuery(ta) : null;
        if (q === null) { $menu.removeClass('show'); return; }
        const list = proj.members.filter(m => Number(m.user_id) !== me && String(m.full_name || '').includes(q)).slice(0, 8);
        if (!list.length) { $menu.removeClass('show'); return; }
        $menu.html(list.map(m => `<button type="button" class="dropdown-item" data-mention="${m.user_id}">${esc(m.full_name)}</button>`).join('')).addClass('show');
    }

    function insertMention(userId) {
        const ta = document.getElementById('work-report-body');
        const m = proj.members.find(x => Number(x.user_id) === Number(userId));
        if (!ta || !m) return;
        const pos = ta.selectionStart;
        const before = ta.value.slice(0, pos).replace(/@([^\s@\[\]()]{0,30})$/, '');
        const token = `@[${String(m.full_name).replace(/[\[\]()]/g, '')}](user:${m.user_id}) `;
        ta.value = before + token + ta.value.slice(pos);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = (before + token).length;
        $('#work-mention-menu').removeClass('show');
    }

    async function sendReport() {
        const body = String($('#work-report-body').val() || '').trim();
        if (!body) { UI.showError('متن گزارش را بنویسید.'); return; }
        const fd = new FormData();
        fd.append('project_id', proj.project.id);
        fd.append('body', body);
        Array.from(document.getElementById('work-report-files').files || []).forEach(f => fd.append('file[]', f, f.name));
        const $p = $('#work-report-progress').removeClass('d-none');
        const res = await upload('work_comment_save', fd, (n) => $p.find('.progress-bar').css('width', n + '%'));
        $p.addClass('d-none');
        if (res?.success) { UI.showSuccess('گزارش ثبت شد.'); await reloadProject(); }
        else UI.showError(res?.error || 'ثبت گزارش ناموفق بود.');
    }

    // ---------- meetings ----------

    async function renderMeetings() {
        $('#work-meeting-filters .nav-link').removeClass('active').filter(`[data-filter="${meetingFilter}"]`).addClass('active');
        const res = await Api.call('work_meetings', { filter: meetingFilter });
        if (!res?.success) return;
        const items = res.items || [];
        if (meetingFilter === 'actions') {
            $('#work-meetings-list').html(items.map(a => `<div class="card crm-card mb-2"><div class="card-body py-2 px-3 d-flex gap-2 align-items-start">
                <div class="flex-grow-1 min-w-0" role="button" data-link="./index.php#work/meeting/${a.meeting_id}/action/${a.id}">
                    <div class="fw-semibold">${esc(a.text)}</div>
                    <div class="small text-muted">جلسه «${esc(a.meeting_title)}»${a.due_date ? ` · <span class="${dayOffset(a.due_date) < 0 ? 'text-danger' : ''}">مهلت ${esc(dueLabel(a.due_date))}</span>` : ''}</div>
                </div>
                <button class="btn btn-sm btn-outline-success" data-my-done="meeting:${a.id}" title="انجام شد"><i class="bi bi-check-lg"></i></button>
            </div></div>`).join('') || emptyBox('مصوبه بازی به شما سپرده نشده است.', 'bi-check2-square'));
            return;
        }
        $('#work-meetings-list').html(items.map(m => `<div class="card crm-card mb-2" role="button" data-link="./index.php#work/meeting/${m.id}">
            <div class="card-body py-2 px-3 d-flex gap-3 align-items-center">
                <div class="text-center text-primary" style="min-width:64px"><div class="small">${esc(faDate(m.starts_at))}</div><div class="fw-bold">${esc(timeOf(m.starts_at))}</div></div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate">${esc(m.title)}</div>
                    <div class="small text-muted d-flex flex-wrap gap-2">
                        ${m.location ? `<span><i class="bi bi-geo-alt me-1"></i>${esc(m.location)}</span>` : ''}
                        ${m.project_title ? `<span><i class="bi bi-kanban me-1"></i>${esc(m.project_title)}</span>` : ''}
                        <span><i class="bi bi-people me-1"></i>${fa(m.attendee_count)}</span>
                        ${Number(m.action_count) ? `<span><i class="bi bi-check2-square me-1"></i>${fa(m.action_count)} مصوبه</span>` : ''}
                    </div>
                </div>
            </div>
        </div>`).join('') || emptyBox(meetingFilter === 'upcoming' ? 'جلسه پیش‌رویی ندارید.' : 'جلسه‌ای ثبت نشده است.', 'bi-calendar'));
    }

    async function meetingDialog(m = null) {
        await loadPeople();
        const projects = (await Api.call('work_projects', { filter: 'active' }, false))?.projects || [];
        const fields = [
            { name: 'title', label: 'موضوع جلسه', required: true, value: m ? m.title : '' },
            { name: 'date', label: 'تاریخ', type: 'date', value: m ? String(m.starts_at).slice(0, 10) : ymd(new Date(Date.now() + 86400000)) },
            { name: 'time', label: 'ساعت', value: m ? timeOf(m.starts_at) : '10:00', placeholder: '10:00' },
            { name: 'location', label: 'محل', value: m ? (m.location || '') : '' },
            { name: 'project_id', label: 'پروژه (اختیاری)', type: 'select', value: m ? (m.project_id || '') : '', options: [{ value: '', label: '—' }].concat(projects.map(p => ({ value: p.id, label: p.title }))) },
            { name: 'agenda', label: 'دستور جلسه', type: 'textarea', value: m ? (m.agenda || '') : '' },
        ];
        if (m) fields.push({ name: 'minutes', label: 'صورت‌جلسه', type: 'textarea', value: m.minutes || '' });
        people.filter(x => Number(x.user_id) !== me).forEach(x => fields.push({ name: 'a_' + x.user_id, label: `حاضر: ${x.full_name}`, type: 'checkbox', value: m ? (m.attendee_ids || []).map(Number).includes(Number(x.user_id)) : false }));
        const v = await UI.formDialog({ title: m ? 'ویرایش جلسه' : 'جلسه جدید', fields, submitText: 'ذخیره' });
        if (!v) return null;
        const time = UI.toEnglishDigits(v.time || '10:00').match(/^(\d{1,2}):(\d{2})$/);
        const payload = { id: m ? m.id : undefined, title: v.title, date: v.date, time: time ? `${time[1].padStart(2, '0')}:${time[2]}` : '10:00',
            location: v.location, project_id: v.project_id || null, agenda: v.agenda, minutes: m ? v.minutes : '',
            attendee_ids: people.filter(x => v['a_' + x.user_id]).map(x => x.user_id) };
        const res = await Api.call('work_meeting_save', payload);
        if (res?.success) { UI.showSuccess('جلسه ذخیره شد.'); return res.id; }
        return null;
    }

    const meetingSheet = () => bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('work-meeting-sheet'));

    async function openMeeting(id, actionId = null) {
        await loadPeople();
        const res = await Api.call('work_meeting', { id });
        if (!res?.success) return;
        mtg = res;
        renderMeeting();
        meetingSheet().show();
        if (actionId) setTimeout(() => {
            const el = document.getElementById(`action-${actionId}`);
            if (el) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.classList.add('work-flash'); setTimeout(() => el.classList.remove('work-flash'), 2000); }
        }, 350);
    }

    async function reloadMeeting() {
        if (!mtg) return;
        const res = await Api.call('work_meeting', { id: mtg.meeting.id });
        if (res?.success) { mtg = res; renderMeeting(); }
        if (pane === 'meetings') renderMeetings();
        if (pane === 'my') renderMyWork();
    }

    function renderMeeting() {
        const m = mtg.meeting;
        $('#work-meeting-title').text(m.title);
        const actions = mtg.actions.map(a => {
            const mineToTick = mtg.can_edit || Number(a.assignee_user_id) === me;
            return `<div class="d-flex align-items-start gap-2 py-2 border-bottom" id="action-${a.id}">
                <input class="form-check-input mt-1" type="checkbox" data-action-done="${a.id}" ${a.is_done ? 'checked' : ''} ${mineToTick && !a.task_id ? '' : 'disabled'} aria-label="انجام شد">
                <div class="flex-grow-1 min-w-0">
                    <div class="${a.is_done ? 'text-decoration-line-through text-muted' : ''}">${esc(a.text)}</div>
                    <div class="small text-muted d-flex flex-wrap gap-2">
                        ${a.assignee_name ? `<span><i class="bi bi-person me-1"></i>${esc(a.assignee_name)}</span>` : ''}
                        ${a.due_date ? `<span><i class="bi bi-clock me-1"></i>${esc(dueLabel(a.due_date))}</span>` : ''}
                        ${a.task_id ? `<a href="#" data-link="./index.php#work/project/${a.task_project_id}/task/${a.task_id}"><i class="bi bi-kanban me-1"></i>کار پروژه</a>` : ''}
                    </div>
                </div>
                ${mtg.can_edit && mtg.project && !a.task_id ? `<button class="btn btn-sm btn-outline-primary text-nowrap" data-action-task="${a.id}">کار پروژه</button>` : ''}
                ${mtg.can_edit ? `<button class="btn btn-sm btn-link text-danger p-0" data-action-del="${a.id}" aria-label="حذف"><i class="bi bi-x-lg"></i></button>` : ''}
            </div>`;
        }).join('');
        $('#work-meeting-body').html(`
            <div class="d-flex flex-wrap gap-3 small text-muted mb-2">
                <span><i class="bi bi-calendar-event me-1"></i>${esc(faDate(m.starts_at))} ساعت ${esc(timeOf(m.starts_at))}</span>
                ${m.location ? `<span><i class="bi bi-geo-alt me-1"></i>${esc(m.location)}</span>` : ''}
                ${mtg.project ? `<a href="#" data-link="./index.php#work/project/${mtg.project.id}"><i class="bi bi-kanban me-1"></i>${esc(mtg.project.title)}</a>` : ''}
                <span><i class="bi bi-person me-1"></i>${esc(m.created_by_name || '')}</span>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-3">${mtg.can_edit ? `<button class="btn btn-sm btn-outline-primary" data-meeting-edit><i class="bi bi-pencil me-1"></i>ویرایش و صورت‌جلسه</button>
                <button class="btn btn-sm btn-outline-danger" data-meeting-del><i class="bi bi-trash me-1"></i>حذف</button>` : ''}
                ${can('use_messenger') ? `<button class="btn btn-sm btn-outline-secondary" data-share-ref="meeting" data-id="${m.id}"><i class="bi bi-chat-dots me-1"></i>ارسال در گفتگو</button>` : ''}</div>
            <h6 class="small fw-bold">حاضران</h6>
            <div class="d-flex flex-wrap gap-1 mb-3">${mtg.attendees.map(a => `<span class="badge text-bg-light">${esc(a.full_name)}</span>`).join('') || '<span class="small text-muted">—</span>'}</div>
            <h6 class="small fw-bold">دستور جلسه</h6>
            <div class="small mb-3" style="white-space:pre-wrap">${m.agenda ? esc(m.agenda) : '<span class="text-muted">—</span>'}</div>
            <h6 class="small fw-bold">صورت‌جلسه</h6>
            <div class="small mb-3" style="white-space:pre-wrap">${m.minutes ? esc(m.minutes) : '<span class="text-muted">هنوز ثبت نشده.</span>'}</div>
            <h6 class="small fw-bold">مصوبات</h6>
            ${actions || '<div class="small text-muted mb-2">مصوبه‌ای ثبت نشده.</div>'}
            <div class="row g-2 mt-1">
                <div class="col-12 col-md-6"><input class="form-control form-control-sm" id="work-action-text" placeholder="مصوبه جدید…"></div>
                <div class="col-5 col-md-2"><select class="form-select form-select-sm" id="work-action-assignee"><option value="">مسئول</option>${(people || []).map(p => `<option value="${p.user_id}">${esc(p.full_name)}</option>`).join('')}</select></div>
                <div class="col-4 col-md-2"><input class="form-control form-control-sm persian-datepicker" id="work-action-due" placeholder="مهلت" autocomplete="off"></div>
                <div class="col-3 col-md-2"><button class="btn btn-sm btn-primary w-100" data-action-add>افزودن</button></div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-4"><h6 class="small fw-bold mb-0">فایل‌ها</h6>
                <button class="btn btn-sm btn-outline-primary" data-meeting-upload><i class="bi bi-upload me-1"></i>افزودن</button></div>
            ${mtg.files.map(f => fileRow(f, { canDelete: mtg.can_edit || Number(f.uploaded_by) === me, deleteAction: 'work_meeting_files' })).join('') || '<div class="small text-muted">فایلی ندارد.</div>'}`);
        UI.initializeDatepickers();
    }

    // ---------- init ----------

    function init() {
        // Lists
        $('#work-project-filters').on('click', '[data-filter]', function () { projectFilter = $(this).data('filter'); renderProjects(); });
        $('#work-meeting-filters').on('click', '[data-filter]', function () { meetingFilter = $(this).data('filter'); renderMeetings(); });
        $('#work-projects-list').on('click', '[data-open-project]', function () { openProject(Number($(this).data('open-project'))); });
        $('#work-new-project-btn').on('click', async () => { const id = await projectDialog(); if (id) { if (typeof CrmManager !== 'undefined') CrmManager.showPane('projects'); openProject(id); } });
        $('#work-new-meeting-btn').on('click', async () => { const id = await meetingDialog(); if (id) { if (typeof CrmManager !== 'undefined') CrmManager.showPane('meetings'); openMeeting(id); } });

        // Links everywhere (cards, bell, meeting ↔ project)
        $(document).on('click', '#crm [data-link], .work-sheet [data-link]', function (e) {
            if ($(e.target).closest('button, input, select').length && !$(e.target).is('[data-link]')) return;
            e.preventDefault();
            openLink($(this).data('link'));
        });
        $(document).on('click', '[data-share-ref]', function () {
            if (typeof ChatUI !== 'undefined') ChatUI.shareRef($(this).data('share-ref'), Number($(this).data('id')));
        });
        $(document).on('click', '.notification-item[data-link]', function (e) {
            if ($(e.target).closest('.dismiss-notification-header').length) return;
            openLink($(this).data('link'));
        });

        // «کارهای من» quick actions
        $('#crm-pane-my').on('click', '[data-my-complete]', async function () {
            const id = Number($(this).data('my-complete'));
            const res = await Api.call('work_task_complete', { id, note: '' });
            if (res?.success) { UI.showSuccess(res.status === 'review' ? 'برای تأیید مدیر پروژه فرستاده شد.' : 'انجام شد.'); renderMyWork(); }
        });
        $(document).on('click', '[data-my-done]', async function () {
            const [src, id] = String($(this).data('my-done')).split(':');
            let res = null;
            if (src === 'crm') res = await Api.call('crm_set_task_status', { id: Number(id), status: 'done' });
            else if (src === 'meeting') {
                const row = await Api.call('work_meetings', { filter: 'actions' }, false);
                const a = (row?.items || []).find(x => Number(x.id) === Number(id));
                if (a) res = await Api.call('work_meeting_action', { op: 'done', meeting_id: a.meeting_id, id: Number(id), done: 1 });
            }
            if (res?.success) { UI.showSuccess('انجام شد.'); if (pane === 'my') renderMyWork(); else if (pane === 'meetings') renderMeetings(); }
        });
        $('#crm-pane-my').on('click', '[data-approve]', async function () { if (await decideDialog(Number($(this).data('approve')), true)) renderMyWork(); });
        $('#crm-pane-my').on('click', '[data-return]', async function () { if (await decideDialog(Number($(this).data('return')), false)) renderMyWork(); });

        // Project sheet
        $('#work-project-tabs').on('click', '[data-tab]', function () { projTab = $(this).data('tab'); renderProjectShell(); });
        $('#work-project-menu').on('click', '[data-act]', async function () {
            if ($(this).data('act') === 'edit') { if (await projectDialog(proj.project)) reloadProject(); return; }
            if (!await UI.confirm('پروژه همراه با همه کارها، مستندات و گزارش‌هایش حذف می‌شود. ادامه می‌دهید؟')) return;
            const res = await Api.call('work_delete_project', { id: proj.project.id });
            if (res?.success) { projectSheet().hide(); proj = null; UI.showSuccess('پروژه حذف شد.'); renderProjects(); }
        });
        const $pb = $('#work-project-body');
        $pb.on('click', '[data-toggle-task]', function (e) {
            if ($(e.target).closest('button, a, input').length) return;
            const id = Number($(this).data('toggle-task'));
            if (projTab === 'board') { projTab = 'tasks'; openTaskId = id; renderProjectShell(); return; }
            openTaskId = Number(openTaskId) === id ? null : id;
            renderProjectShell();
        });
        $pb.on('click', '[data-goto-task]', function () { projTab = 'tasks'; openTaskId = Number($(this).data('goto-task')); renderProjectShell(); });
        $pb.on('click', '[data-task-act]', function () { taskAction($(this).data('task-act'), Number($(this).data('id'))); });
        $pb.on('click', '[data-task-new]', () => taskDialog());
        const quickAdd = async () => {
            const title = String($('#work-quick-title').val() || '').trim();
            if (!title) return;
            const res = await Api.call('work_task_save', { project_id: proj.project.id, title, assignee_user_id: $('#work-quick-assignee').val() || null });
            if (res?.success) await reloadProject();
        };
        $pb.on('click', '[data-quick-add]', quickAdd);
        $pb.on('keydown', '#work-quick-title', (e) => { if (e.key === 'Enter') { e.preventDefault(); quickAdd(); } });
        $pb.on('change', '[data-check]', async function () {
            const res = await Api.call('work_task_check', { op: 'toggle', id: Number($(this).data('check')), done: this.checked ? 1 : 0 });
            if (res?.success) reloadProject();
        });
        const addCheck = async (taskId) => {
            const $in = $pb.find(`[data-check-new="${taskId}"]`);
            const text = String($in.val() || '').trim();
            if (!text) return;
            const res = await Api.call('work_task_check', { op: 'add', task_id: taskId, text });
            if (res?.success) { openTaskId = taskId; reloadProject(); }
        };
        $pb.on('click', '[data-check-add]', function () { addCheck(Number($(this).data('check-add'))); });
        $pb.on('keydown', '[data-check-new]', function (e) { if (e.key === 'Enter') { e.preventDefault(); addCheck(Number($(this).data('check-new'))); } });
        $pb.on('click', '[data-check-del]', async function () { if ((await Api.call('work_task_check', { op: 'delete', id: Number($(this).data('check-del')) }))?.success) reloadProject(); });
        $pb.on('change', '[data-member-role]', async function () {
            const res = await Api.call('work_project_member', { op: 'role', id: proj.project.id, user_id: Number($(this).data('member-role')), role: $(this).val() });
            reloadProject(); if (res?.success) UI.showSuccess('نقش تغییر کرد.');
        });
        $pb.on('click', '[data-member-remove]', async function () {
            if (!await UI.confirm('این عضو از پروژه برداشته شود؟ کارهای بازش بی‌مسئول می‌شوند.')) return;
            if ((await Api.call('work_project_member', { op: 'remove', id: proj.project.id, user_id: Number($(this).data('member-remove')) }))?.success) reloadProject();
        });
        $pb.on('click', '[data-member-add]', async function () {
            if ((await Api.call('work_project_member', { op: 'add', id: proj.project.id, user_id: Number($('#work-add-member').val()), role: 'member' }))?.success) reloadProject();
        });
        // documents
        $pb.on('click', '[data-doc-filter]', function () { docFilter = $(this).data('doc-filter'); renderProjectShell(); });
        let searchTimer = null;
        $pb.on('input', '#work-doc-search', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { const pos = this.selectionStart; renderProjectShell(); const el = document.getElementById('work-doc-search'); if (el) { el.focus(); el.selectionStart = el.selectionEnd = pos; } }, 250);
        });
        $pb.on('click', '[data-doc-upload]', async () => {
            const res = await uploadDialog({ title: 'افزودن مستند', action: 'work_doc_upload', fields: { project_id: proj.project.id }, withMeta: true, limit: proj.upload_limit });
            if (res) { UI.showSuccess('فایل اضافه شد.'); reloadProject(); }
        });
        $(document).on('click', '.work-sheet [data-preview]', function () {
            const id = Number($(this).data('preview'));
            const f = (proj && proj.files.find(x => Number(x.id) === id)) || (mtg && mtg.files.find(x => Number(x.id) === id));
            if (f) previewFile(f);
        });
        $(document).on('click', '.work-sheet [data-del-file]', async function () {
            if (!await UI.confirm('این فایل حذف شود؟')) return;
            const action = $(this).data('action');
            const id = Number($(this).data('del-file'));
            const res = await Api.call(action, action === 'work_meeting_files' ? { op: 'delete', id } : { id });
            if (res?.success) { if (action === 'work_meeting_files') reloadMeeting(); else reloadProject(); }
        });
        // reports
        $pb.on('input click keyup', '#work-report-body', updateMentionMenu);
        $pb.on('click', '[data-mention]', function () { insertMention(Number($(this).data('mention'))); });
        $pb.on('click', '[data-report-send]', sendReport);
        $pb.on('click', '[data-report-edit]', async function () {
            const c = proj.comments.find(x => Number(x.id) === Number($(this).data('report-edit')));
            if (!c) return;
            const v = await UI.formDialog({ title: 'ویرایش گزارش', fields: [{ name: 'body', label: 'متن', type: 'textarea', required: true, value: c.body }], submitText: 'ذخیره' });
            if (v && (await Api.call('work_comment_save', { id: c.id, body: v.body }))?.success) reloadProject();
        });
        $pb.on('click', '[data-report-del]', async function () {
            const id = Number($(this).data('report-del'));
            const hasFiles = proj.files.some(f => Number(f.comment_id) === id);
            const v = await UI.formDialog({ title: 'حذف گزارش', message: 'این گزارش حذف شود؟', fields: hasFiles ? [{ name: 'with_files', label: 'فایل‌های پیوست هم حذف شوند', type: 'checkbox', value: true }] : [], submitText: 'حذف', submitClass: 'btn-danger' });
            if (v && (await Api.call('work_comment_delete', { id, with_files: v.with_files ? 1 : 0 }))?.success) reloadProject();
        });
        // board drag & drop
        $pb.on('dragstart', '.work-task[draggable]', function (e) { dragId = Number($(this).data('task')); e.originalEvent.dataTransfer.effectAllowed = 'move'; e.originalEvent.dataTransfer.setData('text/plain', String(dragId)); $(this).addClass('opacity-50'); });
        $pb.on('dragend', '.work-task[draggable]', function () { $(this).removeClass('opacity-50'); $pb.find('.work-lane').removeClass('work-lane-over'); });
        $pb.on('dragover', '.work-lane', function (e) { e.preventDefault(); $(this).addClass('work-lane-over'); });
        $pb.on('dragleave', '.work-lane', function () { $(this).removeClass('work-lane-over'); });
        $pb.on('drop', '.work-lane', function (e) {
            e.preventDefault();
            const lane = $(this).data('lane');
            // Insert before the first card whose middle is below the pointer.
            const y = e.originalEvent.clientY;
            const before = $(this).find('.work-task').toArray().find(el => { const r = el.getBoundingClientRect(); return y < r.top + r.height / 2 && Number($(el).data('task')) !== dragId; });
            dropOnLane(lane, before ? Number($(before).data('task')) : null);
        });

        // Meeting sheet
        const $mb = $('#work-meeting-body');
        $mb.on('click', '[data-meeting-edit]', async () => { if (await meetingDialog(mtg.meeting)) reloadMeeting(); });
        $mb.on('click', '[data-meeting-del]', async () => {
            if (!await UI.confirm('جلسه همراه با مصوبات و فایل‌هایش حذف شود؟')) return;
            if ((await Api.call('work_meeting_delete', { id: mtg.meeting.id }))?.success) { meetingSheet().hide(); mtg = null; renderMeetings(); }
        });
        $mb.on('click', '[data-action-add]', async () => {
            const text = String($('#work-action-text').val() || '').trim();
            if (!text) return;
            const res = await Api.call('work_meeting_action', { op: 'add', meeting_id: mtg.meeting.id, text, assignee_user_id: $('#work-action-assignee').val() || null, due_date: UI.toGregorian('#work-action-due') || '' });
            if (res?.success) reloadMeeting();
        });
        $mb.on('change', '[data-action-done]', async function () {
            const res = await Api.call('work_meeting_action', { op: 'done', meeting_id: mtg.meeting.id, id: Number($(this).data('action-done')), done: this.checked ? 1 : 0 });
            reloadMeeting(); if (res?.success && this.checked) UI.showSuccess('انجام شد.');
        });
        $mb.on('click', '[data-action-task]', async function () {
            const res = await Api.call('work_meeting_action', { op: 'to_task', meeting_id: mtg.meeting.id, id: Number($(this).data('action-task')) });
            if (res?.success) { UI.showSuccess(res.assigned ? 'کار پروژه ساخته شد.' : 'کار پروژه ساخته شد (مسئول عضو پروژه نبود؛ بی‌مسئول ماند).'); reloadMeeting(); }
        });
        $mb.on('click', '[data-action-del]', async function () {
            if (!await UI.confirm('این مصوبه حذف شود؟')) return;
            if ((await Api.call('work_meeting_action', { op: 'delete', meeting_id: mtg.meeting.id, id: Number($(this).data('action-del')) }))?.success) reloadMeeting();
        });
        $mb.on('click', '[data-meeting-upload]', async () => {
            const res = await uploadDialog({ title: 'افزودن فایل جلسه', action: 'work_meeting_files', fields: { meeting_id: mtg.meeting.id }, limit: mtg.upload_limit });
            if (res) reloadMeeting();
        });

        document.getElementById('work-project-sheet').addEventListener('hidden.bs.offcanvas', () => { openTaskId = null; });
        window.addEventListener('hashchange', () => route());
        // A link opened from a push notification arrives before login finishes.
        const tryRoute = (n) => { if (typeof App !== 'undefined' && App.hasPermission && (App.hasPermission('use_projects') || App.hasPermission('use_crm'))) route(); else if (n > 0) setTimeout(() => tryRoute(n - 1), 500); };
        if (/^#work/.test(window.location.hash)) tryRoute(20);
    }

    return { init, show, openProject, openMeeting, renderMyWork, openLink, uploadDialog, previewFile, fileRow, loadPeople };
})();

$(function () { WorkManager.init(); });
