<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = rtrim(dirname($script), '/');
    if ($base === '.' || $base === '/') {
        $base = '';
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path), true, 303);
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Sessionen har gått ut. Gå tillbaka och försök igen.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flashes) ? $flashes : [];
}

function post_string(string $key, int $maxLength = 255): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return text_slice($value, 0, $maxLength);
}

function text_slice(string $value, int $offset, int $length): string
{
    return function_exists('mb_substr')
        ? mb_substr($value, $offset, $length, 'UTF-8')
        : substr($value, $offset, $length);
}

function text_upper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function nullable_float(string $key): ?float
{
    $raw = str_replace(',', '.', trim((string) ($_POST[$key] ?? '')));
    if ($raw === '') {
        return null;
    }
    if (!is_numeric($raw)) {
        throw new InvalidArgumentException('Pris måste vara ett tal.');
    }
    return round((float) $raw, 2);
}

function nullable_date(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Datum måste skrivas som ÅÅÅÅ-MM-DD.');
    }
    return $value;
}

function money(?float $value): string
{
    if ($value === null) {
        return '';
    }
    $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;
    return number_format($value, $decimals, ',', ' ') . ' kr';
}

function source_label(string $source): string
{
    return match ($source) {
        'ica_api' => 'ICA-synk',
        'demo' => 'Demodata',
        default => 'Manuell',
    };
}

function store_format_label(string $format): string
{
    return match (strtolower($format)) {
        'maxi' => 'Maxi',
        'kvantum' => 'Kvantum',
        'supermarket' => 'Supermarket',
        'nara', 'nära' => 'Nära',
        default => ucfirst($format ?: 'Butik'),
    };
}

function deal_state(array $deal): array
{
    $today = date('Y-m-d');
    if (!empty($deal['valid_from']) && $deal['valid_from'] > $today) {
        return ['Kommande', 'upcoming'];
    }
    if (!empty($deal['valid_to']) && $deal['valid_to'] < $today) {
        return ['Utgånget', 'expired'];
    }
    return ['Aktuellt', 'active'];
}
