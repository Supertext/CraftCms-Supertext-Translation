<?php

/**
 * Demo start: turns DATABASE_URL (Railway's PostgreSQL) into Craft's CRAFT_DB_* variables for
 * the database CRAFT_DEMO_DB_NAME (default "craft"), creating it on that server if missing.
 * Prints shell `export` lines (no passwords are logged; the entrypoint evals them).
 */

$url = getenv('DATABASE_URL') ?: '';
if ($url === '') {
    fwrite(STDERR, "[demo] DATABASE_URL is not set; using the CRAFT_DB_* variables as they are.\n");
    exit(0);
}
$name = getenv('CRAFT_DEMO_DB_NAME') ?: 'craft';
if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
    fwrite(STDERR, "[demo] CRAFT_DEMO_DB_NAME may only contain a-z, 0-9 and _.\n");
    exit(1);
}

$parts = parse_url($url);
$host = $parts['host'] ?? 'localhost';
$port = (int) ($parts['port'] ?? 5432);
$user = rawurldecode($parts['user'] ?? 'postgres');
$password = rawurldecode($parts['pass'] ?? '');
$serverDb = ltrim($parts['path'] ?? '/postgres', '/') ?: 'postgres';

for ($attempt = 1; ; $attempt++) {
    try {
        $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$serverDb};connect_timeout=5", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        break;
    } catch (PDOException $e) {
        if ($attempt >= 30) {
            fwrite(STDERR, "[demo] Database not reachable: {$e->getMessage()}\n");
            exit(1);
        }
        fwrite(STDERR, "[demo] Waiting for the database…\n");
        sleep(2);
    }
}
$exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$exists->execute([$name]);
if (!$exists->fetchColumn()) {
    $pdo->exec("CREATE DATABASE \"{$name}\"");
    fwrite(STDERR, "[demo] Database {$name} created.\n");
}

foreach ([
    'CRAFT_DB_DRIVER' => 'pgsql',
    'CRAFT_DB_SERVER' => $host,
    'CRAFT_DB_PORT' => (string) $port,
    'CRAFT_DB_DATABASE' => $name,
    'CRAFT_DB_USER' => $user,
    'CRAFT_DB_PASSWORD' => $password,
    'CRAFT_DB_SCHEMA' => 'public',
] as $key => $value) {
    echo 'export ' . $key . '=' . escapeshellarg($value) . "\n";
}
