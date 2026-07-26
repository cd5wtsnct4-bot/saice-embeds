<?php
/**
 * Microsoft Graph client for two-way calendar sync. Builds on
 * msal_lite.php's OAuth primitives but requests the extra
 * `Calendars.ReadWrite offline_access` scopes needed to read/write a
 * user's Outlook calendar in the background (offline_access is what
 * gets us a refresh_token).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/msal_lite.php';
require_once __DIR__ . '/crypto.php';

class GraphException extends Exception {}

const GRAPH_CALENDAR_SCOPE = 'openid profile email offline_access Calendars.ReadWrite';

function ms_calendar_sync_enabled(): bool
{
    return ms_login_enabled() && token_encryption_enabled();
}

function graph_calendar_authorize_url(string $state, string $nonce): string
{
    $params = [
        'client_id' => MS_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri' => MS_CALENDAR_REDIRECT_URI,
        'response_mode' => 'query',
        'scope' => GRAPH_CALENDAR_SCOPE,
        'state' => $state,
        'nonce' => $nonce,
        'prompt' => 'consent',
    ];
    return 'https://login.microsoftonline.com/' . rawurlencode(MS_TENANT_ID)
        . '/oauth2/v2.0/authorize?' . http_build_query($params);
}

/** Shared cURL POST helper for the token endpoint. Returns decoded JSON. */
function graph_token_request(array $post): array
{
    $endpoint = 'https://login.microsoftonline.com/' . rawurlencode(MS_TENANT_ID) . '/oauth2/v2.0/token';
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new GraphException('Token request failed: ' . $curlErr);
    }
    $data = json_decode($body, true);
    if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
        $desc = is_array($data) ? ($data['error_description'] ?? $body) : $body;
        throw new GraphException('Token request rejected: ' . $desc);
    }
    return $data;
}

function graph_exchange_calendar_code(string $code): array
{
    return graph_token_request([
        'client_id' => MS_CLIENT_ID,
        'client_secret' => MS_CLIENT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => MS_CALENDAR_REDIRECT_URI,
        'scope' => GRAPH_CALENDAR_SCOPE,
    ]);
}

function graph_refresh_access_token(string $refreshToken): array
{
    return graph_token_request([
        'client_id' => MS_CLIENT_ID,
        'client_secret' => MS_CLIENT_SECRET,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'scope' => GRAPH_CALENDAR_SCOPE,
    ]);
}

/** Ensure a connection row has a live access token, refreshing (and persisting) if needed. */
function graph_valid_access_token(PDO $pdo, array $connection): string
{
    $expiresAt = strtotime($connection['token_expires_at'] . ' UTC');
    if ($expiresAt > time() + 60) {
        return token_decrypt($connection['access_token_enc']);
    }

    $refreshToken = token_decrypt($connection['refresh_token_enc']);
    $tokens = graph_refresh_access_token($refreshToken);
    $newRefresh = $tokens['refresh_token'] ?? $refreshToken; // Microsoft may not always rotate it
    $expiresAtNew = gmdate('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? 3600));

    $stmt = $pdo->prepare('UPDATE ms_calendar_connections SET access_token_enc=?, refresh_token_enc=?, token_expires_at=? WHERE id=?');
    $stmt->execute([
        token_encrypt($tokens['access_token']),
        token_encrypt($newRefresh),
        $expiresAtNew,
        $connection['id'],
    ]);

    return $tokens['access_token'];
}

/** Generic authenticated Graph API call. Returns decoded JSON (or [] for 204/empty). */
function graph_api_call(string $accessToken, string $method, string $url, ?array $jsonBody = null): array
{
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $accessToken];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $tz = date_default_timezone_get();
    $headers[] = 'Prefer: outlook.timezone="' . $tz . '"';

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new GraphException('Graph request failed: ' . $curlErr);
    }
    if ($status === 204 || $body === '') {
        return [];
    }
    $data = json_decode($body, true);
    if ($status >= 400) {
        $msg = is_array($data) ? ($data['error']['message'] ?? $body) : $body;
        throw new GraphException("Graph API error ({$status}): {$msg}");
    }
    return is_array($data) ? $data : [];
}

function graph_fetch_profile(string $accessToken): array
{
    return graph_api_call($accessToken, 'GET', 'https://graph.microsoft.com/v1.0/me?$select=id,mail,userPrincipalName,displayName');
}

/** List events in [start, end) (naive local datetimes, server timezone). Follows pagination. */
function graph_list_calendar_view(string $accessToken, string $startLocal, string $endLocal): array
{
    $url = 'https://graph.microsoft.com/v1.0/me/calendarview?' . http_build_query([
        'startDateTime' => str_replace(' ', 'T', $startLocal),
        'endDateTime' => str_replace(' ', 'T', $endLocal),
        '$top' => 100,
        '$orderby' => 'start/dateTime',
        '$select' => 'id,subject,bodyPreview,start,end,isAllDay,location,lastModifiedDateTime',
    ]);

    $events = [];
    $guard = 0;
    while ($url && $guard < 20) {
        $page = graph_api_call($accessToken, 'GET', $url);
        foreach ($page['value'] ?? [] as $e) {
            $events[] = $e;
        }
        $url = $page['@odata.nextLink'] ?? null;
        $guard++;
    }
    return $events;
}

function graph_event_payload(string $title, ?string $description, string $startLocal, ?string $endLocal, bool $allDay, ?string $location): array
{
    $tz = date_default_timezone_get();
    $end = $endLocal ?: $startLocal;
    return [
        'subject' => $title,
        'body' => ['contentType' => 'text', 'content' => (string)$description],
        'start' => ['dateTime' => str_replace(' ', 'T', $startLocal), 'timeZone' => $tz],
        'end' => ['dateTime' => str_replace(' ', 'T', $end), 'timeZone' => $tz],
        'isAllDay' => $allDay,
        'location' => ['displayName' => (string)$location],
    ];
}

function graph_create_event(string $accessToken, array $payload): array
{
    return graph_api_call($accessToken, 'POST', 'https://graph.microsoft.com/v1.0/me/events', $payload);
}

function graph_update_event(string $accessToken, string $msEventId, array $payload): array
{
    $url = 'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode($msEventId);
    return graph_api_call($accessToken, 'PATCH', $url, $payload);
}

function graph_delete_event(string $accessToken, string $msEventId): void
{
    $url = 'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode($msEventId);
    try {
        graph_api_call($accessToken, 'DELETE', $url);
    } catch (GraphException $e) {
        // Already gone on Microsoft's side (e.g. deleted directly in Outlook) — not an error for us.
        if (!str_contains($e->getMessage(), '404')) {
            throw $e;
        }
    }
}

/** Graph always returns lastModifiedDateTime/createdDateTime in UTC regardless
 *  of the outlook.timezone preference — convert to the app's local timezone
 *  so it's comparable with calendar_events.updated_at (also naive/local). */
function graph_utc_to_local(string $utcIso): ?string
{
    if ($utcIso === '') {
        return null;
    }
    try {
        $dt = new DateTime($utcIso, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/** Map a Graph event object to the calendar_events row fields we store. */
function graph_event_to_crm_fields(array $g): array
{
    $start = str_replace('T', ' ', substr($g['start']['dateTime'] ?? '', 0, 19));
    $end = str_replace('T', ' ', substr($g['end']['dateTime'] ?? '', 0, 19));
    return [
        'title' => (string)($g['subject'] ?? '(no title)'),
        'description' => (string)($g['bodyPreview'] ?? ''),
        'start_datetime' => $start ?: null,
        'end_datetime' => $end ?: null,
        'all_day' => !empty($g['isAllDay']) ? 1 : 0,
        'location' => (string)($g['location']['displayName'] ?? ''),
        'ms_event_id' => (string)($g['id'] ?? ''),
        'ms_last_modified' => graph_utc_to_local((string)($g['lastModifiedDateTime'] ?? '')),
    ];
}
