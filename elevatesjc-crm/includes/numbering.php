<?php
/**
 * Atomic per-year document numbering (e.g. INV-2026-0001, PRO-2026-0001).
 * Uses SELECT ... FOR UPDATE inside a transaction so two concurrent
 * requests can never be handed the same number.
 */
require_once __DIR__ . '/db.php';

function next_document_number(string $counterKey, string $prefix): string
{
    $pdo = db();
    $year = (int)date('Y');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT year, next_seq FROM document_counters WHERE counter_key = ? FOR UPDATE');
        $stmt->execute([$counterKey]);
        $row = $stmt->fetch();

        if (!$row) {
            $seq = 1;
            $ins = $pdo->prepare('INSERT INTO document_counters (counter_key, year, next_seq) VALUES (?, ?, ?)');
            $ins->execute([$counterKey, $year, $seq + 1]);
        } elseif ((int)$row['year'] !== $year) {
            $seq = 1;
            $upd = $pdo->prepare('UPDATE document_counters SET year = ?, next_seq = ? WHERE counter_key = ?');
            $upd->execute([$year, $seq + 1, $counterKey]);
        } else {
            $seq = (int)$row['next_seq'];
            $upd = $pdo->prepare('UPDATE document_counters SET next_seq = next_seq + 1 WHERE counter_key = ?');
            $upd->execute([$counterKey]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return sprintf('%s-%d-%04d', $prefix, $year, $seq);
}
