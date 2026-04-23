<?php
require_once __DIR__ . '/theme.php';
crypto_page_start('Вычисление подписи (r, S)');
?>
    <label for="a">a (коэффициент кривой (перед x без степени)):</label>
    <input type="number" id="a" placeholder="Введите a" value="2">

    <label for="p">p (модуль (mod p)):</label>
    <input type="number" id="p" placeholder="Введите p" value="7">

    <label for="Gx">G (x):</label>
    <input type="number" id="Gx" placeholder="Введите x координату G" value="3">

    <label for="Gy">G (y):</label>
    <input type="number" id="Gy" placeholder="Введите y координату G" value="2">

    <label for="n">n (порядок точки G):</label>
    <input type="number" id="n" placeholder="Введите n" value="11">

    <label for="k">k (случайное число):</label>
    <input type="number" id="k" placeholder="Введите k" value="4">

    <label for="d">d (секретный ключ):</label>
    <input type="number" id="d" placeholder="Введите d" value="2">

    <label for="e">e (хэш-свертка):</label>
    <input type="number" id="e" placeholder="Введите e" value="6">

    <button id="calculate">Вычислить подпись</button>

    <div id="result"></div>
    <div id="steps"></div>

    <script>
        function mod(a, m) {
            return ((a % m) + m) % m;
        }

        function modInverse(a, m) {
            let m0 = m, x0 = 0, x1 = 1;
            if (m === 1) return 0;
            while (a > 1) {
                let q = Math.floor(a / m);
                [a, m] = [m, a % m];
                [x0, x1] = [x1 - q * x0, x0];
            }
            return x1 < 0 ? x1 + m0 : x1;
        }

        function pointAddition(x1, y1, x2, y2, a, p, steps) {
            let k;
            if (x1 === x2 && y1 === y2) {
                if (y1 === 0) {
                    steps.push("y1 = 0 → результат: точка на бесконечности (удвоение невозможно).\n");
                    return [null, null];
                }
                const numerator = mod(3 * x1 ** 2 + a, p);
                const denominator = mod(2 * y1, p);
                const invDenominator = modInverse(denominator, p);
                k = mod(numerator * invDenominator, p);
                steps.push(`   Удвоение точки: k = (3 * x1² + a) / (2 * y1) mod p`);
                steps.push(`      k = (${3} * ${x1}² + ${a}) / (${2} * ${y1}) mod ${p}`);
                steps.push(`      k = ${numerator} / ${denominator} mod ${p} = ${k}`);
            } else {
                if (x1 === x2 && y1 !== y2) {
                    steps.push("x1 = x2 и y1 ≠ y2 → результат: точка на бесконечности.\n");
                    return [null, null];
                }
                const numerator = mod(y2 - y1, p);
                const denominator = mod(x2 - x1, p);
                const invDenominator = modInverse(denominator, p);
                k = mod(numerator * invDenominator, p);
                steps.push(`   Сложение точек: k = (y2 - y1) / (x2 - x1) mod p`);
                steps.push(`      k = (${y2} - ${y1}) / (${x2} - ${x1}) mod ${p}`);
                steps.push(`      k = ${numerator} / ${denominator} mod ${p} = ${k}`);
            }
            const x3 = mod(k ** 2 - x1 - x2, p);
            const y3 = mod(k * (x1 - x3) - y1, p);
            steps.push(`   x3 = k² - x1 - x2 mod ${p} = ${x3}`);
            steps.push(`   y3 = k * (x1 - x3) - y1 mod ${p} = ${y3}\n`);
            return [x3, y3];
        }

        function scalarMultiplication(k, x, y, a, p, steps) {
            let [xR, yR] = [x, y];
            let [xQ, yQ] = [null, null];
            let first = true;
            while (k > 0) {
                if (k % 2 === 1) {
                    if (first) {
                        [xQ, yQ] = [xR, yR];
                        steps.push(`[1]G = (${xQ}, ${yQ})`);
                        first = false;
                    } else {
                        steps.push(`Добавление: (${xQ}, ${yQ}) + (${xR}, ${yR})`);
                        [xQ, yQ] = pointAddition(xQ, yQ, xR, yR, a, p, steps);
                        steps.push(`   Результат: (${xQ}, ${yQ})\n`);
                    }
                }
                if (k > 1) {
                    steps.push(`Удвоение точки (${xR}, ${yR}):`);
                    [xR, yR] = pointAddition(xR, yR, xR, yR, a, p, steps);
                    steps.push(`   Результат удвоения: (${xR}, ${yR})\n`);
                }
                k = Math.floor(k / 2);
            }
            return [xQ, yQ];
        }

        document.getElementById('calculate').addEventListener('click', () => {
            const a = parseInt(document.getElementById('a').value, 10);
            const p = parseInt(document.getElementById('p').value, 10);
            const Gx = parseInt(document.getElementById('Gx').value, 10);
            const Gy = parseInt(document.getElementById('Gy').value, 10);
            const n = parseInt(document.getElementById('n').value, 10);
            const k = parseInt(document.getElementById('k').value, 10);
            const d = parseInt(document.getElementById('d').value, 10);
            const e = parseInt(document.getElementById('e').value, 10);

            let steps = [];
            try {
                const [Cx, Cy] = scalarMultiplication(k, Gx, Gy, a, p, steps);
                steps.push(`Результат [${k}]G = (${Cx}, ${Cy})`);
                const r = mod(Cx, n);
                steps.push(`r = x_C mod n (x_C = G[x])`);
                steps.push(`   r = ${Cx} mod ${n} = ${r}`);
                const kInv = modInverse(k, n);
                steps.push(`k⁻¹ mod n`);
                steps.push(`   k⁻¹ = ${k}⁻¹ mod ${n} = ${kInv}`);
                const S = mod(kInv * (e + r * d), n);
                steps.push(`S = k⁻¹ * (e + r * d) mod n`);
                steps.push(`   S = ${kInv} * (${e} + ${r} * ${d}) mod ${n}`);
                steps.push(`   S = ${kInv} * (${e} + ${r * d}) mod ${n}`);
                steps.push(`   S = ${kInv} * ${e + r * d} mod ${n} = ${S}`);
                steps.push(`Подпись: (r, S) = (${r}, ${S})`);

                document.getElementById('result').textContent = `Результат: (r, S) = (${r}, ${S})`;
                document.getElementById('steps').textContent = steps.join("\n");
            } catch (error) {
                document.getElementById('result').textContent = `Ошибка: ${error.message}`;
                document.getElementById('steps').textContent = "";
            }
        });
    </script>
<?php crypto_page_end(); ?>
