<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анализ бент-функции</title>
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
        h3 {
            padding: 10px;
            margin: 10px;
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
            white-space: pre-wrap;
        }
        table {
            border-collapse: collapse;
            margin: 20px auto;
            width: 90%;
        }
        table, th, td {
            border: 1px solid black;
        }
        th, td {
            padding: 8px;
            text-align: center;
        }
        th {
            background-color: #f2f2f2;
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
    <h1>Анализ нелинейности функции</h1>
    <label for="sequence">Введите двоичную последовательность:</label>
    <input type="text" id="sequence" placeholder="Например, 1111101110110100">
    <button id="calculate-button">Рассчитать</button>
    
    <div id="error"></div>
    <div id="result"></div>

    <script>
        function generateIndices(n) {
            return Array.from({ length: n }, (_, i) => i + 1);
        }

        function generateExp(f) {
            return f.map(value => (value === 0 ? 1 : -1));
        }

        function generateArraysHeaders(n) {
            return Array.from({ length: n }, (_, i) => "x" + (i + 1));
        }

        function generatePatternedArray(n, patternIndex) {
            const result = new Array(n).fill(0); 
            const segmentSize = n / Math.pow(2, patternIndex); 

            for (let i = 0; i < n; i++) {
                const segmentPosition = Math.floor(i / segmentSize) % 2; 
                result[i] = segmentPosition; 
            }

            return result;
        }

        function generateArrays(n) {
            const arrays = [];
            for (let i = 1; i <= Math.log2(n); i++) {
                arrays.push(generatePatternedArray(n, i));
            }
            return arrays;
        }

        function calculateW1(exp) {
            const n = exp.length;
            const half = n / 2;
            const w1 = new Array(n).fill(0);

            for (let i = 0; i < half; i++) {
                w1[i] = exp[i] + exp[i + half];
            }

            for (let i = half; i < n; i++) {
                w1[i] = exp[i - half] - exp[i];
            }

            return w1;
        }

        function calculateW2(w1) {
            const n = w1.length;
            const quarter = n / 4;
            const w2 = new Array(n).fill(0);

            for (let i = 0; i < quarter; i++) {
                w2[i] = w1[i] + w1[i + quarter];
            }

            for (let i = quarter; i < 2 * quarter; i++) {
                w2[i] = w1[i - quarter] - w1[i];
            }

            for (let i = 2 * quarter; i < 3 * quarter; i++) {
                w2[i] = w1[i] + w1[i + quarter];
            }

            for (let i = 3 * quarter; i < n; i++) {
                w2[i] = w1[i - quarter] - w1[i];
            }

            return w2;
        }

        function calculateW3(w2) {
            const n = w2.length;
            const eighth = n / 8;
            const w3 = new Array(n).fill(0);

            for (let i = 0; i < eighth; i++) {
                w3[i] = w2[i] + w2[i + eighth];
            }

            for (let i = eighth; i < 2 * eighth; i++) {
                w3[i] = w2[i - eighth] - w2[i];
            }

            for (let i = 2 * eighth; i < 3 * eighth; i++) {
                w3[i] = w2[i] + w2[i + eighth];
            }

            for (let i = 3 * eighth; i < 4 * eighth; i++) {
                w3[i] = w2[i - eighth] - w2[i];
            }

            for (let i = 4 * eighth; i < 5 * eighth; i++) {
                w3[i] = w2[i] + w2[i + eighth];
            }

            for (let i = 5 * eighth; i < 6 * eighth; i++) {
                w3[i] = w2[i - eighth] - w2[i];
            }

            for (let i = 6 * eighth; i < 7 * eighth; i++) {
                w3[i] = w2[i] + w2[i + eighth];
            }

            for (let i = 7 * eighth; i < n; i++) {
                w3[i] = w2[i - eighth] - w2[i];
            }

            return w3;
        }
        function calculateW4(w3) {
            const n = w3.length;
            const sixteenth = n / 16;
            const w4 = new Array(n).fill(0);

            for (let i = 0; i < sixteenth; i++) {
                w4[i] = w3[i] + w3[i + sixteenth];
            }

            for (let i = sixteenth; i < 2 * sixteenth; i++) {
                w4[i] = w3[i - sixteenth] - w3[i];
            }

            for (let i = 2 * sixteenth; i < 3 * sixteenth; i++) {
                w4[i] = w3[i] + w3[i + sixteenth];
            }

            for (let i = 3 * sixteenth; i < 4 * sixteenth; i++) {
                w4[i] = w3[i - sixteenth] - w3[i];
            }

            for (let i = 4 * sixteenth; i < 5 * sixteenth; i++) {
                w4[i] = w3[i] + w3[i + sixteenth];
            }

            for (let i = 5 * sixteenth; i < 6 * sixteenth; i++) {
                w4[i] = w3[i - sixteenth] - w3[i];
            }

            for (let i = 6 * sixteenth; i < 7 * sixteenth; i++) {
                w4[i] = w3[i] + w3[i + sixteenth];
            }

            for (let i = 7 * sixteenth; i < 8 * sixteenth; i++) {
                w4[i] = w3[i - sixteenth] - w3[i];
            }

            for (let i = 8 * sixteenth; i < 9 * sixteenth; i++) {
                w4[i] = w3[i] + w3[i + sixteenth];
            }

            for (let i = 9 * sixteenth; i < 10 * sixteenth; i++) {
                w4[i] = w3[i - sixteenth] - w3[i];
            }

            for (let i = 10 * sixteenth; i < 11 * sixteenth; i++) {
                w4[i] = w3[i] + w3[i + sixteenth];
            }

            for (let i = 11 * sixteenth; i < 12 * sixteenth; i++) {
                w4[i] = w3[i - sixteenth] - w3[i];
            }

            for (let i = 12 * sixteenth; i < 13 * sixteenth; i++) {
                w4[i] = w3[i] + w3[i + sixteenth];
            }

            for (let i = 13 * sixteenth; i < 14 * sixteenth; i++) {
                w4[i] = w3[i - sixteenth] - w3[i];
            }

            for (let i = 14 * sixteenth; i < 15 * sixteenth; i++) {
                w4[i] = w3[i] + w3[i + sixteenth];
            }

            for (let i = 15 * sixteenth; i < n; i++) {
                w4[i] = w3[i - sixteenth] - w3[i];
            }

            return w4;
        }
        function createTable(headers, rows) {
            let html = "<table><thead><tr>";
            headers.forEach(header => html += `<th>${header}</th>`);
            html += "</tr></thead><tbody>";
            rows.forEach(row => {
                html += "<tr>";
                row.forEach(cell => html += `<td>${cell}</td>`);
                html += "</tr>";
            });
            html += "</tbody></table>";
            return html;
        }

        document.getElementById("calculate-button").addEventListener("click", () => {
            const input = document.getElementById("sequence").value.trim();
            const errorDiv = document.getElementById("error");
            const resultDiv = document.getElementById("result");
            errorDiv.textContent = "";
            resultDiv.innerHTML = "";

            try {
                if (!/^[01]+$/.test(input)) {
                    throw new Error("Введите корректную двоичную последовательность (только 0 и 1).");
                }

                const f = input.split("").map(Number);
                const length = f.length;

                if (Math.log2(length) % 1 !== 0) {
                    throw new Error("Длина последовательности должна быть степенью двойки.");
                }

                const n = Math.log2(length);
                const indices = generateIndices(length);
                const byteArrays = generateArrays(length);
                const exps = generateExp(f);
                const w1 = calculateW1(exps);
                const w2 = calculateW2(w1);
                const w3 = calculateW3(w2);
                const w4 = calculateW4(w3);

                const maxW1W2 = Math.max(...w1, ...w2, ...w3, ...w4, Math.abs(Math.min(...w1, ...w2, ...w3, ...w4)));
                const Nf = 2 ** (n - 1) - 0.5 * Math.max(...w1, ...w2, ...w3, ...w4, Math.abs(Math.min(...w1, ...w2, ...w3, ...w4)));
                const N = 2 ** (n - 1) - 2 ** (n * 0.5 - 1);
                
                 const NfExplanation = `
Формула: Nf = 2^(n-1) - 0.5 * max(|W1|, |W2|, |W3|, |W4|)
n = ${n}, 2^(n-1) = ${2 ** (n - 1)}, max(|W1|, |W2|, |W3|, |W4|) = ${maxW1W2}
Подставляем: Nf = ${2 ** (n - 1)} - 0.5 * ${maxW1W2} = ${Nf}
                `;

                const NExplanation = `
Формула: N = 2^(n-1) - 2^((n/2)-1)
n = ${n}, 2^(n-1) = ${2 ** (n - 1)}, 2^((n/2)-1) = ${2 ** (n * 0.5 - 1)}
Подставляем: N = ${2 ** (n - 1)} - ${2 ** (n * 0.5 - 1)} = ${N}
                `;
                
                // Генерация объяснений для W1
                let w1Explanations = [];
                const half = length / 2;
                for (let i = 0; i < length; i++) {
                    if (i < half) {
                        w1Explanations.push(`W1[${i + 1}] = Exp[${i + 1}] + Exp[${i + half + 1}] = ${exps[i]} + ${exps[i + half]} = ${w1[i]}`);
                    } else {
                        w1Explanations.push(`W1[${i + 1}] = Exp[${i - half + 1}] - Exp[${i + 1}] = ${exps[i - half]} - ${exps[i]} = ${w1[i]}`);
                    }
                }

                let w2Explanations = [];
                const quarter = length / 4;
                for (let i = 0; i < length; i++) {
                    if (i < quarter) {
                        w2Explanations.push(`W2[${i + 1}] = W1[${i + 1}] + W1[${i + quarter + 1}] = ${w1[i]} + ${w1[i + quarter]} = ${w2[i]}`);
                    } else if (i < 2 * quarter) {
                        w2Explanations.push(`W2[${i + 1}] = W1[${i - quarter + 1}] - W1[${i + 1}] = ${w1[i - quarter]} - ${w1[i]} = ${w2[i]}`);
                    } else if (i < 3 * quarter) {
                        w2Explanations.push(`W2[${i + 1}] = W1[${i + 1}] + W1[${i + quarter + 1}] = ${w1[i]} + ${w1[i + quarter]} = ${w2[i]}`);
                    } else {
                        w2Explanations.push(`W2[${i + 1}] = W1[${i - quarter + 1}] - W1[${i + 1}] = ${w1[i - quarter]} - ${w1[i]} = ${w2[i]}`);
                    }
                }

                let w3Explanations = [];
                const eighth = length / 8;
                for (let i = 0; i < length; i++) {
                    if (i < eighth) {
                        w3Explanations.push(`W3[${i + 1}] = W2[${i + 1}] + W2[${i + eighth + 1}] = ${w2[i]} + ${w2[i + eighth]} = ${w3[i]}`);
                    } else if (i < 2 * eighth) {
                        w3Explanations.push(`W3[${i + 1}] = W2[${i - eighth + 1}] - W2[${i + 1}] = ${w2[i - eighth]} - ${w2[i]} = ${w3[i]}`);
                    } else if (i < 3 * eighth) {
                        w3Explanations.push(`W3[${i + 1}] = W2[${i + 1}] + W2[${i + eighth + 1}] = ${w2[i]} + ${w2[i + eighth]} = ${w3[i]}`);
                    } else if (i < 4 * eighth) {
                        w3Explanations.push(`W3[${i + 1}] = W2[${i - eighth + 1}] - W2[${i + 1}] = ${w2[i - eighth]} - ${w2[i]} = ${w3[i]}`);
                    } else if (i < 5 * eighth) {
                        w3Explanations.push(`W3[${i + 1}] = W2[${i + 1}] + W2[${i + eighth + 1}] = ${w2[i]} + ${w2[i + eighth]} = ${w3[i]}`);
                    } else if (i < 6 * eighth) {
                        w3Explanations.push(`W3[${i + 1}] = W2[${i - eighth + 1}] - W2[${i + 1}] = ${w2[i - eighth]} - ${w2[i]} = ${w3[i]}`);
                    } else if (i < 7 * eighth) {
                        w3Explanations.push(`W3[${i + 1}] = W2[${i + 1}] + W2[${i + eighth + 1}] = ${w2[i]} + ${w2[i + eighth]} = ${w3[i]}`);
                    } else {
                        w3Explanations.push(`W3[${i + 1}] = W2[${i - eighth + 1}] - W2[${i + 1}] = ${w2[i - eighth]} - ${w2[i]} = ${w3[i]}`);
                    }
                }

                const w4Explanations = [];
                const sixteenth = length / 16;
                for (let i = 0; i < length; i++) {
                    if (i < sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i + 1}] + W3[${i + sixteenth + 1}] = ${w3[i]} + ${w3[i + sixteenth]} = ${w4[i]}`);
                    } else if (i < 2 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i - sixteenth + 1}] - W3[${i + 1}] = ${w3[i - sixteenth]} - ${w3[i]} = ${w4[i]}`);
                    } else if (i < 3 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i + 1}] + W3[${i + sixteenth + 1}] = ${w3[i]} + ${w3[i + sixteenth]} = ${w4[i]}`);
                    } else if (i < 4 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i - sixteenth + 1}] - W3[${i + 1}] = ${w3[i - sixteenth]} - ${w3[i]} = ${w4[i]}`);
                    } else if (i < 5 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i + 1}] + W3[${i + sixteenth + 1}] = ${w3[i]} + ${w3[i + sixteenth]} = ${w4[i]}`);
                    } else if (i < 6 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i - sixteenth + 1}] - W3[${i + 1}] = ${w3[i - sixteenth]} - ${w3[i]} = ${w4[i]}`);
                    } else if (i < 7 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i + 1}] + W3[${i + sixteenth + 1}] = ${w3[i]} + ${w3[i + sixteenth]} = ${w4[i]}`);
                    } else if (i < 8 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i - sixteenth + 1}] - W3[${i + 1}] = ${w3[i - sixteenth]} - ${w3[i]} = ${w4[i]}`);
                    } else if (i < 9 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i + 1}] + W3[${i + sixteenth + 1}] = ${w3[i]} + ${w3[i + sixteenth]} = ${w4[i]}`);
                    } else if (i < 10 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i - sixteenth + 1}] - W3[${i + 1}] = ${w3[i - sixteenth]} - ${w3[i]} = ${w4[i]}`);
                    } else if (i < 11 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i + 1}] + W3[${i + sixteenth + 1}] = ${w3[i]} + ${w3[i + sixteenth]} = ${w4[i]}`);
                    } else if (i < 12 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i - sixteenth + 1}] - W3[${i + 1}] = ${w3[i - sixteenth]} - ${w3[i]} = ${w4[i]}`);
                    } else if (i < 13 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i + 1}] + W3[${i + sixteenth + 1}] = ${w3[i]} + ${w3[i + sixteenth]} = ${w4[i]}`);
                    } else if (i < 14 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i - sixteenth + 1}] - W3[${i + 1}] = ${w3[i - sixteenth]} - ${w3[i]} = ${w4[i]}`);
                    } else if (i < 15 * sixteenth) {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i + 1}] + W3[${i + sixteenth + 1}] = ${w3[i]} + ${w3[i + sixteenth]} = ${w4[i]}`);
                    } else {
                        w4Explanations.push(`W4[${i + 1}] = W3[${i - sixteenth + 1}] - W3[${i + 1}] = ${w3[i - sixteenth]} - ${w3[i]} = ${w4[i]}`);
                    }
                }


                const tableHeaders = ["N", ...generateArraysHeaders(n), "F", "Exp", "W1", "W2", "W3", "W4"];
                const tableRows = indices.map((_, i) => [
                    indices[i],
                    ...byteArrays.map(arr => arr[i]),
                    f[i],
                    exps[i],
                    w1[i],
                    w2[i],
                    w3[i],
                    w4[i]
                ]);


                const tableHTML = createTable(tableHeaders, tableRows);

                resultDiv.innerHTML = `
                    <h3>Таблица расчетов:</h3>
                    ${tableHTML}
                    <h3>Пошаговые вычисления W1:</h3>
                    <pre>${w1Explanations.join("\n")}</pre>
                    <h3>Пошаговые вычисления W2:</h3>
                    <pre>${w2Explanations.join("\n")}</pre>
                    <h3>Пошаговые вычисления W3:</h3>
                    <pre>${w3Explanations.join("\n")}</pre>
                    <h3>Пошаговые вычисления W4:</h3>
                    <pre>${w4Explanations.join("\n")}</pre>
                    <h3>Рассчёт Nf:</h4><p>${NfExplanation.trim()}</p>
                    <h3>Рассчёт N:</h4>
                    <p>${NExplanation.trim()}</p>
                    <h3>Итоги:</h3>
                    <p>Нелинейность функции (Nf): ${Nf}</p>
                    <p>Условие бент-функции (N): ${N}</p>
                    <p>${Nf === N ? "Функция является бент-функцией." : "Функция не является бент-функцией."}</p>
                    
                `;
            } catch (error) {
                errorDiv.textContent = error.message;
            }
        });

    </script>
</body>
</html>
