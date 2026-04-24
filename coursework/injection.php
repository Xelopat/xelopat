<?php
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Inter:wght@400;500;700&display=swap');

  .cw-page{
    min-height:calc(100vh - 60px);
    background:#151518;
    color:#efeff1;
    padding:28px 0 40px;
  }

  .cw-wrap{
    width:min(1080px, calc(100vw - 24px));
    margin:0 auto;
  }

  .cw-head{
    margin-bottom:20px;
  }

  .cw-label{
    font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:11px;
    color:#61d1ad;
    margin-bottom:6px;
  }

  .cw-title{
    margin:0 0 10px;
    font-size:30px;
    line-height:1.2;
  }

  .cw-sub{
    margin:0;
    color:#a4a8bb;
    font-size:14px;
    line-height:1.6;
    max-width:820px;
  }

  .cw-group{
    margin-top:18px;
    background:#1e1e25;
    border:1px solid #333340;
    border-radius:12px;
    padding:14px;
  }

  .cw-group-title{
    margin:0 0 10px;
    font-size:14px;
    font-weight:700;
    letter-spacing:.01em;
  }

  .cw-group-title--unsafe{ color:#ff9b9b; }
  .cw-group-title--safe{ color:#61d1ad; }
  .cw-group-title--files{ color:#f9c940; }

  .cw-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:10px;
  }

  .cw-item{
    display:block;
    background:#151518;
    border:1px solid #333340;
    border-radius:10px;
    padding:12px;
    text-decoration:none;
    color:#efeff1;
    transition:border-color .16s ease, transform .16s ease;
  }

  .cw-item:hover{
    border-color:#f9c940;
    transform:translateY(-1px);
  }

  .cw-item-title{
    margin:0 0 4px;
    font-size:15px;
    font-weight:700;
  }

  .cw-item-desc{
    margin:0;
    font-size:12px;
    color:#a4a8bb;
    line-height:1.5;
  }

  @media (max-width: 900px){
    .cw-wrap{ width:calc(100vw - 20px); }
    .cw-title{ font-size:26px; }
    .cw-grid{ grid-template-columns:1fr; }
  }
</style>

<main class="cw-page">
  <div class="cw-wrap">
    <header class="cw-head">
      <div class="cw-label">// курсовая</div>
      <h1 class="cw-title">Онлайн-библиотека: демонстрация уязвимостей</h1>
      <p class="cw-sub">Раздел с наглядными примерами уязвимой и защищённой реализации (SQL-инъекции, XSS и LFI) плюс материалы по курсовой работе.</p>
    </header>

    <section class="cw-group">
      <h2 class="cw-group-title cw-group-title--unsafe">Уязвимые реализации</h2>
      <div class="cw-grid">
        <a class="cw-item" href="unsafe_sql.php">
          <p class="cw-item-title">SQL-инъекция</p>
          <p class="cw-item-desc">Поиск книг с небезопасной сборкой SQL-запроса.</p>
        </a>
        <a class="cw-item" href="unsafe_script.php">
          <p class="cw-item-title">XSS</p>
          <p class="cw-item-desc">Отзыв без экранирования пользовательского ввода.</p>
        </a>
        <a class="cw-item" href="unsafe_file.php">
          <p class="cw-item-title">LFI</p>
          <p class="cw-item-desc">Чтение файлов с уязвимой логикой выбора пути.</p>
        </a>
      </div>
    </section>

    <section class="cw-group">
      <h2 class="cw-group-title cw-group-title--safe">Защищённые реализации</h2>
      <div class="cw-grid">
        <a class="cw-item" href="safe_sql.php">
          <p class="cw-item-title">SQL-инъекция</p>
          <p class="cw-item-desc">Подготовленные выражения и безопасная обработка ввода.</p>
        </a>
        <a class="cw-item" href="safe_script.php">
          <p class="cw-item-title">XSS</p>
          <p class="cw-item-desc">Экранирование данных перед выводом в HTML.</p>
        </a>
        <a class="cw-item" href="safe_file.php">
          <p class="cw-item-title">LFI</p>
          <p class="cw-item-desc">Проверка пути и ограничение доступа к файлам.</p>
        </a>
      </div>
    </section>

    <section class="cw-group">
      <h2 class="cw-group-title cw-group-title--files">Материалы</h2>
      <div class="cw-grid">
        <a class="cw-item" href="_Курсовая_Ульянов.pptx">
          <p class="cw-item-title">Презентация (PPTX)</p>
          <p class="cw-item-desc">Слайды по основной части курсовой работы.</p>
        </a>
        <a class="cw-item" href="_Курсовая_Ульянов.docx">
          <p class="cw-item-title">Текст работы (DOCX)</p>
          <p class="cw-item-desc">Полная версия курсовой для скачивания.</p>
        </a>
      </div>
    </section>
  </div>
</main>
