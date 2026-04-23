<?php
try {
    $db = new PDO('sqlite:library.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

$db->exec("CREATE TABLE IF NOT EXISTS books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    author TEXT,
    year TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS secret_data (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    password TEXT
)");

$countBooks = $db->query("SELECT COUNT(*) FROM books")->fetchColumn();
if ($countBooks == 0) {
    $db->exec("INSERT INTO books (title, author, year) VALUES ('Война и мир', 'Лев Толстой', '1869')");
    $db->exec("INSERT INTO books (title, author, year) VALUES ('Преступление и наказание', 'Фёдор Достоевский', '1866')");
    $db->exec("INSERT INTO books (title, author, year) VALUES ('Анна Каренина', 'Лев Толстой', '1877')");
    $db->exec("INSERT INTO books (title, author, year) VALUES ('Идиот', 'Фёдор Достоевский', '1869')");
}

$countSecret = $db->query("SELECT COUNT(*) FROM secret_data")->fetchColumn();
if ($countSecret == 0) {
    $db->exec("INSERT INTO secret_data (username, password) VALUES ('hacker', 'hackpass123')");
    $db->exec("INSERT INTO secret_data (username, password) VALUES ('superuser', 'supersecret')");
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Онлайн-библиотека: Поиск книг по автору (защищённая страница)</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4faf4; padding: 20px; }
        h1 { color: #333; }
        form { background: #e0e0e0; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        label { font-weight: bold; }
        input[type="text"] { padding: 8px; width: 600px; font-size: 16px; margin-top: 5px; }
        input[type="submit"] { padding: 8px 15px; font-size: 16px; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #bbb; padding: 10px; text-align: left; }
        th { background-color: #ddd; }
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
<body>
<div class="header">
    <a href="injection.php" class="back-link">&larr; Назад</a>
    <nav class="nav-links">
        <a href="unsafe_sql.php">SQL</a>
        <a href="unsafe_script.php">XSS</a>
        <a href="unsafe_file.php">LFI</a>
    </nav>
</div>
    <h1>Поиск книг по автору</h1>
    <p>Введите часть имени автора для поиска книги в нашей онлайн-библиотеке.</p>
    <form method="GET" action="">
        <label for="author">Автор:</label><br>
        <input type="text" name="author" id="author" placeholder="Например, Толстой" /><br>
        <input type="submit" value="Найти книги" />
    </form>
<?php
if (isset($_GET['author'])) {
    $author = $_GET['author'];
    if ($author === "") {
        $query = "SELECT id, title, author, year FROM books";
        $stmt = $db->query($query);
    } else {
        $query = "SELECT id, title, author, year FROM books WHERE author LIKE :author";
        $stmt = $db->prepare($query);
        $stmt->execute(['author' => "%" . $author . "%"]);
    }
    echo "<div class='query'>Выполняется запрос: " . htmlspecialchars($query) . "</div>";
    
    try {
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($rows) > 0) {
            echo "<table>";
            echo "<tr><th>ID</th><th>Название</th><th>Автор</th><th>Год</th></tr>";
            foreach ($rows as $row) {
                echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['title']) . "</td>";
                echo "<td>" . htmlspecialchars($row['author']) . "</td>";
                echo "<td>" . htmlspecialchars($row['year']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>Книги не найдены.</p>";
        }
    } catch (PDOException $ex) {
        echo "<p class='error'>Ошибка выполнения запроса: " . htmlspecialchars($ex->getMessage()) . "</p>";
    }
}
?>
    <div>
        <p>Внимание: Эта страница защищена от SQL-инъекций.
           Например, попробуйте ввести следующий запрос в поле поиска:</p>
        <p>' UNION SELECT id, username, password, 1 FROM secret_data -- </p>
        <p>Теперь страница защищена от инъекций</p>
    </div>
</body>
</html>
