<?php
/**
 * Orchestrates two-way sync for one connected Microsoft calendar:
 *   1. retry any local edits that failed to push last time (sync_pending=1)
 *   2. pull the connection's calendarview and upsert into calendar_events
 *
 * Local rows still marked sync_pending after the retry step are left
 * alone during the pull so a not-yet-synced local edit is never clobbered
 * by stale remote data. Known limitation: events deleted directly in
 * Outlook are not detected here (calendarview has no "deleted" marker —
 * that needs Graph's /delta endpoint, a possible future enhancement).
 */
require_once __DIR__ . '/graph_calendar.php';

function sync_connection(PDO $pdo, array $connection): array
{
    $result = ['pulled' => 0, 'pushed' => 0, 'errors' => []];

    try {
        $accessToken = graph_valid_access_token($pdo, $connection);
    } catch (Exception $e) {
        $msg = 'Could not refresh Microsoft access token: ' . $e->getMessage();
        $pdo->prepare('UPDATE ms_calendar_connections SET last_sync_error=? WHERE id=?')
            ->execute([$msg, $connection['id']]);
        $result['errors'][] = $msg;
        return $result;
    }

    // 1. Retry pending local pushes.
    $stmt = $pdo->prepare('SELECT * FROM calendar_events WHERE connection_id = ? AND sync_pending = 1');
    $stmt->execute([$connection['id']]);
    foreach ($stmt->fetchAll() as $row) {
        try {
            $payload = graph_event_payload($row['title'], $row['description'], $row['start_datetime'], $row['end_datetime'], (bool)$row['all_day'], $row['location']);
            if ($row['ms_event_id']) {
                $g = graph_update_event($accessToken, $row['ms_event_id'], $payload);
            } else {
                $g = graph_create_event($accessToken, $payload);
            }
            $fields = graph_event_to_crm_fields($g);
            $upd = $pdo->prepare('UPDATE calendar_events SET ms_event_id=?, ms_last_modified=?, sync_pending=0 WHERE id=?');
            $upd->execute([$fields['ms_event_id'], $fields['ms_last_modified'], $row['id']]);
            $result['pushed']++;
        } catch (GraphException $e) {
            $result['errors'][] = "Push failed for \"{$row['title']}\": " . $e->getMessage();
        }
    }

    // 2. Pull the remote window and upsert.
    $start = gmdate('Y-m-d H:i:s', strtotime('-' . CRM_CALENDAR_SYNC_PAST_DAYS . ' days'));
    $end = gmdate('Y-m-d H:i:s', strtotime('+' . CRM_CALENDAR_SYNC_FUTURE_DAYS . ' days'));
    try {
        $events = graph_list_calendar_view($accessToken, $start, $end);
    } catch (GraphException $e) {
        $msg = 'Pull failed: ' . $e->getMessage();
        $result['errors'][] = $msg;
        $events = [];
    }

    $findStmt = $pdo->prepare('SELECT id, sync_pending FROM calendar_events WHERE connection_id = ? AND ms_event_id = ?');
    $insertStmt = $pdo->prepare(
        'INSERT INTO calendar_events (title, description, start_datetime, end_datetime, all_day, location, connection_id, ms_event_id, ms_last_modified, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $updateStmt = $pdo->prepare(
        'UPDATE calendar_events SET title=?, description=?, start_datetime=?, end_datetime=?, all_day=?, location=?, ms_last_modified=? WHERE id=?'
    );

    foreach ($events as $g) {
        $fields = graph_event_to_crm_fields($g);
        if ($fields['ms_event_id'] === '' || $fields['start_datetime'] === null) {
            continue;
        }
        $findStmt->execute([$connection['id'], $fields['ms_event_id']]);
        $existing = $findStmt->fetch();

        if ($existing) {
            if ((int)$existing['sync_pending'] === 1) {
                continue; // an unsynced local edit is in flight — don't overwrite it
            }
            $updateStmt->execute([
                $fields['title'], $fields['description'], $fields['start_datetime'], $fields['end_datetime'],
                $fields['all_day'], $fields['location'], $fields['ms_last_modified'], $existing['id'],
            ]);
        } else {
            $insertStmt->execute([
                $fields['title'], $fields['description'], $fields['start_datetime'], $fields['end_datetime'],
                $fields['all_day'], $fields['location'], $connection['id'], $fields['ms_event_id'],
                $fields['ms_last_modified'], $connection['user_id'],
            ]);
        }
        $result['pulled']++;
    }

    $errorSummary = $result['errors'] ? implode(' | ', array_slice($result['errors'], 0, 5)) : null;
    $pdo->prepare('UPDATE ms_calendar_connections SET last_synced_at = UTC_TIMESTAMP(), last_sync_error = ? WHERE id = ?')
        ->execute([$errorSummary, $connection['id']]);

    return $result;
}
