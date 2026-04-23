<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Сложение точек на эллиптической кривой</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        label, input, button, div {
            display: block;
            margin-bottom: 10px;
        }
        #steps {
            margin-top: 20px;
            white-space: pre-line;
            font-family: monospace;
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
    <h1>Сложение точек на эллиптической кривой</h1>
    <label for="p">Модуль p:</label>
    <input type="number" id="p" placeholder="Введите модуль p" value="7">

    <label for="a">Коэффициент a:</label>
    <input type="number" id="a" placeholder="Введите коэффициент a" value="2">

    <label for="dot1">Точка 1 (x1, y1):</label>
    <input id="dot1" placeholder="Введите x1, y1 через запятую" value="1,3">

    <label for="dot2">Точка 2 (x2, y2):</label>
    <input id="dot2" placeholder="Введите x2, y2 через запятую" value="1,3">

    <button id="calculate">Сложить точки</button>

    <div id="result"></div>
    <div id="steps"></div>

    

    <script>
        function mod(a, m) {
            return ((a % m) + m) % m;
        }

        function modInverse(a, m) {
            for (let x = 1; x < m; x++) {
                if ((a * x) % m === 1) {
                    return x;
                }
            }
            throw new Error(`Нет обратного элемента для ${a} по модулю ${m}`);
        }

        document.getElementById('calculate').addEventListener('click', function () {
            const p = parseInt(document.getElementById('p').value, 10);
            const a = parseInt(document.getElementById('a').value, 10);

            const [x1, y1] = document.getElementById('dot1').value.split(',').map(Number);
            const [x2, y2] = document.getElementById('dot2').value.split(',').map(Number);

            let steps = "";
            let x3, y3;

            if (x1 === x2 && y1 === y2) {
                if (y1 === 0) {
                    steps += "y1 = 0 → результат: точка на бесконечности (удвоение невозможно).\n";
                    document.getElementById('steps').textContent = steps;
                    document.getElementById('result').textContent = "Результат: точка на бесконечности";
                    return;
                }

                steps += "Удвоение точки:\n";
                const numerator = mod(3 * x1 * x1 + a, p);
                const denominator = mod(2 * y1, p);
                const invDenominator = modInverse(denominator, p);
                const k = mod(numerator * invDenominator, p);

                steps += `   k = (3 * x1² + a) / (2 * y1) mod p\n`;
                steps += `   k = (${3} * ${x1}² + ${a}) / (${2} * ${y1}) mod ${p}\n`;
                steps += `   k = ${numerator} / ${denominator} mod ${p}\n`;
                steps += `   k = ${numerator} * (${denominator})⁻¹ mod ${p}\n`;
                steps += `   (${denominator})⁻¹ mod ${p} = ${invDenominator}\n`;
                steps += `   k = ${k}\n\n`;

                x3 = mod(k * k - 2 * x1, p);
                steps += `   x3 = k² - 2 * x1 mod p\n`;
                steps += `   x3 = ${k}² - 2 * ${x1} mod ${p}\n`;
                steps += `   x3 = ${x3}\n\n`;

                y3 = mod(k * (x1 - x3) - y1, p);
                steps += `   y3 = k * (x1 - x3) - y1 mod p\n`;
                steps += `   y3 = ${k} * (${x1} - ${x3}) - ${y1} mod ${p}\n`;
                steps += `   y3 = ${y3}\n`;

            } else {
                if (x1 === x2 && y1 !== y2) {
                    steps += "x1 = x2 и y1 ≠ y2 → результат: точка на бесконечности.\n";
                    document.getElementById('steps').textContent = steps;
                    document.getElementById('result').textContent = "Результат: точка на бесконечности";
                    return;
                }

                steps += "Сложение двух разных точек:\n";
                const numerator = mod(y2 - y1, p);
                const denominator = mod(x2 - x1, p);
                const invDenominator = modInverse(denominator, p);
                const k = mod(numerator * invDenominator, p);

                steps += `   k = (y2 - y1) / (x2 - x1) mod p\n`;
                steps += `   k = (${y2} - ${y1}) / (${x2} - ${x1}) mod ${p}\n`;
                steps += `   k = ${numerator} / ${denominator} mod ${p}\n`;
                steps += `   k = ${numerator} * (${denominator})⁻¹ mod ${p}\n`;
                steps += `   (${denominator})⁻¹ mod ${p} = ${invDenominator}\n`;
                steps += `   k = ${k}\n\n`;

                x3 = mod(k * k - x1 - x2, p);
                steps += `   x3 = k² - x1 - x2 mod p\n`;
                steps += `   x3 = ${k}² - ${x1} - ${x2} mod ${p}\n`;
                steps += `   x3 = ${x3}\n\n`;

                y3 = mod(k * (x1 - x3) - y1, p);
                steps += `   y3 = k * (x1 - x3) - y1 mod p\n`;
                steps += `   y3 = ${k} * (${x1} - ${x3}) - ${y1} mod ${p}\n`;
                steps += `   y3 = ${y3}\n`;
            }

            document.getElementById('result').textContent = `Результат: (${x3}, ${y3})`;
            document.getElementById('steps').textContent = steps;
        });
    </script>
</body>
</html>
