<?php
require_once __DIR__ . '/theme.php';
crypto_page_start('Линейный регистр сдвига');
?>
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
<?php crypto_page_end(); ?>
