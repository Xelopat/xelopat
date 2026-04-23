<?php
require_once __DIR__ . '/theme.php';
crypto_page_start('Полином Жегалкина');
?>
<label for="input-string">Введите двоичную строку (16 бит):</label>
<input type="text" id="input-string" maxlength="16" placeholder="например, 1010101010101010">
<button onclick="handleInput()">Сгенерировать</button>

<h2>Таблица истинности</h2>
<div class="table-wrap">
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
</div>

<h2>Треугольник Жегалкина</h2>
<div class="table-wrap">
    <table>
        <tbody id="triangle-body"></tbody>
    </table>
</div>

<h2>Формула</h2>
<div id="formula"></div>
<h2>Ответ</h2>
<div id="regularity"></div>

<script>
    function generateTruthTable() {
        const table = [];
        for (let i = 0; i < 16; i++) {
            const binary = i.toString(2).padStart(4, '0');
            table.push(binary.split('').map(Number));
        }
        return table;
    }

    function generateTriangle(input) {
        let row = input.split('').map(Number);
        const triangle = [row.slice()];

        while (row.length > 1) {
            row = row.slice(1).map((val, index) => row[index] ^ val);
            triangle.push(row);
        }
        return triangle;
    }

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

    function handleInput() {
        const input = document.getElementById('input-string').value;
        const truthTable = generateTruthTable();
        const triangle = generateTriangle(input);

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

        const { formula, regularity } = buildFormula(triangle, truthTable);
        document.getElementById('formula').textContent = formula;
        document.getElementById('regularity').textContent = regularity;
    }
</script>
<?php crypto_page_end(); ?>
