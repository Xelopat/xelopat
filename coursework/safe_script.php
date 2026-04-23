<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Онлайн-библиотека: Отзывы о книге (уязвимая страница)</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4faf4; padding: 20px; }
        h1 { color: #333; }
        form { background: #e0e0e0; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        label { font-weight: bold; }
        input[type="text"] { padding: 8px; width: 320px; font-size: 16px; margin-top: 5px; }
        input[type="submit"] { padding: 8px 15px; font-size: 16px; margin-top: 10px; }
		.review-box { background: #fff; border: 1px solid #ccc; padding: 10px; margin-top: 20px; }
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
    <h1>Отзывы о книгах</h1>
    <p>Оставьте отзыв о книге. Эта страница уязвима к XSS, поскольку введённый текст выводится без экранирования.</p>
    <form method="GET" action="">
        <label for="book">Название книги:</label><br>
        <input type="text" name="book" id="book" placeholder="Например, Война и мир" /><br>
        <label for="review">Ваш отзыв:</label><br>
        <textarea name="review" id="review" placeholder="Введите ваш отзыв"></textarea><br>
        <input type="submit" value="Оставить отзыв" />
    </form>
<?php
if (isset($_GET['review'])) {
    $book = isset($_GET['book']) && trim($_GET['book']) !== "" ? $_GET['book'] : "Не указано";
    $review = $_GET['review'];
    echo "<div>";
     echo "<h2>Отзыв о книге: " . htmlspecialchars($book, ENT_QUOTES, 'UTF-8') . "</h2>";
    echo "<p>" . nl2br(htmlspecialchars($review, ENT_QUOTES, 'UTF-8')) . "</p>";
    echo "</div>";
}
?>
    <div>
        <p><strong>Внимание:</strong> Если ввести в поле отзыва JavaScript-код, он будет выполнен в браузере пользователя.</p>
        <p>Например, попробуйте ввести в поле отзыва: &lt;script&gt;alert('XSS!');&lt;/script&gt;</p>
    </div>
</body>
</html>
