<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Линейный регистр сдвига</title>
    <style>

        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin: 20px;
        }
        input[type="text"] {
            width: 300px;
            padding: 10px;
            margin: 10px;
        }
        button {
            padding: 10px 20px;
            margin: 20px;
            border: none;
            background-color: #007bff;
            color: white;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
        #error {
            color: red;
            margin-top: 20px;
        }
        #result {
            margin-top: 20px;
            text-align: left;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        img {
            max-width: 100%;
            height: auto;
        }
        #backButton {
            margin-top: 20px;
            display: inline-block;
            padding: 10px 15px;
            background-color: #007BFF;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        #backButton:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <a href="/" id="backButton">Назад</a>
    <h1>Линейный регистр сдвига</h1>
    <label for="sequence">Введите двоичную последовательность:</label>
    <input type="text" id="sequence" placeholder="Например, 111110111">
    <button id="calculate-button">Рассчитать</button>
    
    <div id="error"></div>
    <div id="result"></div>

    <script>
        document.getElementById('calculate-button').addEventListener('click', async () => {
            const sequenceInput = document.getElementById('sequence');
            const errorDiv = document.getElementById('error');
            const resultDiv = document.getElementById('result');

            errorDiv.textContent = '';
            resultDiv.innerHTML = '';

            const bits = sequenceInput.value.trim();
            
            if (!/^[01]+$/.test(bits)) {
                errorDiv.textContent = 'Ошибка: Введите корректную двоичную последовательность (только 0 и 1).';
                return;
            }

            try {
                const response = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ bits })
                });

                if (!response.ok) {
                    throw new Error(`Ошибка сервера: ${response.status}`);
                }

                const data = await response.json();
                resultDiv.innerHTML = "";
                if (data.error) {
                    errorDiv.textContent = `Ошибка: ${data.error}`;
                } else {
                    resultDiv.innerHTML = `<p>${data.text}</p>`;
                    data.images.forEach((image, index) => {
                        const img = document.createElement('img');
                        img.src = `data:image/png;base64,${image}`;
                        img.alt = `Изображение ${index + 1}`;
                        resultDiv.appendChild(img);
                    });
                }
            } catch (error) {
                errorDiv.textContent = `Ошибка: ${error.message}`;
            }
        });
    </script>
</body>
</html>
