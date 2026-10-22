<?php
// /api/controllers/BankImportController.php

require_once __DIR__ . '/../models/BankImport.php';

class BankImportController {
    private $conn;
    private $model;

    public function __construct($db) {
        $this->conn = $db;
        $this->model = new BankImport($db);
    }

    private function sqlNullString($value, $nullIfEmpty = true) {
        if ($value === null) return 'NULL';
        $value = (string)$value;
        if ($nullIfEmpty && $value === '') return 'NULL';
        return "'" . $this->conn->real_escape_string($value) . "'";
    }

    public function importStatement($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            send_json(['error' => 'نشست نامعتبر است.'], 401);
            return;
        }

        $account_id = intval($data['account_id'] ?? 0);
        $rows = $data['rows'] ?? [];
        $bankFormat = $data['bank_format'] ?? 'generic';
        if (!in_array($bankFormat, ['mellat', 'dey', 'melli', 'blubank', 'generic'], true)) {
            $bankFormat = 'generic';
        }

        if (!$account_id) {
            send_json(['error' => 'شناسه حساب الزامی است.'], 400);
            return;
        }
        if (empty($rows) || !is_array($rows)) {
            send_json(['error' => 'داده‌ای برای ورود وجود ندارد.'], 400);
            return;
        }

        $stmt = $this->conn->prepare("SELECT id FROM accounts WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $account_id, $entity_id);
        $stmt->execute();
        if (empty(db_stmt_to_assoc_array($stmt))) {
            send_json(['error' => 'حساب یافت نشد.'], 404);
            return;
        }

        $fileName = $data['file_name'] ?? ('bank_import_' . date('Y-m-d_H-i'));
        $periodStart = $data['period_start'] ?? null;
        $periodEnd = $data['period_end'] ?? null;
        $openingBalance = isset($data['opening_balance']) ? floatval($data['opening_balance']) : null;
        $closingBalance = isset($data['closing_balance']) ? floatval($data['closing_balance']) : null;
        $creditTotal = floatval($data['credit_total'] ?? 0);
        $debitTotal = floatval($data['debit_total'] ?? 0);
        $autoMatch = !isset($data['auto_match']) || $data['auto_match'];
        $dateWindow = intval($data['date_window'] ?? 2);
        if ($dateWindow < 0) $dateWindow = 0;
        if ($dateWindow > 30) $dateWindow = 30;

        // Normalize & compute totals if missing
        $normalized = [];
        $computedCredit = 0;
        $computedDebit = 0;
        $minDate = null;
        $maxDate = null;
        foreach ($rows as $row) {
            $txDate = $row['date'] ?? null;
            if (!$txDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txDate)) {
                continue;
            }
            $amount = floatval(str_replace(',', '', (string)($row['amount'] ?? 0)));
            if (abs($amount) < 0.00001) continue;

            if ($amount > 0) $computedCredit += $amount;
            else $computedDebit += abs($amount);

            if (!$minDate || $txDate < $minDate) $minDate = $txDate;
            if (!$maxDate || $txDate > $maxDate) $maxDate = $txDate;

            $normalized[] = [
                'date' => $txDate,
                'time' => $row['time'] ?? null,
                'amount' => $amount,
                'balance' => isset($row['balance']) && $row['balance'] !== '' && $row['balance'] !== null ? floatval($row['balance']) : null,
                'description' => $row['description'] ?? '',
                'counterparty' => $row['counterparty'] ?? '',
                'reference' => $row['reference'] ?? null,
                'document_number' => $row['document_number'] ?? null,
                'tracking_number' => $row['tracking_number'] ?? null,
                'deposit_id' => $row['deposit_id'] ?? null,
                'branch' => $row['branch'] ?? null,
                'note' => $row['note'] ?? null,
            ];
        }

        if (empty($normalized)) {
            send_json(['error' => 'هیچ ردیف معتبری برای ورود یافت نشد.'], 400);
            return;
        }

        if (!$periodStart) $periodStart = $minDate;
        if (!$periodEnd) $periodEnd = $maxDate;
        if ($creditTotal <= 0) $creditTotal = $computedCredit;
        if ($debitTotal <= 0) $debitTotal = $computedDebit;

        $totalRows = count($normalized);

        $this->conn->begin_transaction();
        try {
            $fn = $this->conn->real_escape_string($fileName);
            $bf = $this->conn->real_escape_string($bankFormat);
            $ps = $this->conn->real_escape_string($periodStart);
            $pe = $this->conn->real_escape_string($periodEnd);
            $openLit = $openingBalance === null ? 'NULL' : floatval($openingBalance);
            $closeLit = $closingBalance === null ? 'NULL' : floatval($closingBalance);

            $ok = $this->conn->query("
                INSERT INTO bank_imports
                (entity_id, account_id, file_name, total_rows, matched_rows, bank_format, period_start, period_end,
                 opening_balance, closing_balance, credit_total, debit_total, status)
                VALUES (
                    " . intval($entity_id) . ",
                    " . intval($account_id) . ",
                    '{$fn}',
                    " . intval($totalRows) . ",
                    0,
                    '{$bf}',
                    '{$ps}',
                    '{$pe}',
                    {$openLit},
                    {$closeLit},
                    " . floatval($creditTotal) . ",
                    " . floatval($debitTotal) . ",
                    'in_progress'
                )
            ");
            if (!$ok) {
                throw new Exception('خطا در ثبت هدر ورود: ' . $this->conn->error);
            }
            $importId = intval($this->conn->insert_id);

            foreach ($normalized as $row) {
                $sql = sprintf(
                    "INSERT INTO bank_import_items
                    (import_id, transaction_date, transaction_time, amount, balance_after, description, counterparty,
                     reference_number, document_number, tracking_number, deposit_id, branch, note)
                    VALUES (%d, '%s', %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
                    $importId,
                    $this->conn->real_escape_string($row['date']),
                    $this->sqlNullString($row['time'] ?? null),
                    floatval($row['amount']),
                    $row['balance'] === null ? 'NULL' : floatval($row['balance']),
                    $this->sqlNullString($row['description'] ?? '', false),
                    $this->sqlNullString($row['counterparty'] ?? '', false),
                    $this->sqlNullString($row['reference'] ?? null),
                    $this->sqlNullString($row['document_number'] ?? null),
                    $this->sqlNullString($row['tracking_number'] ?? null),
                    $this->sqlNullString($row['deposit_id'] ?? null),
                    $this->sqlNullString($row['branch'] ?? null),
                    $this->sqlNullString($row['note'] ?? null)
                );
                if (!$this->conn->query($sql)) {
                    throw new Exception('خطا در ثبت ردیف: ' . $this->conn->error);
                }
            }

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => $e->getMessage()], 500);
            return;
        }

        $this->model->backfillMatchHistoryForAccount($account_id, $entity_id);
        $retiredImports = $this->model->retireOverlappingImports(
            $account_id,
            $entity_id,
            $periodStart,
            $periodEnd,
            $importId
        );

        $proposals = [];
        $priorCount = 0;
        if ($autoMatch) {
            $proposals = $this->collectProposals($importId, $account_id, $entity_id, $dateWindow);
            $priorCount = count(array_filter($proposals, function ($p) {
                return !empty($p['prior_match']);
            }));
        }

        $msg = "{$totalRows} تراکنش وارد شد";
        if ($retiredImports > 0) {
            $msg .= "؛ {$retiredImports} ورود قبلی هم‌پوشان بایگانی و حذف شد";
        }
        if (count($proposals)) {
            $msg .= "؛ " . count($proposals) . " پیشنهاد هوشمند آماده تأیید است";
            if ($priorCount) {
                $msg .= " ({$priorCount} مورد از تطبیق قبلی)";
            }
            $msg .= '.';
        } else {
            $msg .= '.';
        }

        log_activity($this->conn, 'IMPORT_BANK', "صورتحساب بانکی وارد شد ({$totalRows} تراکنش، قالب {$bankFormat}).");
        send_json([
            'success' => true,
            'import_id' => $importId,
            'total_rows' => $totalRows,
            'proposed_count' => count($proposals),
            'prior_match_count' => $priorCount,
            'proposals' => $proposals,
            'auto_matched' => 0,
            'message' => $msg,
        ]);
    }

    private function collectProposals($importId, $accountId, $entityId, $dateWindow) {
        $items = $this->model->getItems($importId, 'unmatched');
        if (empty($items)) return [];

        $this->model->backfillMatchHistoryForAccount($accountId, $entityId);

        $dates = array_column($items, 'transaction_date');
        $minD = min($dates);
        $maxD = max($dates);
        $from = date('Y-m-d', strtotime($minD . " -{$dateWindow} days"));
        $to = date('Y-m-d', strtotime($maxD . " +{$dateWindow} days"));

        $candidates = $this->model->getLedgerCandidates($accountId, $entityId, $from, $to);
        $used = $this->model->getAlreadyMatchedKeys($importId);

        $prior = $this->model->buildPriorMatchProposals($items, $accountId, $entityId, $used);
        $priorItemIds = array_flip(array_column($prior, 'item_id'));
        $remainingItems = array_values(array_filter($items, function ($i) use ($priorItemIds) {
            return !isset($priorItemIds[intval($i['id'])]);
        }));

        $usedAfterPrior = $used;
        foreach ($prior as $p) {
            $usedAfterPrior[$p['source'] . ':' . $p['ref_id']] = true;
        }

        $smart = $this->model->buildAutoMatchProposals($remainingItems, $candidates, $usedAfterPrior, $dateWindow, $entityId);
        return array_merge($prior, $smart);
    }

    /** Smart proposals only — never auto-applies. */
    public function proposeBankMatches($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $importId = intval($data['import_id'] ?? 0);
        $dateWindow = intval($data['date_window'] ?? 2);
        if ($dateWindow < 0) $dateWindow = 0;
        if ($dateWindow > 30) $dateWindow = 30;
        if (!$importId) {
            send_json(['error' => 'شناسه ورود الزامی است.'], 400);
            return;
        }
        $import = $this->model->getImportForEntity($importId, $entity_id);
        if (!$import) {
            send_json(['error' => 'یافت نشد.'], 404);
            return;
        }
        $proposals = $this->collectProposals($importId, intval($import['account_id']), $entity_id, $dateWindow);
        send_json([
            'success' => true,
            'import_id' => $importId,
            'proposed_count' => count($proposals),
            'proposals' => $proposals,
        ]);
    }

    /** Apply user-confirmed matches in batch. */
    public function confirmBankMatches($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $importId = intval($data['import_id'] ?? 0);
        $matches = $data['matches'] ?? [];
        if (!$importId || !is_array($matches) || empty($matches)) {
            send_json(['error' => 'لیست تطبیق برای تأیید خالی است.'], 400);
            return;
        }
        $import = $this->model->getImportForEntity($importId, $entity_id);
        if (!$import) {
            send_json(['error' => 'یافت نشد.'], 404);
            return;
        }

        $used = $this->model->getAlreadyMatchedKeys($importId);
        $applied = 0;
        $feeCreated = 0;
        $enriched = 0;
        $skipped = 0;
        $errors = [];

        foreach ($matches as $m) {
            $itemId = intval($m['item_id'] ?? $m['id'] ?? 0);
            $source = $m['source'] ?? '';
            $refId = intval($m['ref_id'] ?? 0);
            // پیش‌فرض: اگر اختلاف کارمزد باشد ثبت شود؛ فقط با false صریح غیرفعال می‌شود
            $createFee = true;
            if (array_key_exists('create_fee_expense', $m)) {
                $v = $m['create_fee_expense'];
                $createFee = !($v === false || $v === 0 || $v === '0' || $v === 'false' || $v === '');
            } elseif (array_key_exists('adjust_amount', $m)) {
                $createFee = !empty($m['adjust_amount']);
            }
            if (!$itemId || !$refId || !in_array($source, ['payment', 'expense', 'income', 'check'], true)) {
                $skipped++;
                continue;
            }
            $item = $this->model->getItemOwned($itemId, $entity_id);
            if (!$item || intval($item['import_id']) !== $importId) {
                $skipped++;
                $errors[] = "آیتم {$itemId} نامعتبر";
                continue;
            }
            if (($item['match_status'] ?? '') === 'matched') {
                $skipped++;
                continue;
            }
            $key = $source . ':' . $refId;
            if (isset($used[$key])) {
                $skipped++;
                $errors[] = "دفتر {$key} قبلاً تطبیق شده";
                continue;
            }

            // Apply the match BEFORE creating the fee expense: both enrich and
            // fee run their own inner transactions, so they can't join one outer
            // transaction. If a later step fails, re-running skips already
            // matched items instead of creating duplicate fees.
            try {
                $this->model->applyMatch($itemId, $source, $refId, $importId);
                $used[$key] = true;
                $applied++;
            } catch (Exception $e) {
                $skipped++;
                $errors[] = "آیتم {$itemId}: تطبیق — " . $e->getMessage();
                continue;
            }

            $enrich = $this->model->enrichLedgerFromBank($source, $refId, $item, $entity_id, false);
            if (!empty($enrich['error'])) {
                $errors[] = "آیتم {$itemId}: شرح دفتر — " . $enrich['error'];
            } elseif (!empty($enrich['description_changed'])) {
                $enriched++;
            }

            if ($createFee) {
                $plan = $this->model->buildFeeExpensePlan($source, $refId, $item, $entity_id);
                if ($plan) {
                    $feeRes = $this->model->createBankFeeExpense($plan, $entity_id);
                    if (!empty($feeRes['error'])) {
                        $errors[] = "آیتم {$itemId}: کارمزد — " . $feeRes['error'];
                    } else {
                        $feeCreated++;
                    }
                }
            }
        }

        $msg = "{$applied} تطبیق تأیید و ثبت شد";
        if ($enriched) $msg .= "؛ شرح {$enriched} تراکنش با جزئیات بانک به‌روز شد";
        if ($feeCreated) $msg .= "؛ {$feeCreated} هزینه کارمزد جداگانه ثبت شد";
        if ($skipped) $msg .= "؛ {$skipped} مورد رد شد.";
        else $msg .= '.';

        send_json([
            'success' => true,
            'applied' => $applied,
            'fee_created' => $feeCreated,
            'enriched' => $enriched,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => $msg,
        ]);
    }

    public function getImports($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $importId = intval($data['import_id'] ?? 0);

        if ($importId) {
            $this->getImportDetail(['import_id' => $importId, 'include_suggestions' => !empty($data['include_suggestions'])]);
            return;
        }

        $items = $this->model->listImports($entity_id);
        send_json(['items' => $items]);
    }

    public function getImportDetail($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $importId = intval($data['import_id'] ?? 0);
        if (!$importId) {
            send_json(['error' => 'شناسه ورود الزامی است.'], 400);
            return;
        }

        $import = $this->model->getImportForEntity($importId, $entity_id);
        if (!$import) {
            send_json(['error' => 'یافت نشد.'], 404);
            return;
        }

        $statusFilter = $data['status'] ?? null;
        $items = $this->model->getItems($importId, $statusFilter);

        $dateWindow = intval($data['date_window'] ?? 2);
        $includeSuggestions = !isset($data['include_suggestions']) || $data['include_suggestions'];

        $suggestionsMap = [];
        if ($includeSuggestions) {
            $unmatched = array_filter($items, function ($i) {
                return ($i['match_status'] ?? '') === 'unmatched';
            });
            if (!empty($unmatched)) {
                $this->model->backfillMatchHistoryForAccount(intval($import['account_id']), $entity_id);
                $dates = array_column($unmatched, 'transaction_date');
                $from = date('Y-m-d', strtotime(min($dates) . " -{$dateWindow} days"));
                $to = date('Y-m-d', strtotime(max($dates) . " +{$dateWindow} days"));
                $candidates = $this->model->getLedgerCandidates(intval($import['account_id']), $entity_id, $from, $to);
                $used = $this->model->getAlreadyMatchedKeys($importId);
                foreach ($unmatched as $item) {
                    $prior = $this->model->resolvePriorMatchForItem(
                        $item,
                        intval($import['account_id']),
                        $entity_id,
                        $used
                    );
                    if ($prior) {
                        $needsFee = !empty($prior['needs_fee_expense']);
                        $prior['change_preview'] = $this->model->buildChangePreviewBundle(
                            $prior['source'],
                            intval($prior['ref_id']),
                            $item,
                            $entity_id,
                            $needsFee ? floatval($prior['fee'] ?? 0) : 0
                        );
                    }
                    $sugs = $this->model->suggestForItem($item, $candidates, $used, $dateWindow);
                    if ($prior) {
                        $priorKey = $prior['source'] . ':' . $prior['ref_id'];
                        $sugs = array_values(array_filter($sugs, function ($s) use ($priorKey) {
                            return ($s['source'] . ':' . $s['ref_id']) !== $priorKey;
                        }));
                        array_unshift($sugs, $prior);
                    }
                    $suggestionsMap[intval($item['id'])] = $this->model->attachChangePreviewsToSuggestions(
                        $sugs,
                        $item,
                        $entity_id
                    );
                }
            }
        }

        foreach ($items as &$item) {
            $item['suggestions'] = $suggestionsMap[intval($item['id'])] ?? [];
        }
        unset($item);

        $unmatchedCount = 0;
        $matchedCount = 0;
        $ignoredCount = 0;
        $unmatchedCredit = 0;
        $unmatchedDebit = 0;
        foreach ($items as $it) {
            if ($it['match_status'] === 'matched') $matchedCount++;
            elseif ($it['match_status'] === 'ignored') $ignoredCount++;
            else {
                $unmatchedCount++;
                if (floatval($it['amount']) > 0) $unmatchedCredit += floatval($it['amount']);
                else $unmatchedDebit += abs(floatval($it['amount']));
            }
        }

        // If filtered, recount from full set for summary
        if ($statusFilter) {
            $all = $this->model->getItems($importId, null);
            $unmatchedCount = $matchedCount = $ignoredCount = 0;
            $unmatchedCredit = $unmatchedDebit = 0;
            foreach ($all as $it) {
                if ($it['match_status'] === 'matched') $matchedCount++;
                elseif ($it['match_status'] === 'ignored') $ignoredCount++;
                else {
                    $unmatchedCount++;
                    if (floatval($it['amount']) > 0) $unmatchedCredit += floatval($it['amount']);
                    else $unmatchedDebit += abs(floatval($it['amount']));
                }
            }
        }

        $reconciliation = $this->model->buildReconciliationSummary($import, $entity_id);

        send_json([
            'import' => $import,
            'items' => $items,
            'summary' => array_merge([
                'total' => intval($import['total_rows']),
                'matched' => $matchedCount,
                'unmatched' => $unmatchedCount,
                'ignored' => $ignoredCount,
                'unmatched_credit' => $unmatchedCredit,
                'unmatched_debit' => $unmatchedDebit,
                'account_balance' => floatval($import['account_balance'] ?? 0),
                'statement_closing_balance' => isset($import['closing_balance']) ? floatval($import['closing_balance']) : null,
                'progress_pct' => intval($import['total_rows']) > 0
                    ? round((($matchedCount + $ignoredCount) / intval($import['total_rows'])) * 100)
                    : 0,
            ], $reconciliation),
        ]);
    }

    public function suggestBankMatches($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $importId = intval($data['import_id'] ?? 0);
        $itemId = intval($data['item_id'] ?? 0);
        $dateWindow = intval($data['date_window'] ?? 2);

        if ($itemId) {
            $item = $this->model->getItemOwned($itemId, $entity_id);
            if (!$item) {
                send_json(['error' => 'آیتم یافت نشد.'], 404);
                return;
            }
            $from = date('Y-m-d', strtotime($item['transaction_date'] . " -{$dateWindow} days"));
            $to = date('Y-m-d', strtotime($item['transaction_date'] . " +{$dateWindow} days"));
            $candidates = $this->model->getLedgerCandidates(intval($item['account_id']), $entity_id, $from, $to);
            $used = $this->model->getAlreadyMatchedKeys(intval($item['import_id']));
            $sugs = $this->model->suggestForItem($item, $candidates, $used, $dateWindow);
            send_json([
                'suggestions' => $this->model->attachChangePreviewsToSuggestions($sugs, $item, $entity_id),
            ]);
            return;
        }

        if (!$importId) {
            send_json(['error' => 'شناسه الزامی است.'], 400);
            return;
        }
        $this->getImportDetail([
            'import_id' => $importId,
            'include_suggestions' => true,
            'date_window' => $dateWindow,
            'status' => 'unmatched',
        ]);
    }

    public function matchItem($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $id = intval($data['id'] ?? $data['item_id'] ?? 0);
        $source = $data['matched_source'] ?? $data['source'] ?? 'payment';
        $refId = intval($data['matched_ref_id'] ?? $data['ref_id'] ?? $data['payment_id'] ?? 0);

        if (!$id || !$refId || !in_array($source, ['payment', 'expense', 'income', 'check'], true)) {
            send_json(['error' => 'اطلاعات نامعتبر.'], 400);
            return;
        }

        $item = $this->model->getItemOwned($id, $entity_id);
        if (!$item) {
            send_json(['error' => 'آیتم یافت نشد.'], 404);
            return;
        }
        if ($item['match_status'] === 'matched') {
            send_json(['error' => 'این ردیف قبلاً تطبیق شده است.'], 400);
            return;
        }

        // Ensure ref not already matched in this import
        $used = $this->model->getAlreadyMatchedKeys(intval($item['import_id']));
        if (isset($used[$source . ':' . $refId])) {
            send_json(['error' => 'این تراکنش دفتر قبلاً به ردیف دیگری وصل شده است.'], 400);
            return;
        }

        // پیش‌فرض: کارمزد در صورت وجود اختلاف ثبت شود؛ فقط با false صریح رد می‌شود
        $createFee = true;
        if (array_key_exists('create_fee_expense', $data)) {
            $v = $data['create_fee_expense'];
            $createFee = !($v === false || $v === 0 || $v === '0' || $v === 'false' || $v === '');
        } elseif (array_key_exists('adjust_amount', $data)) {
            $createFee = !empty($data['adjust_amount']);
        }
        $enrich = $this->model->enrichLedgerFromBank($source, $refId, $item, $entity_id, false);
        if (!empty($enrich['error'])) {
            send_json(['error' => 'تطبیق انجام نشد؛ به‌روزرسانی تراکنش: ' . $enrich['error']], 400);
            return;
        }

        $feeCreated = false;
        $feeAmount = 0;
        if ($createFee) {
            $plan = $this->model->buildFeeExpensePlan($source, $refId, $item, $entity_id);
            if ($plan) {
                $feeRes = $this->model->createBankFeeExpense($plan, $entity_id);
                if (!empty($feeRes['error'])) {
                    send_json(['error' => 'تطبیق انجام نشد؛ ثبت کارمزد: ' . $feeRes['error']], 400);
                    return;
                }
                $feeCreated = true;
                $feeAmount = floatval($feeRes['amount'] ?? $plan['amount']);
            }
        }

        $this->model->applyMatch($id, $source, $refId, intval($item['import_id']));
        $descEnriched = !empty($enrich['description_changed']);
        $parts = ['تطبیق شد'];
        if ($descEnriched) $parts[] = 'شرح با جزئیات بانک به‌روز شد';
        if ($feeCreated) $parts[] = 'هزینه کارمزد ' . number_format($feeAmount, 0, '.', ',') . ' ثبت شد';
        send_json([
            'success' => true,
            'fee_created' => $feeCreated,
            'fee_amount' => $feeAmount,
            'description_enriched' => $descEnriched,
            'before' => $enrich['before'] ?? null,
            'after' => $enrich['after'] ?? null,
            'message' => implode('؛ ', $parts) . '.',
        ]);
    }

    public function unmatchItem($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $id = intval($data['id'] ?? $data['item_id'] ?? 0);
        if (!$id) {
            send_json(['error' => 'شناسه نامعتبر.'], 400);
            return;
        }
        $item = $this->model->getItemOwned($id, $entity_id);
        if (!$item) {
            send_json(['error' => 'آیتم یافت نشد.'], 404);
            return;
        }
        $this->model->clearMatch($id, intval($item['import_id']), 'unmatched');
        send_json(['success' => true]);
    }

    public function ignoreItem($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $id = intval($data['id'] ?? $data['item_id'] ?? 0);
        if (!$id) {
            send_json(['error' => 'شناسه نامعتبر.'], 400);
            return;
        }
        $item = $this->model->getItemOwned($id, $entity_id);
        if (!$item) {
            send_json(['error' => 'آیتم یافت نشد.'], 404);
            return;
        }
        $this->model->clearMatch($id, intval($item['import_id']), 'ignored');
        // clearMatch sets unmatched fields null and status — good for ignored too
        send_json(['success' => true]);
    }

    public function unignoreItem($data) {
        $this->unmatchItem($data);
    }

    public function createFromBankItem($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $id = intval($data['id'] ?? $data['item_id'] ?? 0);
        if (!$id) {
            send_json(['error' => 'شناسه نامعتبر.'], 400);
            return;
        }
        $item = $this->model->getItemOwned($id, $entity_id);
        if (!$item) {
            send_json(['error' => 'آیتم یافت نشد.'], 404);
            return;
        }
        if ($item['match_status'] === 'matched') {
            send_json(['error' => 'این ردیف قبلاً تطبیق شده است.'], 400);
            return;
        }

        $result = $this->model->createFromItem($item, $data);
        if (!empty($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 400);
            return;
        }
        $msg = $result['message'] ?? 'ثبت و تطبیق شد.';
        log_activity($this->conn, 'CREATE_FROM_BANK', $msg);
        send_json(array_merge(['success' => true, 'message' => $msg], $result));
    }

    public function deleteImport($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $importId = intval($data['import_id'] ?? $data['id'] ?? 0);
        if (!$importId) {
            send_json(['error' => 'شناسه نامعتبر.'], 400);
            return;
        }
        $import = $this->model->getImportForEntity($importId, $entity_id);
        if (!$import) {
            send_json(['error' => 'یافت نشد.'], 404);
            return;
        }
        $this->model->archiveImportMatchesToHistory($importId, $entity_id);
        $stmt = $this->conn->prepare("DELETE FROM bank_imports WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $importId, $entity_id);
        $stmt->execute();
        log_activity($this->conn, 'DELETE_BANK_IMPORT', "ورود صورتحساب #{$importId} حذف شد (تطبیق‌ها در حافظه نگه‌داری شد؛ تراکنش‌های دفتر حذف نشد).");
        send_json([
            'success' => true,
            'message' => 'ورود صورتحساب حذف شد. تراکنش‌های ثبت‌شده در دفتر (دریافت/پرداخت/هزینه) پاک نشدند؛ تطبیق‌های قبلی برای ورود مجدد ذخیره شدند.',
        ]);
    }

    public function searchLedger($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $accountId = intval($data['account_id'] ?? 0);
        $q = trim((string)($data['q'] ?? ''));
        $amount = isset($data['amount']) ? abs(floatval($data['amount'])) : null;
        $bankSign = isset($data['bank_amount']) ? floatval($data['bank_amount']) : null;
        $dateWindow = intval($data['date_window'] ?? 2);
        $aroundDate = $data['date'] ?? null;

        if (!$accountId) {
            send_json(['error' => 'حساب الزامی است.'], 400);
            return;
        }

        $from = $to = null;
        if ($aroundDate) {
            $from = date('Y-m-d', strtotime($aroundDate . " -{$dateWindow} days"));
            $to = date('Y-m-d', strtotime($aroundDate . " +{$dateWindow} days"));
        }

        $importId = intval($data['import_id'] ?? 0);
        $candidates = $this->model->getLedgerCandidates($accountId, $entity_id, $from, $to);
        $used = $importId
            ? $this->model->getAlreadyMatchedKeys($importId)
            : $this->model->getMatchedKeysForAccount($accountId, $entity_id);
        $results = [];

        foreach ($candidates as $c) {
            $key = $c['source'] . ':' . $c['ref_id'];
            if (isset($used[$key])) continue;

            $signedBank = $bankSign;
            if ($signedBank === null && $amount !== null) {
                $signedBank = (intval($c['direction']) >= 0 ? 1 : -1) * $amount;
            }
            $bankItem = [
                'amount' => $signedBank !== null ? $signedBank : floatval($c['amount']) * intval($c['direction']),
                'transaction_date' => $aroundDate,
                'description' => $q,
                'counterparty' => $q,
                'tracking_number' => $q,
                'reference_number' => '',
                'document_number' => '',
                'deposit_id' => '',
                'note' => '',
                'branch' => '',
            ];
            $detail = $this->model->scoreMatchDetailed($bankItem, $c);
            $score = intval($detail['score'] ?? 0);

            if ($amount !== null && ($detail['amount_mode'] ?? 'none') === 'none') {
                continue;
            }
            if ($q !== '') {
                $hay = mb_strtolower(($c['label'] ?? '') . ' ' . ($c['description'] ?? '') . ' ' . ($c['person_name'] ?? '') . ' ' . ($c['check_number'] ?? ''), 'UTF-8');
                $qOk = mb_strpos($hay, mb_strtolower($q, 'UTF-8'), 0, 'UTF-8') !== false;
                if (!$qOk && $score < 60) continue;
            } elseif ($score < 40) {
                continue;
            }

            $results[] = [
                'source' => $c['source'],
                'ref_id' => intval($c['ref_id']),
                'date' => $c['date'],
                'amount' => floatval($c['amount']),
                'direction' => intval($c['direction']),
                'label' => $c['label'],
                'person_name' => $c['person_name'] ?? '',
                'score' => $score,
                'reasons' => $detail['reasons'] ?? [],
            ];
        }

        usort($results, function ($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });
        send_json(['items' => array_slice($results, 0, 40)]);
    }
}
