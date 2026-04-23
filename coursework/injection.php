<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Онлайн-библиотека: Демонстрация уязвимостей</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f9f9f9;
            margin: 0;
            padding: 40px;
        }
        .back-button {
            position: absolute;
            top: 20px;
            left: 20px;
        }
        .back-button a {
            display: inline-block;
            padding: 10px 15px;
            background: #e0e0e0;
            color: #333;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
        }
        .back-button a:hover {
            background: #d5d5d5;
        }
        h1 {
            text-align: center;
            color: #333;
        }
        h2.group {
            margin-top: 60px;
            color: #444;
            text-align: center;
            font-size: 24px;
        }
        .links {
            max-width: 800px;
            margin: 20px auto 0 auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .block {
            padding: 20px;
            border-radius: 8px;
            background: #fff;
            border-left: 6px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            cursor: pointer;
            transition: background 0.2s;
        }
        .block:hover {
            background: #f0f0f0;
        }
        .safe {
            border-color: #2c8b4b;
        }
        .unsafe {
            border-color: #c0392b;
        }
        .block-title {
            margin: 0;
            font-size: 18px;
            font-weight: bold;
            color: #222;
        }
    </style>
</head>
<body>

    <div class="back-button">
        <a href="https://xelopat.ru">&larr; Назад на сайт</a>
    </div>

    <h1>Онлайн-библиотека — Демонстрация уязвимостей</h1>

    <h2 class="group">Уязвимые реализации</h2>
    <div class="links">
        <div class="block unsafe" onclick="location.href='unsafe_sql.php'">
            <p class="block-title">SQL-инъекция</p>
        </div>
        <div class="block unsafe" onclick="location.href='unsafe_script.php'">
            <p class="block-title">XSS — межсайтовый скриптинг</p>
        </div>
        <div class="block unsafe" onclick="location.href='unsafe_file.php'">
            <p class="block-title">LFI — локальное включение файлов</p>
        </div>
    </div>

    <h2 class="group">Защищённые реализации</h2>
    <div class="links">
        <div class="block safe" onclick="location.href='safe_sql.php'">
            <p class="block-title">SQL-инъекция</p>
        </div>
        <div class="block safe" onclick="location.href='safe_script.php'">
            <p class="block-title">XSS — межсайтовый скриптинг</p>
        </div>
        <div class="block safe" onclick="location.href='safe_file.php'">
            <p class="block-title">LFI — локальное включение файлов</p>
        </div>
    </div>

    <h2 class="group">Скачать материалы</h2>
    <div class="links">
        <div class="block" onclick="location.href='_Курсовая_Ульянов.pptx'">
            <p class="block-title">Презентация (PPTX)</p>
        </div>
        <div class="block" onclick="location.href='_Курсовая_Ульянов.docx'">
            <p class="block-title">Курсовая работа (DOCX)</p>
        </div>
    </div>
</body>
</html>
