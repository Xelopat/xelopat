<?php
$site_root = dirname(__DIR__);
$site_page_title = 'База домофонов — xelopat';
include $site_root . '/header.php';

function dom_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dom_int($value, int $default): int {
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    return $filtered === false ? $default : max(1, (int)$filtered);
}

function dom_lower(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function dom_upper(string $value): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function dom_title(string $value): string {
    return function_exists('mb_convert_case') ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : ucfirst($value);
}

function dom_like_variants(string $value): array {
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $lower = dom_lower(str_replace('ё', 'е', $value));
    $variants = [
        $value,
        $lower,
        dom_title($lower),
        dom_upper($lower),
    ];

    $filtered = [];
    foreach ($variants as $variant) {
        if ($variant !== '') {
            $filtered[] = $variant;
        }
    }

    return array_values(array_unique($filtered));
}

function dom_is_stopword(string $token): bool {
    static $stopwords = [
        'москва' => true,
        'г' => true,
        'город' => true,
        'ул' => true,
        'улица' => true,
        'пр' => true,
        'проспект' => true,
        'пр-д' => true,
        'проезд' => true,
        'пер' => true,
        'переулок' => true,
        'бул' => true,
        'бульвар' => true,
        'аллея' => true,
        'ш' => true,
        'шоссе' => true,
        'наб' => true,
        'набережная' => true,
        'дом' => true,
        'д' => true,
        'корпус' => true,
        'корп' => true,
        'к' => true,
        'строение' => true,
        'стр' => true,
        'подъезд' => true,
        'под' => true,
        'п' => true,
    ];

    return isset($stopwords[$token]);
}

function dom_parse_search(string $query): array {
    $normalized = dom_lower(str_replace('ё', 'е', $query));
    $normalized = preg_replace('/[№#,.();:]+/u', ' ', $normalized);
    $normalized = preg_replace('/\s+/u', ' ', trim((string)$normalized));

    $house = '';
    $building = '';
    $entrance = '';
    $explicit_address = false;

    if (preg_match('/(?<![\p{L}\p{N}])(\d+[а-яa-z]?)[\s-]*(?:к|корп|корпус)\.?\s*(\d+[а-яa-z]?)(?![\p{L}\p{N}])/u', $normalized, $match)) {
        $house = $match[1];
        $building = $match[2];
        $explicit_address = true;
    }
    if (preg_match('/(?:^|\s)(?:д|дом)\.?\s*(\d+[а-яa-z]?)(?=\s|$)/u', $normalized, $match)) {
        $house = $match[1];
        $explicit_address = true;
    }
    if (preg_match('/(?:^|\s)(?:к|корп|корпус)\.?\s*(\d+[а-яa-z]?)(?=\s|$)/u', $normalized, $match)) {
        $building = $match[1];
        $explicit_address = true;
    }
    if (preg_match('/(?:^|\s)(?:подъезд|под|п)\.?\s*(\d+[а-яa-z]?)(?=\s|$)/u', $normalized, $match)) {
        $entrance = $match[1];
        $explicit_address = true;
    }

    $for_tokens = preg_replace('/(?<![\p{L}\p{N}])(\d+[а-яa-z]?)[\s-]*(?:к|корп|корпус)\.?\s*(\d+[а-яa-z]?)(?![\p{L}\p{N}])/u', ' ', $normalized);
    $for_tokens = preg_replace('/(?:^|\s)(?:д|дом|к|корп|корпус|подъезд|под|п|строение|стр)\.?\s*\d+[а-яa-z]?(?=\s|$)/u', ' ', (string)$for_tokens);
    $for_tokens = preg_replace('/\s+/u', ' ', trim((string)$for_tokens));
    $tokens = $for_tokens === '' ? [] : preg_split('/\s+/u', $for_tokens, -1, PREG_SPLIT_NO_EMPTY);

    $street_tokens = [];
    $numbers = [];
    foreach ($tokens as $token) {
        $token = trim($token, " \t\n\r\0\x0B-");
        if ($token === '' || dom_is_stopword($token)) {
            continue;
        }
        if (preg_match('/^\d+[а-яa-z]?$/u', $token)) {
            $numbers[] = $token;
            continue;
        }
        if (preg_match('/\d/u', $token) && !preg_match('/^\d+-[а-яa-z]+$/u', $token)) {
            $numbers[] = $token;
            continue;
        }
        $street_tokens[] = $token;
    }

    $flex_extra = '';
    if ($house === '' && $street_tokens && $numbers) {
        $house = $numbers[0];
        if (count($numbers) > 1) {
            $flex_extra = $numbers[1];
        }
    }

    $address_mode = $explicit_address || (bool)$street_tokens || ($house !== '' && $query !== '');

    return [
        'street_tokens' => array_values(array_unique($street_tokens)),
        'house' => $house,
        'building' => $building,
        'entrance' => $entrance,
        'flex_extra' => $flex_extra,
        'address_mode' => $address_mode,
    ];
}

function dom_bind(PDOStatement $stmt, array $params): void {
    foreach ($params as $key => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $type);
    }
}

function dom_database_candidates(string $site_root): array {
    $candidates = [
        $site_root . '/data/domophones.sqlite',
    ];

    $document_root = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($document_root !== '') {
        $candidates[] = rtrim($document_root, '/\\') . '/data/domophones.sqlite';
        $candidates[] = dirname(rtrim($document_root, '/\\')) . '/data/domophones.sqlite';
    }

    $candidates[] = dirname($site_root) . '/data/domophones.sqlite';

    return array_values(array_unique($candidates));
}

$db_candidates = dom_database_candidates($site_root);
$db_path = '';
foreach ($db_candidates as $candidate) {
    if (is_file($candidate)) {
        $db_path = $candidate;
        break;
    }
}
$query = trim((string)($_GET['q'] ?? ''));
$page = dom_int($_GET['page'] ?? 1, 1);
$per_page = 80;
$offset = ($page - 1) * $per_page;
$db_ready = $db_path !== '';
if (!$db_ready) {
    error_log('Domophones SQLite not found. Checked: ' . implode('; ', $db_candidates));
}
$db_error = '';
$stats = [
    'streets' => 0,
    'houses' => 0,
    'entrances' => 0,
    'codes' => 0,
];
$results = [];
$has_more = false;

if ($db_ready) {
    try {
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($stats as $table => $_) {
            $stats[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }

        if ($query !== '') {
            $parsed = dom_parse_search($query);
            $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
            $where = [];
            $params = [];
            $score = ['0'];

            if ($parsed['address_mode']) {
                foreach ($parsed['street_tokens'] as $i => $token) {
                    $parts = [];
                    foreach (dom_like_variants($token) as $v => $variant) {
                        $key = ':street_' . $i . '_' . $v;
                        $parts[] = "s.name LIKE {$key}";
                        $params[$key] = '%' . $variant . '%';
                    }
                    if ($parts) {
                        $where[] = '(' . implode(' OR ', $parts) . ')';
                    }
                }

                if ($parsed['house'] !== '') {
                    $where[] = '(h.house_number = :house OR h.raw_house = :house OR h.raw_house LIKE :house_compact)';
                    $params[':house'] = $parsed['house'];
                    $params[':house_compact'] = $parsed['house'] . 'к%';
                    $score[] = 'CASE WHEN h.house_number = :house THEN 0 ELSE 40 END';
                }

                if ($parsed['building'] !== '') {
                    $where[] = '(h.building = :building OR h.raw_house LIKE :building_compact)';
                    $params[':building'] = $parsed['building'];
                    $params[':building_compact'] = '%к' . $parsed['building'];
                    $score[] = 'CASE WHEN h.building = :building THEN 0 ELSE 25 END';
                } elseif ($parsed['house'] !== '') {
                    $score[] = "CASE WHEN h.building = '' THEN 0 ELSE 8 END";
                }

                if ($parsed['entrance'] !== '') {
                    $where[] = 'e.entrance_number = :entrance';
                    $params[':entrance'] = $parsed['entrance'];
                    $score[] = 'CASE WHEN e.entrance_number = :entrance THEN 0 ELSE 30 END';
                }

                if ($parsed['flex_extra'] !== '') {
                    $where[] = '(e.entrance_number = :flex_extra OR h.building = :flex_extra OR h.raw_house LIKE :flex_extra_compact)';
                    $params[':flex_extra'] = $parsed['flex_extra'];
                    $params[':flex_extra_compact'] = '%к' . $parsed['flex_extra'];
                    $score[] = 'CASE WHEN e.entrance_number = :flex_extra THEN 0 WHEN h.building = :flex_extra THEN 5 ELSE 20 END';
                }
            }

            if (!$where) {
                foreach ($terms as $i => $term) {
                    $parts = [];
                    foreach (dom_like_variants($term) as $v => $variant) {
                        $key = ':term_' . $i . '_' . $v;
                        $parts[] = "(
                            s.name LIKE {$key}
                            OR h.house_number LIKE {$key}
                            OR h.building LIKE {$key}
                            OR h.raw_house LIKE {$key}
                            OR e.entrance_number LIKE {$key}
                            OR c.code LIKE {$key}
                            OR c.raw LIKE {$key}
                        )";
                        $params[$key] = '%' . $variant . '%';
                    }
                    if ($parts) {
                        $where[] = '(' . implode(' OR ', $parts) . ')';
                    }
                }
            }

            $relevance = implode(' + ', $score);
            $sql = "
                SELECT
                    s.name AS street,
                    h.house_number,
                    h.building,
                    h.raw_house,
                    e.entrance_number,
                    c.code,
                    c.raw,
                    ({$relevance}) AS relevance
                FROM codes c
                JOIN entrances e ON e.id = c.entrance_id
                JOIN houses h ON h.id = e.house_id
                JOIN streets s ON s.id = h.street_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY relevance, s.name, CAST(h.house_number AS INTEGER), h.house_number, h.building, CAST(e.entrance_number AS INTEGER), e.entrance_number, c.code
                LIMIT :limit OFFSET :offset
            ";
            $stmt = $pdo->prepare($sql);
            $params[':limit'] = $per_page + 1;
            $params[':offset'] = $offset;
            dom_bind($stmt, $params);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($results) > $per_page) {
                $has_more = true;
                array_pop($results);
            }
        }
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
}

$result_count = count($results);
$base_url = '/bases/domophones.php?q=' . rawurlencode($query);
?>

<link rel="stylesheet" href="/assets/bases.css">

<main class="b-shell">
  <div class="b-wrap">
    <section class="b-head">
      <div>
        <span class="b-kicker">// базы</span>
        <h1 class="b-title">Домофоны</h1>
        <p class="b-sub">Поиск по улице, дому, корпусу, подъезду и коду.</p>
      </div>
      <div class="b-stats" style="--n:4" aria-label="Статистика базы">
        <div class="b-stat"><strong><?= dom_h(number_format($stats['streets'], 0, '.', ' ')) ?></strong><span>улиц</span></div>
        <div class="b-stat"><strong><?= dom_h(number_format($stats['houses'], 0, '.', ' ')) ?></strong><span>домов</span></div>
        <div class="b-stat"><strong><?= dom_h(number_format($stats['entrances'], 0, '.', ' ')) ?></strong><span>подъездов</span></div>
        <div class="b-stat"><strong><?= dom_h(number_format($stats['codes'], 0, '.', ' ')) ?></strong><span>кодов</span></div>
      </div>
    </section>

    <section class="b-panel">
      <form class="b-form" method="get" action="/bases/domophones.php">
        <input class="b-input" type="search" name="q" value="<?= dom_h($query) ?>" placeholder="Уральская 11 к1, дом 11 корпус 1" autofocus>
        <button class="b-btn" type="submit">Найти</button>
      </form>
      <p class="b-hint">Примеры: <code>Уральская 11 к1</code>, <code>Уральская 11к1</code>, <code>дом 11 корпус 1</code></p>
      <?php if (!$db_ready): ?>
        <div class="b-alert">База не найдена.</div>
      <?php elseif ($db_error !== ''): ?>
        <div class="b-alert">SQLite не открылся: <?= dom_h($db_error) ?></div>
      <?php endif; ?>
    </section>

    <?php if ($query !== '' && $db_ready && $db_error === ''): ?>
      <div class="b-count">Показано: <?= dom_h((string)$result_count) ?><?= $has_more ? '+' : '' ?>, страница <?= dom_h((string)$page) ?></div>

      <?php if ($results): ?>
        <div class="b-tablewrap">
          <table class="b-table">
            <thead>
              <tr><th>Адрес</th><th>Подъезд</th><th>Код</th></tr>
            </thead>
            <tbody>
              <?php foreach ($results as $row): ?>
                <?php
                  $house = (string)($row['house_number'] ?? '');
                  $building = (string)($row['building'] ?? '');
                  $house_label = (string)($row['raw_house'] ?? '');
                  if ($house_label === '') {
                      $house_label = $house . ($building !== '' ? ' к. ' . $building : '');
                  }
                  $entrance = (string)($row['entrance_number'] ?? '');
                ?>
                <tr>
                  <td class="b-cell-lead" data-label="Адрес"><?= dom_h((string)$row['street']) ?>, <?= dom_h($house_label) ?></td>
                  <td data-label="Подъезд"><?= $entrance !== '' ? dom_h($entrance) : '<span class="b-dim">не указан</span>' ?></td>
                  <td data-label="Код"><span class="b-pill"><?= dom_h((string)$row['code']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="b-pager">
          <?php if ($page > 1): ?><a href="<?= dom_h($base_url . '&page=' . ($page - 1)) ?>">← Назад</a><?php endif; ?>
          <?php if ($has_more): ?><a href="<?= dom_h($base_url . '&page=' . ($page + 1)) ?>">Дальше →</a><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="b-empty">Ничего не найдено.</div>
      <?php endif; ?>
    <?php elseif ($query === '' && $db_ready && $db_error === ''): ?>
      <div class="b-empty">Введи улицу, дом, подъезд или код в поле поиска.</div>
    <?php endif; ?>
  </div>
</main>
