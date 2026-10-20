<?php
declare(strict_types=1);
function users(): array
{
    return json_decode((string)@file_get_contents(APP_ROOT . '/.private/users.json'), true) ?: [];
}

function attempt_login(string $username, string $password): bool
{
    $file = fopen(APP_ROOT . '/.private/login-attempts.json', 'c+');
    if (!$file || !flock($file, LOCK_EX)) throw new RuntimeException('Die Anmeldung ist momentan nicht verfügbar.');
    try {
        $attempts = json_decode(stream_get_contents($file), true) ?: [];
        $attempts = array_filter($attempts, fn($entry) => $entry['until'] > time());
        $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'local') . '|' . $username);
        $entry = $attempts[$key] ?? ['count' => 0, 'until' => time() + 900];
        if ($entry['count'] >= 8) throw new RuntimeException('Zu viele Anmeldeversuche. Bitte in 15 Minuten erneut versuchen.');
        $hash = users()[$username] ?? '';
        // Always perform a password comparison, including for unknown accounts.
        $ok = password_verify($password, $hash ?: '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi') && $hash !== '';
        if ($ok) unset($attempts[$key]);
        else { $entry['count']++; $attempts[$key] = $entry; }
        rewind($file);
        ftruncate($file, 0);
        fwrite($file, json_encode($attempts, JSON_THROW_ON_ERROR));
        fflush($file);
        return $ok;
    } finally { flock($file, LOCK_UN); fclose($file); }
}

function require_login(): void
{
    if (!isset($_SESSION['user'])) redirect('index.php');
}
