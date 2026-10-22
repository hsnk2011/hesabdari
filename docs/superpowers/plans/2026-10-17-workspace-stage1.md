# میز کار — مرحله ۱ (پروژه، کار، جلسه) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Projects (members, tasks with review flow, checklist, prerequisites, board, reports, documents, budget) and meetings (attendees, minutes, decisions → tasks) inside hesabdari, modelled on mz7, under a «میز کار» tab.

**Architecture:** New tables in `api/core/schema.php`; models `WorkProject`, `WorkTask`, `WorkMeeting`, `WorkFiles`, `WorkReminders`, `MyWork` in `api/models/`; one router-mapped `WorkController`; notifications through the existing `CrmNotifier`; files in a protected `storage/work/` served only through the API. UI: the CRM tab becomes «میز کار» (`templates/section_crm.php` + new `js/work-manager.js`).

**Tech Stack:** PHP 8.3 (mysqli, finfo), MySQL, jQuery + Bootstrap 5 RTL, HTML5 drag-and-drop, existing `Api.call` / `UI.*` / `UI.formDialog`.

**Spec:** `docs/superpowers/specs/2026-10-17-workspace-stage1-projects-meetings-design.md` (rules ported from mz7 `src/core/projects/*`, `src/core/meetings/index.ts`).

## Global Constraints
- Branch `workspace-stage1`. No composer, no new JS libraries.
- Everything entity-scoped by `$_SESSION['current_entity_id']`; people = active team members with a user (`TeamMember::userIdsForEntity`).
- Models take `($entityId, $userId, $seesAll, ...)` explicitly (no session reads) so tests can call them; the controller supplies them. `$seesAll` = user holds `manage_projects`.
- Model methods return `['success' => true, ...]` or `['error' => '<Persian>', 'statusCode' => 4xx]` (same as CRM models); `WorkController::guarded` turns that into JSON.
- Limits (spec): project title ≤200, description ≤5000, comment ≤3000, task title ≤300, task description ≤3000, completion/review note ≤1000, check item ≤300 (≤50 per task), prerequisites ≤20 per task, meeting title ≤200, agenda ≤5000, minutes ≤20000, decision text ≤1000; files ≤25MB (video ≤100MB), ≤10 per upload.
- Insert ids are read **before** `log_activity` (it inserts its own row). Tests run with a session and `user_id => 1` so `log_activity` really writes.
- Notifications: `CrmNotifier::notify($db, $e, $userId, $type, $title, $message, $link)`; types `work_task`, `work_project`, `work_meeting`; links: `./index.php#work/project/{pid}/task/{tid}`, `#work/project/{pid}/comment/{cid}`, `#work/project/{pid}/doc/{fid}`, `#work/project/{pid}/members`, `#work/meeting/{mid}`, `#work/meeting/{mid}/action/{aid}`. Never notify the actor.
- Every new route has a `$permission_map` entry (`tests/permissions_test.php`). Bump `APP_VERSION` to `2.4.0`.
- Tests: CLI scripts in `tests/` on the local DB (`expect_true`, unique `$token`, cleanup in `finally`); pass = no `[FAIL]`.

## Review Focus
1. A member removed from a project (or deactivated in the team) must stop seeing it and its files immediately — file download re-checks membership. (Task 2/3 test.)
2. Uploading a renamed executable (`shell.php` → `photo.jpg`) is refused by content detection, and nothing is left in `storage/`. (Task 2 test.)
3. Deleting a project or meeting deletes its files from disk, not only the rows. (Task 3/6 test.)
4. A task whose prerequisite is deleted is no longer blocked by it. (Task 4 test.)
5. Server `upload_max_filesize` smaller than 25MB: the API answers with a clear Persian message instead of an empty `$_FILES`. (Task 2 test via `WorkFiles::uploadLimitBytes()` and the empty-files branch.)

---

### Task 1: Schema, permissions, storage protection

**Files:** Modify `api/core/schema.php`, `api/core/constants.php` (`2.4.0`), `js/access-control-manager.js`, `docs/deploy/nginx-hesabdari-hardening.conf`, `backend/scripts/.tmp-deploy-hesabdari-code.sh` (gitignored, add `--exclude='storage/'`), `.gitignore` (`storage/work/`); Create `storage/.htaccess` (Require all denied, same directive style as `uploads/.htaccess`), `storage/work/.gitkeep`. Test: `tests/workspace_schema_test.php`.

**Produces:** tables per spec §داده: `projects`, `project_members`, `project_tasks` (+ `reminded_on date NULL`), `project_task_checks`, `project_task_deps`, `project_comments`, `meetings` (+ `reminded_at datetime NULL`), `meeting_attendees`, `meeting_actions` (UNIQUE `task_id`), `work_files` (KEY owner_type, owner_id); columns `expenses.project_id int NULL` (+ KEY), `notifications.link varchar(255) NULL`; permissions `use_projects` = `پروژه‌ها و جلسات`, `manage_projects` = `مدیریت همه پروژه‌ها و جلسات` granted like `use_crm` / `manage_settings` via `grant_new_permission_like`.

- [ ] Step 1: failing test — after `SchemaMigrator::run`, all 10 tables exist; `expenses.project_id`, `notifications.link`, `project_tasks.reminded_on`, `meetings.reminded_at` exist; `meeting_actions.task_id` unique; both permissions in defaults and in `permissions`; `storage/.htaccess` contains `Require all denied`; the nginx conf deny regex contains `storage`.
- [ ] Step 2: run → FAIL. Step 3: implement. Step 4: test + `permissions_test.php` PASS. Step 5: commit `feat(work): schema, permissions, protected storage (v2.4.0)`.

### Task 2: WorkFiles (store, detect, stream)

**Files:** Create `api/models/WorkFiles.php`; Modify `api/core/CrmNotifier.php` (store `$link` in `notifications.link`). Test: `tests/work_files_test.php`.

**Produces:**
- `WorkFiles::root(): string` = `<app>/storage/work`.
- `WorkFiles::detect(string $path, string $name): ?array` → `['mime','ext','kind']`, kind ∈ image|video|audio|pdf|office|zip; by `finfo` MIME (office docs via zip MIME + extension docx/xlsx/pptx); null for anything else (SVG, HTML, PHP, text, executables).
- `WorkFiles::uploadLimitBytes(): int` = min(25MB, `upload_max_filesize`, `post_max_size`).
- `WorkFiles::normalize(array $filesField): array` (PHP multi-file `$_FILES['file']` → list of `name,tmp_name,size,error`).
- `WorkFiles::store($db, int $e, string $ownerType, int $ownerId, int $uid, array $files, array $meta = [] /* comment_id,title,description */, bool $fromUpload = true): array` → `['success'=>true,'ids'=>int[]]` | error. All-or-nothing: on any invalid file nothing is kept (moved files unlinked). Path `<root>/<e>/<Y>/<bin2hex(random_bytes(16))>.<ext>`. Errors: `حداکثر ۱۰ فایل در هر بار.`, `نوع فایل «{name}» مجاز نیست.`, `حجم فایل «{name}» بیش از حد مجاز است.`, upload error / empty → `فایلی دریافت نشد؛ شاید حجم آن از سقف سرور ({N} مگابایت) بیشتر است.`.
- `WorkFiles::listFor($db, int $e, string $ownerType, int $ownerId): array`; `get($db, int $e, int $id): ?array`; `deleteRows($db, array $rows): void` (unlinks files); `stream(array $row): void` (Range support; inline only for image/pdf/video/audio, else attachment; `X-Content-Type-Options: nosniff`).

- [ ] Step 1: failing tests — a real 1×1 PNG stored → row with kind image, file exists under `root()` and not under `uploads/`; a PHP file renamed `photo.jpg` refused and no new file under `root()/<e>`; 11 files refused; oversize (fake 26MB sparse file) refused; batch of [good, bad] keeps nothing; `deleteRows` removes the file; `CrmNotifier::notify(..., './index.php#work/x')` stores `link`.
- [ ] Step 2 FAIL → Step 3 implement → Step 4 PASS → Step 5 commit `feat(work): protected file storage`.

### Task 3: Projects and members

**Files:** Create `api/models/WorkProject.php`, `api/controllers/WorkController.php`; Modify `api/index.php`. Test: `tests/work_projects_test.php`.

**Produces (`WorkProject`):**
- `STATUSES = ['planning','active','on_hold','done','cancelled']`, `PRIORITIES = ['low','medium','high','urgent']`.
- `access(int $e, int $uid, bool $seesAll, int $id): ?array` → `['project'=>row,'members'=>rows,'isMember'=>bool,'canManage'=>bool]`; null when not visible.
- `create(int $e, int $uid, bool $canCreate, array $d): array` (d: title, description, status, priority, start_date, due_date, budget, members: [{user_id, role}]) — creator added as manager; members must be team users; only with `$canCreate` (= `manage_projects`) else 403 `ساختن پروژه فقط با دسترسی «مدیریت همه پروژه‌ها».`.
- `update(int $e, int $uid, bool $seesAll, int $id, array $d)`, `delete(...)` (deletes tasks, comments, `work_files` rows+files, unlinks meetings' `project_id`, sets `expenses.project_id` NULL).
- `addMember(e, uid, seesAll, id, int $userId, string $role)`, `setMemberRole(...)`, `removeMember(...)` — min 1 member / 1 manager (`پروژه باید حداقل یک عضو داشته باشد.` / `آخرین مدیر پروژه را نمی‌توان برداشت؛ ابتدا مدیر دیگری تعیین کنید.`); removal unassigns their open tasks; added member notified (`work_project`, link `#work/project/{id}/members`).
- `listFor(int $e, int $uid, bool $seesAll, string $filter): array` (filter active = planning/active/on_hold, mine = member, done = done/cancelled, all) each with `progress {total,done,percent}`, `my_open_tasks`, members (names), overdue flag.
- `progress(array $taskStatuses): array`.
- **Controller** `WorkController` (pattern of `CrmController`): `entityId()`, `userId()`, `seesAll()` (= `manage_projects` in session perms), `guarded()`. Routes (`use_projects`): `work_projects`, `work_project`, `work_save_project`, `work_delete_project`, `work_project_member` (action add|role|remove), `work_people` (team users for pickers).

- [ ] Step 1: failing tests — non-manage user cannot create; manager creates with member B → both members, creator role manager, B notified; B (member) sees it in `mine`, user C does not and `access()` is null for C; removing the only manager refused; removing B unassigns B's open task (insert one directly); `seesAll` sees all; progress 1 of 2 done → 50; delete removes tasks and a stored file from disk; a deactivated team member is refused as new member.
- [ ] Step 2 FAIL → 3 → 4 PASS (+ permissions_test) → 5 commit `feat(work): projects and members`.

### Task 4: Tasks — flow, checklist, prerequisites, order

**Files:** Create `api/models/WorkTask.php`; Modify `WorkController`, `api/index.php`. Test: `tests/work_tasks_test.php`.

**Consumes:** `WorkProject::access`. **Produces (`WorkTask`):**
- `create(e, uid, seesAll, int $projectId, array $d)` (title, description, assignee_user_id, due_date, depends_on: int[]) — any member; assignee must be a member; notify assignee.
- `update(e, uid, seesAll, int $taskId, array $d)` — manager or creator (full edit); omitted fields keep values; reassign notifies new assignee.
- `setStatus(e, uid, seesAll, int $taskId, string $status)` — only `todo|in_progress`; `canMove` rule (spec); starting with open prerequisites → `اول این پیش‌نیازها باید انجام شود: «...»`.
- `complete(e, uid, seesAll, int $taskId, ?string $note)` → status `done` when actor is manager or project has no manager, else `review`; notifications per spec (`منتظر تأیید شما: «title»` to managers / `«title»: انجام شد` to stakeholders).
- `approve(e, uid, seesAll, int $taskId, ?string $note)`, `sendBack(..., ?string $reason)` — managers only, only from `review`.
- `reopen(e, uid, seesAll, int $taskId)` — manager or completer; `done` → `in_progress`, clears completed/review fields.
- `delete(e, uid, seesAll, int $taskId)` — manager or creator; deletes deps both ways and checks.
- `reorder(e, uid, seesAll, int $projectId, array $orderedIds)` — any member; sets `sort_order` 1..n.
- `addCheck(...,$taskId,string $text)`, `toggleCheck(...,int $checkId, bool $done)`, `deleteCheck(...)`, `setDeps(...,int $taskId,array $ids)`.
- `static createsCycle(int $taskId, array $newDeps, array $edges): bool` — port of mz7 `deps.ts` (edges: `[['task_id'=>, 'depends_on_id'=>], ...]`).
- `listForProject(int $e, int $projectId, int $viewerId): array` — tasks with assignee name, checks done/total, deps, `blocked` (open prerequisites), sorted review → in_progress → by due date (nulls last) → newest; `doneLog` grouped by completion day.
- Routes (`use_projects`): `work_task_save`, `work_task_status`, `work_task_complete`, `work_task_approve`, `work_task_return`, `work_task_reopen`, `work_task_delete`, `work_task_order`, `work_task_check` (action add|toggle|delete), `work_task_deps`.

- [ ] Step 1: failing tests — member completes → `review`, managers get `منتظر تأیید شما`; manager approves → `done`, `reviewed_by` set, `completed_by` stays the member; send back → `in_progress` with `review_note`; manager's own complete → `done` directly; member cannot move a task assigned to someone else (403); prerequisites block start and complete with the exact message; `createsCycle` A→B, B→A true / A→B, B→C false; deleting the prerequisite unblocks; checklist toggle records `done_by`; 51st check refused; reorder persists order.
- [ ] Step 2 FAIL → 3 → 4 PASS → 5 commit `feat(work): project tasks with review flow, checklist and prerequisites`.

### Task 5: Reports, documents, budget

**Files:** Modify `WorkProject.php` (comments, documents, budget), `WorkController`, `api/index.php`, `api/models/Expense.php` (save `project_id`), `templates/section_transactions.php` (+ optional «پروژه» select in `#expense-form`), `js/app.js` (expense `prepareModal`/`getFormData` fill/send `project_id`). Test: `tests/work_content_test.php`.

**Produces:**
- `addComment(e, uid, seesAll, int $projectId, string $body, array $files = [], bool $fromUpload = true)` — files stored with `comment_id`; `@[Name](user:ID)` tokens → mentioned members get `work_project` «{author} شما را در گزارش «{project}» نام برد»; all other members «گزارش جدید در «{project}»»; link `#work/project/{id}/comment/{cid}`.
- `editComment(...)` (author only, sets `edited_at`), `deleteComment(..., bool $withFiles)` (author or manager).
- `addDocuments(e, uid, seesAll, int $projectId, array $files, ?string $title, ?string $desc, bool $fromUpload = true)`; `deleteDocument(...)` (uploader or manager).
- `budget(int $e, int $projectId): array` → `['budget'=>?float,'spent'=>float,'remaining'=>?float,'over'=>bool]`; spent = SUM(expenses.amount) where `project_id` matches and same entity.
- `Expense::save` accepts `project_id` (must be a project of the entity, else ignored as NULL); no other expense behaviour changes.
- `work_project` detail returns `budget`, `comments` (with files), `documents`.
- Routes (`use_projects`): `work_comment_save`, `work_comment_delete`, `work_doc_upload`, `work_doc_delete`, `work_file` (GET download/stream; checks the owner's access — project member/seesAll, meeting visibility), `work_project_options` (projects for the expense form: active projects of the entity — permission `manage_transactions`).
- `api/index.php`: `work_file` must pass through the router for GET with the session; it streams and exits (no JSON).

- [ ] Step 1: failing tests — mention notifies exactly the mentioned member with the mention text and others with «گزارش جدید»; comment with a file → file has `comment_id`; non-author non-manager cannot delete comment; delete with files removes them from disk; budget 1,000,000 with two linked expenses 300,000 + 200,000 → spent 500,000, remaining 500,000; expense saved with another entity's project id stores NULL; existing expense tests still pass (`contacts_and_misc_income_test.php`).
- [ ] Step 2 FAIL → 3 → 4 PASS → 5 commit `feat(work): project reports, documents and budget from expenses`.

### Task 6: Meetings and decisions

**Files:** Create `api/models/WorkMeeting.php`; Modify `WorkController`, `api/index.php`. Test: `tests/work_meetings_test.php`.

**Produces (`WorkMeeting`):**
- `create(e, uid, array $d)` (title, date Y-m-d, time H:i, location, agenda, minutes, project_id, attendee_ids) — attendees must be team users; project must be visible to the creator and active (planning/active/on_hold); invite notifications `دعوت به جلسه: {title}` with Jalali date+time; link `#work/meeting/{id}`.
- `update(e, uid, seesAll, id, d)` (creator or seesAll; only new attendees invited; changing time resets `reminded_at`), `delete(...)` (also its `work_files`).
- `list(e, uid, seesAll, string $filter)` — upcoming (starts_at ≥ now − 12h, asc), past (desc), actions (my open decisions whose task is not done).
- `detail(e, uid, seesAll, id)` — meeting, attendees, actions (done = done or task done), files, `can_edit`.
- `addAction(e, uid, seesAll, id, array $d)` (creator/attendee/seesAll; assignee notified), `setActionDone(..., int $actionId, bool $done)` (assignee or creator), `actionToTask(..., int $actionId)` (creator/seesAll; meeting has project; once; assignee kept only if project member; returns `['task_id'=>, 'assigned'=>bool]`), `deleteAction(...)`, `addFiles(...)`.
- Visibility: creator, attendees, seesAll.
- Routes (`use_projects`): `work_meetings`, `work_meeting`, `work_meeting_save`, `work_meeting_delete`, `work_meeting_action` (action add|done|to_task|delete), `work_meeting_files`.

- [ ] Step 1: failing tests — attendee sees meeting, non-attendee does not; invite notifications to attendees except creator; decision → task in the meeting's project with same text and due date; assignee not a project member → task unassigned and `assigned=false`; second conversion refused; decision counts done when its task is done; meeting without project cannot convert; delete removes files.
- [ ] Step 2 FAIL → 3 → 4 PASS → 5 commit `feat(work): meetings with decisions that become project tasks`.

### Task 7: Reminders and «کارهای من»

**Files:** Create `api/models/WorkReminders.php`, `api/models/MyWork.php`; Modify `api/core/Scheduler.php` (`work_task_reminders` 3600s, `work_meeting_reminders` 60s), `api/models/CrmAutomation.php` (digest counts project tasks and today's meetings), `WorkController`, `api/index.php`. Test: `tests/work_reminders_test.php`.

**Produces:**
- `WorkReminders::dailyTasks($db, ?string $now = null): int` — after 08:00 Tehran, once per task per day (`reminded_on`): open (todo/in_progress) tasks with assignee in projects planning/active, due tomorrow / today / overdue ≤7 days; titles `یادآوری کار: فردا موعد انجام است` / `امروز موعد انجام است` / `از موعد آن گذشته است`.
- `WorkReminders::meetings($db, ?string $now = null): int` — meetings starting within the next 30 minutes with `reminded_at` NULL → attendees and creator `جلسه «{title}» ساعت {HH:MM} شروع می‌شود`; stamp first.
- `MyWork::forUser($db, int $e, int $uid): array` → `['awaiting_review'=>[], 'overdue'=>[], 'today'=>[], 'upcoming'=>[], 'meetings_today'=>[]]`; items `['source'=>'project'|'meeting'|'crm','id','title','due','link','context']` from open project tasks assigned to the user, open decisions assigned to the user (not converted to a task), open `crm_tasks`; `awaiting_review` = `review` tasks in projects the user manages.
- Route `work_my` (permission any of `use_projects`, `use_crm`).

- [ ] Step 1: failing tests — task due tomorrow reminded once (second run 0); before 08:00 nothing; review/done tasks skipped; meeting in 20 minutes reminded once, meeting in 2 hours not; `forUser` merges one project task, one decision and one CRM task into the right buckets with correct `source`; `awaiting_review` lists a member's completed task for the manager only.
- [ ] Step 2 FAIL → 3 → 4 PASS; `php cron.php --force` runs clean → 5 commit `feat(work): due reminders, meeting reminders and a single My Work list`.

### Task 8: «میز کار» UI

**Files:** Modify `templates/main_tabs.php` (tab label «میز کار», `data-permission-any="use_crm,use_projects"`), `templates/section_crm.php` (sub-nav: کارهای من · پروژه‌ها · جلسات · مشتریان · تیم; panes and the project/meeting full-screen sheets), `js/crm-manager.js` (my-tasks pane delegates to WorkManager; customers sub-pane holds debtors/leads switcher), `js/app.js` (bell: `n.link` → `data-link`, click sets `location.hash`), `templates/scripts.php`, `style.css`; Create `js/work-manager.js`.

**Produces:** `WorkManager.init()`, `WorkManager.openProject(id, target)`, `WorkManager.openMeeting(id, target)`, `WorkManager.renderMyWork()`; hash routes `#work/project/{id}[/(task|comment|doc)/{id}|/members]`, `#work/meeting/{id}[/action/{id}]`.

Behaviour (spec §رابط کاربری): projects list with filters and cards; project sheet with tabs نمای کلی · کارها · تابلو · انجام‌شده · مستندات · گزارش‌ها; quick add (title + Enter + assignee select); task dialog (UI.formDialog + checklist and prerequisite editors); complete dialog with optional note (text says whether it goes to the manager); approve/return dialogs; board with four lanes, HTML5 drag on desktop and named action buttons on phones (`شروع کردم`، `انجام شد (ارسال برای تأیید مدیر)`، `پس گرفتن درخواست تأیید`، `باز کردن دوباره کار`); done log grouped by day; documents with type filter, search, image preview, video/audio player; reports with `@` picker; meetings list (پیش‌رو / گذشته / مصوبه‌های من) and meeting sheet; «پروژه» select in the expense form; all text escaped with `UI.escapeHtml`.

- [ ] Step 1: implement.
- [ ] Step 2: browser check (built-in browser, `hesabdari.test`, two local test users — a project manager and a member — credentials only in the gitignored workspace): create project with member, quick-add tasks, member completes → manager approves from «کارهای من»/board, prerequisite blocks, checklist, upload image + PDF and open them, report with @mention notifies, meeting with decision → task, expense linked to project shows in budget, deep links from the bell; phone width, desktop, dark mode; no console errors.
- [ ] Step 3: `permissions_test.php` PASS → commit `feat(work): «میز کار» tab with projects, board and meetings`.

### Task 9: Docs, suite, review

- [ ] `DOCUMENTATION.md`: section «تغییرات نسخه 2.4.0 (میز کار: پروژه و جلسه)» incl. deploy notes (`storage/` exclude, nginx rule, cron).
- [ ] Full suite `tests/*_test.php` → 0 failures; remove local test users/data.
- [ ] Commit `docs(work): v2.4.0 notes`; final whole-branch review; no push/deploy without the user.
