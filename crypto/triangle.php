<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Я легенда?</title>
    <script>
        // Generate truth table for x4, x3, x2, x1
        function generateTruthTable() {
            const table = [];
            for (let i = 0; i < 16; i++) {
                const binary = i.toString(2).padStart(4, '0');
                table.push(binary.split('').map(Number));
            }
            return table;
        }

        // Generate Zhegalkin triangle from input
        function generateTriangle(input) {
            let row = input.split('').map(Number);
            const triangle = [row.slice()];

            while (row.length > 1) {
                row = row.slice(1).map((val, index) => row[index] ^ val);
                triangle.push(row);
            }
            return triangle;
        }

        // Build formula based on Zhegalkin triangle and truth table
        function buildFormula(triangle, truthTable) {
            const formula = [];
            let x1Count = 0;

            for (let i = 0; i < triangle.length; i++) {
                if (triangle[i][0] === 1) {
                    const terms = [];
                    for (let j = 0; j < 4; j++) {
                        if (truthTable[i][j] === 1) {
                            const variable = `x${4 - j}`;
                            terms.push(variable);
                            if (variable === 'x1') {
                                x1Count++;
                            }
                        }
                    }
                    formula.push(terms.join(''));
                }
            }

            const result = formula.join(' ⊕ ');
            const regularity = x1Count === 1 ? 'Регулярный т.к. x1 встречается 1 раз' : 'Не регулярный т.к. x1 встречается более 1 раза';
            return { formula: result || '0', regularity };
        }

        // Main function to handle input and output
        function handleInput() {
            const input = document.getElementById('input-string').value;
            const truthTable = generateTruthTable();
            const triangle = generateTriangle(input);

            // Display truth table
            const tableBody = document.getElementById('truth-table-body');
            tableBody.innerHTML = '';
            truthTable.forEach((row, index) => {
                const rowElement = document.createElement('tr');
                row.forEach(value => {
                    const cell = document.createElement('td');
                    cell.textContent = value;
                    rowElement.appendChild(cell);
                });
                const outputCell = document.createElement('td');
                outputCell.textContent = triangle[index][0];
                rowElement.appendChild(outputCell);
                tableBody.appendChild(rowElement);
            });

            // Display Zhegalkin triangle
            const triangleBody = document.getElementById('triangle-body');
            triangleBody.innerHTML = '';
            triangle.forEach(row => {
                const rowElement = document.createElement('tr');
                row.forEach(value => {
                    const cell = document.createElement('td');
                    cell.textContent = value;
                    rowElement.appendChild(cell);
                });
                triangleBody.appendChild(rowElement);
            });

            // Display formula and regularity
            const { formula, regularity } = buildFormula(triangle, truthTable);
            document.getElementById('formula').textContent = `${formula}`;
            document.getElementById('regularity').textContent = `${regularity}`;
        }
    </script>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin: 20px;
        }
        .result {
            margin: 20px;
            padding: 10px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        table {
            margin: 20px auto;
            border-collapse: collapse;
            width: 80%;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        th {
            background-color: #f2f2f2;
        }
        input[type="text"] {
            width: 300px;
            padding: 5px;
            margin: 10px;
        }
        button {
            padding: 5px 15px;
            margin: 10px;
            border: none;
            background-color: #007bff;
            color: white;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <a href="/" id="backButton">Назад</a>
    <h1>Полином Жегалкина</h1>
    <label for="input-string">Введите двоичную строку (16 бит):</label>
    <input type="text" id="input-string" maxlength="16" placeholder="например, 1010101010101010">
    <button onclick="handleInput()">Сгенерировать</button>

    <h2>Таблица истинности</h2>
    <table>
        <thead>
            <tr>
                <th>x4</th>
                <th>x3</th>
                <th>x2</th>
                <th>x1</th>
                <th>Из полинома</th>
            </tr>
        </thead>
        <tbody id="truth-table-body"></tbody>
    </table>

    <h2>Треугольник Жегалкина</h2>
    <table>
        <tbody id="triangle-body"></tbody>
    </table>

    <h2>Формула</h2>
    <p id="formula"></p>
    <h2>Ответ</h2>
    <p id="regularity"></p>
</body>
</html>
