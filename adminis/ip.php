<?php
$site_page_title = 'Калькулятор подсетей — xelopat';
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>
<style>
  .ip-form{
    display:grid;
    gap:14px;
  }

  .ip-out{
    margin-top:14px;
    background:var(--bg);
    border:1px solid var(--line);
    border-radius:10px;
    padding:14px;
    overflow:auto;
  }

  .ip-out .data-table{ min-width:760px; }
</style>

<main class="page">
  <div class="page-wrap page-wrap--narrow">
    <div class="page-label">// администрирование</div>
    <h1 class="page-title">Калькулятор подсетей (VLSM)</h1>
    <p class="page-sub">Введите сеть в формате CIDR и список групп ПК через пробел. Расчёт строится от самой крупной подсети к меньшей.</p>

    <section class="panel">
      <form id="subnetForm" class="ip-form">
        <div class="field">
          <label for="networkAddress">Адрес сети</label>
          <input type="text" id="networkAddress" placeholder="192.168.0.0/24" value="192.168.0.0/24" required>
        </div>

        <div class="field">
          <label for="userGroups">Количество ПК в каждой подсети (через пробел)</label>
          <input type="text" id="userGroups" placeholder="50 30 20" required>
        </div>

        <div>
          <button class="btn" type="submit">Рассчитать</button>
        </div>
      </form>

      <div class="ip-out" id="output"></div>
    </section>
  </div>
</main>

<script>
  function ipToDecimal(ip) {
    return ip.split('.').reduce((acc, octet) => (acc << 8) | parseInt(octet, 10), 0) >>> 0;
  }

  function decimalToIp(decimal) {
    return [(decimal >>> 24) & 255, (decimal >>> 16) & 255, (decimal >>> 8) & 255, decimal & 255].join('.');
  }

  function getMask(bits) {
    return `${decimalToIp((0xFFFFFFFF << (32 - bits)) >>> 0)} /${bits}`;
  }

  function parseNetwork(value) {
    const parts = value.split('/');
    if (parts.length !== 2) throw new Error('Сеть должна быть в формате A.B.C.D/XX');
    const baseIp = parts[0].trim();
    const cidr = Number(parts[1]);
    if (!Number.isInteger(cidr) || cidr < 1 || cidr > 32) {
      throw new Error('CIDR должен быть числом от 1 до 32');
    }
    const ipParts = baseIp.split('.');
    if (ipParts.length !== 4) throw new Error('Некорректный IP-адрес');
    for (const p of ipParts) {
      const n = Number(p);
      if (!Number.isInteger(n) || n < 0 || n > 255) {
        throw new Error('Некорректный IP-адрес');
      }
    }
    return { baseIp, cidr };
  }

  function calculateSubnets(networkAddress, userGroups) {
    const { baseIp, cidr } = parseNetwork(networkAddress);
    const networkSize = 2 ** (32 - cidr);
    // Приводим адрес к началу сети: 192.168.0.5/24 -> 192.168.0.0
    const baseDecimal = ipToDecimal(baseIp) - (ipToDecimal(baseIp) % networkSize);
    let currentDecimal = baseDecimal;
    const results = [];

    const groups = userGroups.slice().sort((a, b) => b - a).map((users) => {
      const requiredBits = Math.ceil(Math.log2(users + 2));
      return { users, requiredBits, subnetSize: 2 ** requiredBits };
    });

    const tooBig = groups.find((g) => g.requiredBits > 32 - cidr);
    if (tooBig) {
      throw new Error(`Подсеть на ${tooBig.users} ПК требует /${32 - tooBig.requiredBits} (${tooBig.subnetSize} адресов), а вся сеть /${cidr} — только ${networkSize} адресов`);
    }

    // Подсети — степени двойки и идут по убыванию, поэтому они влезают ровно тогда, когда влезает их сумма
    const totalRequired = groups.reduce((sum, g) => sum + g.subnetSize, 0);
    if (totalRequired > networkSize) {
      throw new Error(`Не хватает адресов: требуется ${totalRequired}, а в сети /${cidr} доступно ${networkSize}`);
    }

    groups.forEach(({ users, requiredBits, subnetSize }) => {
      const subnetMask = 32 - requiredBits;

      results.push({
        users,
        networkAddress: decimalToIp(currentDecimal),
        subnetMask: getMask(subnetMask),
        firstAddress: decimalToIp(currentDecimal + 1),
        lastAddress: decimalToIp(currentDecimal + subnetSize - 2),
        broadcastAddress: decimalToIp(currentDecimal + subnetSize - 1)
      });

      currentDecimal += subnetSize;
    });

    return { results, totalRequired, networkSize };
  }

  function renderResults({ results, totalRequired, networkSize }) {
    return `
      <p class="page-sub" style="margin:0 0 10px">Использовано ${totalRequired} из ${networkSize} адресов, свободно ${networkSize - totalRequired}.</p>
      <table class="data-table">
        <thead>
          <tr>
            <th>ПК</th>
            <th>Адрес сети</th>
            <th>Маска</th>
            <th>Первый адрес</th>
            <th>Последний адрес</th>
            <th>Broadcast</th>
          </tr>
        </thead>
        <tbody>
          ${results.map((result) => `
            <tr>
              <td>${result.users}</td>
              <td>${result.networkAddress}</td>
              <td>${result.subnetMask}</td>
              <td>${result.firstAddress}</td>
              <td>${result.lastAddress}</td>
              <td>${result.broadcastAddress}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  }

  document.getElementById('subnetForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const outputNode = document.getElementById('output');

    const networkAddress = document.getElementById('networkAddress').value.trim();
    const userGroupsRaw = document.getElementById('userGroups').value.trim();
    const tokens = userGroupsRaw.split(/\s+/).filter(Boolean);
    const userGroups = tokens.map(Number);

    if (userGroups.some((n) => !Number.isInteger(n) || n <= 0)) {
      outputNode.innerHTML = '<p class="form-error">Количество ПК должно быть целым положительным числом.</p>';
      return;
    }

    if (!userGroups.length) {
      outputNode.innerHTML = '<p class="form-error">Добавьте хотя бы одно число для подсети.</p>';
      return;
    }

    try {
      const results = calculateSubnets(networkAddress, userGroups);
      outputNode.innerHTML = renderResults(results);
    } catch (error) {
      outputNode.innerHTML = `<p class="form-error">Ошибка: ${String(error.message || error)}</p>`;
    }
  });
</script>
