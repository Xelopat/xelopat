<?php
$site_root = dirname(__DIR__);
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
                    src.path AS source_path,
                    ({$relevance}) AS relevance
                FROM codes c
                JOIN entrances e ON e.id = c.entrance_id
                JOIN houses h ON h.id = e.house_id
                JOIN streets s ON s.id = h.street_id
                LEFT JOIN sources src ON src.id = c.source_id
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

<style>
  .db-shell{
    min-height:calc(100vh - 60px);
    background:#151518;
    color:#efeff1;
    position:relative;
    overflow:hidden;
  }
  .db-shell::before{
    content:"";
    position:absolute;
    inset:0;
    background-image:radial-gradient(circle at 1px 1px, rgba(51,51,64,.48) 1px, transparent 0);
    background-size:80px 80px;
    pointer-events:none;
  }
  .db-container{
    width:min(1280px, calc(100vw - 24px));
    margin:0 auto;
    position:relative;
    z-index:1;
    padding:42px 0 64px;
  }
  .db-head{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:24px;
    margin-bottom:22px;
  }
  .db-kicker{
    display:inline-flex;
    align-items:center;
    border-radius:4px;
    background:#0f3328;
    color:#61d1ad;
    font:11px/1.3 "Space Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    padding:4px 10px;
    margin-bottom:12px;
  }
  .db-title{
    margin:0;
    font:700 38px/1.1 "Space Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
  .db-subtitle{
    margin:10px 0 0;
    color:#a4a8bb;
    font-size:14px;
    line-height:1.55;
    max-width:680px;
  }
  .db-stats{
    display:grid;
    grid-template-columns:repeat(4, minmax(86px, 1fr));
    gap:8px;
    flex:0 0 min(470px, 100%);
  }
  .db-stat{
    border:1px solid #333340;
    background:#1e1e25;
    border-radius:8px;
    padding:10px 12px;
  }
  .db-stat strong{
    display:block;
    color:#f9c940;
    font:700 18px/1.1 "Space Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
  .db-stat span{
    display:block;
    margin-top:5px;
    color:#868899;
    font-size:11px;
  }
  .search-panel{
    border:1px solid #333340;
    background:#1e1e25;
    border-radius:12px;
    padding:14px;
    box-shadow:0 18px 40px rgba(0,0,0,.18);
  }
  .search-form{
    display:grid;
    grid-template-columns:1fr auto;
    gap:10px;
  }
  .search-input{
    width:100%;
    border:1px solid #333340;
    background:#151518;
    color:#efeff1;
    border-radius:8px;
    min-height:46px;
    padding:0 14px;
    font:500 14px/1.3 Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    outline:none;
  }
  .search-input:focus{
    border-color:rgba(249,201,64,.72);
    box-shadow:0 0 0 3px rgba(249,201,64,.08);
  }
  .search-btn{
    border:1px solid transparent;
    border-radius:8px;
    background:#f9c940;
    color:#151518;
    min-height:46px;
    padding:0 18px;
    font-weight:700;
    cursor:pointer;
  }
  .db-note{
    margin-top:10px;
    color:#868899;
    font-size:12px;
  }
  .db-alert{
    margin-top:16px;
    border:1px solid rgba(255,143,143,.36);
    background:rgba(255,143,143,.08);
    color:#ffb7b7;
    border-radius:8px;
    padding:12px 14px;
    font-size:13px;
  }
  .results-bar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin:22px 0 10px;
    color:#868899;
    font-size:13px;
  }
  .results-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    overflow:hidden;
    border:1px solid #333340;
    border-radius:12px;
    background:#1e1e25;
  }
  .results-table th,
  .results-table td{
    text-align:left;
    padding:11px 12px;
    border-bottom:1px solid #2a2a34;
    vertical-align:top;
    font-size:13px;
  }
  .results-table th{
    color:#61d1ad;
    background:#191920;
    font:700 11px/1.2 "Space Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    text-transform:uppercase;
  }
  .results-table tr:last-child td{
    border-bottom:none;
  }
  .code-pill{
    display:inline-flex;
    align-items:center;
    border:1px solid rgba(249,201,64,.28);
    background:rgba(249,201,64,.08);
    color:#f9c940;
    border-radius:6px;
    padding:3px 8px;
    font:700 13px/1.3 "Space Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
  .muted{
    color:#868899;
  }
  .source{
    color:#868899;
    font-size:11px;
    word-break:break-word;
  }
  .pager{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-top:14px;
  }
  .pager a{
    color:#efeff1;
    text-decoration:none;
    border:1px solid #333340;
    background:#1e1e25;
    border-radius:8px;
    padding:8px 12px;
    font-size:13px;
  }
  .pager a:hover{
    color:#f9c940;
  }
  .empty-state{
    border:1px solid #333340;
    background:#1e1e25;
    border-radius:12px;
    padding:20px;
    margin-top:18px;
    color:#a4a8bb;
  }
  @media (max-width: 900px){
    .db-head{
      display:block;
    }
    .db-stats{
      margin-top:18px;
      grid-template-columns:repeat(2, minmax(0, 1fr));
    }
    .results-table,
    .results-table tbody,
    .results-table tr,
    .results-table td{
      display:block;
      width:100%;
    }
    .results-table thead{
      display:none;
    }
    .results-table tr{
      border-bottom:1px solid #2a2a34;
      padding:10px 0;
    }
    .results-table tr:last-child{
      border-bottom:none;
    }
    .results-table td{
      border-bottom:none;
      padding:6px 12px;
    }
    .results-table td::before{
      content:attr(data-label);
      display:block;
      color:#61d1ad;
      font-size:10px;
      font-family:"Space Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      text-transform:uppercase;
      margin-bottom:3px;
    }
  }
  @media (max-width: 560px){
    .db-container{
      width:calc(100vw - 16px);
      padding-top:28px;
    }
    .db-title{
      font-size:30px;
    }
    .search-form{
      grid-template-columns:1fr;
    }
    .search-btn{
      width:100%;
    }
  }
</style>

<main class="db-shell">
  <div class="db-container">
    <section class="db-head">
      <div>
        <span class="db-kicker">// базы</span>
        <h1 class="db-title">Домофоны<span style="color:#f9c940">_</span></h1>
        <p class="db-subtitle">Поиск по улице, дому, корпусу, подъезду и коду.</p>
      </div>
      <div class="db-stats" aria-label="Статистика базы">
        <div class="db-stat"><strong><?= dom_h(number_format($stats['streets'], 0, '.', ' ')) ?></strong><span>улиц</span></div>
        <div class="db-stat"><strong><?= dom_h(number_format($stats['houses'], 0, '.', ' ')) ?></strong><span>домов</span></div>
        <div class="db-stat"><strong><?= dom_h(number_format($stats['entrances'], 0, '.', ' ')) ?></strong><span>подъездов</span></div>
        <div class="db-stat"><strong><?= dom_h(number_format($stats['codes'], 0, '.', ' ')) ?></strong><span>кодов</span></div>
      </div>
    </section>

    <section class="search-panel">
      <form class="search-form" method="get" action="/bases/domophones.php">
        <input class="search-input" type="search" name="q" value="<?= dom_h($query) ?>" placeholder="Например: Уральская 11 к1, Уральская 11к1, дом 11 корпус 1" autofocus>
        <button class="search-btn" type="submit">Найти</button>
      </form>
      <?php if (!$db_ready): ?>
        <div class="db-alert">База не найдена.</div>
      <?php elseif ($db_error !== ''): ?>
        <div class="db-alert">SQLite не открылся: <?= dom_h($db_error) ?></div>
      <?php endif; ?>
    </section>

    <?php if ($query !== '' && $db_ready && $db_error === ''): ?>
      <div class="results-bar">
        <span>Показано: <?= dom_h((string)$result_count) ?><?= $has_more ? '+' : '' ?></span>
        <span class="muted">страница <?= dom_h((string)$page) ?></span>
      </div>

      <?php if ($results): ?>
        <table class="results-table">
          <thead>
            <tr>
              <th>Адрес</th>
              <th>Подъезд</th>
              <th>Код</th>
              <th>Источник</th>
            </tr>
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
                <td data-label="Адрес">
                  <?= dom_h((string)$row['street']) ?>, <?= dom_h($house_label) ?>
                </td>
                <td data-label="Подъезд"><?= $entrance !== '' ? dom_h($entrance) : '<span class="muted">не указан</span>' ?></td>
                <td data-label="Код"><span class="code-pill"><?= dom_h((string)$row['code']) ?></span></td>
                <td data-label="Источник"><span class="source"><?= dom_h((string)($row['source_path'] ?? '')) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="pager">
          <?php if ($page > 1): ?>
            <a href="<?= dom_h($base_url . '&page=' . ($page - 1)) ?>">Назад</a>
          <?php endif; ?>
          <?php if ($has_more): ?>
            <a href="<?= dom_h($base_url . '&page=' . ($page + 1)) ?>">Дальше</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="empty-state">Ничего не найдено.</div>
      <?php endif; ?>
    <?php elseif ($query === '' && $db_ready && $db_error === ''): ?>
      <div class="empty-state">Введи улицу, дом, подъезд или код в поле поиска.</div>
    <?php endif; ?>
  </div>
</main>
