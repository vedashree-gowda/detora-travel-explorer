<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isHttps,
    'path' => '/',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dataDirectory = getenv('DETORA_DATA_DIR') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'detora-data';
    if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0700, true) && !is_dir($dataDirectory)) {
        error_log('DETORA could not create the configured data directory.');
        respond(500, ['error' => 'The server could not save your request. Please try again later.']);
    }

    try {
        $pdo = new PDO('sqlite:' . $dataDirectory . DIRECTORY_SEPARATOR . 'detora.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                phone TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS bookings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                destination TEXT NOT NULL,
                travel_date TEXT NOT NULL,
                travellers INTEGER NOT NULL,
                package TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    } catch (PDOException $exception) {
        error_log('DETORA database initialization failed: ' . $exception->getMessage());
        respond(500, ['error' => 'The server could not save your request. Please try again later.']);
    }

    return $pdo;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'csrf') {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    respond(200, ['csrf_token' => $_SESSION['csrf_token']]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed.']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== 0) {
    respond(415, ['error' => 'Send form data as JSON.']);
}

try {
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    respond(400, ['error' => 'The submitted form data is invalid.']);
}

if (!is_array($input)) {
    respond(400, ['error' => 'The submitted form data is invalid.']);
}

$csrfToken = $input['csrf_token'] ?? '';
if (!is_string($csrfToken) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    respond(403, ['error' => 'Your secure form session expired. Refresh the page and try again.']);
}

$action = $input['action'] ?? '';
$name = trim((string)($input['name'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));

if ($name === '' || strlen($name) > 100) {
    respond(422, ['error' => 'Enter a name of 1 to 100 characters.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    respond(422, ['error' => 'Enter a valid email address.']);
}

if ($action === 'register') {
    $phone = trim((string)($input['phone'] ?? ''));
    $password = $input['password'] ?? '';
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        respond(422, ['error' => 'Enter a 10-digit phone number.']);
    }
    if (!is_string($password) || strlen($password) < 8 || strlen($password) > 128) {
        respond(422, ['error' => 'Password must be between 8 and 128 characters.']);
    }

    try {
        $statement = database()->prepare(
            'INSERT INTO users (name, email, phone, password_hash) VALUES (:name, :email, :phone, :password_hash)'
        );
        $statement->execute([
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            respond(409, ['error' => 'An account with this email already exists.']);
        }
        error_log('DETORA registration failed: ' . $exception->getMessage());
        respond(500, ['error' => 'The server could not create your account. Please try again later.']);
    }

    respond(201, ['message' => 'Account created.']);
}

if ($action === 'booking') {
    $destination = trim((string)($input['destination'] ?? ''));
    $travelDate = trim((string)($input['travel_date'] ?? ''));
    $travellers = filter_var($input['travellers'] ?? null, FILTER_VALIDATE_INT);
    $package = trim((string)($input['package'] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $travelDate);
    $validPackages = ['Budget Trip', 'Standard Trip', 'Luxury Trip'];

    if ($destination === '' || strlen($destination) > 120) {
        respond(422, ['error' => 'Choose a destination.']);
    }
    if (!$date || $date->format('Y-m-d') !== $travelDate || $travelDate < date('Y-m-d')) {
        respond(422, ['error' => 'Choose a valid date today or later.']);
    }
    if ($travellers === false || $travellers < 1 || $travellers > 30) {
        respond(422, ['error' => 'Choose between 1 and 30 travellers.']);
    }
    if (!in_array($package, $validPackages, true)) {
        respond(422, ['error' => 'Choose a travel package.']);
    }

    try {
        $statement = database()->prepare(
            'INSERT INTO bookings (name, email, destination, travel_date, travellers, package)
             VALUES (:name, :email, :destination, :travel_date, :travellers, :package)'
        );
        $statement->execute([
            ':name' => $name,
            ':email' => $email,
            ':destination' => $destination,
            ':travel_date' => $travelDate,
            ':travellers' => $travellers,
            ':package' => $package,
        ]);
    } catch (PDOException $exception) {
        error_log('DETORA booking failed: ' . $exception->getMessage());
        respond(500, ['error' => 'The server could not save your booking. Please try again later.']);
    }

    respond(201, ['message' => 'Booking request received.']);
}

respond(400, ['error' => 'Unknown form action.']);
