<?php
require_once __DIR__ . '/theme.php';
crypto_page_start('Линейный регистр сдвига');
?>
    <label for="sequence">Введите двоичную последовательность:</label>
    <input type="text" id="sequence" placeholder="Например, 1111101110110100">
    <button id="calculate-button">Рассчитать LFSR</button>
    
    <div id="error"></div>
    <div id="result"></div>

    <script>
        function parseBits(input) {
            return input.split('').map(ch => Number(ch));
        }

        function berlekampMassey(sequence) {
            let C = [1];
            let B = [1];
            let L = 0;
            let m = 1;

            for (let n = 0; n < sequence.length; n++) {
                let d = sequence[n];
                for (let i = 1; i <= L; i++) {
                    d ^= (C[i] || 0) & sequence[n - i];
                }

                if (d === 1) {
                    const T = C.slice();
                    const neededLength = B.length + m;
                    while (C.length < neededLength) C.push(0);
                    for (let i = 0; i < B.length; i++) {
                        C[i + m] ^= B[i];
                    }

                    if (2 * L <= n) {
                        L = n + 1 - L;
                        B = T;
                        m = 1;
                    } else {
                        m += 1;
                    }
                } else {
                    m += 1;
                }
            }

            C = C.slice(0, L + 1);
            const taps = [];
            for (let i = 1; i <= L; i++) {
                if ((C[i] || 0) === 1) taps.push(i);
            }
            return { L, C, taps };
        }

        function polynomialString(C, L) {
            if (L === 0) return '1';
            const terms = ['1'];
            for (let i = 1; i <= L; i++) {
                if ((C[i] || 0) === 1) {
                    terms.push(i === 1 ? 'D' : `D^${i}`);
                }
            }
            return terms.join(' + ');
        }

        function recurrenceString(taps) {
            if (!taps.length) return 's[n] = 0';
            const right = taps.map(i => `s[n-${i}]`).join(' ⊕ ');
            return `s[n] = ${right}`;
        }

        function computeNextBit(history, index, taps) {
            if (!taps.length) return 0;
            return taps.reduce((acc, delay) => acc ^ history[index - delay], 0);
        }

        function buildValidationRows(sequence, taps, L, maxRows) {
            if (L >= sequence.length) return { rowsHtml: '', total: 0, mismatches: 0 };

            let rowsHtml = '';
            let mismatches = 0;
            const total = sequence.length - L;
            const limit = Math.min(total, maxRows);

            for (let n = L; n < L + limit; n++) {
                const predicted = computeNextBit(sequence, n, taps);
                const actual = sequence[n];
                const status = predicted === actual ? 'OK' : 'ERR';
                if (status !== 'OK') mismatches += 1;
                const details = taps.length
                    ? taps.map(i => `s[${n - i}]=${sequence[n - i]}`).join(' ⊕ ')
                    : 'константный 0';

                rowsHtml += `
                    <tr>
                        <td>${n}</td>
                        <td>${details}</td>
                        <td>${predicted}</td>
                        <td>${actual}</td>
                        <td>${status}</td>
                    </tr>`;
            }

            return { rowsHtml, total, mismatches };
        }

        function buildStepRows(generated, taps, L, steps) {
            if (L === 0) {
                return `
                    <tr>
                        <td>0</td>
                        <td>пустой регистр</td>
                        <td>константный 0</td>
                        <td>0</td>
                    </tr>`;
            }

            let rowsHtml = '';
            const maxIndex = generated.length - 1;
            const start = L;
            const end = Math.min(maxIndex, L + steps - 1);

            for (let n = start; n <= end; n++) {
                const state = generated.slice(n - L, n).join('');
                const details = taps.length
                    ? taps.map(i => `s[${n - i}]=${generated[n - i]}`).join(' ⊕ ')
                    : '0';
                rowsHtml += `
                    <tr>
                        <td>${n}</td>
                        <td>${state}</td>
                        <td>${details}</td>
                        <td>${generated[n]}</td>
                    </tr>`;
            }
            return rowsHtml;
        }

        function renderResult(sequence, model) {
            const { L, C, taps } = model;
            const resultDiv = document.getElementById('result');
            const MAX_VALIDATION_ROWS = 128;
            const EXTEND_COUNT = 24;
            const STEP_ROWS = 18;

            const { rowsHtml, total, mismatches } = buildValidationRows(sequence, taps, L, MAX_VALIDATION_ROWS);
            const generated = sequence.slice();
            for (let n = sequence.length; n < sequence.length + EXTEND_COUNT; n++) {
                generated.push(computeNextBit(generated, n, taps));
            }
            const extension = generated.slice(sequence.length).join('');
            const stepRows = buildStepRows(generated, taps, L, STEP_ROWS);
            const tapsText = taps.length ? taps.join(', ') : 'нет';

            resultDiv.innerHTML = `
                <h3>Результат</h3>
                <p>Минимальная длина регистра: <b>${L}</b></p>
                <p>Тапы (задержки): <b>${tapsText}</b></p>
                <p>Полином связи: <b>C(D) = ${polynomialString(C, L)}</b></p>
                <p>Рекуррентная формула: <b>${recurrenceString(taps)}</b></p>

                <h3>Проверка на входной последовательности</h3>
                <p class="result-note">
                    Проверено позиций: ${Math.min(total, MAX_VALIDATION_ROWS)} из ${total}.
                    Несовпадений: ${mismatches}.
                </p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>n</th>
                                <th>Развёртка</th>
                                <th>Прогноз</th>
                                <th>Факт</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>${rowsHtml || '<tr><td colspan="5">Недостаточно длины для проверки.</td></tr>'}</tbody>
                    </table>
                </div>

                <h3>Шаги регистра</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>n</th>
                                <th>Состояние регистра</th>
                                <th>Обратная связь</th>
                                <th>Новый бит</th>
                            </tr>
                        </thead>
                        <tbody>${stepRows}</tbody>
                    </table>
                </div>

                <h3>Продолжение последовательности</h3>
                <pre>${extension}</pre>
            `;
        }

        document.getElementById('calculate-button').addEventListener('click', () => {
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
            if (bits.length < 4) {
                errorDiv.textContent = 'Ошибка: для устойчивого результата нужно минимум 4 бита.';
                return;
            }

            try {
                const sequence = parseBits(bits);
                const model = berlekampMassey(sequence);
                renderResult(sequence, model);
            } catch (error) {
                errorDiv.textContent = `Ошибка: ${error.message}`;
            }
        });
    </script>
<?php crypto_page_end(); ?>
