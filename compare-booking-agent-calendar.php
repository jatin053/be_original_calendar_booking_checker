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
        'Date',
        'Booking Ref #',
        'Supplier Ref #',
        'Product',
        'Option',
        "Traveler's First Name",
        "Traveler's Last Name",
        'Email',
        'Phone',
        'Net Price',
    ];

    $bookings = readBookingAgentSheet($options['booking_report'], $requiredColumns);
    $accessToken = getGoogleAccessToken($options['credentials_file'], $options['token_file'], $options);
    $calendarIds = $options['calendar_ids'] ?: getCalendarIds($accessToken);
    $calendarReferences = readLiveCalendarAgentReferences($accessToken, $calendarIds, $bookings);
    $result = findMissingAgentBookings($bookings, $calendarReferences, count($calendarIds));
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
    $isAuthError = ($code === 400 || $code === 401 || $code === 403) && (
        stripos($message, 'invalid_grant') !== false ||
        stripos($message, 'invalid_token') !== false ||
        stripos($message, 'expired') !== false ||
        stripos($message, 'revoked') !== false ||
        stripos($message, 'unauthorized') !== false
    );

    if ($isAuthError && isset($options['token_file']) && is_file($options['token_file'])) {
        $tokenData = json_decode((string) @file_get_contents($options['token_file']), true);
        if (is_array($tokenData) && !empty($tokenData['refresh_token'])) {
            unset($tokenData['access_token']);
            @file_put_contents($options['token_file'], json_encode($tokenData, JSON_PRETTY_PRINT));
        } else {
            @unlink($options['token_file']);
        }
    }

    if (!empty($options['no_interactive'])) {
        $error = $isAuthError ? 'auth_required' : 'api_error';
        $response = [
            'status' => 'error',
            'error' => $error,
            'message' => ($isAuthError ? 'Authentication expired or invalid: ' : 'API error: ') . $message,
        ];

        if ($isAuthError) {
            $response['auth_url'] = buildAuthUrlForOptions($options);
        }

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
    exit(1);
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
    fwrite(STDERR, "  php compare-booking-agent-calendar.php <booking-agent-sheet.xlsx> [options]\n\n");
    fwrite(STDERR, "Options are the same as compare-bookings-calendar.php, except this script reads XLSX agent sheets.\n");
}

function validateInput(array $options): void
{
    if (!is_file($options['booking_report'])) {
        throw new Exception("Booking agent sheet not found: {$options['booking_report']}");
    }

    if (!is_file($options['credentials_file'])) {
        throw new Exception("Google credentials file not found: {$options['credentials_file']}");
    }
}

function readBookingAgentSheet(string $reportPath, array $requiredColumns): array
{
    if (strtolower(pathinfo($reportPath, PATHINFO_EXTENSION)) === 'xlsx') {
        return readBookingAgentSheetXlsx($reportPath, $requiredColumns);
    }

    return readBookingAgentSheetCsv($reportPath, $requiredColumns);
}

function readBookingAgentSheetCsv(string $reportPath, array $requiredColumns): array
{
    $file = fopen($reportPath, 'rb');
    if ($file === false) {
        throw new Exception('Unable to open booking agent sheet: ' . $reportPath);
    }

    $headers = fgetcsv($file, 0, ',', '"', '\\');
    if ($headers === false) {
        fclose($file);
        return [];
    }

    $headers = array_map('trim', $headers);
    validateColumns($headers, $requiredColumns);

    $bookings = [];
    while (($row = fgetcsv($file, 0, ',', '"', '\\')) !== false) {
        if (isEmptyRow($row)) {
            continue;
        }
        $booking = array_combine($headers, array_pad($row, count($headers), ''));
        $booking['Date'] = normalizeExcelDate((string) ($booking['Date'] ?? ''));
        $bookings[] = $booking;
    }

    fclose($file);
    return $bookings;
}

function readBookingAgentSheetXlsx(string $reportPath, array $requiredColumns): array
{
    $archive = readXlsxArchive($reportPath);
    $sharedStrings = readSharedStrings($archive);
    $sheetPath = findFirstWorksheetPath($archive);
    $sheetXml = $archive[$sheetPath] ?? false;

    if ($sheetXml === false) {
        throw new Exception('Unable to read worksheet XML from XLSX file.');
    }

    $rows = readWorksheetRows($sheetXml, $sharedStrings);
    if ($rows === []) {
        return [];
    }

    $headers = array_map('trim', $rows[0]);
    validateColumns($headers, $requiredColumns);

    $bookings = [];
    for ($i = 1; $i < count($rows); $i++) {
        $row = array_pad($rows[$i], count($headers), '');
        if (isEmptyRow($row)) {
            continue;
        }

        $booking = array_combine($headers, array_slice($row, 0, count($headers)));
        $booking['Date'] = normalizeExcelDate((string) ($booking['Date'] ?? ''));
        $bookings[] = $booking;
    }

    return $bookings;
}

function readXlsxArchive(string $reportPath): array
{
    $data = file_get_contents($reportPath);
    if ($data === false) {
        throw new Exception('Unable to read XLSX file: ' . $reportPath);
    }

    $eocdOffset = strrpos($data, "PK\x05\x06");
    if ($eocdOffset === false) {
        throw new Exception('Invalid XLSX file: ZIP directory not found.');
    }

    $centralSize = readUInt32($data, $eocdOffset + 12);
    $centralOffset = readUInt32($data, $eocdOffset + 16);
    $entries = [];
    $position = $centralOffset;
    $centralEnd = $centralOffset + $centralSize;

    while ($position < $centralEnd) {
        if (substr($data, $position, 4) !== "PK\x01\x02") {
            throw new Exception('Invalid XLSX file: bad ZIP central directory.');
        }

        $method = readUInt16($data, $position + 10);
        $compressedSize = readUInt32($data, $position + 20);
        $nameLength = readUInt16($data, $position + 28);
        $extraLength = readUInt16($data, $position + 30);
        $commentLength = readUInt16($data, $position + 32);
        $localOffset = readUInt32($data, $position + 42);
        $name = substr($data, $position + 46, $nameLength);

        $localNameLength = readUInt16($data, $localOffset + 26);
        $localExtraLength = readUInt16($data, $localOffset + 28);
        $fileDataOffset = $localOffset + 30 + $localNameLength + $localExtraLength;
        $compressed = substr($data, $fileDataOffset, $compressedSize);

        if ($method === 0) {
            $entries[$name] = $compressed;
        } elseif ($method === 8) {
            $uncompressed = gzinflate($compressed);
            if ($uncompressed === false) {
                throw new Exception('Unable to inflate XLSX entry: ' . $name);
            }
            $entries[$name] = $uncompressed;
        }

        $position += 46 + $nameLength + $extraLength + $commentLength;
    }

    return $entries;
}

function readUInt16(string $data, int $offset): int
{
    $value = unpack('v', substr($data, $offset, 2));
    return (int) $value[1];
}

function readUInt32(string $data, int $offset): int
{
    $value = unpack('V', substr($data, $offset, 4));
    return (int) $value[1];
}

function readSharedStrings(array $archive): array
{
    $xmlText = $archive['xl/sharedStrings.xml'] ?? false;
    if ($xmlText === false) {
        return [];
    }

    $xml = simplexml_load_string($xmlText);
    if ($xml === false) {
        throw new Exception('Invalid sharedStrings.xml in XLSX file.');
    }

    $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $strings = [];
    foreach ($xml->xpath('//x:si') ?: [] as $item) {
        $parts = [];
        collectTextNodes($item, $parts);
        $strings[] = implode('', $parts);
    }

    return $strings;
}

function findFirstWorksheetPath(array $archive): string
{
    foreach (array_keys($archive) as $name) {
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
            return $name;
        }
    }

    throw new Exception('No worksheet found in XLSX file.');
}

function readWorksheetRows(string $sheetXml, array $sharedStrings): array
{
    $xml = simplexml_load_string($sheetXml);
    if ($xml === false) {
        throw new Exception('Invalid worksheet XML in XLSX file.');
    }

    $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = [];
    foreach ($xml->xpath('//x:sheetData/x:row') ?: [] as $row) {
        $cells = [];
        $position = 0;
        foreach ($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
            $attrs = $cell->attributes();
            $ref = (string) ($attrs['r'] ?? '');
            $index = $ref !== '' ? columnIndexFromCellRef($ref) : $position;
            $cells[$index] = readCellValue($cell, $sharedStrings);
            $position++;
        }

        if ($cells === []) {
            $rows[] = [];
            continue;
        }

        $max = max(array_keys($cells));
        $values = [];
        for ($i = 0; $i <= $max; $i++) {
            $values[] = $cells[$i] ?? '';
        }
        $rows[] = $values;
    }

    return $rows;
}

function readCellValue(SimpleXMLElement $cell, array $sharedStrings): string
{
    $attrs = $cell->attributes();
    $type = (string) ($attrs['t'] ?? '');
    $children = $cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    if ($type === 'inlineStr') {
        $parts = [];
        collectTextNodes($cell, $parts);
        return implode('', $parts);
    }

    $value = isset($children->v) ? (string) $children->v : '';

    if ($type === 's' && $value !== '') {
        return (string) ($sharedStrings[(int) $value] ?? '');
    }

    return $value;
}

function collectTextNodes(SimpleXMLElement $node, array &$parts): void
{
    if ($node->getName() === 't') {
        $parts[] = (string) $node;
    }

    foreach ($node->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main') as $child) {
        collectTextNodes($child, $parts);
    }
}

function columnIndexFromCellRef(string $cellRef): int
{
    $letters = preg_replace('/\d+/', '', strtoupper($cellRef));
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = ($index * 26) + (ord($letter) - ord('A') + 1);
    }
    return $index - 1;
}

function normalizeExcelDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (is_numeric($value)) {
        $seconds = ((float) $value - 25569) * 86400;
        return gmdate('Y-m-d H:i:s', (int) round($seconds));
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('Y-m-d H:i:s', $timestamp);
}

function validateColumns(array $headers, array $requiredColumns): void
{
    $missingColumns = array_values(array_diff($requiredColumns, $headers));
    if ($missingColumns !== []) {
        fwrite(STDERR, 'Missing required columns: ' . implode(', ', $missingColumns) . PHP_EOL);
        exit(1);
    }
}

function isEmptyRow(array $row): bool
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
        throw new Exception('Invalid Google credentials JSON.');
    }

    if (!empty($options['code'])) {
        $code = extractAuthorizationCode($options['code']);
        $redirectUri = resolveRedirectUri($client, $options);
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
            if (!$isAuthError) {
                throw $e;
            }
            @unlink($tokenFile);
        }
    }

    if (!empty($options['no_interactive'])) {
        throw new Exception('unauthorized: Authentication required.', 401);
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

    fwrite(STDERR, "Open this link and login with the Google account that has calendar access:\n");
    fwrite(STDERR, 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params) . "\n\n");
    fwrite(STDERR, "After login, paste the full localhost URL or only the code here:\n");

    $input = trim((string) fgets(STDIN));
    $code = extractAuthorizationCode($input);
    if ($code === '') {
        throw new Exception('Authorization code not found.');
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

function buildAuthUrlForOptions(array $options): string
{
    try {
        $credentials = readJsonFile($options['credentials_file']);
        $client = $credentials['installed'] ?? $credentials['web'] ?? null;
        if ($client === null) {
            return '';
        }

        $params = [
            'client_id' => $client['client_id'],
            'redirect_uri' => resolveRedirectUri($client, $options),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];
        return 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);
    } catch (Exception $e) {
        return '';
    }
}

function resolveRedirectUri(array $client, array $options): string
{
    return !empty($options['redirect_uri']) ? $options['redirect_uri'] : ($client['redirect_uris'][0] ?? 'http://localhost');
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
    return httpRequest('POST', $client['token_uri'] ?? 'https://oauth2.googleapis.com/token', [], $fields);
}

function getCalendarIds(string $accessToken): array
{
    $ids = [];
    $pageToken = null;
    do {
        $params = ['minAccessRole' => 'reader', 'maxResults' => 250];
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
        throw new Exception('No readable Google calendars found for this account.');
    }
    return $ids;
}

function readLiveCalendarAgentReferences(string $accessToken, array $calendarIds, array $bookings): array
{
    [$timeMin, $timeMax] = getBookingDateRange($bookings);
    $expectedRefs = buildExpectedReferenceMap($bookings);
    $found = [];

    foreach ($calendarIds as $calendarId) {
        readAgentReferencesFromCalendar($accessToken, $calendarId, $timeMin, $timeMax, $expectedRefs, $found);
    }

    return $found;
}

function getBookingDateRange(array $bookings): array
{
    $timestamps = [];
    foreach ($bookings as $booking) {
        $timestamp = strtotime($booking['Date'] ?? '');
        if ($timestamp !== false) {
            $timestamps[] = $timestamp;
        }
    }

    if ($timestamps === []) {
        return [gmdate('c', strtotime('-1 year')), gmdate('c', strtotime('+1 year'))];
    }

    return [gmdate('c', strtotime('-1 day', min($timestamps))), gmdate('c', strtotime('+1 day', max($timestamps)))];
}

function buildExpectedReferenceMap(array $bookings): array
{
    $refs = [];
    foreach ($bookings as $booking) {
        foreach (getAgentBookingReferences($booking) as $ref) {
            $refs[$ref] = true;
        }
    }
    return $refs;
}

function getAgentBookingReferences(array $booking): array
{
    $refs = [];
    $bookingRef = trim((string) ($booking['Booking Ref #'] ?? ''));
    if ($bookingRef !== '') {
        $refs[] = $bookingRef;
    }

    $supplierRef = str_replace(["\r\n", "\r"], "\n", (string) ($booking['Supplier Ref #'] ?? ''));
    foreach (preg_split('/\n+/', $supplierRef) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $refs[] = $line;
        }
    }

    return array_values(array_unique($refs));
}

function readAgentReferencesFromCalendar(string $accessToken, string $calendarId, string $timeMin, string $timeMax, array $expectedRefs, array &$found): void
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
            $text = implode("\n", [$event['summary'] ?? '', $event['description'] ?? '', $event['location'] ?? '']);
            foreach ($expectedRefs as $ref => $_) {
                if (!isset($found[$ref]) && stripos($text, $ref) !== false) {
                    $found[$ref] = true;
                }
            }
        }

        $pageToken = $response['nextPageToken'] ?? null;
    } while ($pageToken !== null);
}

function findMissingAgentBookings(array $bookings, array $calendarReferences, int $calendarCount): array
{
    $allBookings = [];
    $checkedBookings = [];
    $alreadyExists = [];
    $missing = [];

    foreach ($bookings as $booking) {
        $bookingObject = buildAgentBookingObject($booking);
        $bookingObject['CSV Status'] = '';
        $bookingObject['Calendar Check'] = 'Checked';

        if (agentBookingExistsInCalendar($booking, $calendarReferences)) {
            $bookingObject['Calendar Result'] = 'Already on calendar';
            $alreadyExists[] = $bookingObject;
        } else {
            $bookingObject['Calendar Result'] = 'Missing from calendar';
            $missing[] = $bookingObject;
        }

        $allBookings[] = $bookingObject;
        $checkedBookings[] = $bookingObject;
    }

    return [
        'calendar_count_checked' => $calendarCount,
        'total_bookings_checked' => count($bookings),
        'bookings_compared' => count($bookings),
        'status_skipped' => 0,
        'already_exists_skip' => count($alreadyExists),
        'missing_printed' => count($missing),
        'all_bookings' => $allBookings,
        'checked_bookings' => $checkedBookings,
        'already_exists' => $alreadyExists,
        'missing' => $missing,
        'cancelled' => [],
    ];
}

function agentBookingExistsInCalendar(array $booking, array $calendarReferences): bool
{
    foreach (getAgentBookingReferences($booking) as $ref) {
        if (isset($calendarReferences[$ref])) {
            return true;
        }
    }
    return false;
}

function buildAgentBookingObject(array $booking): array
{
    $startDate = $booking['Date'] ?? '';
    $supplierRefs = implode(', ', array_slice(getAgentBookingReferences($booking), 1));

    return [
        'Action' => 'Booking',
        'Amount' => $booking['Net Price'] ?? '',
        'City' => detectCity(($booking['Product'] ?? '') . ' ' . ($booking['Option'] ?? '')),
        'Email' => $booking['Email'] ?? '',
        'End Date' => buildEndDate($startDate),
        'Guests' => countAgentGuests($booking),
        'Name' => trim(($booking["Traveler's First Name"] ?? '') . ' ' . ($booking["Traveler's Last Name"] ?? '')),
        'Phone' => normalizePhone($booking['Phone'] ?? ''),
        'Ref' => $booking['Booking Ref #'] ?? '',
        'Source' => 'GetYourGuide',
        'Start Date' => $startDate,
        'Tour' => $booking['Product'] ?? '',
        'Option' => $booking['Option'] ?? '',
        'Supplier Ref' => $supplierRefs,
    ];
}

function countAgentGuests(array $booking): int
{
    $columns = ['Adult', 'Senior', 'Student (with ID)', 'EU Citizens (with ID)', 'Student EU Citizens (with ID)', 'Military (with ID)', 'Youth', 'Child', 'Infant', 'Group'];
    $guests = 0;
    foreach ($columns as $column) {
        $value = trim((string) ($booking[$column] ?? ''));
        if ($value !== '' && is_numeric($value)) {
            $guests += (int) $value;
        }
    }
    return $guests;
}

function buildEndDate(string $startDate): string
{
    $timestamp = strtotime($startDate);
    return $timestamp === false ? '' : date('Y-m-d H:i:s', strtotime('+1 hour', $timestamp));
}

function detectCity(string $tourName): string
{
    $cities = ['Amsterdam', 'Athens', 'Barcelona', 'Berlin', 'Budapest', 'Hamburg', 'Madrid', 'Malaga', 'Nice', 'Paris', 'Stockholm', 'Warsaw'];
    foreach ($cities as $city) {
        if (stripos($tourName, $city) !== false) {
            return $city;
        }
    }
    return '';
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
    return $phone[0] === '+' ? $phone : '+' . $phone;
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