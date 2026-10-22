# میز کار — مرحله ۲ و ۳ (پیام‌رسان، کارتابل، پرسنل، ایده‌ها) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Internal messenger (direct/group, files, voice, reactions, refs to customers/projects/tasks/meetings) and the office cartable (payment requests that book real expenses, letters with referral), staff files and ideas, inside «میز کار».

**Architecture:** Same pattern as stage 1: tables in `api/core/schema.php`; models `WorkChat` (conversations + messages), `WorkRequest`, `HrPersonnel`, `WorkIdea` in `api/models/`; controllers `ChatController`, `OfficeController`; notifications via `CrmNotifier`; files via `WorkFiles`; UI in `js/work-chat.js` and `js/work-office.js` mounted as panes of the «میز کار» tab.

**Tech Stack:** PHP 8.3 / MySQL / jQuery + Bootstrap 5 RTL; MediaRecorder for voice notes; polling for chat sync.

**Spec:** `docs/superpowers/specs/2026-10-18-workspace-stage2-3-design.md`

## Global Constraints
- Branch `workspace-stage2-3` (from `audit-fixes`). `APP_VERSION` → `2.5.0`. No libraries.
- Models take `($e, $uid, ...)` explicitly; return `['success'=>true,...]` or `['error'=>…, 'statusCode'=>4xx]`; read `insert_id` before `log_activity`; tests run with a session and `user_id => 1`.
- People = `TeamMember::userIdsForEntity($db, $e)`. Everything scoped by `entity_id`.
- Files only through `WorkFiles` (owner types `chat`, `request`); downloads through `work_file` (extend its access check).
- Limits: message ≤4000, group title ≤100, description ≤500, ≤10 files per message, page 50; request title ≤200, body ≤5000, note ≤2000; personnel note ≤2000; idea title ≤200, body ≤5000, comment ≤2000.
- Reactions allowed: `👍 ❤️ 😂 😮 😢 🙏 👏 ✅`.
- Payment categories (key → label): ADVANCE مساعده, SALARY حقوق و پاداش, PURCHASE خرید کالا و ملزومات, REPAIR تعمیرات و خدمات, PETTY_CASH شارژ تنخواه, TRANSPORT حمل‌ونقل و کرایه, SUPPLIER پرداخت به تأمین‌کننده, OTHER سایر.
- HR record kinds (key → label, money): warning تذکر شفاهی, notice اخطار کتبی, fine جریمه ✓, advance مساعده ✓, bonus پاداش ✓, commendation تشویق, other سایر.
- New permissions (key → label → granted like): `use_messenger` پیام‌رسان داخلی → use_projects; `use_cartable` کارتابل (درخواست و نامه) → use_projects; `approve_payments` تأیید درخواست پرداخت → manage_settings; `pay_payments` پرداخت درخواست‌ها → manage_settings; `manage_hr` پرونده پرسنلی → manage_settings; `use_ideas` ایده‌ها → use_projects.
- Every route in `$permission_map`; `tests/permissions_test.php` stays green; CLI tests in `tests/`.

## Review Focus
1. A user removed from a group (or deactivated in the team) can no longer read its messages or download its files. (Task 2/3 test.)
2. Paying the same request twice (double click / two payers) books one expense only. (Task 4 test.)
3. Expense booking fails (closed period, bad account) → request stays approved, no hr record. (Task 4 test.)
4. Polling with a `since` cursor returns edits, deletions and reactions, not only new messages. (Task 3 test.)
5. Anonymous idea: author id never leaves the server for other viewers (list and detail). (Task 6 test.)

---

### Task 1: Schema, permissions, version
**Files:** `api/core/schema.php`, `api/core/constants.php`, `js/access-control-manager.js`; Test `tests/workspace2_schema_test.php`.
Tables per spec §داده (`chat_conversations` UNIQUE(entity_id, direct_key); `chat_members` PK; `chat_messages` KEY(conversation_id, id), KEY(conversation_id, updated_at); `chat_reactions` PK; `work_requests` UNIQUE(entity_id, number); `work_request_events`; `hr_personnel`; `hr_records` UNIQUE(request_id); `ideas` UNIQUE(project_id); `idea_comments`; `idea_votes` PK) + `work_files.is_voice tinyint(1) NOT NULL DEFAULT 0`; six permissions with grants.
- [ ] failing test (tables, column, permissions, version) → implement → PASS (+permissions_test) → commit `feat(work2): schema, permissions (v2.5.0)`.

### Task 2: Conversations and members
**Files:** Create `api/models/WorkChat.php`, `api/controllers/ChatController.php`; Modify `api/index.php`. Test `tests/chat_conversations_test.php`.
**Produces (`WorkChat`):** `membership(int $e, int $uid, int $cid): ?array` (conversation + my member row; null if not member or not active team user); `openDirect(e, uid, int $otherUid): array{success,id}` (one per pair; self → `با خودتان نمی‌توانید گفتگو کنید.`); `createGroup(e, uid, string $title, ?string $desc, array $memberIds)`; `updateGroup(e, uid, cid, array $d)` (admin); `addMembers(e, uid, cid, array $ids)`, `removeMember(e, uid, cid, int $userId)` (admin; not self → `برای خروج از گروه از گزینه «ترک گروه» استفاده کنید.`), `setAdmin(e, uid, cid, int $userId, bool $admin)` (≥1 admin → `گروه باید حداقل یک مدیر داشته باشد.`), `leave(e, uid, cid)` (last admin → oldest member promoted; empty group deleted with its messages/files); `listFor(e, uid): array` (each: id, type, title (direct: other person's name), last message preview, last_message_at, unread, muted, pinned, other_user_id); `setMuted`, `setPinned`; `postSystem($cid, $uid, string $body)`; `static directKey(int $a, int $b): string`.
Routes (`use_messenger`): `chat_list`, `chat_open_direct`, `chat_create_group`, `chat_update_group`, `chat_members` (op add|remove|admin), `chat_leave`, `chat_prefs` (muted/pinned), `chat_people`.
- [ ] tests: direct twice → same id; self refused; group creator admin, members added with system message «… گروه را ساخت»; non-admin cannot add; last admin cannot be demoted; leave by last admin promotes oldest; leave by everyone deletes group; deactivated team member gets null membership; list shows unread and other person's name → implement → PASS → commit.

### Task 3: Messages
**Files:** Modify `WorkChat.php` (messages part), `ChatController.php`, `api/index.php`, `api/core/CrmNotifier.php` (`push(..., payload with tag)` passes through), `sw.js` (`tag` → `showNotification` option, `renotify`), `api/controllers/WorkController.php` (`file()` allows owner `chat` for members). Test `tests/chat_messages_test.php`.
**Produces:** `send(e, uid, cid, array $d, array $files = [], bool $fromUpload = true)` (d: body, reply_to_id, ref_type, ref_id, voice flag; ref visible to sender: customer via CrmPerson::exists, project/task via WorkProject::access, meeting via WorkMeeting::visible; ref_title snapshot; updates last_message_at; push to unmuted other members + mentioned; returns message); `messages(e, uid, cid, array $q)` (q: before_id | since (updated_at cursor) | around_id | search; returns `['messages'=>[], 'cursor'=>string, 'pinned'=>?msg]`); `edit(e, uid, int $mid, string $body)`; `delete(e, uid, int $mid)`; `react(e, uid, int $mid, string $emoji)`; `pin(e, uid, cid, ?int $mid)`; `markRead(e, uid, cid)`; `forward(e, uid, int $mid, int $toCid)`; `unreadTotal(e, uid): array{total, chats}`; `serialize(row, viewer)` (deleted → body/attachments/reactions empty).
Routes (`use_messenger`): `chat_messages`, `chat_send`, `chat_edit`, `chat_delete`, `chat_react`, `chat_pin`, `chat_read`, `chat_forward`, `chat_unread`.
- [ ] tests: send text + file; non-member cannot read/send/download; since-cursor returns an edited and a deleted message and a reaction change; edit others refused; group admin deletes others' message, files removed from disk; reaction toggle; unread counts other people's messages after last read and excludes muted in total; forward copies file physically and sets forwarded_from; ref to a project the sender cannot see refused; reply snippet → implement → PASS → commit.

### Task 4: Cartable (requests)
**Files:** Create `api/models/WorkRequest.php`, `api/controllers/OfficeController.php`; Modify `api/index.php`, `WorkController::file()` (owner `request`). Test `tests/work_requests_test.php`.
**Produces (`WorkRequest`):** `CATEGORIES`; `static allowedActions(array $req, int $uid, array $perms): array` (port of mz7 flow; perms keys approve, pay, all); `create(e, uid, array $d, array $files = [], bool $fromUpload = true)` (payment: amount > 0, category; letter: assignee required; number = max+1 per entity in a transaction); `listFor(e, uid, array $perms, string $tab, ?string $kind)` (tabs: mine_action | mine | all); `detail(e, uid, perms, id)` (request, events with names, files, allowed actions); `act(e, uid, perms, id, string $action, array $d)` (note rules; refer: to_user_id; pay: account_id, category (expense label), date → `Expense::save` with description `درخواست پرداخت #N: title`, project_id, person supplier if any → `expense_id`; row lock `SELECT … FOR UPDATE` and status check so it books once; advance/bonus/salary with personnel → `hr_records` row with request_id); notifications per spec; `addFiles`.
Routes (`use_cartable`): `office_requests`, `office_request`, `office_request_save`, `office_request_act`, `office_request_files`, `office_pay_options` (accounts + expense categories; `pay_payments`).
- [ ] tests: number sequential; approver sees all payments, plain user only own; approve/reject/pay/cancel/reopen transitions with allowed actions; pay books exactly one expense with project and amount; second pay refused; pay with closed-period date (or invalid account) leaves request approved and no record; advance with personnel creates hr record once; letter refer changes holder and notifies, done needs note, referred user can see it; notifications to approvers on create → implement → PASS → commit.

### Task 5: Staff files (HR)
**Files:** Create `api/models/HrPersonnel.php`; Modify `OfficeController.php`, `api/index.php`. Test `tests/hr_personnel_test.php`.
**Produces:** `KINDS`; `list(e, ?string $q, bool $inactive)`; `save(e, uid, array $d)`; `detail(e, id)` (person, records, summary per kind); `addRecord(e, uid, id, array $d)` (amount only for money kinds; note required); `deleteRecord(e, uid, recordId)` (creator only; not when request_id set); `importTeam(e, uid): int`; `options(e)` (for payment form).
Routes (`manage_hr`): `hr_list`, `hr_save`, `hr_detail`, `hr_record` (op add|delete), `hr_import_team`; `hr_options` (`use_cartable`).
- [ ] tests: create, record with amount only for money kinds, summary totals, delete rules, import creates one per active team member and is idempotent → implement → PASS → commit.

### Task 6: Ideas
**Files:** Create `api/models/WorkIdea.php`; Modify `OfficeController.php`, `api/index.php`. Test `tests/work_ideas_test.php`.
**Produces:** `create/update/delete(e, uid, …)` (author while pending), `listFor(e, uid, bool $canDecide)`, `detail(e, uid, canDecide, id)`, `comment(e, uid, id, string $body)`, `vote(e, uid, id, bool $agree)` (toggle), `decide(e, uid, canDecide, id, string $decision /* approve|reject */, array $d)` (approve → `WorkProject::create(e, uid, true, …)` with author + member_ids, sets project_id; reject needs note); `serialize(row, viewerId)` hides `author_user_id`/name when anonymous and viewer ≠ author.
Routes (`use_ideas`): `ideas_list`, `idea_detail`, `idea_save`, `idea_delete`, `idea_comment`, `idea_vote`, `idea_decide` (`manage_projects` checked inside).
- [ ] tests: anonymous author hidden in list and detail for others, shown to author; vote toggle and counts; edit after decision refused; approve creates project with author member and links it; reject without note refused; deciders notified on create → implement → PASS → commit.

### Task 7: «کارهای من», badges, cleanup hooks
**Files:** Modify `api/models/MyWork.php` (bucket `cartable`: payments to approve/pay, letters I hold), `api/models/CrmAutomation.php` digest counts cartable, `api/models/WorkProject.php::delete` (requests keep project NULL), `api/controllers/WorkController.php::my` (+ chat unread). Test `tests/work2_mywork_test.php`.
- [ ] tests: approver sees pending payment in cartable bucket; payer sees approved one; letter holder sees it; digest mentions requests → implement → PASS → commit.

### Task 8: UI
**Files:** Create `js/work-chat.js`, `js/work-office.js`; Modify `templates/section_crm.php` (panes chat, cartable, ideas, hr + sheets), `templates/scripts.php`, `templates/main_tabs.php` (unread badge on tab, permission-any list), `js/crm-manager.js` (`showPane` delegates new panes), `js/work-manager.js` (cartable bucket in My Work; «ارسال در گفتگو» on task/meeting), `js/crm-manager.js` («ارسال در گفتگو» in customer file), `style.css`.
Behaviour per spec §رابط کاربری; chat polling stops when the tab is hidden; voice via MediaRecorder (hold-to-record on touch, click to start/stop on desktop) with fallback message when unsupported/insecure.
- [ ] implement → browser check with two users (manager/member) at phone and desktop widths and dark mode → commit.

### Task 9: Docs, suite, review
- [ ] `DOCUMENTATION.md` «تغییرات نسخه 2.5.0»; full `tests/*_test.php` green; remove local test data; self-review; commit.
