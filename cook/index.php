<?php
// /cook/index.php
declare(strict_types=1);

require_once $_SERVER["DOCUMENT_ROOT"] . "/auth/lib.php";

/*
  Калькулятор мармелада.
  Просмотр доступен всем.

  Расчет идет "на 100 г основы" (пюре/сок/вода).
  Поле "Сколько граммов основы" = сколько граммов основы.

  Выбор пользователя:
    1) плотность
    2) граммы основы
    3) обсыпка

  Кислота по умолчанию: только яблочная (без выбора на странице).
*/

if (!function_exists("esc")) {
    function esc(string $s): string {
        if (function_exists("h")) return h($s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
}

$cfg = [
    "densities" => [
        "soft" => [
            "title" => "Мягкий",
            "sweet" => [
                "sugar_min" => 38.0,
                "sugar_max" => 38.0,
                "pectin" => 2.0,
                "acid_total" => 0.15, // яблочная
                "texture" => "желе",
            ],
            "sour" => [
                "sugar_min" => 34.0,
                "sugar_max" => 35.0,
                "pectin" => 2.0,
                "acid_total" => 0.15, // яблочная
                "texture" => "желе",
            ],
        ],
        "classic" => [
            "title" => "Классический",
            "sweet" => [
                "sugar_min" => 42.0,
                "sugar_max" => 42.0,
                "pectin" => 2.5,
                "acid_total" => 0.20,
                "texture" => "классика",
            ],
            "sour" => [
                "sugar_min" => 38.0,
                "sugar_max" => 40.0,
                "pectin" => 2.5,
                "acid_total" => 0.20,
                "texture" => "классика",
            ],
        ],
        "dense" => [
            "title" => "Плотный",
            "sweet" => [
                "sugar_min" => 45.0,
                "sugar_max" => 45.0,
                "pectin" => 3.0,
                "acid_total" => 0.25,
                "texture" => "плотный",
            ],
            "sour" => [
                "sugar_min" => 40.0,
                "sugar_max" => 42.0,
                "pectin" => 3.0,
                "acid_total" => 0.25,
                "texture" => "плотный",
            ],
        ],
        "chewy" => [
            "title" => "Очень плотный / жевательный",
            "sweet" => [
                "sugar_min" => 45.0,
                "sugar_max" => 47.0,
                "pectin" => 3.2,
                "acid_total" => 0.30,
                "texture" => "жевательный",
            ],
            "sour" => [
                "sugar_min" => 40.0,
                "sugar_max" => 42.0,
                "pectin" => 3.2,
                "acid_total" => 0.30,
                "texture" => "жевательный",
            ],
        ],
    ],

    "powders" => [
        "sweet" => [
            "title" => "Сладкая",
            "mode" => "sweet",
            "sugar_powder_min" => 6.0,
            "sugar_powder_max" => 8.0,
            "malic" => 0.0,
            "citric" => 0.0,
        ],
        "sour_classic" => [
            "title" => "Кислая (классика)",
            "mode" => "sour",
            "sugar_powder_min" => 4.5,
            "sugar_powder_max" => 4.5,
            "malic" => 1.0,
            "citric" => 0.5,
        ],
        "sour_very" => [
            "title" => "Кислая (очень кислая)",
            "mode" => "sour",
            "sugar_powder_min" => 4.0,
            "sugar_powder_max" => 4.0,
            "malic" => 1.2,
            "citric" => 0.8,
        ],
    ],

    "auto_rules" => [
        "sugar_down_if_sour_powder" => 0.92, // -8%
    ],
];

$cfg_json = json_encode(
    $cfg,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($cfg_json === false) $cfg_json = "{}";
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Конструктор мармелада</title>
  <style>
    *,*::before,*::after{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial;background:#f6f7fb;color:#0f172a}
    .page{max-width:980px;margin:0 auto;padding:18px}
    h1{margin:14px 0 0 0;font-size:22px}
    .muted{color:#64748b;font-size:13px;line-height:1.4}
    .card{
      background:#fff;border:1px solid #e2e8f0;border-radius:14px;
      box-shadow:0 14px 40px rgba(2,6,23,0.08);padding:14px;margin-top:12px
    }
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width: 820px){ .grid{grid-template-columns:1fr} }
    label{display:block;font-size:12px;color:#334155;margin-top:10px}
    select, input[type="number"]{
      width:100%;margin-top:6px;border:1px solid #e2e8f0;border-radius:12px;padding:10px;font:inherit;outline:none;background:#fff
    }
    .hr{height:1px;background:#e2e8f0;margin:12px 0}
    .kpi{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
    .chip{font-size:12px;color:#475569;border:1px solid #e2e8f0;border-radius:999px;padding:4px 8px;background:#fff}
    pre{
      margin:10px 0 0 0;
      padding:12px;
      border:1px solid #e2e8f0;
      border-radius:12px;
      background:#fbfcff;
      overflow:auto;
      font-size:13px;
      line-height:1.35;
      white-space:pre-wrap;
    }
  </style>
</head>
<body>

<?php @include $_SERVER["DOCUMENT_ROOT"] . "/header.php"; ?>

<div class="page">
  <h1>Конструктор мармелада</h1>
  <div class="muted">Выбираешь плотность, граммы основы и обсыпку. Пропорции считаются автоматически.</div>

  <div class="card">
    <div class="grid">
      <div>
        <label>Плотность</label>
        <select id="density">
          <option value="soft">Мягкий</option>
          <option value="classic">Классический</option>
          <option value="dense">Плотный</option>
          <option value="chewy">Очень плотный / жевательный</option>
        </select>

        <label>Сколько граммов основы (пюре/сок/вода)</label>
        <input type="number" id="grams" min="10" step="1" value="100">
        <div class="muted" style="margin-top:6px;">Кислота по умолчанию: яблочная.</div>
      </div>

      <div>
        <label>Обсыпка</label>
        <select id="powder">
          <option value="sweet">Сладкая</option>
          <option value="sour_classic">Кислая (классика)</option>
          <option value="sour_very">Кислая (очень кислая)</option>
        </select>
      </div>
    </div>

    <div class="hr"></div>

    <div class="kpi" id="kpi"></div>
    <pre id="result"></pre>
  </div>
</div>

<script>
  const CFG = <?= $cfg_json ?>;

  function clamp(n, a, b){
    if (Number.isNaN(n)) return a;
    if (n < a) return a;
    if (n > b) return b;
    return n;
  }

  function roundSmart(x){
    if (!isFinite(x)) return 0;
    const abs = Math.abs(x);
    if (abs >= 10) return Math.round(x * 10) / 10;
    if (abs >= 1)  return Math.round(x * 100) / 100;
    return Math.round(x * 1000) / 1000;
  }

  function pickSugar(baseRow){
    const a = Number(baseRow.sugar_min || 0);
    const b = Number(baseRow.sugar_max || 0);
    if (a === b) return a;
    return (a + b) / 2; // чтобы результат был однозначный
  }

  function calc(){
    const densityKey = document.getElementById("density").value;
    const powderKey = document.getElementById("powder").value;

    const gramsRaw = parseFloat(document.getElementById("grams").value);
    const grams = clamp(gramsRaw, 10, 100000);

    const powder = CFG.powders[powderKey];
    const density = CFG.densities[densityKey];
    const powderMode = powder.mode; // sweet / sour
    const baseRow = density[powderMode];
    const rules = CFG.auto_rules;

    const factor = grams / 100.0;

    let sugarInside = pickSugar(baseRow);
    let pectin = Number(baseRow.pectin || 0);
    const malic = Number(baseRow.acid_total || 0); // всегда яблочная

    // кислая обсыпка => -8% сахара внутри
    if (powderMode === "sour") {
      sugarInside = sugarInside * Number(rules.sugar_down_if_sour_powder || 1);
    }

    const sugarInside_g = sugarInside * factor;
    const pectin_g = pectin * factor;
    const malic_g = malic * factor;

    // обсыпка (на 100 г мармелада) считаем от grams как приближение
    const powderSugar = ((Number(powder.sugar_powder_min) + Number(powder.sugar_powder_max)) / 2) * factor;
    const powderMalic = Number(powder.malic || 0) * factor;
    const powderCitric = Number(powder.citric || 0) * factor;

    const kpi = document.getElementById("kpi");
    kpi.innerHTML = "";
    const chips = [
      "Основа: " + grams + " г",
      "Сахар: " + roundSmart(sugarInside_g) + " г",
      "Пектин NH: " + roundSmart(pectin_g) + " г",
      "Яблочная кислота: " + roundSmart(malic_g) + " г",
      "Обсыпка: " + (powder.title || ""),
    ];
    for (const t of chips){
      const s = document.createElement("span");
      s.className = "chip";
      s.textContent = t;
      kpi.appendChild(s);
    }

    const lines = [];
    lines.push("ИНГРЕДИЕНТЫ");
    lines.push("- Основа: " + grams + " г");
    lines.push("- Сахар: " + roundSmart(sugarInside_g) + " г");
    lines.push("- Пектин NH: " + roundSmart(pectin_g) + " г");
    lines.push("- Яблочная кислота: " + roundSmart(malic_g) + " г");
    lines.push("");
    lines.push("ОБСЫПКА");
    lines.push("- Сахарная пудра: " + roundSmart(powderSugar) + " г");
    if ((powderMalic + powderCitric) > 0) {
      lines.push("- Яблочная кислота: " + roundSmart(powderMalic) + " г");
      lines.push("- Лимонная кислота: " + roundSmart(powderCitric) + " г");
    }
    lines.push("");
    lines.push("РЕЦЕПТ");
    lines.push("1) Смешай сахар с пектином (сухая смесь).");
    lines.push("2) Влей в основу, интенсивно размешивая, чтобы не было комков.");
    lines.push("3) Нагревай до уверенного кипения, провари 30-60 секунд.");
    lines.push("4) Сними с огня и добавь яблочную кислоту, быстро размешай.");
    lines.push("5) Разлей в форму, остуди до стабилизации.");
    lines.push("6) Нарежь, обваляй в обсыпке и дай подсохнуть 30-60 минут.");

    document.getElementById("result").textContent = lines.join("\n");
  }

  ["density","grams","powder"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener("change", calc);
    if (el && id === "grams") el.addEventListener("input", calc);
  });

  calc();
</script>

</body>
</html>
