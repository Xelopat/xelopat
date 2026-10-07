<?php
// Локальная статистика посещений: SQLite в /data/stats.sqlite (закрыто от веба, деплой не трогает).
// IP не храним: посетитель — это хэш IP + браузера с секретной солью.
declare(strict_types=1);

function stats_root(): string {
    return rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/\\');
}

function stats_db(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo ?: null;
    try {
        if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) { $pdo = false; return null; }
        $dir = stats_root() . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . $dir . '/stats.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=2000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS visits (
            id INTEGER PRIMARY KEY,
            ts INTEGER NOT NULL,
            day TEXT NOT NULL,
            path TEXT NOT NULL,
            ref TEXT NOT NULL DEFAULT '',
            visitor TEXT NOT NULL,
            device TEXT NOT NULL DEFAULT '',
            browser TEXT NOT NULL DEFAULT '',
            source TEXT NOT NULL DEFAULT 'live'
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS visits_day ON visits(day)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS visits_ts ON visits(ts)');
        return $pdo;
    } catch (Throwable $e) {
        $pdo = false;
        return null;
    }
}

function stats_salt(): string {
    $file = stats_root() . '/.private/stats_salt';
    $salt = is_file($file) ? trim((string)file_get_contents($file)) : '';
    if ($salt === '') {
        $salt = bin2hex(random_bytes(16));
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
        @file_put_contents($file, $salt, LOCK_EX);
    }
    return $salt;
}

function stats_visitor(string $ip, string $ua): string {
    return substr(hash('sha256', stats_salt() . '|' . $ip . '|' . $ua), 0, 16);
}

function stats_is_bot(string $ua): bool {
    if ($ua === '') return true;
    return (bool)preg_match('~bot|crawl|spider|slurp|curl|wget|python|httpclient|okhttp|go-http|java/|headless|lighthouse|preview|monitor|uptime|scan|fetch|feed|facebookexternalhit|telegram|whatsapp|vkshare|yandex(?!browser)~i', $ua);
}

function stats_device(string $ua): string {
    if (preg_match('~iPad|Tablet~i', $ua)) return 'Планшет';
    if (preg_match('~Mobi|iPhone|Android~i', $ua)) return 'Телефон';
    return 'Компьютер';
}

function stats_browser(string $ua): string {
    foreach (['YaBrowser' => 'Яндекс', 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $k => $v) {
        if (stripos($ua, $k) !== false) return $v;
    }
    return 'Другой';
}

// /travel/index.php?item=x -> /travel/ ; запросы не учитываем, чтобы страницы не дробились
function stats_norm_path(string $uri): string {
    $p = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
    $p = preg_replace('~/index\.php$~', '/', $p);
    return $p === '' ? '/' : $p;
}

function stats_ref_host(string $ref, string $ownHost): string {
    $h = strtolower((string)(parse_url($ref, PHP_URL_HOST) ?: ''));
    $h = preg_replace('~^www\.~', '', $h);
    $own = preg_replace('~^www\.~', '', strtolower($ownHost));
    return ($h === '' || $h === $own) ? '' : $h;
}

// Вызывается из header.php на каждой странице сайта
function stats_track(?array $user): void {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        if ($user && in_array('admin', (array)($user['roles'] ?? []), true)) return; // свои заходы не считаем
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (stats_is_bot($ua)) return;
        $db = stats_db();
        if (!$db) return;
        $now = time();
        $st = $db->prepare("INSERT INTO visits (ts, day, path, ref, visitor, device, browser, source) VALUES (?, ?, ?, ?, ?, ?, ?, 'live')");
        $st->execute([
            $now, date('Y-m-d', $now),
            stats_norm_path((string)($_SERVER['REQUEST_URI'] ?? '/')),
            stats_ref_host((string)($_SERVER['HTTP_REFERER'] ?? ''), (string)($_SERVER['HTTP_HOST'] ?? '')),
            stats_visitor((string)($_SERVER['REMOTE_ADDR'] ?? ''), $ua),
            stats_device($ua), stats_browser($ua),
        ]);
    } catch (Throwable $e) {
        // статистика никогда не должна ломать страницу
    }
}

/* ---------------- история из логов FastPanel ---------------- */

// FastPanel пишет логи в /var/www/<user>/data/logs/<домен>-frontend.access.log (+ ротированные .gz)
function stats_log_files(): array {
    $root = stats_root();
    $dirs = array_unique([dirname($root, 2) . '/logs', dirname($root) . '/logs', $root . '/../logs']);
    $files = [];
    foreach ($dirs as $d) {
        if (!@is_dir($d)) continue;
        foreach (glob($d . '/*access*') ?: [] as $f) {
            if (is_file($f) && is_readable($f)) $files[] = realpath($f) ?: $f;
        }
    }
    $files = array_values(array_unique($files));
    // Если есть логи nginx (frontend), берём только их: backend дублирует те же запросы
    $front = array_values(array_filter($files, function ($f) { return stripos(basename($f), 'frontend') !== false; }));
    return $front ?: $files;
}

function stats_log_dirs_checked(): array {
    $root = stats_root();
    return array_values(array_unique([dirname($root, 2) . '/logs', dirname($root) . '/logs']));
}

function stats_skip_path(string $path, string $query): bool {
    if (preg_match('~\.(css|js|map|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|mp4|webm|mov|json|txt|xml|sqlite|docx?|pptx?|pdf|zip)$~i', $path)) return true;
    if (preg_match('~^/(assets|uploads|img|auth|data|\.private|perfumes/uploads)/~', $path)) return true;
    if ($path === '/admin.php' || $path === '/favicon.ico') return true;
    if (strpos($query, 'api=') !== false) return true;
    return false;
}

// Полный переимпорт: старые строки из логов удаляем и читаем заново.
// Берём только то, что было раньше первой «живой» записи, чтобы не считать дважды.
function stats_import_logs(): array {
    $db = stats_db();
    if (!$db) return ['ok' => false, 'error' => 'SQLite недоступен'];
    $files = stats_log_files();
    if (!$files) return ['ok' => false, 'error' => 'Логи не найдены или нет прав на чтение. Проверял: ' . implode(', ', stats_log_dirs_checked())];

    @set_time_limit(300);
    $firstLive = (int)$db->query("SELECT COALESCE(MIN(ts), 0) FROM visits WHERE source = 'live'")->fetchColumn();
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $re = '~^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) \S+ "([^"]*)" "([^"]*)"~';

    $db->beginTransaction();
    $db->exec("DELETE FROM visits WHERE source = 'log'");
    $ins = $db->prepare("INSERT INTO visits (ts, day, path, ref, visitor, device, browser, source) VALUES (?, ?, ?, ?, ?, ?, ?, 'log')");
    $added = 0; $lines = 0; $minTs = PHP_INT_MAX; $maxTs = 0;

    foreach ($files as $f) {
        $gz = substr($f, -3) === '.gz';
        $h = $gz ? @gzopen($f, 'rb') : @fopen($f, 'rb');
        if (!$h) continue;
        while (($line = $gz ? gzgets($h) : fgets($h)) !== false) {
            $lines++;
            if (!preg_match($re, $line, $m)) continue;
            [, $ip, $time, $method, $uri, $status, $ref, $ua] = $m;
            if ($method !== 'GET' || $status !== '200' || stats_is_bot($ua)) continue;
            $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
            if (stats_skip_path($path, (string)(parse_url($uri, PHP_URL_QUERY) ?: ''))) continue;
            $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $time);
            if (!$dt) continue;
            $ts = $dt->getTimestamp();
            if ($firstLive && $ts >= $firstLive) continue;
            $ins->execute([$ts, date('Y-m-d', $ts), stats_norm_path($uri), stats_ref_host($ref, $host), stats_visitor($ip, $ua), stats_device($ua), stats_browser($ua)]);
            $added++;
            $minTs = min($minTs, $ts); $maxTs = max($maxTs, $ts);
        }
        $gz ? gzclose($h) : fclose($h);
    }
    $db->commit();
    return ['ok' => true, 'files' => count($files), 'lines' => $lines, 'added' => $added,
            'from' => $added ? date('d.m.Y', $minTs) : '', 'to' => $added ? date('d.m.Y', $maxTs) : ''];
}

/* ---------------- отчёт для админки ---------------- */

function stats_report(int $days): array {
    $db = stats_db();
    if (!$db) return ['ok' => false];
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $q = function (string $sql, array $p = []) use ($db) { $st = $db->prepare($sql); $st->execute($p); return $st; };

    $byDay = [];
    foreach ($q('SELECT day, COUNT(*) v, COUNT(DISTINCT visitor) u FROM visits WHERE day >= ? GROUP BY day', [$from]) as $r) $byDay[$r['day']] = $r;
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $series[] = ['day' => $d, 'views' => (int)($byDay[$d]['v'] ?? 0), 'uniques' => (int)($byDay[$d]['u'] ?? 0)];
    }
    $tot = $q('SELECT COUNT(*) v, COUNT(DISTINCT visitor) u FROM visits WHERE day >= ?', [$from])->fetch();
    $all = $q("SELECT COUNT(*) v, MIN(day) first, SUM(source = 'log') logs FROM visits")->fetch();

    $top = function (string $col) use ($q, $from) {
        return $q("SELECT $col k, COUNT(*) v FROM visits WHERE day >= ? AND $col != '' GROUP BY $col ORDER BY v DESC LIMIT 10", [$from])->fetchAll();
    };
    return [
        'ok' => true,
        'series' => $series,
        'views' => (int)$tot['v'],
        'uniques' => (int)$tot['u'],
        'today' => (int)end($series)['views'],
        'pages' => $top('path'),
        'refs' => $top('ref'),
        'devices' => $top('device'),
        'browsers' => $top('browser'),
        'all_views' => (int)$all['v'],
        'first_day' => (string)($all['first'] ?? ''),
        'log_rows' => (int)$all['logs'],
    ];
}
