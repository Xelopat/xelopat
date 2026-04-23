<?php

$baseDir = realpath(__DIR__ . '/books');
if ($baseDir === false) {
    die("Ошибка: директория 'books' не найдена.");
}

function isTraversalAllowed($input) {
    return strpos($input, '../../') === false;
}

$book = isset($_GET['book']) ? $_GET['book'] : '';

$targetPath = ($book !== '') ? realpath($baseDir . '/' . $book) : false;

?>
<!DOCTYPE html>
<html lang="ru">
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Онлайн-библиотека: Чтение файлов книг (LFI)</title>
    <style>
        body { font-family: Arial, sans-serif; background: #fff4f4; padding: 20px; }
        h1, h2 { color: #333; }
        form { background: #e0e0e0; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        label { font-weight: bold; }
        input[type="text"], input[type="number"] { padding: 8px; width: 300px; font-size: 16px; }
        input[type="submit"] { padding: 8px 15px; font-size: 16px; }
        pre { background: #fff; border: 1px solid #ccc; padding: 10px; white-space: pre-wrap; }
        ul { list-style: none; padding: 0; }
        li { margin-bottom: 5px; }
        .no-file, .error {
            text-align: center;
            font-size: 18px;
            color: #a00;
            margin-top: 20px;
        }
        .book-content {
            max-width: 800px;
            margin: 20px auto;
            padding: 30px;
            background: #fafafa;
            border: 1px solid #ddd;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            font-family: "Georgia", serif;
            font-size: 18px;
            line-height: 1.6;
            text-align: justify;
            white-space: pre-wrap;
        }
        .pagination {
            max-width: 800px;
            margin: 20px auto;
            text-align: center;
        }
        .pagination a, .pagination strong {
            margin: 0 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 18px;
        }
        .pagination a { color: #3366cc; }
        .pagination span { margin: 0 5px; }
        .pagination form {
            display: inline-block;
            margin-left: 20px;
        }
        .header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    background: #eee;
    border-radius: 6px;
    margin-bottom: 20px;
    position: relative;
}
.header-left {
    flex: 1;
}
.header-center {
    flex: 2;
    text-align: center;
}
.back-link {
    text-decoration: none;
    font-weight: bold;
    color: #333;
}
.nav-links a {
    margin: 0 15px;
    text-decoration: none;
    color: #0077cc;
    font-weight: bold;
    font-size: 16px;
}
.nav-links a:hover {
    text-decoration: underline;
}

    </style>
</head>
<div class="header">
    <a href="injection.php" class="back-link">&larr; Назад</a>
    <nav class="nav-links">
        <a href="unsafe_sql.php">SQL</a>
        <a href="unsafe_script.php">XSS</a>
        <a href="unsafe_file.php">LFI</a>
    </nav>
</div>
<body>
    <h1>Чтение файлов книг</h1>
    <p>Выберите книгу из списка или введите название файла вручную. Допускается переход на один уровень вверх (../), но не более.</p>
    
    <h2>Список файлов в папке "books":</h2>
    <ul>
    <?php
    $files = scandir($baseDir);
    foreach ($files as $file) {
        if (substr($file, -4) === '.txt') {
            echo "<li><a href=\"?book=" . urlencode($file) . "\">" . htmlspecialchars($file) . "</a></li>";
        }
    }
    ?>
    </ul>
    
    <form method="GET" action="">
        <label for="book">Название файла:</label><br>
        <input type="text" name="book" id="book" value="<?php echo htmlspecialchars($book); ?>" /><br><br>
        <input type="submit" value="Показать содержимое" />
    </form>
    
    <h2>Содержимое файла:</h2>
    <pre>
<?php
if ($book === '') {
    echo "<p class='no-file'>Файл не выбран.</p>";
} else {

    if (strpos($book, 'http') !== false){
        $filePath = $book;
        echo "Больше не работает в целях безопасности";
        exit();
    }
    $filePath = realpath($baseDir . '/' . $book);
    if ($filePath === false) {
        echo "<p class='error'>Недопустимый путь или файл не существует.</p>";
    } else {
        $fullContent = file_get_contents($filePath);
        $safeContent = htmlspecialchars($fullContent, ENT_QUOTES, 'UTF-8');

        $lines = preg_split('/\r\n|\r|\n/', $safeContent);
        $linesPerPage = 40;
        $totalLines = count($lines);
        $totalPages = ceil($totalLines / $linesPerPage);
        $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($currentPage < 1) { $currentPage = 1; }
        if ($currentPage > $totalPages) { $currentPage = $totalPages; }
        $startLine = ($currentPage - 1) * $linesPerPage;
        $pageLines = array_slice($lines, $startLine, $linesPerPage);
        $pageContentFormatted = implode("<br>", $pageLines);
        echo "<div class='book-content'>{$pageContentFormatted}</div>";
        
        echo "<div class='pagination'>";
        
        if ($totalPages <= 5) {
            for ($i = 1; $i <= $totalPages; $i++) {
                if ($i == $currentPage) {
                    echo "<strong>$i</strong>";
                } else {
                    echo "<a href=\"?book=" . urlencode($book) . "&amp;page=$i\">$i</a>";
                }
            }
        } else {
            if ($currentPage == 1) {
                echo "<strong>1</strong>";
            } else {
                echo "<a href=\"?book=" . urlencode($book) . "&amp;page=1\">1</a>";
            }
            
            if ($currentPage > 3) {
                echo "<span>...</span>";
            }
            
            $startBlock = max(2, $currentPage - 1);
            $endBlock = min($startBlock + 2, $totalPages - 1);
            for ($i = $startBlock; $i <= $endBlock; $i++) {
                if ($i == $currentPage) {
                    echo "<strong>$i</strong>";
                } else {
                    echo "<a href=\"?book=" . urlencode($book) . "&amp;page=$i\">$i</a>";
                }
            }
            
            if ($endBlock < $totalPages - 1) {
                echo "<span>...</span>";
            }
            
            if ($currentPage == $totalPages) {
                echo "<strong>$totalPages</strong>";
            } else {
                echo "<a href=\"?book=" . urlencode($book) . "&amp;page=$totalPages\">$totalPages</a>";
            }
        }
        
        echo "<form method='GET' action=''>";
        echo "<input type='hidden' name='book' value=\"" . htmlspecialchars($book) . "\">";
        echo " Перейти на страницу: <input type='number' name='page' min='1' max='$totalPages' value='$currentPage' style='width:60px;'>";
        echo "<input type='submit' value='Перейти'>";
        echo "</form>";
        
        echo "</div>";
    }
}
?>
    </pre>
</body>
</html>
