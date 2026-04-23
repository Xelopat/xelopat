<?php
require_once __DIR__ . '/theme.php';
crypto_page_start('Проверка эллиптической подписи');
?>
    <label for="a">a (коэффициент кривой(перед x без степени)):</label>
    <input type="number" id="a" placeholder="Введите a" value="2">

    <label for="p">p (модуль):</label>
    <input type="number" id="p" placeholder="Введите p" value="7">

    <label for="Gx">G (x):</label>
    <input type="number" id="Gx" placeholder="Введите x координату G" value="3">

    <label for="Gy">G (y):</label>
    <input type="number" id="Gy" placeholder="Введите y координату G" value="5">

    <label for="n">n (порядок точки G):</label>
    <input type="number" id="n" placeholder="Введите n" value="11">

    <label for="Qx">Q (открытый ключ, x):</label>
    <input type="number" id="Qx" placeholder="Введите x координату Q" value="5">

    <label for="Qy">Q (открытый ключ, y):</label>
    <input type="number" id="Qy" placeholder="Введите y координату Q" value="6">

    <label for="r">r (первый из подписи):</label>
    <input type="number" id="r" placeholder="Введите r" value="4">

    <label for="S">S (второй из подписи):</label>
    <input type="number" id="S" placeholder="Введите S" value="9">

    <label for="e">e (хэш-свертка):</label>
    <input type="number" id="e" placeholder="Введите e" value="5">

    <button id="verify">Проверить подпись</button>

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

        function pointAddition(x1, y1, x2, y2, a, p, steps, label = "") {
            let k;
            if (x1 === x2 && y1 === y2) {
                if (y1 === 0) {
                    steps.push(`${label}: Точка на бесконечности (удвоение невозможно).`);
                    return [null, null];
                }
                const numerator = mod(3 * x1 ** 2 + a, p);
                const denominator = mod(2 * y1, p);
                const invDenominator = modInverse(denominator, p);
                k = mod(numerator * invDenominator, p);
                steps.push(`${label}: Удвоение точки (${x1}, ${y1}) + (${x2}, ${y2})`);
                steps.push(`k = (3 * x1² + a) / (2 * y1) mod p`);
                steps.push(`   k = (${3} * ${x1}² + ${a}) / (${2} * ${y1}) mod ${p}`);
                steps.push(`   k = ${numerator} / ${denominator} mod ${p} = ${k}`);
            } else {
                if (x1 === x2 && y1 !== y2) {
                    steps.push(`${label}: Точка на бесконечности.`);
                    return [null, null];
                }
                const numerator = mod(y2 - y1, p);
                const denominator = mod(x2 - x1, p);
                const invDenominator = modInverse(denominator, p);
                k = mod(numerator * invDenominator, p);
                steps.push(`${label}: Сложение двух разных точек (${x1}, ${y1}) + (${x2}, ${y2})`);
                steps.push(`k = (y2 - y1) / (x2 - x1) mod p`);
                steps.push(`   k = (${y2} - ${y1}) / (${x2} - ${x1}) mod ${p}`);
                steps.push(`   k = ${numerator} / ${denominator} mod ${p} = ${k}`);
            }
            const x3 = mod(k ** 2 - x1 - x2, p);
            const y3 = mod(k * (x1 - x3) - y1, p);
            steps.push(`   x3 = k² - x1 - x2 mod ${p} = ${x3}`);
            steps.push(`   y3 = k * (x1 - x3) - y1 mod ${p} = ${y3}`);
            steps.push(`   Результат: (${x3}, ${y3})<br>`);
            return [x3, y3];
        }

        function scalarMultiplication(k, x, y, a, p, steps, label = "", step="") {
            let [xR, yR] = [x, y];
            let [xQ, yQ] = [null, null];
            let count = 1;
            steps.push(`<b>${step}.</b> Вычисление ${label}[${k}] (${x}, ${y}):`);
            while (k > 0) {
                if (k % 2 === 1) {
                    if (xQ === null) {
                        [xQ, yQ] = [xR, yR];
                        steps.push(`   [${count}](${x}, ${y}) = (${xQ}, ${yQ})`);
                    } else {
                        [xQ, yQ] = pointAddition(xQ, yQ, xR, yR, a, p, steps, `[${count}]`);
                    }
                }
                if (k > 1) {
                    [xR, yR] = pointAddition(xR, yR, xR, yR, a, p, steps, `Удвоение [${count}]`);
                }
                count *= 2;
                k = Math.floor(k / 2);
            }
            return [xQ, yQ];
        }

        document.getElementById('verify').addEventListener('click', () => {
            const a = parseInt(document.getElementById('a').value, 10);
            const p = parseInt(document.getElementById('p').value, 10);
            const Gx = parseInt(document.getElementById('Gx').value, 10);
            const Gy = parseInt(document.getElementById('Gy').value, 10);
            const n = parseInt(document.getElementById('n').value, 10);
            const Qx = parseInt(document.getElementById('Qx').value, 10);
            const Qy = parseInt(document.getElementById('Qy').value, 10);
            const r = parseInt(document.getElementById('r').value, 10);
            const S = parseInt(document.getElementById('S').value, 10);
            const e = parseInt(document.getElementById('e').value, 10);

            let steps = [];
            try {
                const SInv = modInverse(S, n);
                steps.push(`<b>1.</b> Вычисление V = S^(-1) mod n = ${SInv}`);

                const U1 = mod(e * SInv, n);
                steps.push(`<b>2.</b> U1 = e * S^(-1) mod n = ${U1}`);

                const U2 = mod(r * SInv, n);
                steps.push(`<b>3.</b> U2 = r * S^(-1) mod n = ${U2}`);

                const [U1Gx, U1Gy] = scalarMultiplication(U1, Gx, Gy, a, p, steps, `G`, "4");
                steps.push(`<b>[${U1}]G = (${U1Gx}, ${U1Gy})</b><br>`);

                const [U2Qx, U2Qy] = scalarMultiplication(U2, Qx, Qy, a, p, steps, `Q`, "5");
                steps.push(`<b>[${U2}]Q = (${U2Qx}, ${U2Qy})</b><br>`);

                const [x, y] = pointAddition(U1Gx, U1Gy, U2Qx, U2Qy, a, p, steps, `<b>6.</b>[${U1}]G + [${U2}]Q`);
                steps.push(`[${U1}]G + [${U2}]Q = (${x}, ${y})<br>`);
                steps.push("<b>7.</b> Проверка подписи x mod n = r");
                if (mod(x, n) === r) {
                    steps.push(`${x} mod ${n} = ${r}`);
                    steps.push("Подпись верна т.к. x mod n = r");
                    document.getElementById('result').innerHTML = "Результат: Подпись верна";
                } else {
                    steps.push(`${x} mod ${n} ≠ ${r}`);
                    steps.push("Подпись отклоняется");
                    document.getElementById('result').innerHTML = "Результат: Подпись отклоняется";
                }

                document.getElementById('steps').innerHTML = steps.join("\n");
            } catch (error) {
                document.getElementById('result').innerHTML = `Ошибка: ${error.message}`;
                document.getElementById('steps').innerHTML = "";
            }
        });
    </script>
<?php crypto_page_end(); ?>
