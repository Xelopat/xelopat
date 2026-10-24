<?php
// /guitar/index.php
declare(strict_types=1);

/*
  Хобби: гитара. Просмотр доступен всем.
    /guitar/                 — список песен
    /guitar/?song=<slug>     — разбор песни: аккорды, структура, тренажёр с метрономом
  Данные песен: /guitar/songs.php
*/

if (!function_exists("esc")) {
    function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
}

$songs = require __DIR__ . "/songs.php";

$song = null;
$slug = (string)($_GET["song"] ?? "");
if ($slug !== "") {
    foreach ($songs as $s) {
        if ($s["slug"] === $slug) {
            $song = $s;
            break;
        }
    }
    if ($song === null) {
        http_response_code(404);
    }
}

$chords_of = static function (array $s): array {
    $out = [];
    foreach ($s["sections"] as $sec) {
        foreach ($sec["bars"] as $c) {
            if (!in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
    }
    return $out;
};

$page_title = $song ? $song["title"] . " — гитара" : "Гитара";
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= esc($page_title) ?></title>
  <style>
    .g-page{max-width:980px;margin:0 auto;padding:calc(var(--header-h,60px) + 24px) 16px 60px;color:var(--text,#efeff1)}
    .g-page h1{font-size:26px;margin:0 0 4px}
    .g-page h2{font-size:18px;margin:30px 0 10px}
    .g-sub{color:var(--muted,#868899);margin:0 0 18px}
    .g-sub a,.g-back{color:var(--accent,#f9c940);text-decoration:none}
    .g-card{background:var(--panel,#1e1e25);border:1px solid var(--line,#333340);border-radius:14px;padding:16px}
    .g-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
    a.g-song{display:block;text-decoration:none;color:inherit;background:var(--panel,#1e1e25);border:1px solid var(--line,#333340);border-radius:14px;padding:16px;transition:border-color .15s,transform .15s}
    a.g-song:hover{border-color:var(--accent,#f9c940);transform:translateY(-2px)}
    .g-song b{display:block;font-size:18px}
    .g-song span{color:var(--muted,#868899);font-size:14px}
    .g-song .g-pills{margin-top:10px}
    .g-pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
    .g-pill{background:var(--panel-2,#191920);border:1px solid var(--line,#333340);border-radius:99px;padding:3px 11px;font-size:13px;color:var(--muted,#868899)}
    .g-pill b{color:var(--accent,#f9c940);font-weight:600}
    .g-btn{font:inherit;font-size:14px;color:var(--text,#efeff1);background:var(--panel-2,#191920);border:1px solid var(--line,#333340);border-radius:10px;padding:7px 13px;cursor:pointer}
    .g-btn:hover{border-color:var(--accent,#f9c940)}
    .g-btn.on{background:var(--accent,#f9c940);color:#151518;border-color:var(--accent,#f9c940)}
    #g-play{background:var(--accent,#f9c940);color:#151518;border-color:var(--accent,#f9c940);font-weight:700;min-width:120px;font-size:16px;padding:9px 16px}
    .g-ctrl{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:14px}
    .g-ctrl label{font-size:14px;color:var(--muted,#868899);display:flex;align-items:center;gap:8px}
    .g-ctrl input[type=range]{accent-color:var(--accent,#f9c940);width:160px}
    .g-bpm-v{color:var(--text,#efeff1);font-weight:700;min-width:2.2em;display:inline-block}
    .g-stage{display:grid;grid-template-columns:1fr 1fr;gap:14px;text-align:center}
    @media(max-width:560px){.g-stage{grid-template-columns:1fr}}
    .g-lbl{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted,#868899)}
    #g-now{font-size:68px;font-weight:700;line-height:1.1}
    #g-next{font-size:40px;font-weight:700;color:var(--violet,#9d8ff0);line-height:1.4}
    #g-sec-now{min-height:21px;font-size:14px;color:var(--green,#61d1ad)}
    .g-strum{display:flex;justify-content:center;gap:4px;margin:14px 0 8px}
    .g-sl{width:11.5%;max-width:52px;text-align:center;border:1px solid var(--line,#333340);border-radius:8px;padding:6px 0 2px;background:var(--panel-2,#191920);color:var(--muted,#868899)}
    .g-sl .g-a{font-size:26px;line-height:1.1;font-weight:700}
    .g-sl .g-c{font-size:11px}
    .g-sl.hit{color:var(--text,#efeff1)}
    .g-sl:not(.hit) .g-a{opacity:.3}
    .g-sl.cur{background:var(--accent,#f9c940);border-color:var(--accent,#f9c940);color:#151518}
    .g-strums{display:flex;flex-wrap:wrap;gap:8px;justify-content:center}
    .g-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
    .g-ch{text-align:center}
    .g-ch-n{font-size:22px;font-weight:700}
    .g-ch svg{width:120px;color:var(--text,#efeff1)}
    .g-ch small{display:block;color:var(--muted,#868899);font-size:13px;line-height:1.3}
    .g-sec{padding:12px 0;border-top:1px solid var(--line,#333340)}
    .g-sec:first-child{border-top:0;padding-top:0}
    .g-sec-h{display:flex;align-items:center;gap:12px;margin-bottom:8px}
    .g-bars{display:flex;flex-wrap:wrap;gap:6px}
    .g-bar{min-width:52px;text-align:center;padding:6px 10px;border-radius:8px;border:2px solid;background:var(--panel-2,#191920);font-weight:700;transition:background .1s,color .1s}
    .g-bar.cur{background:var(--accent,#f9c940);color:#151518}
    .g-note{color:var(--muted,#868899);font-size:14px}
    ol.g-steps{padding-left:20px;margin:0}
    ol.g-steps li{margin:6px 0}
  </style>
</head>
<body>

<?php @include $_SERVER["DOCUMENT_ROOT"] . "/header.php"; ?>

<div class="g-page">
<?php if ($slug !== "" && $song === null): ?>
  <h1>Песня не найдена</h1>
  <p class="g-sub"><a class="g-back" href="/guitar/">← К списку песен</a></p>

<?php elseif ($song === null): ?>
  <h1>Гитара</h1>
  <p class="g-sub">Хобби, которое я только осваиваю. Здесь разборы песен для новичка: аккорды, структура и тренажёр с метрономом.</p>
  <div class="g-list">
    <?php foreach ($songs as $s): ?>
      <a class="g-song" href="/guitar/?song=<?= esc($s["slug"]) ?>">
        <b><?= esc($s["title"]) ?></b>
        <span><?= esc($s["artist"]) ?></span>
        <div class="g-pills">
          <span class="g-pill"><b><?= esc($s["level"]) ?></b></span>
          <span class="g-pill"><?= esc(implode(", ", $chords_of($s))) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

<?php else: ?>
  <p class="g-sub"><a class="g-back" href="/guitar/">← Все песни</a></p>
  <h1><?= esc($song["title"]) ?></h1>
  <p class="g-sub"><?= esc($song["artist"]) ?></p>
  <div class="g-pills">
    <span class="g-pill">Тональность <b><?= esc($song["key"]) ?></b></span>
    <span class="g-pill"><b><?= esc($song["capo"]) ?></b></span>
    <span class="g-pill">Сложность <b><?= esc($song["level"]) ?></b></span>
    <span class="g-pill">Аккорды <b><?= esc(implode(", ", $chords_of($song))) ?></b></span>
  </div>
  <p class="g-note"><?= esc($song["note"]) ?></p>

  <h2>Тренажёр</h2>
  <div class="g-card">
    <div class="g-ctrl">
      <button type="button" class="g-btn" id="g-play">▶ Играть</button>
      <label>Темп <input type="range" id="g-bpm" min="40" max="140" step="2"> <span class="g-bpm-v"><span id="g-bpm-v"></span></span> уд/мин</label>
      <label><input type="checkbox" id="g-click" checked> Метроном</label>
    </div>
    <div class="g-stage">
      <div><div class="g-lbl">Сейчас</div><div id="g-now">—</div></div>
      <div><div class="g-lbl">Дальше</div><div id="g-next">—</div></div>
    </div>
    <div style="text-align:center"><span id="g-sec-now"></span></div>
    <div class="g-strum" id="g-strum"></div>
    <div class="g-strums" id="g-strums"></div>
    <p class="g-note" style="margin-bottom:0">Один аккорд = один такт (4 доли). Сначала один такт отсчёта. «↻ петля» у раздела гоняет только его. Начни с медленного темпа — 50–60.</p>
  </div>

  <h2>Аккорды</h2>
  <div class="g-card">
    <div class="g-grid" id="g-chords"></div>
    <p class="g-note" style="margin-bottom:0">Вертикальные линии — струны (слева самая толстая), цифры — пальцы (1 указательный, 2 средний, 3 безымянный, 4 мизинец), ○ — открытая струна, ✕ — не играть.</p>
  </div>

  <h2>Структура</h2>
  <div class="g-card" id="g-sections"></div>

  <h2>Как учить</h2>
  <div class="g-card">
    <ol class="g-steps">
      <li>Сначала выучи аккорды по одному, потом переходы парами — по минуте на пару.</li>
      <li>Играй один удар вниз на аккорд в медленном темпе (50–60), пока смены не станут без пауз.</li>
      <li>Если не успел сменить аккорд — не останавливайся, доиграй такт на прежнем и попади в следующий. Ритм важнее.</li>
      <li>Когда легко, прибавь темп и переходи к бою с восьмыми.</li>
    </ol>
  </div>

  <p class="g-note" style="margin-top:20px">Аккорды составлены по любительским разборам (<a class="g-back" href="<?= esc($song["source"]) ?>" rel="noopener" target="_blank">источник</a>) и упрощены, поэтому могут отличаться от оригинала. Тексты песен здесь не публикуются.</p>

  <script>window.GUITAR_SONG = <?= json_encode($song, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="/guitar/guitar.js?v=<?= (int)@filemtime(__DIR__ . "/guitar.js") ?>"></script>
<?php endif; ?>
</div>

</body>
</html>
