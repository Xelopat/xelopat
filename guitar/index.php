<?php
// /guitar/index.php
declare(strict_types=1);

/*
  Хобби: гитара. Просмотр доступен всем.
    /guitar/                 — список песен
    /guitar/?song=<slug>     — разбор песни: тренажёр с записью (шкала аккордов, бой по аккордам), схемы аккордов, свой текст
  Данные песен: /guitar/songs.php
*/

if (!function_exists("esc")) {
    function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
}

// Сессия и refresh-токен «запомнить меня» должны стартовать ДО любого вывода: header.php подключается
// уже после <head>, и к тому моменту заголовки отправлены — токен менялся на сервере, а новый cookie не доходил.
require_once $_SERVER["DOCUMENT_ROOT"] . "/auth/lib.php";
auth_current_user();

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
        foreach ($sec["bars"] as $bar) {
            foreach ((array)$bar as $c) {
                if (!in_array($c, $out, true)) {
                    $out[] = $c;
                }
            }
        }
    }
    return $out;
};

// Тренажёр работает только с записью: если файла нет, блока нет.
$audio_src = null;
if ($song && !empty($song["audio"]["file"])) {
    $f = basename((string)$song["audio"]["file"]);
    if (is_file(__DIR__ . "/audio/" . $f)) {
        $audio_src = "/guitar/audio/" . rawurlencode($f);
    }
}
if ($song && $audio_src === null) {
    unset($song["audio"]);
}

$page_title = $song ? $song["title"] . " — гитара" : "Гитара";
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= esc($page_title) ?></title>
  <style>
    .g-page{max-width:1000px;margin:0 auto;padding:calc(var(--header-h,60px) + 16px) 16px 48px;color:var(--text,#efeff1)}
    .g-page h1{font-size:22px;margin:2px 0 6px}
    .g-artist{color:var(--muted,#868899);font-weight:400;font-size:16px}
    .g-page h2{font-size:14px;margin:16px 0 6px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted,#868899)}
    .g-sub{color:var(--muted,#868899);margin:0 0 18px}
    .g-sub a,.g-back{color:var(--accent,#f9c940);text-decoration:none}
    .g-card{background:var(--panel,#1e1e25);border:1px solid var(--line,#333340);border-radius:12px;padding:12px 14px}
    .g-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
    a.g-song{display:block;text-decoration:none;color:inherit;background:var(--panel,#1e1e25);border:1px solid var(--line,#333340);border-radius:14px;padding:16px;transition:border-color .15s,transform .15s}
    a.g-song:hover{border-color:var(--accent,#f9c940);transform:translateY(-2px)}
    .g-song b{display:block;font-size:18px}
    .g-song span{color:var(--muted,#868899);font-size:14px}
    .g-song .g-pills{margin-top:10px}
    .g-pills{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
    .g-pill{background:var(--panel-2,#191920);border:1px solid var(--line,#333340);border-radius:99px;padding:3px 11px;font-size:13px;color:var(--muted,#868899)}
    .g-pill b{color:var(--accent,#f9c940);font-weight:600}
    .g-btn{font:inherit;font-size:14px;color:var(--text,#efeff1);background:var(--panel-2,#191920);border:1px solid var(--line,#333340);border-radius:10px;padding:7px 13px;cursor:pointer}
    .g-btn:hover{border-color:var(--accent,#f9c940)}
    .g-btn.on{background:var(--accent,#f9c940);color:#151518;border-color:var(--accent,#f9c940)}
    .g-main{background:var(--accent,#f9c940);color:#151518;border-color:var(--accent,#f9c940);font-weight:700;min-width:120px;font-size:16px;padding:9px 16px}
    .g-ctrl{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:10px}
    .g-ctrl label,.g-lbl2{font-size:13px;color:var(--muted,#868899);display:flex;align-items:center;gap:8px}
    .g-time{color:var(--muted,#868899);font-variant-numeric:tabular-nums}
    .g-sel{font:inherit;font-size:14px;color:var(--text,#efeff1);background:var(--panel-2,#191920);border:1px solid var(--line,#333340);border-radius:10px;padding:6px 10px}
    .g-spacer{flex:1}

    /* сцена: сейчас / дальше */
    .g-stage{display:grid;grid-template-columns:1fr 1fr;gap:8px;text-align:center}
    .g-lbl{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted,#868899)}
    #g-now{font-size:56px;font-weight:700;line-height:1.1}
    #g-next{font-size:34px;font-weight:700;color:var(--violet,#9d8ff0);line-height:1.6}
    #g-sec-now{display:block;text-align:center;min-height:20px;font-size:14px;color:var(--green,#61d1ad);margin-bottom:8px}

    /* крупная шкала: окно вокруг текущего места */
    .g-tlz{position:relative;height:96px;border-radius:10px;overflow:hidden;background:var(--panel-2,#191920);cursor:pointer}
    .g-tlz-in{position:absolute;top:0;bottom:0;left:0;will-change:transform}
    .g-seg{position:absolute;top:18px;bottom:0;display:flex;align-items:center;justify-content:center;color:#151518;overflow:hidden;border-radius:6px}
    .g-seg b{font-size:26px}
    .g-seg.bs{box-shadow:inset 3px 0 0 rgba(0,0,0,.45)}
    .g-tick{position:absolute;top:0;height:16px;border-left:1px solid var(--line,#333340)}
    .g-tick span{position:absolute;left:4px;top:0;font-size:10px;color:var(--muted,#868899)}
    .g-tlz::after{content:"";position:absolute;left:50%;top:0;bottom:0;width:3px;margin-left:-1px;background:var(--text,#efeff1);box-shadow:0 0 8px #000;pointer-events:none}
    .g-ov{position:relative;height:22px;border-radius:6px;overflow:hidden;background:var(--panel-2,#191920);cursor:pointer;margin-top:8px}
    .g-ovb{position:absolute;top:0;bottom:0;border-right:2px solid var(--panel,#1e1e25);font-size:10px;color:#fff;overflow:hidden;white-space:nowrap}
    .g-ovb span{padding-left:4px;line-height:22px}
    .g-ovph{position:absolute;top:0;bottom:0;width:2px;background:#fff;pointer-events:none}

    /* бой по аккордам */
    .g-strumbox{margin-top:12px;border-top:1px solid var(--line,#333340);padding-top:10px}
    .g-strum{display:flex;gap:2px;margin:4px 0}
    .g-sl{flex:1 1 0;min-width:0;max-width:60px;text-align:center;border:1px solid var(--line,#333340);border-bottom:4px solid var(--line,#333340);border-radius:6px;padding:3px 0 1px;background:var(--panel-2,#191920);color:var(--muted,#868899)}
    .g-sl .g-a{font-size:22px;line-height:1.1;font-weight:700}
    .g-sl .g-c{font-size:10px}
    .g-sl .g-k{font-size:13px;font-weight:700;line-height:1.3}
    .g-sl.hit{color:var(--text,#efeff1)}
    .g-sl:not(.hit) .g-a{opacity:.3}
    .g-sl.dim{opacity:.4}
    .g-sl.cur{background:var(--accent,#f9c940);border-color:var(--accent,#f9c940);color:#151518}
    .g-sl.cur .g-k{color:#151518!important}
    .g-bar-next{opacity:.7;transform-origin:left}
    .g-bar-next .g-sl .g-a{font-size:16px}

    /* аккорды */
    .g-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px}
    .g-ch{text-align:center}
    .g-ch-n{font-size:20px;font-weight:700;line-height:1.2}
    .g-ch svg{width:100px;color:var(--text,#efeff1)}

    /* текст */
    .g-lyr-ta{width:100%;font:15px/1.5 ui-monospace,Menlo,Consolas,monospace;background:var(--panel-2,#191920);color:var(--text,#efeff1);border:1px solid var(--line,#333340);border-radius:10px;padding:10px}
    .g-lyr{font:15px/1.5 ui-monospace,Menlo,Consolas,monospace;white-space:pre;overflow:auto;position:relative;max-height:340px}
    .g-lyr-ch{color:var(--accent,#f9c940);font-weight:700;font-size:16px;margin-top:4px}
    .g-lyr-tx{color:var(--text,#efeff1)}
    .g-lyr-h{color:var(--green,#61d1ad);font-weight:700;margin-top:10px}
    .g-lyr-pair{border-left:3px solid transparent;padding-left:8px;margin-left:-11px;border-radius:4px}
    .g-lyr-pair.cur{border-left-color:var(--accent,#f9c940);background:rgba(249,201,64,.12)}

    [hidden]{display:none!important}
    .g-note{color:var(--muted,#868899);font-size:13px;margin:6px 0}
    .g-hint{font-size:12px;color:var(--muted,#868899);margin:6px 0 0}
    @media(max-width:560px){
      #g-now{font-size:44px}#g-next{font-size:28px}
      .g-sl .g-a{font-size:17px}.g-sl .g-k{font-size:11px}
      .g-seg b{font-size:20px}
    }
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
  <p class="g-sub">Хобби, которое я только осваиваю. Здесь разборы песен для новичка: аккорды, бой и тренажёр с записью.</p>
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
  <a class="g-back" href="/guitar/">← Все песни</a>
  <h1><?= esc($song["title"]) ?> <span class="g-artist">— <?= esc($song["artist"]) ?></span></h1>
  <div class="g-pills">
    <span class="g-pill">Тональность <b><?= esc($song["key"]) ?></b></span>
    <span class="g-pill"><b><?= esc($song["capo"]) ?></b></span>
    <span class="g-pill">Сложность <b><?= esc($song["level"]) ?></b></span>
    <span class="g-pill">Аккорды <b><?= esc(implode(", ", $chords_of($song))) ?></b></span>
  </div>
  <p class="g-note"><?= esc($song["note"]) ?></p>

  <?php if ($audio_src !== null): ?>
  <h2>Тренажёр</h2>
  <div class="g-card">
    <audio id="g-au" src="<?= esc($audio_src) ?>" preload="metadata"></audio>
    <div class="g-stage">
      <div><div class="g-lbl">Сейчас</div><div id="g-now">—</div></div>
      <div><div class="g-lbl">Дальше</div><div id="g-next">—</div></div>
    </div>
    <span id="g-sec-now"></span>

    <div class="g-ctrl">
      <button type="button" class="g-btn" id="g-prev" title="К предыдущей смене аккорда">⏮ Аккорд</button>
      <button type="button" class="g-btn g-main" id="g-sp">▶ Играть</button>
      <button type="button" class="g-btn" id="g-nextch" title="К следующей смене аккорда">Аккорд ⏭</button>
      <span class="g-time" id="g-time">0:00</span>
      <span class="g-spacer"></span>
      <span class="g-lbl2">Скорость
        <button type="button" class="g-btn g-rate" data-r="0.5">0.5×</button>
        <button type="button" class="g-btn g-rate" data-r="0.75">0.75×</button>
        <button type="button" class="g-btn g-rate on" data-r="1">1×</button>
      </span>
      <span class="g-lbl2">Масштаб
        <button type="button" class="g-btn" id="g-zin" title="Приблизить">+</button>
        <span id="g-zlab">24 с</span>
        <button type="button" class="g-btn" id="g-zout" title="Отдалить">−</button>
      </span>
    </div>

    <div class="g-tlz" id="g-tlz"><div class="g-tlz-in" id="g-tlz-in"></div></div>
    <div class="g-ov" id="g-ov"></div>

    <div class="g-strumbox">
      <div class="g-ctrl" style="margin-bottom:4px">
        <label>Бой <select class="g-sel" id="g-strum-sel"></select></label>
        <span class="g-hint" style="margin:0">Цвет под ударом — аккорд, которым он играется. Яркие удары — для текущего аккорда.</span>
      </div>
      <div class="g-strum" id="g-bar-now"></div>
      <div class="g-lbl" style="margin-top:6px">Следующий такт</div>
      <div class="g-strum g-bar-next" id="g-bar-next"></div>
    </div>
    <p class="g-hint">Клик по шкале — перемотка. Смены аккордов определены по записи автоматически, поэтому возможны неточности.</p>
  </div>
  <?php else: ?>
  <h2>Бой</h2>
  <div class="g-card">
    <div class="g-ctrl" style="margin-bottom:4px"><label>Вариант <select class="g-sel" id="g-strum-sel"></select></label></div>
    <div id="g-strum-rows"></div>
    <p class="g-hint">↓ вниз · ↑ вверх · ✕ приглушённый.</p>
  </div>
  <?php endif; ?>

  <h2>Аккорды</h2>
  <div class="g-card">
    <div class="g-grid" id="g-chords"></div>
    <p class="g-hint">Линии — струны (слева самая толстая), цифры — пальцы (1 указательный … 4 мизинец), ○ — открытая струна, ✕ — не играть.</p>
  </div>

  <h2>Текст</h2>
  <div class="g-card">
    <div class="g-ctrl">
      <button type="button" class="g-btn" id="g-lyr-edit">Вставить / править</button>
      <button type="button" class="g-btn" id="g-lyr-load">Загрузить .txt</button>
      <input type="file" id="g-lyr-file" accept=".txt,text/plain" hidden>
    </div>
    <textarea id="g-lyr-ta" class="g-lyr-ta" rows="14" hidden spellcheck="false" placeholder="Аккорды над словами, как в обычном разборе"></textarea>
    <div id="g-lyr" class="g-lyr"></div>
    <p class="g-hint">Свой текст с аккордами над словами или .txt: хранится только в этом браузере, никуда не отправляется. Во время игры подсвечивается строка, которая сейчас играет.</p>
  </div>

  <p class="g-note" style="margin-top:16px">Аккорды составлены по любительским разборам и упрощены, поэтому могут отличаться от оригинала.</p>

  <script>window.GUITAR_SONG = <?= json_encode($song, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="/guitar/guitar.js?v=<?= (int)@filemtime(__DIR__ . "/guitar.js") ?>"></script>
<?php endif; ?>
</div>

</body>
</html>
