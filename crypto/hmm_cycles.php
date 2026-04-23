<?php
require_once __DIR__ . '/theme.php';
crypto_page_start('Построение циклов по формуле');
?>
    <script>
        function evaluateFormula(formula, x4, x3, x2, x1) {
            const parsedFormula = formula
                .replace(/x1/g, x1)
                .replace(/x2/g, x2)
                .replace(/x3/g, x3)
                .replace(/x4/g, x4);
            return parsedFormula.split('+').reduce((acc, term) => {
                const value = term.split('').reduce((prod, variable) => prod * Number(variable), 1);
                return (acc + value) % 2;
            }, 0);
        }

        function generateSingleCycle(formula, startN, visitedGlobal) {
            const cycle = [];
            const visited = new Set();
            let currentState = startN.toString(2).padStart(4, '0');

            let firstState = currentState;
            let firstN = parseInt(firstState, 2);

            cycle.push({ bits: firstState, N: firstN });

            while (!visited.has(currentState)) {
                visited.add(currentState);
                visitedGlobal.add(currentState);

                const [x4, x3, x2, x1] = currentState.split('').map(Number);
                const newX4 = evaluateFormula(formula, x4, x3, x2, x1);
                currentState = `${newX4}${x4}${x3}${x2}`;
                const decimalValue = parseInt(currentState, 2);

                cycle.push({ bits: currentState, N: decimalValue });
            }
            

            return cycle;
        }

        function generateAllCycles(formula) {
            const allCycles = [];
            const visitedGlobal = new Set();

            const numbers = Array.from({ length: 16 }, (_, i) => i);
            while (numbers.length > 0) {
                const randomIndex = Math.floor(Math.random() * numbers.length);
                const startN = numbers.splice(randomIndex, 1)[0];
                const binaryState = startN.toString(2).padStart(4, '0');
                if (!visitedGlobal.has(binaryState)) {
                    const cycle = generateSingleCycle(formula, startN, visitedGlobal);
                    allCycles.push(cycle);
                }
            }

            return allCycles;
        }

function expandExpression(expr) {
    // Удаляем пробелы
    expr = expr.replace(/\s+/g, '');

    // Парсим выражение на факторы
    let factors = parseToFactors(expr);

    // Конвертируем каждый фактор в многочлен (массив термов)
    // Терм – массив переменных или ['1'] для константы
    let polynomialFactors = factors.map(parseFactor);

    // Перемножаем все факторы между собой
    let poly = [['1']]; 
    for (let factor of polynomialFactors) {
        poly = multiplyPolynomials(poly, factor);
    }

    return poly;
}

function parseToFactors(expr) {
    // Разделим выражение на факторы, учитывая, что факторы могут быть:
    // - Скобочные группы, например (x1+1)
    // - Наборы переменных без знаков и без скобок, например x1x2 или x3
    // Выражение – произведение таких факторов.
    let factors = [];
    let i = 0;
    while (i < expr.length) {
        if (expr[i] === '(') {
            // Найдём соответствующую ')'
            let start = i + 1;
            let depth = 1;
            i++;
            while (i < expr.length && depth > 0) {
                if (expr[i] === '(') depth++;
                else if (expr[i] === ')') depth--;
                i++;
            }
            // Теперь i указывает на символ после ')'
            let content = expr.substring(start, i-1);
            factors.push('(' + content + ')');
        } else {
            // Значит это последовательность переменных до следующей скобки или конца строки
            let start = i;
            while (i < expr.length && expr[i] !== '(' && expr[i] !== ')') {
                i++;
            }
            let part = expr.substring(start, i);
            if (part.length > 0) {
                factors.push(part);
            }
        }
    }
    return factors;
}

function parseFactor(factorStr) {
    // Если фактор в скобках: (....)
    // Разбиваем по '+'
    // Если внутри нет '+', просто один терм
    if (factorStr.startsWith('(') && factorStr.endsWith(')')) {
        let inner = factorStr.substring(1, factorStr.length - 1);
        let terms = inner.split('+').map(t => t === '1' ? ['1'] : [t]);
        return terms;
    } else {
        // Фактор без скобок (например x1x2)
        // Это один терм, состоящий из перечисленных переменных.
        // Допустим, переменные идут подряд: x1x2x3...
        // Нам нужно их выделить. Предполагается, что переменные в формате xN, где N — число.
        // Разобьём строку factorStr на переменные:
        let vars = factorStr.match(/x\d+|1/g);
        // Если не нашли переменных и единиц, это может быть пусто, но такого не должно быть.
        // Если есть '1' отдельно, то это константа. Если только переменные, это один терм.
        if (!vars) {
            // Нет переменных, странно. Пусть будет константа 1.
            return [['1']];
        }
        // Если есть '1' среди них - но обычно без '+' внутри фактора '1' не встретится просто так.
        // Предположим, что '1' не встречается здесь отдельно, иначе это странный ввод.
        // Просто возвращаем как один терм.
        return [vars];
    }
}

// Функция умножения двух многочленов по модулю 2
function multiplyPolynomials(poly1, poly2) {
    let result = [];
    for (let t1 of poly1) {
        for (let t2 of poly2) {
            let newTerm = multiplyTerms(t1, t2);
            addTermXor(result, newTerm);
        }
    }
    return result;
}

// Умножение термов: объединяем списки переменных (кроме '1'), без повторов
function multiplyTerms(term1, term2) {
    // Если term1 = ['1'] => просто term2
    if (term1.length === 1 && term1[0] === '1') {
        return term2.slice().sort();
    }
    // Если term2 = ['1'] => просто term1
    if (term2.length === 1 && term2[0] === '1') {
        return term1.slice().sort();
    }

    // Объединяем переменные
    let varsSet = new Set(term1.filter(v => v !== '1').concat(term2.filter(v => v !== '1')));
    let varsArr = Array.from(varsSet).sort();
    return varsArr.length === 0 ? ['1'] : varsArr;
}

// Добавление терма в многочлен по XOR
function addTermXor(poly, term) {
    let termStr = term.join('*');
    let index = poly.findIndex(t => t.join('*') === termStr);
    if (index >= 0) {
        // Если нашли тот же терм - удаляем
        poly.splice(index, 1);
    } else {
        // Иначе добавляем
        poly.push(term);
    }
}

// Конвертируем многочлен обратно в строку
function polyToString(poly) {
    if (poly.length === 0) return '0';
    return poly.map(term => {
        if (term.length === 1 && term[0] === '1') {
            return '1';
        } else {
            return term.join('');
        }
    }).join('+');
}

function removeEvenPairsFromString(expr) {
    // Удаляем пробелы и разбиваем по '+'
    let terms = expr.replace(/\s+/g, '').split('+').filter(t => t.length > 0);

    // Подсчёт вхождений каждого терма
    let counts = {};
    for (let t of terms) {
        counts[t] = (counts[t] || 0) + 1;
    }

    // Оставляем только термы с нечётным числом вхождений
    let resultTerms = [];
    for (let t in counts) {
        if (counts[t] % 2 === 1) {
            resultTerms.push(t);
        }
    }

    // Если результат пуст, возвращаем '0'
    return resultTerms.length > 0 ? resultTerms.join('+') : '0';
}

        function handleCycleGeneration() {
            const formula = document.getElementById('formula').value;

            if (!formula.match(/^[x1234+]*$/)) {
                alert('Некорректная формула! Используйте только x1, x2, x3, x4 и + для сложения.');
                return;
            }

            const allCycles = generateAllCycles(formula);
            const resultsContainer = document.getElementById('results-container');

            resultsContainer.innerHTML = "";
            allCycles.forEach((cycle, index) => {
                const table = document.createElement('table');
                table.innerHTML = `
                    <thead>
                        <tr>
                            <th colspan="5">Цикл ${index + 1}</th>
                        </tr>
                        <tr>
                            <th>x4</th>
                            <th>x3</th>
                            <th>x2</th>
                            <th>x1</th>
                            <th>N</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${cycle.map(({ bits, N }) => `
                            <tr>
                                <td>${bits[0]}</td>
                                <td>${bits[1]}</td>
                                <td>${bits[2]}</td>
                                <td>${bits[3]}</td>
                                <td>${N}</td>
                            </tr>`).join('')}
                    </tbody>
                `;
                resultsContainer.appendChild(table);
            });

            let resulted_cycle = allCycles[0];
            let resulted_formula = formula;

            const extra = document.createElement('p');

            for (let i = 1; i < allCycles.length; i++) {
                let current_cycle = allCycles[i];
                let alpha = null, beta = null;
                let maxOnesCount = -1;

                // Поиск alpha и beta с максимальным количеством единиц в первых трёх битах
                for (let cycle_0 of resulted_cycle) {
                    for (let cycle_1 of current_cycle) {
                        let bits_0 = cycle_0.bits;
                        let bits_1 = cycle_1.bits;

                        // Проверяем совпадение первых трёх битов
                        if (bits_0[0] === bits_1[0] && bits_0[1] === bits_1[1] && bits_0[2] === bits_1[2]) {
                            const onesCount = [bits_0[0], bits_0[1], bits_0[2]].reduce((a, b) => a + parseInt(b, 10), 0);

                            if (onesCount > maxOnesCount) {
                                maxOnesCount = onesCount;
                                alpha = bits_0;
                                beta = bits_1;
                            }
                        }
                    }
                }

                if (alpha && beta) {
                    extra.innerHTML += `Цикл ${i + 1}: Альфа = ${alpha}, Бета = ${beta}<br>`;
                    let toProduce = alpha.slice(0, 3);
                    // Преобразуем альфу в набор иксов
                    let condition = toProduce
                        .split('')
                        .map((bit, index) => (bit === '1' ? `x${ 4 - index}` : `!x${4 - index}`))
                        .join('*');

                    condition = condition
                        .replace(/!x(\d)/g, (match, p1) => `(x${p1}+1)`) // Заменяем !x на (x + 1)
                        .replace(/\*/g, ''); // Расширяем условия

                    extra.innerHTML += `Промежуточная формула ${condition}<br>`;
                    condition = polyToString(expandExpression(condition));
                    extra.innerHTML += `Раскрылии скобки ${condition}<br>`;
                    resulted_formula += `+${condition}`;
                    extra.innerHTML += `Формула: ${resulted_formula}<br>`;
                    resulted_formula = removeEvenPairsFromString(resulted_formula);
                    extra.innerHTML += `Сокращаем: ${resulted_formula}<br><br>`;

                    
                } else {
                    extra.innerHTML += `Цикл ${i + 1}: совпадений не найдено<br>`;
                }

                // Объединение текущего цикла с результирующим, исключая дубли
                for (let cycle of current_cycle) {
                    if (!resulted_cycle.some(c => c.bits === cycle.bits)) {
                        resulted_cycle.push(cycle);
                    }
                }
            }
            extra.innerHTML += `<br>Итоговая формула: ${resulted_formula}<br>`;
            resultsContainer.appendChild(extra);

            extra.innerHTML += `<br>Полный цикл:<br>`;
            const lastCycle = generateAllCycles(resulted_formula);


                    extra.innerHTML += "";
                    lastCycle.forEach((cycle, index) => {
                        const table = document.createElement('table');
                        table.innerHTML = `
                            <thead>
                                <tr>
                                    <th colspan="5">Цикл ${index + 1}</th>
                                </tr>
                                <tr>
                                    <th>x4</th>
                                    <th>x3</th>
                                    <th>x2</th>
                                    <th>x1</th>
                                    <th>N</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${cycle.map(({ bits, N }) => `
                                    <tr>
                                        <td>${bits[0]}</td>
                                        <td>${bits[1]}</td>
                                        <td>${bits[2]}</td>
                                        <td>${bits[3]}</td>
                                        <td>${N}</td>
                                    </tr>`).join('')}
                            </tbody>
                        `;
                        extra.appendChild(table);
                    });
        }
    </script>
    <label for="formula">Введите формулу (например, x1+x2x3+x4):</label>
    <input type="text" id="formula" placeholder="Введите формулу">
    <button onclick="handleCycleGeneration()">Сгенерировать циклы</button>
    <div id="results-container"></div>
<?php crypto_page_end(); ?>
