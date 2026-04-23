<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Калькулятор подсетей</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #f2f4f8;
            margin: 0;
            padding: 40px 20px;
            color: #333;
        }

        h1 {
            text-align: center;
            margin-bottom: 30px;
        }

        form {
            max-width: 600px;
            margin: 0 auto;
            background: #fff;
            padding: 25px 30px;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        input[type="text"] {
            width: 100%;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        button {
            background-color: #0077cc;
            color: white;
            padding: 10px 20px;
            font-size: 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }

        button:hover {
            background-color: #005fa3;
        }

        .output {
            max-width: 900px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        table, th, td {
            border: 1px solid #ccc;
        }

        th {
            background-color: #f5f7fa;
        }

        th, td {
            padding: 10px;
            text-align: center;
        }

        h2 {
            margin-top: 0;
        }
    </style>
</head>
<body>
    <h1>Калькулятор подсетей</h1>
    <form id="subnetForm">
        <label for="networkAddress">Адрес сети:</label>
        <input type="text" id="networkAddress" placeholder="192.168.0.0/24" value="192.168.0.0/24" required>

        <label for="userGroups">Количество ПК в каждой подсети (через пробел):</label>
        <input type="text" id="userGroups" placeholder="50 30 20" required>

        <button type="submit">Рассчитать подсети</button>
    </form>

    <div class="output" id="output"></div>

    <script>
        function calculateSubnets(networkAddress, userGroups) {
            function ipToDecimal(ip) {
                return ip.split('.').reduce((acc, octet) => (acc << 8) | parseInt(octet, 10), 0);
            }

            function decimalToIp(decimal) {
                return [(decimal >>> 24) & 255, (decimal >>> 16) & 255, (decimal >>> 8) & 255, decimal & 255].join('.');
            }

            function getMask(bits) {
                return `${decimalToIp((0xFFFFFFFF << (32 - bits)) >>> 0)} /${bits}`;
            }

            const [baseIp, cidr] = networkAddress.split('/');
            const baseDecimal = ipToDecimal(baseIp);
            let currentDecimal = baseDecimal;
            const results = [];

            userGroups.sort((a, b) => b - a); // от большего к меньшему

            userGroups.forEach(users => {
                const requiredBits = Math.ceil(Math.log2(users + 2));
                const subnetMask = 32 - requiredBits;
                const subnetSize = 2 ** requiredBits;
                if (subnetMask < 1 || subnetMask > 32) {
                    throw new Error(`Невозможно создать подсеть для ${users} пользователей.`);
                }
                const networkAddress = decimalToIp(currentDecimal);
                const broadcastAddress = decimalToIp(currentDecimal + subnetSize - 1);
                const firstAddress = decimalToIp(currentDecimal + 1);
                const lastAddress = decimalToIp(currentDecimal + subnetSize - 2);

                results.push({
                    users,
                    networkAddress,
                    subnetMask: getMask(subnetMask),
                    firstAddress,
                    lastAddress,
                    broadcastAddress
                });

                currentDecimal += subnetSize;
            });

            return results;
        }

        document.getElementById('subnetForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const networkAddress = document.getElementById('networkAddress').value;
            const userGroups = document.getElementById('userGroups').value.split(' ').map(Number);

            try {
                const results = calculateSubnets(networkAddress, userGroups);
                const outputDiv = document.getElementById('output');
                outputDiv.innerHTML = '<h2>Рассчитанные подсети</h2>';

                const table = document.createElement('table');
                const headerRow = `<tr>
                    <th>Количество ПК</th>
                    <th>Адрес сети</th>
                    <th>Маска подсети</th>
                    <th>Первый адрес</th>
                    <th>Последний адрес</th>
                    <th>Широковещательный адрес</th>
                </tr>`;

                table.innerHTML = headerRow + results.map(result => `
                    <tr>
                        <td>${result.users}</td>
                        <td>${result.networkAddress}</td>
                        <td>${result.subnetMask}</td>
                        <td>${result.firstAddress}</td>
                        <td>${result.lastAddress}</td>
                        <td>${result.broadcastAddress}</td>
                    </tr>
                `).join('');

                outputDiv.appendChild(table);
            } catch (error) {
                alert('Ошибка при расчёте подсетей: ' + error.message);
            }
        });
    </script>
</body>
</html>
