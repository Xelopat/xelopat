<?php
require_once __DIR__ . '/theme.php';
crypto_page_start('Проверка цифровой подписи');
?>
    <label for="alpha">α (множество ненулевых вычетов):</label>
    <input type="number" id="alpha" placeholder="Введите α" value="15">

    <label for="P">P (модуль):</label>
    <input type="number" id="P" placeholder="Введите P" value="439">

    <label for="a">a (секретный ключ):</label>
    <input type="number" id="a" placeholder="Введите a" value="22">

    <label for="X">X (сообщение):</label>
    <input type="number" id="X" placeholder="Введите X" value="234">

    <label for="r">r (рандомизатор):</label>
    <input type="number" id="r" placeholder="Введите r" value="79">

    <button id="verify">Проверить подпись</button>

    <div id="result" class="formula"></div>

    <script>
        function modExp(base, exp, mod) {
            let result = 1;
            base = base % mod;
            while (exp > 0) {
                if (exp % 2 === 1) result = (result * base) % mod;
                exp = Math.floor(exp / 2);
                base = (base * base) % mod;
            }
            return result;
        }

        function mod(a, m) {
            return ((a % m) + m) % m;
        }

        function modInverse(a, m) {
            let m0 = m, t, q;
            let x0 = 0, x1 = 1;
            if (m === 1) return 0;
            while (a > 1) {
                q = Math.floor(a / m);
                t = m;
                m = a % m; 
                a = t;
                t = x0;
                x0 = x1 - q * x0;
                x1 = t;
            }
            if (x1 < 0) x1 += m0;
            return x1;
        }

        document.getElementById('verify').addEventListener('click', () => {
            const alpha = parseInt(document.getElementById('alpha').value, 10);
            const P = parseInt(document.getElementById('P').value, 10);
            const a = parseInt(document.getElementById('a').value, 10);
            const X = parseInt(document.getElementById('X').value, 10);
            const r = parseInt(document.getElementById('r').value, 10);

            let result = "";
            try {
                const S1 = modExp(alpha, r, P);
                result += `S<sub>1</sub> = α<sup>r</sup> mod P<br>`;
                result += `S<sub>1</sub> = ${alpha}<sup>${r}</sup> mod ${P} = ${S1}<br><br>`;

                const rInv = modInverse(r, P - 1);
                const S2 = mod((X - a * S1) * rInv, P - 1);
                result += `S<sub>2</sub> = (X - a * S<sub>1</sub>) * r<sup>-1</sup> mod (P - 1)<br>`;
                result += `S<sub>2</sub> = (${X} - ${a} * ${S1}) * ${rInv} mod ${P - 1} = ${S2}<br><br>`;

                const Z1 = modExp(alpha, X, P);
                result += `Z<sub>1</sub> = α<sup>X</sup> mod P<br>`;
                result += `Z<sub>1</sub> = ${alpha}<sup>${X}</sup> mod ${P} = ${Z1}<br><br>`;

                const B = modExp(alpha, a, P);
                result += `β = α<sup>a</sup> mod P<br>`;
                result += `β = ${alpha}<sup>${a}</sup> mod ${P} = ${B}<br><br>`;

                const part1 = modExp(B, S1, P);
                const part2 = modExp(S1, S2, P);
                const Z2 = mod(part1 * part2, P);
                result += `Z<sub>2</sub> = β<sup>S<sub>1</sub></sup> * S<sub>1</sub><sup>S<sub>2</sub></sup> mod P<br>`;
                result += `Z<sub>2</sub> = (${B}<sup>${S1}</sup> * ${S1}<sup>${S2}</sup>) mod ${P} = ${Z2}<br><br>`;

                if (Z1 === Z2) {
                    result += `Результат: Подпись верна, так как Z<sub>1</sub> = Z<sub>2</sub> (${Z1} = ${Z2})<br><br>`;
                } else {
                    result += `Результат: Подпись не верна, так как Z<sub>1</sub> ≠ Z<sub>2</sub> (${Z1} ≠ ${Z2})<br><br>`;
                }

                result += `Электронная подпись:<br>`;
                result += `(X, S) = (${X}, ${S1}, ${S2})<br>`;
                result += `S = (${S1}, ${S2})<br>`;

                document.getElementById('result').innerHTML = result;
            } catch (error) {
                document.getElementById('result').textContent = `Ошибка: ${error.message}`;
            }
        });
    </script>
<?php crypto_page_end(); ?>
