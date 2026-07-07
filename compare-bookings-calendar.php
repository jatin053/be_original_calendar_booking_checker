<?php

try {
    $options = parseArguments($argv);

    if ($options['help']) {
        printUsage();
        exit(0);
    }

    if ($options['auth_only']) {
        getGoogleAccessToken($options['credentials_file'], $options['token_file'], $options);
        echo json_encode(['status' => 'ok'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    if ($options['booking_report'] === null) {
        printUsage();
        exit(1);
    }

    validateInput($options);

    $requiredColumns = [
        'Booking Reference',
        'Net Price',
        'Status',
        'Travel Date',
        'Lead traveler Name',
        'Lead traveler Contact Info',
        'Number of Passengers',
        'Product Name',
        'Tour Grade Code',
        'Booking Source',
    ];

    $bookings = readBookingReport($options['booking_report'], $requiredColumns);
    $accessToken = getGoogleAccessToken($options['credentials_file'], $options['token_file'], $options);
    $calendarIds = $options['calendar_ids'] ?: getCalendarIds($accessToken);
    $calendarReferences = readLiveCalendarBookingReferences($accessToken, $calendarIds, $bookings, $options['include_cancelled']);
    $result = findMissingBookings($bookings, $calendarReferences, $options['include_cancelled'], count($calendarIds));
    $result['calendar_ids_checked'] = $calendarIds;

    $output = $options['with_summary'] ? $result : $result['missing'];
    $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if ($options['output_file'] !== null) {
        file_put_contents($options['output_file'], $json);
    }

    echo $json;
} catch (Exception $e) {
    $code = $e->getCode();
    $message = $e->getMessage();

    // Check if it's an authorization/token issue
    $isAuthError = ($code === 400 || $code === 401 || $code === 403) && (
        stripos($message, 'invalid_grant') !== false ||
        stripos($message, 'invalid_token') !== false ||
        stripos($message, 'expired') !== false ||
        stripos($message, 'revoked') !== false ||
        stripos($message, 'unauthorized') !== false
    );

    if ($isAuthError) {
        if (isset($options['token_file']) && is_file($options['token_file'])) {
            $tokenData = json_decode((string) @file_get_contents($options['token_file']), true);
            if (is_array($tokenData) && !empty($tokenData['refresh_token'])) {
                unset($tokenData['access_token']);
                @file_put_contents($options['token_file'], json_encode($tokenData, JSON_PRETTY_PRINT));
            } else {
                @unlink($options['token_file']);
            }
        }

        if (!empty($options['no_interactive'])) {
            $authUrl = '';
            try {
                $credentials = readJsonFile($options['credentials_file']);
                $client = $credentials['installed'] ?? $credentials['web'] ?? null;
                if ($client !== null) {
                    $redirectUri = resolveRedirectUri($client, $options);
                    $params = [
                        'client_id' => $client['client_id'],
                        'redirect_uri' => $redirectUri,
                        'response_type' => 'code',
                        'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
                        'access_type' => 'offline',
                        'prompt' => 'consent',
                    ];
                    $authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);
                }
            } catch (Exception $ex) {
                // Ignore credentials file read errors here
            }

            echo json_encode([
                'status' => 'error',
                'error' => 'auth_required',
                'message' => 'Authentication expired or invalid: ' . $message,
                'auth_url' => $authUrl
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit(0);
        }

        fwrite(STDERR, "Authentication error: {$message}\n");
        exit(1);
    } else {
        if (!empty($options['no_interactive'])) {
            echo json_encode([
                'status' => 'error',
                'error' => 'api_error',
                'message' => 'API error: ' . $message
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit(0);
        }

        fwrite(STDERR, "Error: {$message}\n");
        exit(1);
    }
}

function parseArguments(array $argv): array
{
    $args = array_slice($argv, 1);
    $paths = [];

    $options = [
        'booking_report' => null,
        'credentials_file' => __DIR__ . '/credentials.json',
        'token_file' => __DIR__ . '/token.json',
        'calendar_ids' => [],
        'include_cancelled' => false,
        'with_summary' => false,
        'output_file' => null,
        'help' => false,
        'no_interactive' => false,
        'auth_only' => false,
        'code' => null,
        'redirect_uri' => null,
    ];

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--include-cancelled') {
            $options['include_cancelled'] = true;
        } elseif ($arg === '--with-summary') {
            $options['with_summary'] = true;
        } elseif ($arg === '--no-interactive') {
            $options['no_interactive'] = true;
        } elseif ($arg === '--auth-only') {
            $options['auth_only'] = true;
        } elseif (str_starts_with($arg, '--code=')) {
            $options['code'] = substr($arg, strlen('--code='));
        } elseif (str_starts_with($arg, '--credentials=')) {
            $options['credentials_file'] = substr($arg, strlen('--credentials='));
        } elseif (str_starts_with($arg, '--token=')) {
            $options['token_file'] = substr($arg, strlen('--token='));
        } elseif (str_starts_with($arg, '--calendar-id=')) {
            $options['calendar_ids'][] = substr($arg, strlen('--calendar-id='));
        } elseif (str_starts_with($arg, '--output=')) {
            $options['output_file'] = substr($arg, strlen('--output='));
        } elseif (str_starts_with($arg, '--redirect-uri=')) {
            $options['redirect_uri'] = substr($arg, strlen('--redirect-uri='));
        } else {
            $paths[] = $arg;
        }
    }

    $options['booking_report'] = $paths[0] ?? null;

    return $options;
}

function printUsage(): void
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/compare-bookings-calendar.php <booking-report.csv> [options]\n\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  --credentials=path.json  Google OAuth JSON file. Default: scripts/credentials.json\n");
    fwrite(STDERR, "  --token=path.json        OAuth token cache. Default: scripts/token.json\n");
    fwrite(STDERR, "  --calendar-id=id         Check one calendar. Use again for multiple calendars.\n");
    fwrite(STDERR, "  --include-cancelled      Also check cancelled bookings. Default checks Confirmed and Amended only.\n");
    fwrite(STDERR, "  --with-summary           Print counts along with missing booking objects.\n");
    fwrite(STDERR, "  --output=path.json       Save JSON output to a file.\n");
    fwrite(STDERR, "  --auth-only              Only complete/check Google OAuth login.\n\n");
    fwrite(STDERR, "This script reads the CSV and checks live Google Calendar events using Google Calendar API.\n");
}

function validateInput(array $options): void
{
    if (!is_file($options['booking_report'])) {
        throw new Exception("Booking report not found: {$options['booking_report']}");
    }

    if (!is_file($options['credentials_file'])) {
        throw new Exception("Google credentials file not found: {$options['credentials_file']}");
    }
}

function readBookingReport(string $reportPath, array $requiredColumns): array
{
    $file = fopen($reportPath, 'rb');
    if ($file === false) {
        fwrite(STDERR, "Unable to open booking report: {$reportPath}\n");
        exit(1);
    }

    $headers = fgetcsv($file, 0, ',', '"', '\\');
    if ($headers === false) {
        fclose($file);
        return [];
    }

    validateColumns($headers, $requiredColumns);

    $bookings = [];
    while (($row = fgetcsv($file, 0, ',', '"', '\\')) !== false) {
        if (isEmptyCsvRow($row)) {
            continue;
        }

        $bookings[] = array_combine($headers, array_pad($row, count($headers), ''));
    }

    fclose($file);
    return $bookings;
}

function validateColumns(array $headers, array $requiredColumns): void
{
    $missingColumns = array_values(array_diff($requiredColumns, $headers));

    if ($missingColumns !== []) {
        fwrite(STDERR, 'Missing required columns: ' . implode(', ', $missingColumns) . PHP_EOL);
        exit(1);
    }
}

function isEmptyCsvRow(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }

    return true;
}

function getGoogleAccessToken(string $credentialsFile, string $tokenFile, array $options = []): string
{
    $credentials = readJsonFile($credentialsFile);
    $client = $credentials['installed'] ?? $credentials['web'] ?? null;

    if ($client === null) {
        if (!empty($options['no_interactive'])) {
            echo json_encode([
                'status' => 'error',
                'error' => 'invalid_credentials',
                'message' => 'Invalid Google credentials JSON.'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit(0);
        }
        fwrite(STDERR, "Invalid Google credentials JSON.\n");
        exit(1);
    }

    if (!empty($options['code'])) {
        $code = extractAuthorizationCode($options['code']);
        $redirectUri = resolveRedirectUri($client, $options);
        try {
            $token = googleTokenRequest($client, [
                'code' => $code,
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]);
            $token['created_at'] = time();
            saveJsonFile($tokenFile, $token);
            return $token['access_token'];
        } catch (Exception $e) {
            if (!empty($options['no_interactive'])) {
                echo json_encode([
                    'status' => 'error',
                    'error' => 'auth_required',
                    'message' => 'Failed to exchange authorization code: ' . $e->getMessage()
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                exit(0);
            }
            fwrite(STDERR, "Failed to exchange authorization code: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    $token = is_file($tokenFile) ? readJsonFile($tokenFile) : null;

    if (is_array($token) && !empty($token['access_token']) && !isTokenExpired($token)) {
        return $token['access_token'];
    }

    if (is_array($token) && !empty($token['refresh_token'])) {
        try {
            $token = refreshGoogleToken($client, $token['refresh_token']);
            saveJsonFile($tokenFile, $token);
            return $token['access_token'];
        } catch (Exception $e) {
            $code = $e->getCode();
            $msg = $e->getMessage();
            $isAuthError = ($code === 400 || $code === 401 || $code === 403) && (
                stripos($msg, 'invalid_grant') !== false ||
                stripos($msg, 'invalid_token') !== false ||
                stripos($msg, 'expired') !== false ||
                stripos($msg, 'revoked') !== false ||
                stripos($msg, 'unauthorized') !== false
            );
            if ($isAuthError) {
                if (is_file($tokenFile)) {
                    @unlink($tokenFile);
                }
                $token = null;
            } else {
                throw $e;
            }
        }
    }

    if (!empty($options['no_interactive'])) {
        $redirectUri = resolveRedirectUri($client, $options);
        $params = [
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];
        $authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);

        echo json_encode([
            'status' => 'error',
            'error' => 'auth_required',
            'auth_url' => $authUrl
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $token = createGoogleTokenFromLogin($client);
    saveJsonFile($tokenFile, $token);

    return $token['access_token'];
}

function isTokenExpired(array $token): bool
{
    return empty($token['created_at']) || empty($token['expires_in']) || time() >= ($token['created_at'] + $token['expires_in'] - 60);
}

function createGoogleTokenFromLogin(array $client): array
{
    $redirectUri = $client['redirect_uris'][0] ?? 'http://localhost';
    $params = [
        'client_id' => $client['client_id'],
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
        'access_type' => 'offline',
        'prompt' => 'consent',
    ];

    $authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);

    fwrite(STDERR, "Open this link and login with the Google account that has calendar access:\n");
    fwrite(STDERR, $authUrl . "\n\n");
    fwrite(STDERR, "After login, paste the full localhost URL or only the code here:\n");

    $input = trim((string) fgets(STDIN));
    $code = extractAuthorizationCode($input);

    if ($code === '') {
        fwrite(STDERR, "Authorization code not found.\n");
        exit(1);
    }

    $token = googleTokenRequest($client, [
        'code' => $code,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);

    $token['created_at'] = time();
    return $token;
}

function resolveRedirectUri(array $client, array $options): string
{
    if (!empty($options['redirect_uri'])) {
        return $options['redirect_uri'];
    }

    return $client['redirect_uris'][0] ?? 'http://localhost';
}

function extractAuthorizationCode(string $input): string
{
    if (str_contains($input, 'code=')) {
        $parts = parse_url($input);
        parse_str($parts['query'] ?? '', $query);
        return (string) ($query['code'] ?? '');
    }

    return $input;
}

function refreshGoogleToken(array $client, string $refreshToken): array
{
    $token = googleTokenRequest($client, [
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ]);

    $token['refresh_token'] = $refreshToken;
    $token['created_at'] = time();

    return $token;
}

function googleTokenRequest(array $client, array $fields): array
{
    $tokenUri = $client['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    return httpRequest('POST', $tokenUri, [], $fields);
}

function getCalendarIds(string $accessToken): array
{
    $ids = [];
    $pageToken = null;

    do {
        $params = [
            'minAccessRole' => 'reader',
            'maxResults' => 250,
        ];

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        $response = googleGet($accessToken, 'https://www.googleapis.com/calendar/v3/users/me/calendarList', $params);

        foreach ($response['items'] ?? [] as $calendar) {
            $id = $calendar['id'] ?? '';
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        $pageToken = $response['nextPageToken'] ?? null;
    } while ($pageToken !== null);

    if ($ids === []) {
        fwrite(STDERR, "No readable Google calendars found for this account.\n");
        exit(1);
    }

    return $ids;
}

function readLiveCalendarBookingReferences(string $accessToken, array $calendarIds, array $bookings, bool $includeCancelled): array
{
    [$timeMin, $timeMax] = getBookingDateRange($bookings, $includeCancelled);
    $references = [];

    foreach ($calendarIds as $calendarId) {
        readReferencesFromCalendar($accessToken, $calendarId, $timeMin, $timeMax, $references);
    }

    return $references;
}

function getBookingDateRange(array $bookings, bool $includeCancelled): array
{
    $timestamps = [];

    foreach ($bookings as $booking) {
        if (!shouldCheckBooking($booking, $includeCancelled)) {
            continue;
        }

        $timestamp = strtotime($booking['Travel Date'] ?? '');
        if ($timestamp !== false) {
            $timestamps[] = $timestamp;
        }
    }

    if ($timestamps === []) {
        return [gmdate('c', strtotime('-1 year')), gmdate('c', strtotime('+1 year'))];
    }

    $start = strtotime('-1 day', min($timestamps));
    $end = strtotime('+1 day', max($timestamps));

    return [gmdate('c', $start), gmdate('c', $end)];
}

function readReferencesFromCalendar(string $accessToken, string $calendarId, string $timeMin, string $timeMax, array &$references): void
{
    $pageToken = null;

    do {
        $params = [
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'singleEvents' => 'true',
            'showDeleted' => 'false',
            'maxResults' => 2500,
        ];

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events';
        $response = googleGet($accessToken, $url, $params);

        foreach ($response['items'] ?? [] as $event) {
            addBookingReferences($event['summary'] ?? '', $references);
            addBookingReferences($event['description'] ?? '', $references);
            addBookingReferences($event['location'] ?? '', $references);
        }

        $pageToken = $response['nextPageToken'] ?? null;
    } while ($pageToken !== null);
}

function addBookingReferences(string $text, array &$references): void
{
    if (preg_match_all('/\bBR-\d+\b/', $text, $matches)) {
        foreach ($matches[0] as $reference) {
            $references[$reference] = true;
        }
    }
}

function findMissingBookings(array $bookings, array $calendarReferences, bool $includeCancelled, int $calendarCount): array
{
    $total = count($bookings);
    $checked = 0;
    $skipped = 0;
    $statusSkipped = 0;
    $allBookings = [];
    $checkedBookings = [];
    $alreadyExists = [];
    $missing = [];
    $cancelled = [];

    foreach ($bookings as $booking) {
        $bookingObject = buildMissingBookingObject($booking);
        $bookingObject['CSV Status'] = $booking['Status'] ?? '';

        $status = strtolower(trim($booking['Status'] ?? ''));
        $isCancelled = str_contains($status, 'cancel') || str_contains($status, 'canceled');

        if ($isCancelled) {
            $statusSkipped++;
            $bookingObject['Calendar Check'] = 'Not checked';
            $bookingObject['Calendar Result'] = 'Cancelled';
            $allBookings[] = $bookingObject;
            $cancelled[] = $bookingObject;
            continue;
        }

        if (!shouldCheckBooking($booking, $includeCancelled)) {
            $statusSkipped++;
            $bookingObject['Calendar Check'] = 'Not checked';
            $bookingObject['Calendar Result'] = 'Skipped by CSV status';
            $allBookings[] = $bookingObject;
            continue;
        }

        $checked++;
        $bookingObject['Calendar Check'] = 'Checked';

        if (bookingExistsInCalendar($booking, $calendarReferences)) {
            $skipped++;
            $bookingObject['Calendar Result'] = 'Already on calendar';
            $allBookings[] = $bookingObject;
            $checkedBookings[] = $bookingObject;
            $alreadyExists[] = $bookingObject;
            continue;
        }

        $bookingObject['Calendar Result'] = 'Missing from calendar';
        $allBookings[] = $bookingObject;
        $checkedBookings[] = $bookingObject;
        $missing[] = $bookingObject;
    }

    return [
        'calendar_count_checked' => $calendarCount,
        'total_bookings_checked' => $total,
        'bookings_compared' => $checked,
        'status_skipped' => $statusSkipped,
        'already_exists_skip' => $skipped,
        'missing_printed' => count($missing),
        'all_bookings' => $allBookings,
        'checked_bookings' => $checkedBookings,
        'already_exists' => $alreadyExists,
        'missing' => $missing,
        'cancelled' => $cancelled,
    ];
}

function shouldCheckBooking(array $booking, bool $includeCancelled): bool
{
    return true;
}

function bookingExistsInCalendar(array $booking, array $calendarReferences): bool
{
    $reference = $booking['Booking Reference'] ?? '';
    return $reference !== '' && isset($calendarReferences[$reference]);
}

function buildMissingBookingObject(array $booking): array
{
    $startDate = buildStartDate($booking['Travel Date'] ?? '', $booking['Tour Grade Code'] ?? '');

    return [
        'Action' => 'Booking',
        'Amount' => $booking['Net Price'] ?? '',
        'City' => detectCity($booking['Product Name'] ?? ''),
        'Email' => '',
        'End Date' => buildEndDate($startDate),
        'Guests' => (int) ($booking['Number of Passengers'] ?? 0),
        'Name' => $booking['Lead traveler Name'] ?? '',
        'Phone' => normalizePhone($booking['Lead traveler Contact Info'] ?? ''),
        'Ref' => $booking['Booking Reference'] ?? '',
        'Source' => normalizeSource($booking['Booking Source'] ?? ''),
        'Start Date' => $startDate,
        'Tour' => $booking['Product Name'] ?? '',
    ];
}

function buildStartDate(string $travelDate, string $tourGradeCode): string
{
    $time = '';

    if (preg_match('/~(\d{1,2}:\d{2})/', $tourGradeCode, $matches)) {
        $time = $matches[1] . ':00';
    }

    $timestamp = strtotime(trim($travelDate . ' ' . $time));
    if ($timestamp === false) {
        return trim($travelDate . ' ' . $time);
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function buildEndDate(string $startDate): string
{
    $timestamp = strtotime($startDate);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d H:i:s', strtotime('+1 hour', $timestamp));
}

function detectCity(string $tourName): string
{
    $cities = [
        'Amsterdam', 'Athens', 'Barcelona', 'Berlin', 'Budapest', 'Hamburg',
        'Madrid', 'Malaga', 'Nice', 'Paris', 'Stockholm', 'Warsaw',
    ];

    foreach ($cities as $city) {
        if (stripos($tourName, $city) !== false) {
            return $city;
        }
    }

    return '';
}

function normalizeSource(string $source): string
{
    return 'Viator.com';
}

function normalizePhone(string $phone): string
{
    $phone = trim($phone);
    if ($phone === '') {
        return '';
    }
    if ($phone[0] === '0') {
        $phone = trim(substr($phone, 1));
    }
    if ($phone === '') {
        return '';
    }
    if ($phone[0] !== '+') {
        return '+' . $phone;
    }
    return $phone;
}

function googleGet(string $accessToken, string $url, array $params = []): array
{
    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    return httpRequest('GET', $url, ['Authorization: Bearer ' . $accessToken]);
}

function httpRequest(string $method, string $url, array $headers = [], ?array $fields = null): array
{
    if (function_exists('curl_init')) {
        $response = curlHttpRequest($method, $url, $headers, $fields);
    } else {
        $response = streamHttpRequest($method, $url, $headers, $fields);
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        throw new Exception('Invalid JSON response from Google API: ' . substr($response['body'], 0, 200));
    }

    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = $data['error_description'] ?? $data['error']['message'] ?? $response['body'];
        throw new Exception($message, $response['status']);
    }

    return $data;
}

function curlHttpRequest(string $method, string $url, array $headers, ?array $fields): array
{
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

    if ($fields !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $body = curl_exec($curl);
    if ($body === false) {
        throw new Exception('HTTP request failed: ' . curl_error($curl));
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return ['status' => $status, 'body' => $body];
}

function streamHttpRequest(string $method, string $url, array $headers, ?array $fields): array
{
    $content = $fields === null ? null : http_build_query($fields);

    if ($content !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
        ],
    ]);

    $body = file_get_contents($url, false, $context);
    if ($body === false) {
        throw new Exception('HTTP request failed.');
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }

    return ['status' => $status, 'body' => $body];
}

function readJsonFile(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new Exception("Invalid JSON file: {$path}");
    }

    return $data;
}

function saveJsonFile(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new Exception('Failed to encode token JSON: ' . json_last_error_msg());
    }

    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new Exception('Token folder is not writable: ' . $directory);
    }

    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new Exception('Failed to write token file: ' . $path);
    }
}
