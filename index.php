<?php
session_start();

$uploadDir = sys_get_temp_dir();
$error = null;
$authUrl = null;
$resultData = null;
$tokenFile = __DIR__ . '/token.json';
$hasToken = isValidTokenFile($tokenFile);
$selectedReportType = normalizeReportType($_POST['report_type'] ?? $_GET['report_type'] ?? ($_SESSION['selected_report_type'] ?? 'booking_request'));
$_SESSION['selected_report_type'] = $selectedReportType;

if (isset($_GET['reset'])) {
    unset($_SESSION['csv_files']);
    header('Location: index.php');
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['csv_files']);
    if (is_file($tokenFile)) {
        @unlink($tokenFile);
    }
    header('Location: index.php');
    exit;
}

if (!$hasToken) {
    $redirectError = getRedirectUriError();
    if ($redirectError !== null) {
        $error = $redirectError;
    } else {
        $authUrl = getGoogleAuthUrl();
    }
}

function normalizeReportType(?string $reportType): string
{
    return $reportType === 'booking_agent_sheet' ? 'booking_agent_sheet' : 'booking_request';
}

function filterFilesByReportType(array $files, string $reportType): array
{
    $filtered = [];
    foreach ($files as $file) {
        if (normalizeReportType($file['report_type'] ?? 'booking_request') === $reportType) {
            $filtered[] = $file;
        }
    }
    return $filtered;
}
function getRedirectUri(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8888';
    $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
    $scheme = 'http';

    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ) {
        $scheme = 'https';
    }

    return $scheme . '://' . $host . $script;
}

function getAllowedRedirectUri(): ?string
{
    $credentialsFile = __DIR__ . '/credentials.json';
    if (!is_file($credentialsFile)) {
        return null;
    }

    $credentials = json_decode((string) file_get_contents($credentialsFile), true);
    $client = $credentials['installed'] ?? $credentials['web'] ?? null;
    if ($client === null) {
        return null;
    }

    $currentRedirect = getRedirectUri();
    $allowedRedirects = $client['redirect_uris'] ?? [];
    if (!is_array($allowedRedirects) || $allowedRedirects === []) {
        return null;
    }

    $currentRedirect = trim($currentRedirect);
    foreach ($allowedRedirects as $allowed) {
        if (trim((string) $allowed) === $currentRedirect) {
            return $allowed;
        }
    }

    return null;
}

function getRedirectUriError(): ?string
{
    $credentialsFile = __DIR__ . '/credentials.json';
    if (!is_file($credentialsFile)) {
        return 'Google credentials file not found.';
    }

    $credentials = json_decode((string) file_get_contents($credentialsFile), true);
    $client = $credentials['installed'] ?? $credentials['web'] ?? null;
    $allowedRedirects = $client['redirect_uris'] ?? [];
    if ($client === null || !is_array($allowedRedirects) || $allowedRedirects === []) {
        return 'Google credentials JSON does not contain any allowed redirect URLs.';
    }

    $currentRedirect = getRedirectUri();
    foreach ($allowedRedirects as $allowed) {
        if (trim((string) $allowed) === $currentRedirect) {
            return null;
        }
    }

    return 'This Mac is opening the app at ' . $currentRedirect . ', but that URL is not registered in Google OAuth. Add it to credentials.json and Google Cloud redirect URIs. Allowed URLs: ' . implode(', ', $allowedRedirects);
}

function getGoogleAuthUrl(): ?string
{
    $credentialsFile = __DIR__ . '/credentials.json';
    if (!is_file($credentialsFile)) {
        return null;
    }

    $credentials = json_decode((string) file_get_contents($credentialsFile), true);
    $client = $credentials['installed'] ?? $credentials['web'] ?? null;
    if ($client === null) {
        return null;
    }

    $redirectUri = getRedirectUri();
    $params = [
        'client_id' => $client['client_id'],
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
        'access_type' => 'offline',
        'prompt' => 'consent',
    ];

    return 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);
}

function isValidTokenFile(string $tokenFile): bool
{
    if (!is_file($tokenFile)) {
        return false;
    }

    $contents = trim((string) file_get_contents($tokenFile));
    if ($contents === '') {
        @unlink($tokenFile);
        return false;
    }

    $data = json_decode($contents, true);
    if (!is_array($data) || (empty($data['access_token']) && empty($data['refresh_token']))) {
        @unlink($tokenFile);
        return false;
    }

    return true;
}

function runCompareScript(string $csvPath, string $reportType = 'booking_request'): array
{
    $scriptName = $reportType === 'booking_agent_sheet'
        ? 'compare-booking-agent-calendar.php'
        : 'compare-bookings-calendar.php';
    $scriptPath = __DIR__ . '/' . $scriptName;
    $redirectUri = getRedirectUri();
    $phpBinary = getPhpBinary();
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($csvPath) . ' --with-summary --no-interactive --redirect-uri=' . escapeshellarg($redirectUri);
    return runJsonCommand($cmd);
}

function runAuthOnly(?string $code = null): array
{
    $scriptPath = __DIR__ . '/compare-bookings-calendar.php';
    $redirectUri = getRedirectUri();
    $phpBinary = getPhpBinary();
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' --auth-only --no-interactive --redirect-uri=' . escapeshellarg($redirectUri);
    if ($code !== null) {
        $cmd .= ' --code=' . escapeshellarg($code);
    }

    return runJsonCommand($cmd);
}

    function getPhpBinary(): string
{
    $candidates = [];

    if (defined('PHP_BINDIR')) {
        $phpDir = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR);
        $candidates[] = $phpDir . DIRECTORY_SEPARATOR . 'php.exe';
        $candidates[] = $phpDir . DIRECTORY_SEPARATOR . 'php';
    }

    $candidates[] = 'C:\xampp\php\php.exe';
    $candidates[] = '/Applications/MAMP/bin/php/php8.3.14/bin/php';
    $candidates[] = '/Applications/MAMP/bin/php/php8.2.20/bin/php';
    $candidates[] = '/Applications/MAMP/bin/php/php8.1.29/bin/php';
    $candidates[] = '/opt/homebrew/bin/php';
    $candidates[] = '/usr/local/bin/php';
    $candidates[] = '/usr/bin/php';
    $candidates[] = PHP_BINARY;

    foreach (array_unique($candidates) as $candidate) {
        if (!is_string($candidate) || $candidate === '' || !is_file($candidate) || !is_executable($candidate)) {
            continue;
        }

        $name = strtolower(basename($candidate));
        if (str_contains($name, 'cgi') || str_contains($name, 'fcgi')) {
            continue;
        }

        return $candidate;
    }

    return PHP_BINARY;
}

function runJsonCommand(string $cmd): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [
            'status' => 'error',
            'error' => 'process_failed',
            'message' => 'Unable to start comparison script.',
        ];
    }

    fclose($pipes[0]);
    $stdout = trim(stream_get_contents($pipes[1]));
    $stderr = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $output = $stdout !== '' ? $stdout : $stderr;

    $decoded = json_decode($stdout, true);
    if (!is_array($decoded) && $stderr !== '') {
        $decoded = json_decode($stderr, true);
    }

    if (is_array($decoded)) {
        return $decoded;
    }

    if ($output === '') {
        $output = 'No output returned from comparison script.';
    }

    return [
        'status' => 'error',
        'error' => $exitCode === 0 ? 'invalid_response' : 'script_failed',
        'message' => $output,
    ];
}

function normalizeUploadedFiles(array $files): array
{
    $normalized = [];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];

    foreach ($names as $index => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => is_array($files['type']) ? ($files['type'][$index] ?? '') : ($files['type'] ?? ''),
            'tmp_name' => is_array($files['tmp_name']) ? ($files['tmp_name'][$index] ?? '') : ($files['tmp_name'] ?? ''),
            'error' => is_array($files['error']) ? ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => is_array($files['size']) ? ($files['size'][$index] ?? 0) : ($files['size'] ?? 0),
        ];
    }

    return $normalized;
}

function combineResults(array $files): array
{
    $combined = [
        'calendar_count_checked' => 0,
        'total_bookings_checked' => 0,
        'bookings_compared' => 0,
        'status_skipped' => 0,
        'already_exists_skip' => 0,
        'missing_printed' => 0,
        'missing' => [],
        'files' => [],
        'file_errors' => [],
        'files_processed' => 0,
        'calendar_ids_checked' => [],
        'all_bookings' => [],
        'checked_bookings' => [],
        'already_exists' => [],
        'cancelled' => [],
        'cancelled_count' => 0,
    ];

    foreach ($files as $file) {
        if (empty($file['path']) || !is_file($file['path'])) {
            continue;
        }

        $response = runCompareScript($file['path'], $file['report_type'] ?? 'booking_request');
        if (isset($response['status']) && $response['status'] === 'error') {
            if (($response['error'] ?? '') === 'auth_required') {
                return $response;
            }
            $combined['file_errors'][] = [
                'name' => $file['name'] ?? basename($file['path']),
                'message' => $response['message'] ?? 'Th        is file could not be processed.',
            ];
            continue;
        }

        $combined['files_processed']++;

        $fileSummary = [
            'name' => $file['name'] ?? basename($file['path']),
            'report_type' => $file['report_type'] ?? 'booking_request',
            'calendar_count_checked' => (int) ($response['calendar_count_checked'] ?? 0),
            'total_bookings_checked' => (int) ($response['total_bookings_checked'] ?? 0),
            'bookings_compared' => (int) ($response['bookings_compared'] ?? $response['total_bookings_checked'] ?? 0),
            'status_skipped' => (int) ($response['status_skipped'] ?? 0),
            'already_exists_skip' => (int) ($response['already_exists_skip'] ?? 0),
            'missing_printed' => (int) ($response['missing_printed'] ?? 0),
            'cancelled_count' => count($response['cancelled'] ?? []),
        ];

        $combined['files'][] = $fileSummary;
        $combined['calendar_count_checked'] = max($combined['calendar_count_checked'], $fileSummary['calendar_count_checked']);
        $combined['total_bookings_checked'] += $fileSummary['total_bookings_checked'];
        $combined['bookings_compared'] += $fileSummary['bookings_compared'];
        $combined['status_skipped'] += $fileSummary['status_skipped'];
        $combined['already_exists_skip'] += $fileSummary['already_exists_skip'];
        $combined['missing_printed'] += $fileSummary['missing_printed'];
        $combined['cancelled_count'] += $fileSummary['cancelled_count'];

        foreach (($response['calendar_ids_checked'] ?? []) as $calendarId) {
            $combined['calendar_ids_checked'][$calendarId] = true;
        }

        foreach (($response['all_bookings'] ?? $response['checked_bookings'] ?? []) as $booking) {
            $booking['_csv_name'] = $fileSummary['name'];
            $combined['all_bookings'][] = $booking;
        }

        foreach (($response['checked_bookings'] ?? []) as $booking) {
            $booking['_csv_name'] = $fileSummary['name'];
            $combined['checked_bookings'][] = $booking;
        }

        foreach (($response['already_exists'] ?? []) as $booking) {
            $booking['_csv_name'] = $fileSummary['name'];
            $combined['already_exists'][] = $booking;
        }

        foreach (($response['missing'] ?? []) as $booking) {
            $booking['_csv_name'] = $fileSummary['name'];
            $combined['missing'][] = $booking;
        }

        foreach (($response['cancelled'] ?? []) as $booking) {
            $booking['_csv_name'] = $fileSummary['name'];
            $combined['cancelled'][] = $booking;
        }
    }

    $combined['calendar_ids_checked'] = array_keys($combined['calendar_ids_checked']);

    if ($combined['files_processed'] === 0 && $combined['file_errors'] !== []) {
        $firstError = $combined['file_errors'][0]['message'] ?? 'No valid booking files were processed.';
        return [
            'status' => 'error',
            'error' => 'csv_errors',
            'message' => 'No valid booking files were processed. ' . $firstError,
            'file_errors' => $combined['file_errors'],
        ];
    }

    return $combined;
}

if (isset($_GET['code'])) {
    $code = trim((string) $_GET['code']);
    if ($code !== '') {
        $response = runAuthOnly($code);
        if (isset($response['status']) && $response['status'] === 'error') {
            $error = $response['message'] ?? 'Google login failed.';
            $authUrl = getGoogleAuthUrl();
        } else {
            $hasToken = true;
            $authUrl = null;
            header('Location: index.php');
            exit;
        }
    }
}
function bookingCopyJson(array $booking): string
{
    $copyBooking = [
        'Action' => $booking['Action'] ?? 'Booking',
        'Amount' => $booking['Amount'] ?? '',
        'City' => $booking['City'] ?? '',
        'Email' => $booking['Email'] ?? '',
        'End Date' => $booking['End Date'] ?? '',
        'Guests' => (int) ($booking['Guests'] ?? 0),
        'Name' => $booking['Name'] ?? '',
        'Phone' => $booking['Phone'] ?? '',
        'Ref' => $booking['Ref'] ?? '',
        'Source' => $booking['Source'] ?? '',
        'Start Date' => $booking['Start Date'] ?? '',
        'Tour' => $booking['Tour'] ?? '',
    ];

    return json_encode($copyBooking, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_code') {
    $code = trim((string) ($_POST['code'] ?? ''));

    if ($code === '') {
        $error = 'Authorization code cannot be empty.';
        $authUrl = getGoogleAuthUrl();
    } else {
        $response = runAuthOnly($code);
        if (isset($response['status']) && $response['status'] === 'error') {
            $error = $response['message'] ?? 'Google login failed.';
            $authUrl = getGoogleAuthUrl();
        } else {
            $hasToken = true;
            $authUrl = null;
            if (!empty($_SESSION['csv_files'])) {
                $resultData = combineResults(filterFilesByReportType($_SESSION['csv_files'], $selectedReportType));
                if (isset($resultData['status']) && $resultData['status'] === 'error') {
                    $error = $resultData['message'] ?? 'An API error occurred.';
                    $authUrl = ($resultData['error'] ?? '') === 'auth_required' ? getGoogleAuthUrl() : null;
                    $resultData = null;
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['booking_csv']) && $error === null) {
    $savedFiles = $_SESSION['csv_files'] ?? [];
    $newFilesUploaded = 0;
    $reportType = $selectedReportType;

    foreach (normalizeUploadedFiles($_FILES['booking_csv']) as $file) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Failed to upload ' . $file['name'] . '. Error code: ' . $file['error'];
            break;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeExtension = preg_match('/^[a-z0-9]{1,12}$/', $extension) ? $extension : 'upload';
        $tempPath = $uploadDir . '/' . uniqid('booking_', true) . '.' . $safeExtension;
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            $error = 'Failed to save the uploaded file: ' . $file['name'];
            break;
        }

        // Avoid duplicate filenames in the session list
        $existsKey = -1;
        foreach ($savedFiles as $key => $existingFile) {
            if ($existingFile['name'] === $file['name'] && normalizeReportType($existingFile['report_type'] ?? 'booking_request') === $reportType) {
                $existsKey = $key;
                break;
            }
        }

        if ($existsKey !== -1) {
            if (is_file($savedFiles[$existsKey]['path'])) {
                @unlink($savedFiles[$existsKey]['path']);
            }
            $savedFiles[$existsKey]['path'] = $tempPath;
            $savedFiles[$existsKey]['report_type'] = $reportType;
        } else {
            $savedFiles[] = [
                'path' => $tempPath,
                'name' => $file['name'],
                'report_type' => $reportType,
            ];
        }
        $newFilesUploaded++;
    }

    if ($error === null && $newFilesUploaded === 0 && empty($_SESSION['csv_files'])) {
        $error = 'Please select at least one file.';
    }

    if ($error === null) {
        $_SESSION['csv_files'] = $savedFiles;
        if (!$hasToken) {
            $authUrl = getGoogleAuthUrl();
        } else {
            $response = combineResults(filterFilesByReportType($savedFiles, $selectedReportType));
            if (isset($response['status']) && $response['status'] === 'error') {
                $error = $response['message'] ?? 'An API error occurred.';
                $authUrl = ($response['error'] ?? '') === 'auth_required' ? getGoogleAuthUrl() : null;
            } else {
                $resultData = $response;
            }
        }
    }
}

if ($resultData === null && $authUrl === null && $error === null && !empty($_SESSION['csv_files'])) {
    $response = combineResults(filterFilesByReportType($_SESSION['csv_files'], $selectedReportType));
    if (isset($response['status']) && $response['status'] === 'error') {
        $error = $response['message'] ?? 'An API error occurred.';
        $authUrl = ($response['error'] ?? '') === 'auth_required' ? getGoogleAuthUrl() : null;
    } else {
        $resultData = $response;
    }
}

if ($resultData === null) {
    $resultData = [
        'calendar_count_checked' => 0,
        'total_bookings_checked' => 0,
        'bookings_compared' => 0,
        'status_skipped' => 0,
        'already_exists_skip' => 0,
        'missing_printed' => 0,
        'missing' => [],
        'files' => [],
        'calendar_ids_checked' => [],
        'all_bookings' => [],
        'checked_bookings' => [],
        'cancelled' => [],
        'cancelled_count' => 0,
    ];
}

$fileCount = count($resultData['files'] ?? []);
$visibleSessionFiles = filterFilesByReportType($_SESSION['csv_files'] ?? [], $selectedReportType);
$fileLabel = $fileCount === 1 ? ($resultData['files'][0]['name'] ?? 'Report file') : $fileCount . ' files';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Calendar Comparison Portal</title>
    <link rel="icon" href="data:,">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --surface-color: rgba(22, 28, 45, 0.6);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --accent-primary: #3b82f6;
            --accent-secondary: #6366f1;
            --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg-color);
            background-image: radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.15) 0px, transparent 50%), radial-gradient(at 100% 100%, rgba(99, 102, 241, 0.15) 0px, transparent 50%);
            color: var(--text-primary);
            font-family: var(--font-family);
            min-height: 100vh;
            line-height: 1.5;
            padding: 2rem 1.5rem;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        header { text-align: center; margin-bottom: 2.5rem; animation: fadeInDown 0.6s ease-out; }
        h1 {
            font-size: 2.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff 30%, #a5b4fc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }
        .subtitle { color: var(--text-secondary); font-size: 1rem; }
        .glass-card {
            background: var(--surface-color);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
            margin-bottom: 2rem;
        }
        .alert { display: flex; align-items: center; gap: 0.75rem; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; font-size: 0.95rem; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; }
        .upload-zone {
            border: 2px dashed rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 3rem 2rem;
            text-align: center;
            cursor: pointer;
            position: relative;
            background: rgba(255, 255, 255, 0.02);
            transition: all 0.3s ease;
        }
        .upload-zone:hover, .upload-zone.dragover { border-color: var(--accent-primary); background: rgba(59, 130, 246, 0.04); }
        .file-input-hidden { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .upload-icon { font-size: 3rem; margin-bottom: 1rem; display: block; }
        .upload-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.25rem; }
        .upload-subtitle { font-size: 0.85rem; color: var(--text-secondary); }
        .upload-actions { display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap; margin-top: 1.5rem; }
        .selected-files { max-width: 720px; margin: 1.25rem auto 0; text-align: left; display: none; }
        .selected-files.is-visible { display: block; }
        .selected-files-title { color: var(--text-primary); font-weight: 600; margin-bottom: 0.75rem; }
        .selected-files-list { display: grid; gap: 0.5rem; }
        .selected-file { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 1rem; align-items: center; padding: 0.7rem 0.85rem; border-radius: 8px; background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-color); color: var(--text-primary); font-size: 0.9rem; }
        .selected-file-name { overflow-wrap: anywhere; }
        .selected-file-size { color: var(--text-secondary); white-space: nowrap; }
        .remove-file { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.25); color: #fca5a5; border-radius: 6px; cursor: pointer; padding: 0.25rem 0.5rem; }
        .upload-submit { margin-top: 1.25rem; width: 100%; display: none !important; }
        .upload-submit.is-visible { display: inline-flex !important; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; text-decoration: none; border: none; font-size: 0.95rem; }
        .btn-primary { background: var(--accent-gradient); color: #ffffff; box-shadow: 0 4px 14px 0 rgba(99, 102, 241, 0.4); }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px 0 rgba(99, 102, 241, 0.6); }
        .btn-secondary { background: rgba(255, 255, 255, 0.08); color: var(--text-primary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.15); }
        .btn-danger { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.25); border-color: rgba(239, 68, 68, 0.45); }
        .auth-container { max-width: 550px; margin: 2rem auto; text-align: center; }
        .auth-icon { font-size: 3.5rem; margin-bottom: 1rem; }
        .auth-steps { text-align: left; margin: 1.5rem 0; background: rgba(255, 255, 255, 0.03); padding: 1.25rem; border-radius: 12px; border: 1px solid var(--border-color); }
        .auth-step { margin-bottom: 0.75rem; font-size: 0.95rem; display: flex; gap: 0.5rem; }
        .step-number { font-weight: 700; color: var(--accent-primary); }
        .code-input-form { display: flex; flex-direction: column; gap: 1rem; margin-top: 1.5rem; }
        .input-text { width: 100%; padding: 0.85rem 1rem; border-radius: 8px; border: 1px solid var(--border-color); background: rgba(0, 0, 0, 0.2); color: #ffffff; font-family: var(--font-family); font-size: 0.95rem; }
        .input-text:focus { outline: none; border-color: var(--accent-primary); }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; }
        .dashboard-actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .csv-dropdown-container {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 0.4rem 1rem;
            gap: 0.5rem;
            margin: 0.2rem;
        }
        .csv-select-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .csv-select {
            background: transparent;
            border: none;
            color: #ffffff;
            font-size: 0.85rem;
            font-weight: 600;
            outline: none;
            cursor: pointer;
            font-family: inherit;
        }
        .csv-select option {
            background: #0f172a;
            color: #ffffff;
        }
        .file-info-strip {
            display: none;
            margin: 0 0 1.5rem;
            padding: 0.85rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.03);
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .file-info-strip.is-visible { display: block; }
        .file-info-strip strong { color: var(--text-primary); }
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        .pagination-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .pagination-btn:hover:not(:disabled) {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            transform: translateY(-1px);
        }
        .pagination-btn.active {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            box-shadow: 0 0 12px rgba(59, 130, 246, 0.4);
        }
        .pagination-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            padding: 1.25rem;
            border-radius: 12px;
            text-align: center;
            color: inherit;
            cursor: pointer;
            font-family: inherit;
        }
        .file-summary {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            padding: 1.25rem;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
        }
        .file-summary strong {
            font-size: 1rem;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            word-break: break-all;
        }
        .file-summary span {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .stat-card:hover, .stat-card.is-active { border-color: rgba(59, 130, 246, 0.55); background: rgba(59, 130, 246, 0.08); }
        .stat-detail-panel {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(10, 15, 30, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            box-sizing: border-box;
        }
        .stat-detail-panel.is-visible {
            display: flex;
        }
        .stat-detail-content {
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            width: 100%;
            max-width: 960px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: modalFadeIn 0.25s ease-out;
            overflow: hidden;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .detail-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            background: rgba(255, 255, 255, 0.01);
        }
        .detail-close { padding: 0.45rem 0.75rem; }
        .detail-table-wrap {
            padding: 1.5rem;
            overflow-x: auto;
            overflow-y: auto;
            max-height: 100%;
        }
        .detail-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .detail-table th, .detail-table td { padding: 0.65rem 0.75rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06); text-align: left; vertical-align: top; }
        .detail-table th { color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
        .detail-table td { color: var(--text-primary); }
        .status-pill { display: inline-flex; padding: 0.22rem 0.5rem; border-radius: 999px; font-size: 0.78rem; font-weight: 700; white-space: nowrap; border: 1px solid rgba(255,255,255,0.12); background: rgba(255,255,255,0.06); }
        .status-pill.ok { color: #34d399; background: rgba(16,185,129,0.12); border-color: rgba(16,185,129,0.25); }
        .status-pill.warn { color: #fbbf24; background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.25); }
        .status-pill.bad { color: #f87171; background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.25); }
        .detail-note { color: var(--text-secondary); margin-bottom: 0; }
        .stat-val { font-size: 2rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-subtext { color: var(--text-secondary); font-size: 0.78rem; margin-top: 0.45rem; }
        .stat-blue { color: var(--accent-primary); } .stat-orange { color: var(--warning-color); } .stat-red { color: var(--danger-color); } .stat-green { color: var(--success-color); } .stat-purple { color: #a855f7; }
        .file-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .file-section-title { font-size: 1.1rem; margin: 0 0 1rem; color: var(--text-primary); }
        .file-summary strong { display: block; margin-bottom: 0.5rem; overflow-wrap: anywhere; }
        .file-summary span { color: var(--text-secondary); display: block; font-size: 0.9rem; }
        .skipped-files { padding: 1rem; border-color: rgba(245, 158, 11, 0.35); }
        .skipped-files summary { cursor: pointer; color: #fbbf24; font-weight: 700; }
        .skipped-files-list { margin-top: 0.85rem; }
        .search-container { margin-bottom: 1.5rem; position: relative; }
        .search-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-secondary); font-size: 1.1rem; }
        .search-input { width: 100%; padding: 0.85rem 1rem 0.85rem 2.75rem; border-radius: 8px; border: 1px solid var(--border-color); background: rgba(255, 255, 255, 0.03); color: #ffffff; font-size: 0.95rem; }
        .search-input:focus { outline: none; border-color: var(--accent-primary); background: rgba(255, 255, 255, 0.05); }
        .bookings-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem; }
        .booking-card { background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s ease; position: relative; overflow: hidden; animation: cardEntrance 0.4s ease-out; animation-fill-mode: both; }
        .booking-card:hover { transform: translateY(-3px); border-color: rgba(99, 102, 241, 0.25); background: rgba(255, 255, 255, 0.04); box-shadow: 0 4px 20px 0 rgba(0, 0, 0, 0.2); }
        .booking-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--danger-color); }
        .booking-card.cancelled::before { background: #a855f7; }
        .booking-card.cancelled:hover { border-color: rgba(168, 85, 247, 0.35); }
        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; gap: 0.75rem; }
        .traveler-name { font-weight: 600; font-size: 1.1rem; color: #ffffff; margin-bottom: 0.15rem; }
        .ref-badge { background: rgba(239, 68, 68, 0.12); color: #f87171; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.8rem; font-family: monospace; font-weight: 600; border: 1px solid rgba(239, 68, 68, 0.2); white-space: nowrap; }
        .tour-title { font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1rem; line-height: 1.4; flex-grow: 1; }
        .card-footer { border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 0.75rem; display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.85rem; }
        .footer-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        .footer-label { color: var(--text-secondary); }
        .footer-val { font-weight: 500; text-align: right; overflow-wrap: anywhere; }
        .source-tag { padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: rgba(156, 163, 175, 0.15); color: #d1d5db; border: 1px solid rgba(156, 163, 175, 0.2); }
        .copy-object-panel { margin-top: 1rem; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 8px; background: rgba(0, 0, 0, 0.22); overflow: hidden; }
        .copy-object-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.65rem 0.75rem; border-bottom: 1px solid rgba(255, 255, 255, 0.07); color: var(--text-secondary); font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
        .copy-object-button { padding: 0.4rem 0.65rem; font-size: 0.78rem; border-radius: 6px; white-space: nowrap; }
        .copy-object-code { margin: 0; padding: 0.85rem; max-height: 260px; overflow: auto; color: #dbeafe; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 0.78rem; line-height: 1.45; white-space: pre; tab-size: 2; }
        .empty-state { text-align: center; padding: 4rem 2rem; color: var(--text-secondary); }
        .empty-icon { font-size: 4rem; margin-bottom: 1.5rem; display: block; }
        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes cardEntrance { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Google Calendar Sync Check</h1>
            <p class="subtitle" id="pageSubtitle">Upload one or many CSV booking reports, then connect Google Calendar to compare live events.</p>
        </header>

        <?php if ($error !== null): ?>
            <div class="alert alert-error"><span>!</span><div><?php echo htmlspecialchars($error); ?></div></div>
            <?php if (!empty($response['file_errors'])): ?>
                <div class="glass-card" style="padding: 1rem; border-color: rgba(239, 68, 68, 0.3);">
                    <?php foreach ($response['file_errors'] as $fileError): ?>
                        <div style="color: #fca5a5; margin: 0.35rem 0;">
                            <strong><?php echo htmlspecialchars($fileError['name'] ?? 'File'); ?>:</strong>
                            <?php echo htmlspecialchars($fileError['message'] ?? 'Could not process this file.'); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($authUrl !== null): ?>
            <div class="glass-card auth-container">
                <div class="auth-icon">🔑</div>
                <h2>Google Calendar Authorization Required</h2>
                <p class="subtitle" style="margin-top: 0.5rem;">Connect your Google Calendar to compare booking reports with live calendar events.</p>
                <div class="auth-steps">
                    <div class="auth-step"><span class="step-number">1.</span><span>Click the button below to sign in with Google.</span></div>
                    <div class="auth-step"><span class="step-number">2.</span><span>Allow calendar read access when prompted.</span></div>
                    <div class="auth-step"><span class="step-number">3.</span><span>You will be logged in and automatically redirected back.</span></div>
                </div>
                <a href="<?php echo htmlspecialchars($authUrl); ?>" class="btn btn-primary" style="margin-top: 1rem; width: 100%;">Log in with Google Account</a>
            </div>
        <?php else: ?>
            <div class="dashboard-header">
                <div class="csv-dropdown-container">
                    <span class="csv-select-label" id="fileSelectLabel">CSV File:</span>
                    <select id="csvSelect" class="csv-select" onchange="currentPage = 1; filterDashboard()">
                        <option value="all" 
                                data-total="<?php echo (int) $resultData['total_bookings_checked']; ?>"
                                data-compared="<?php echo (int) ($resultData['bookings_compared'] ?? 0); ?>"
                                data-skipped="<?php echo (int) ($resultData['status_skipped'] ?? 0); ?>"
                                data-exists="<?php echo (int) $resultData['already_exists_skip']; ?>"
                                data-missing="<?php echo (int) $resultData['missing_printed']; ?>"
                                data-cancelled="<?php echo (int) ($resultData['cancelled_count'] ?? 0); ?>">
                            All CSV Files (<?php echo count($resultData['files'] ?? []); ?>)
                        </option>
                        <?php foreach (($resultData['files'] ?? []) as $file): ?>
                            <option value="<?php echo htmlspecialchars($file['name']); ?>"
                                    data-total="<?php echo (int) $file['total_bookings_checked']; ?>"
                                    data-compared="<?php echo (int) ($file['bookings_compared'] ?? 0); ?>"
                                    data-skipped="<?php echo (int) ($file['status_skipped'] ?? 0); ?>"
                                    data-exists="<?php echo (int) $file['already_exists_skip']; ?>"
                                    data-missing="<?php echo (int) $file['missing_printed']; ?>"
                                    data-cancelled="<?php echo (int) ($file['cancelled_count'] ?? 0); ?>">
                                <?php echo htmlspecialchars($file['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="dashboard-actions">
                    <div class="csv-dropdown-container">
                        <span class="csv-select-label">Report Type:</span>
                        <select id="reportTypeSelect" class="csv-select" onchange="handleReportTypeChange()">
                            <option value="booking_request" <?php echo $selectedReportType === 'booking_request' ? 'selected' : ''; ?>>Booking Request</option>
                            <option value="booking_agent_sheet" <?php echo $selectedReportType === 'booking_agent_sheet' ? 'selected' : ''; ?>>Booking Agent Sheet</option>
                        </select>
                    </div>
                    <form id="uploadForm" method="POST" enctype="multipart/form-data" style="display: none;">
                        <input type="hidden" id="reportTypeInput" name="report_type" value="<?php echo htmlspecialchars($selectedReportType); ?>"><input id="csvInput" type="file" name="booking_csv[]" multiple required>
                    </form>
                    <label for="csvInput" id="uploadButtonLabel" class="btn btn-secondary" style="cursor: pointer; margin: 0; user-select: none;">Upload CSVs</label>
                    <?php if (!empty($_SESSION['csv_files'])): ?>
                        <a href="index.php?reset=1" class="btn btn-secondary">Clear Files</a>
                    <?php endif; ?>
                    <a href="index.php?logout=1" class="btn btn-danger">Logout</a>
                </div>
            </div>
            <div id="fileInfoPanel" class="file-info-strip"></div>

            <div class="stats-grid">
                <button type="button" class="stat-card" data-detail-target="calendars"><div class="stat-val stat-blue"><?php echo (int) $resultData['calendar_count_checked']; ?></div><div class="stat-label">Calendars Checked</div></button>
                <button type="button" class="stat-card" data-detail-target="total"><div class="stat-val stat-green"><?php echo (int) $resultData['total_bookings_checked']; ?></div><div class="stat-label" id="totalStatLabel">Total Bookings</div><div class="stat-subtext" id="comparedBreakdown"><?php echo (int) ($resultData['bookings_compared'] ?? 0); ?> compared, <?php echo (int) ($resultData['status_skipped'] ?? 0); ?> skipped by status</div></button>
                <button type="button" class="stat-card" data-detail-target="exists"><div class="stat-val stat-orange"><?php echo (int) $resultData['already_exists_skip']; ?></div><div class="stat-label">Already on Calendar</div></button>
                <button type="button" class="stat-card" data-detail-target="missing"><div class="stat-val stat-red"><?php echo (int) $resultData['missing_printed']; ?></div><div class="stat-label" id="missingStatLabel">Missing Bookings</div></button>
                <button type="button" class="stat-card" data-detail-target="cancelled"><div class="stat-val stat-purple"><?php echo (int) ($resultData['cancelled_count'] ?? 0); ?></div><div class="stat-label" id="cancelledStatLabel">Cancelled Bookings</div></button>
            </div>

            <div class="stat-detail-panel" id="detail-calendars">
                <div class="stat-detail-content">
                    <div class="detail-header">
                        <div>
                            <h2 style="font-size: 1.25rem; margin-bottom: 0.25rem; margin-top: 0;">Calendars Checked</h2>
                            <p class="detail-note">These are the Google Calendar IDs searched for matching booking references.</p>
                        </div>
                        <button type="button" class="btn btn-secondary detail-close">Close</button>
                    </div>
                    <div class="detail-table-wrap">
                        <table class="detail-table">
                            <thead><tr><th>#</th><th>Calendar ID</th></tr></thead>
                            <tbody>
                                <?php foreach (($resultData['calendar_ids_checked'] ?? []) as $idx => $calendarId): ?>
                                    <tr><td><?php echo $idx + 1; ?></td><td><?php echo htmlspecialchars($calendarId); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php
                $detailSets = [
                    'total' => ['title' => 'Total Bookings Uploaded', 'note' => 'All booking rows read from valid CSV reports, with CSV status and calendar result.', 'rows' => $resultData['all_bookings'] ?? $resultData['checked_bookings'] ?? []],
                    'exists' => ['title' => 'Already On Calendar', 'note' => 'CSV bookings whose booking references exist in one of the Google Calendars.', 'rows' => $resultData['already_exists'] ?? []],
                    'missing' => ['title' => 'Missing Bookings', 'note' => 'CSV bookings whose references do not exist in Google Calendar events.', 'rows' => $resultData['missing'] ?? []],
                    'cancelled' => ['title' => 'Cancelled Bookings', 'note' => 'CSV bookings with a cancelled status (skipped for calendar synchronization checks).', 'rows' => $resultData['cancelled'] ?? []],
                ];
            ?>
            <?php foreach ($detailSets as $detailId => $detail): ?>
                <div class="stat-detail-panel" id="detail-<?php echo htmlspecialchars($detailId); ?>">
                    <div class="stat-detail-content">
                        <div class="detail-header">
                            <div>
                                <h2 style="font-size: 1.25rem; margin-bottom: 0.25rem; margin-top: 0;"><?php echo htmlspecialchars($detail['title']); ?></h2>
                                <p class="detail-note"><?php echo htmlspecialchars($detail['note']); ?></p>
                            </div>
                            <button type="button" class="btn btn-secondary detail-close">Close</button>
                        </div>
                        <div class="detail-table-wrap">
                            <table class="detail-table">
                                <thead><tr><th>CSV File</th><th>Ref</th><th>Status</th><th>Calendar Check</th><th>Calendar Result</th><th>Name</th><th>Travel Date</th><th>Guests</th><th>Tour</th><th>Source</th></tr></thead>
                                <tbody>
                                    <?php if (empty($detail['rows'])): ?>
                                        <tr><td colspan="10" style="text-align: center; padding: 2rem; color: var(--text-secondary);">No records in this group.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($detail['rows'] as $booking): ?>
                                            <?php
                                                $csvStatus = (string) ($booking['CSV Status'] ?? '');
                                                $calendarCheck = (string) ($booking['Calendar Check'] ?? 'Checked');
                                                $calendarResult = (string) ($booking['Calendar Result'] ?? ($detailId === 'missing' ? 'Missing from calendar' : ($detailId === 'exists' ? 'Already on calendar' : 'Checked')));
                                                $statusClass = in_array($csvStatus, ['Confirmed', 'Amended'], true) ? 'ok' : (stripos($csvStatus, 'cancel') !== false ? 'bad' : 'warn');
                                                $resultClass = stripos($calendarResult, 'missing') !== false ? 'bad' : (stripos($calendarResult, 'already') !== false ? 'ok' : 'warn');
                                            ?>
                                            <tr data-csv="<?php echo htmlspecialchars($booking['_csv_name'] ?? 'Report.csv'); ?>">
                                                <td><?php echo htmlspecialchars($booking['_csv_name'] ?? 'Report.csv'); ?></td>
                                                <td><?php echo htmlspecialchars($booking['Ref'] ?? ''); ?></td>
                                                <td><span class="status-pill <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars($csvStatus !== '' ? $csvStatus : 'Unknown'); ?></span></td>
                                                <td><span class="status-pill <?php echo $calendarCheck === 'Checked' ? 'ok' : 'warn'; ?>"><?php echo htmlspecialchars($calendarCheck); ?></span></td>
                                                <td><span class="status-pill <?php echo htmlspecialchars($resultClass); ?>"><?php echo htmlspecialchars($calendarResult); ?></span></td>
                                                <td><?php echo htmlspecialchars($booking['Name'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($booking['Start Date'] ?? ''); ?></td>
                                                <td><?php echo (int) ($booking['Guests'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars($booking['Tour'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($booking['Source'] ?? ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($resultData['file_errors'])): ?>
                <details class="glass-card skipped-files">
                    <summary>Skipped files (<?php echo count($resultData['file_errors']); ?>)</summary>
                    <div class="skipped-files-list">
                        <?php foreach ($resultData['file_errors'] as $fileError): ?>
                            <div style="color: var(--text-secondary); margin: 0.35rem 0;">
                                <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($fileError['name'] ?? 'File'); ?>:</strong>
                                <?php echo htmlspecialchars($fileError['message'] ?? 'Could not process this file.'); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if (!empty($resultData['files'])): ?>
                <h2 class="file-section-title">Processed files</h2>
                <div class="file-grid">
                    <?php foreach ($resultData['files'] as $file): ?>
                        <div class="file-summary">
                            <strong><?php echo htmlspecialchars($file['name']); ?></strong>
                            <span>Total: <?php echo (int) $file['total_bookings_checked']; ?></span>
                            <span>Compared: <?php echo (int) ($file['bookings_compared'] ?? 0); ?></span>
                            <span>Skipped by status: <?php echo (int) ($file['status_skipped'] ?? 0); ?></span>
                            <span>On calendar: <?php echo (int) $file['already_exists_skip']; ?></span>
                            <span>Missing: <?php echo (int) $file['missing_printed']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="glass-card" style="padding-top: 1.5rem;">
                <?php 
                $gridBookings = array_merge(
                    array_map(function($b) { $b['_grid_type'] = 'missing'; return $b; }, $resultData['missing'] ?? []),
                    array_map(function($b) { $b['_grid_type'] = 'cancelled'; return $b; }, $resultData['cancelled'] ?? [])
                );
                usort($gridBookings, function($a, $b) {
                    $dateA = $a['Start Date'] ?? '';
                    $dateB = $b['Start Date'] ?? '';
                    return strcmp($dateA, $dateB);
                });
                ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.5rem;">
                    <h2 id="missingSectionTitle" style="font-size: 1.35rem; font-weight: 600;">Missing &amp; Cancelled Calendar Events</h2>
                    <span style="color: var(--text-secondary); font-size: 0.9rem;" id="filterCount">Showing <?php echo count($gridBookings); ?> bookings</span>
                </div>

                <div class="search-container">
                    <span class="search-icon">Search</span>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search by name, reference, city, tour, source, phone, amount, or file..." onkeyup="filterBookings()">
                </div>

                <div class="bookings-grid" id="bookingsGrid">
                    <?php if (empty($visibleSessionFiles)): ?>
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <span class="empty-icon">📥</span>
                            <h3 id="emptyUploadTitle">No CSV Files Uploaded</h3>
                            <p class="subtitle" id="emptyUploadText" style="margin-top: 0.5rem;">Click the <strong>Upload CSVs</strong> button at the top right to upload your booking CSV reports and compare them with your Google Calendar.</p>
                        </div>
                    <?php elseif (empty($gridBookings)): ?>
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <span class="empty-icon">OK</span>
                            <h3>All Bookings Logged</h3>
                            <p class="subtitle" style="margin-top: 0.5rem;">Every checked booking in the uploaded file<?php echo $fileCount === 1 ? '' : 's'; ?> exists on your Google Calendar.</p>
                        </div>
                    <?php else: ?>
                        <?php $idx = 0; foreach ($gridBookings as $booking): $idx++; ?>
                            <?php
                                $searchText = strtolower(implode(' ', array_map('strval', $booking)));
                                $copyJson = bookingCopyJson($booking);
                            ?>
                            <div class="booking-card <?php echo (($booking['_grid_type'] ?? '') === 'cancelled') ? 'cancelled' : ''; ?>" style="animation-delay: <?php echo ($idx < 30) ? ($idx * 0.03) : 0; ?>s;" data-search="<?php echo htmlspecialchars($searchText); ?>" data-csv="<?php echo htmlspecialchars($booking['_csv_name'] ?? 'Report.csv'); ?>">
                                <div>
                                    <div class="card-header">
                                        <div class="traveler-name"><?php echo htmlspecialchars($booking['Name'] ?? 'Unknown Traveler'); ?></div>
                                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                                            <?php if (($booking['_grid_type'] ?? '') === 'cancelled'): ?>
                                                <span class="status-pill bad" style="font-size: 0.75rem;">CANCELLED</span>
                                            <?php endif; ?>
                                            <div class="ref-badge" <?php echo (($booking['_grid_type'] ?? '') === 'cancelled') ? 'style="background: rgba(168, 85, 247, 0.12); color: #c084fc; border-color: rgba(168, 85, 247, 0.2);"' : ''; ?>><?php echo htmlspecialchars($booking['Ref'] ?? 'N/A'); ?></div>
                                        </div>
                                    </div>
                                    <div class="tour-title"><?php echo htmlspecialchars($booking['Tour'] ?? 'No Tour Specified'); ?></div>
                                </div>
                                <div class="card-footer">
                                    <div class="footer-row"><span class="footer-label">CSV File</span><span class="footer-val"><?php echo htmlspecialchars($booking['_csv_name'] ?? 'Report.csv'); ?></span></div>
                                    <div class="footer-row"><span class="footer-label">Travel Date</span><span class="footer-val"><?php echo htmlspecialchars($booking['Start Date'] ?? 'N/A'); ?></span></div>
                                    <div class="footer-row"><span class="footer-label">Guests / Contact</span><span class="footer-val"><?php echo (int) ($booking['Guests'] ?? 0); ?><?php echo !empty($booking['Phone']) ? ' - ' . htmlspecialchars($booking['Phone']) : ''; ?></span></div>
                                    <div class="footer-row"><span class="footer-label">Amount</span><span class="footer-val"><?php echo htmlspecialchars($booking['Amount'] ?? '0.00'); ?></span></div>
                                    <div class="footer-row"><span class="footer-label">Source</span><span class="source-tag"><?php echo htmlspecialchars($booking['Source'] ?? 'Unknown'); ?></span></div>
                                </div>
                                <div class="copy-object-panel">
                                    <div class="copy-object-header">
                                        <span>Booking Object</span>
                                        <button type="button" class="btn btn-secondary copy-object-button" data-copy-object>Copy</button>
                                    </div>
                                    <pre class="copy-object-code"><code><?php echo htmlspecialchars($copyJson); ?></code></pre>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div id="paginationContainer" class="pagination-container"></div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const csvInput = document.getElementById('csvInput');
        if (csvInput) {
            csvInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    document.getElementById('uploadForm').submit();
                }
            });
        }

        function closeStatDetails() {
            document.querySelectorAll('.stat-card').forEach(item => item.classList.remove('is-active'));
            document.querySelectorAll('.stat-detail-panel').forEach(panel => panel.classList.remove('is-visible'));
            document.body.style.overflow = '';
        }

        document.querySelectorAll('[data-detail-target]').forEach(card => {
            card.addEventListener('click', () => {
                const target = card.dataset.detailTarget;
                const panel = document.getElementById(`detail-${target}`);
                const willOpen = panel && !panel.classList.contains('is-visible');
                closeStatDetails();
                if (willOpen) {
                    card.classList.add('is-active');
                    panel.classList.add('is-visible');
                    document.body.style.overflow = 'hidden';
                }
            });
        });

        document.querySelectorAll('.detail-close').forEach(button => {
            button.addEventListener('click', closeStatDetails);
        });

        document.querySelectorAll('.stat-detail-panel').forEach(panel => {
            panel.addEventListener('click', (e) => {
                if (e.target === panel) {
                    closeStatDetails();
                }
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeStatDetails();
            }
        });

        document.querySelectorAll('[data-copy-object]').forEach(button => {
            button.addEventListener('click', async () => {
                const panel = button.closest('.copy-object-panel');
                const code = panel ? panel.querySelector('.copy-object-code') : null;
                const text = code ? code.textContent : '';

                if (text === '') {
                    return;
                }

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(text);
                    } else {
                        const textarea = document.createElement('textarea');
                        textarea.value = text;
                        textarea.setAttribute('readonly', '');
                        textarea.style.position = 'fixed';
                        textarea.style.opacity = '0';
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                    }

                    const originalLabel = button.textContent;
                    button.textContent = 'Copied';
                    button.disabled = true;
                    setTimeout(() => {
                        button.textContent = originalLabel;
                        button.disabled = false;
                    }, 1200);
                } catch (error) {
                    button.textContent = 'Copy failed';
                    setTimeout(() => {
                        button.textContent = 'Copy';
                    }, 1600);
                }
            });
        });

        let currentPage = 1;
        const itemsPerPage = 12;

        function handleReportTypeChange() {
            const reportTypeSelect = document.getElementById('reportTypeSelect');
            const reportTypeInput = document.getElementById('reportTypeInput');
            if (!reportTypeSelect) return;
            if (reportTypeInput) reportTypeInput.value = reportTypeSelect.value;
            window.location.href = `index.php?report_type=${encodeURIComponent(reportTypeSelect.value)}`;
        }
        function updateReportTypeUi() {
            const reportTypeSelect = document.getElementById('reportTypeSelect');
            if (!reportTypeSelect) return;

            const isAgentSheet = reportTypeSelect.value === 'booking_agent_sheet';
            const labels = isAgentSheet ? {
                subtitle: 'Upload a Booking Agent Excel sheet, then connect Google Calendar to compare live events.',
                fileSelect: 'Excel File:',
                allFiles: 'All Excel Files',
                upload: 'Upload Excel',
                accept: '',
                total: 'Total Rows',
                missing: 'Missing Agent Bookings',
                cancelled: 'Skipped Rows',
                section: 'Missing Booking Agent Sheet Events',
                search: 'Search by name, booking ref, supplier ref, product, email, phone, or Excel file...',
                emptyTitle: 'No Excel Files Uploaded',
                emptyText: 'Click the Upload Excel button at the top right to upload your booking agent sheet and compare it with your Google Calendar.'
            } : {
                subtitle: 'Upload one or many CSV booking reports, then connect Google Calendar to compare live events.',
                fileSelect: 'CSV File:',
                allFiles: 'All CSV Files',
                upload: 'Upload CSVs',
                accept: '',
                total: 'Total Bookings',
                missing: 'Missing Bookings',
                cancelled: 'Cancelled Bookings',
                section: 'Missing & Cancelled Calendar Events',
                search: 'Search by name, reference, city, tour, source, phone, amount, or file...',
                emptyTitle: 'No CSV Files Uploaded',
                emptyText: 'Click the Upload CSVs button at the top right to upload your booking CSV reports and compare them with your Google Calendar.'
            };

            const setText = (id, value) => {
                const element = document.getElementById(id);
                if (element) element.textContent = value;
            };

            setText('pageSubtitle', labels.subtitle);
            setText('fileSelectLabel', labels.fileSelect);
            setText('uploadButtonLabel', labels.upload);
            setText('totalStatLabel', labels.total);
            setText('missingStatLabel', labels.missing);
            setText('cancelledStatLabel', labels.cancelled);
            setText('missingSectionTitle', labels.section);
            setText('emptyUploadTitle', labels.emptyTitle);
            setText('emptyUploadText', labels.emptyText);

            const csvSelect = document.getElementById('csvSelect');
            if (csvSelect && csvSelect.options.length > 0) {
                csvSelect.options[0].textContent = `${labels.allFiles} (${Math.max(csvSelect.options.length - 1, 0)})`;
            }

            const searchInput = document.getElementById('searchInput');
            if (searchInput) searchInput.placeholder = labels.search;

            const csvInput = document.getElementById('csvInput');
            if (csvInput) csvInput.removeAttribute('accept');

            const reportTypeInput = document.getElementById('reportTypeInput');
            if (reportTypeInput) reportTypeInput.value = reportTypeSelect.value;
        }

        function updateReportTypePanel() {
            updateReportTypeUi();
            const csvSelect = document.getElementById('csvSelect');
            const reportTypeSelect = document.getElementById('reportTypeSelect');
            const panel = document.getElementById('fileInfoPanel');

            if (!csvSelect || !reportTypeSelect || !panel) return;

            const selectedOption = csvSelect.options[csvSelect.selectedIndex];
            const selectedFile = csvSelect.value === 'all' ? (reportTypeSelect.value === 'booking_agent_sheet' ? 'All Excel Files' : 'All CSV Files') : csvSelect.value;
            const total = selectedOption ? (selectedOption.dataset.total || '0') : '0';
            const compared = selectedOption ? (selectedOption.dataset.compared || '0') : '0';

            const bookingRequestFields = [
                'Booking Reference',
                'Net Price',
                'Status',
                'Travel Date',
                'Lead traveler Name',
                'Lead traveler Contact Info',
                'Number of Passengers',
                'Product Name',
                'Tour Grade Code',
                'Booking Source'
            ].join(', ');

            const bookingAgentFields = [
                'Date',
                'Booking Ref #',
                'Supplier Ref #',
                'Product',
                'Option',
                "Traveler's First Name",
                "Traveler's Last Name",
                'Email',
                'Phone',
                'Adult/Senior/Student/Youth/Child/Infant',
                'Net Price',
                'Language',
                'Reseller Information'
            ].join(', ');

            if (reportTypeSelect.value === 'booking_agent_sheet') {
                panel.innerHTML = `<strong>Booking Agent Sheet:</strong> use the Excel fields: ${bookingAgentFields}.`;
            } else {
                panel.innerHTML = `<strong>Booking Request:</strong> existing script runs for ${selectedFile} (${total} total, ${compared} compared). Required CSV fields: ${bookingRequestFields}.`;
            }

            panel.classList.add('is-visible');
        }

        function filterDashboard() {
            const csvSelect = document.getElementById('csvSelect');
            if (!csvSelect) return;

            const selectedOption = csvSelect.options[csvSelect.selectedIndex];
            if (!selectedOption) return;

            const selectedCsv = csvSelect.value.trim().toLowerCase();
            const originalSelectedCsv = csvSelect.value;

            // 1. Update top stats boxes
            const statTotalVal = document.querySelector('[data-detail-target="total"] .stat-val');
            const statExistsVal = document.querySelector('[data-detail-target="exists"] .stat-val');
            const statMissingVal = document.querySelector('[data-detail-target="missing"] .stat-val');
            const statCancelledVal = document.querySelector('[data-detail-target="cancelled"] .stat-val');
            const comparedBreakdown = document.getElementById('comparedBreakdown');

            if (statTotalVal) statTotalVal.textContent = selectedOption.dataset.total || '0';
            if (statExistsVal) statExistsVal.textContent = selectedOption.dataset.exists || '0';
            if (statMissingVal) statMissingVal.textContent = selectedOption.dataset.missing || '0';
            if (statCancelledVal) statCancelledVal.textContent = selectedOption.dataset.cancelled || '0';
            if (comparedBreakdown) {
                comparedBreakdown.textContent = `${selectedOption.dataset.compared || '0'} compared, ${selectedOption.dataset.skipped || '0'} skipped by status`;
            }
            updateReportTypePanel();

            // 2. Filter booking cards under "Missing Calendar Events"
            const searchInput = document.getElementById('searchInput');
            const searchQuery = searchInput ? searchInput.value.toLowerCase().trim() : '';
            const cards = Array.from(document.querySelectorAll('.booking-card'));
            
            // First, find all cards matching filters
            const matchedCards = cards.filter(card => {
                const cardCsv = (card.dataset.csv || '').trim();
                const matchesCsv = (selectedCsv === 'all' || selectedCsv === '' || cardCsv === originalSelectedCsv);
                const matchesSearch = searchQuery === '' || (card.dataset.search || '').includes(searchQuery);
                return matchesCsv && matchesSearch;
            });

            const totalMatched = matchedCards.length;
            const totalPages = Math.ceil(totalMatched / itemsPerPage) || 1;

            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            // Hide all cards first, then show only the ones on the current page
            cards.forEach(card => card.style.display = 'none');

            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            
            matchedCards.slice(startIndex, endIndex).forEach(card => {
                card.style.display = 'flex';
            });

            const filterCountElement = document.getElementById('filterCount');
            if (filterCountElement) {
                filterCountElement.textContent = `Showing ${totalMatched > 0 ? startIndex + 1 : 0}-${Math.min(endIndex, totalMatched)} of ${totalMatched} bookings`;
            }

            // Render Pagination controls
            renderPagination(totalPages);

            // 3. Filter modal tables rows
            const modalRows = document.querySelectorAll('.detail-table tbody tr');
            modalRows.forEach(row => {
                if (row.cells.length < 2) return; // skip "No records" row if present
                const rowCsv = (row.dataset.csv || '').trim();
                const matchesCsv = (selectedCsv === 'all' || selectedCsv === '' || rowCsv === originalSelectedCsv);
                if (matchesCsv) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function renderPagination(totalPages) {
            const container = document.getElementById('paginationContainer');
            if (!container) return;

            container.innerHTML = '';

            if (totalPages <= 1) return;

            // Previous Button
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pagination-btn';
            prevBtn.innerHTML = '← Previous';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.addEventListener('click', () => {
                currentPage--;
                filterDashboard();
                document.getElementById('bookingsGrid').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
            container.appendChild(prevBtn);

            // Page numbers
            for (let i = 1; i <= totalPages; i++) {
                if (totalPages > 8) {
                    // Only show first page, last page, and pages near the current page
                    if (i !== 1 && i !== totalPages && Math.abs(i - currentPage) > 2) {
                        if (i === 2 || i === totalPages - 1) {
                            const ellipsis = document.createElement('span');
                            ellipsis.textContent = '...';
                            ellipsis.style.color = 'var(--text-secondary)';
                            ellipsis.style.padding = '0 0.25rem';
                            if (container.lastChild && container.lastChild.textContent !== '...') {
                                container.appendChild(ellipsis);
                            }
                        }
                        continue;
                    }
                }

                const pageBtn = document.createElement('button');
                pageBtn.className = 'pagination-btn' + (i === currentPage ? ' active' : '');
                pageBtn.textContent = i;
                pageBtn.addEventListener('click', () => {
                    currentPage = i;
                    filterDashboard();
                    document.getElementById('bookingsGrid').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
                container.appendChild(pageBtn);
            }

            // Next Button
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pagination-btn';
            nextBtn.innerHTML = 'Next →';
            nextBtn.disabled = (currentPage === totalPages);
            nextBtn.addEventListener('click', () => {
                currentPage++;
                filterDashboard();
                document.getElementById('bookingsGrid').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
            container.appendChild(nextBtn);
        }

        function filterBookings() {
            currentPage = 1; // reset page when search query changes
            filterDashboard();
        }

        // Initialize dashboard state on load
        document.addEventListener('DOMContentLoaded', () => {
            filterDashboard();
        });
    </script>
</body>
</html>
