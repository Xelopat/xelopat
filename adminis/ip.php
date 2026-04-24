<?php
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Inter:wght@400;500;700&display=swap');

  .ip-page{
    min-height:calc(100vh - 60px);
    background:#151518;
    color:#efeff1;
    padding:28px 0 38px;
  }

  .ip-wrap{
    width:min(980px, calc(100vw - 24px));
    margin:0 auto;
  }

  .ip-label{
    font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:11px;
    color:#61d1ad;
    margin-bottom:6px;
  }

  .ip-title{
    margin:0 0 10px;
    font-size:30px;
    line-height:1.2;
  }

  .ip-sub{
    margin:0 0 20px;
    color:#a4a8bb;
    font-size:14px;
    line-height:1.6;
    max-width:760px;
  }

  .ip-panel{
    background:#1e1e25;
    border:1px solid #333340;
    border-radius:12px;
    padding:16px;
  }

  .ip-grid{
    display:grid;
    grid-template-columns:1fr;
    gap:14px;
  }

  .ip-field label{
    display:block;
    margin-bottom:7px;
    font-size:13px;
    color:#c5c8d6;
    font-weight:600;
  }

  .ip-field input{
    width:100%;
    background:#151518;
    border:1px solid #333340;
    border-radius:8px;
    padding:10px 12px;
    color:#efeff1;
    font:inherit;
    font-size:14px;
    outline:none;
  }

  .ip-field input:focus{
    border-color:#61d1ad;
    box-shadow:0 0 0 3px rgba(97,209,173,.15);
  }

  .ip-actions{
    display:flex;
    justify-content:flex-start;
  }

  .ip-btn{
    border:1px solid #333340;
    background:#151518;
    color:#efeff1;
    border-radius:8px;
    padding:10px 14px;
    font:inherit;
    font-size:14px;
    font-weight:600;
    cursor:pointer;
    transition:border-color .16s ease, color .16s ease;
  }

  .ip-btn:hover{
    border-color:#f9c940;
    color:#f9c940;
  }

  .ip-out{
    margin-top:14px;
    background:#151518;
    border:1px solid #333340;
    border-radius:10px;
    padding:14px;
    overflow:auto;
  }

  .ip-table{
    width:100%;
    border-collapse:collapse;
    min-width:760px;
  }

  .ip-table th,
  .ip-table td{
    border-bottom:1px solid #2b2b36;
    padding:9px 8px;
    text-align:left;
    font-size:13px;
    vertical-align:top;
  }

  .ip-table th{
    color:#61d1ad;
    font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.04em;
  }

  .ip-table tr:last-child td{
    border-bottom:none;
  }

  .ip-error{
    margin:0;
    color:#ff8f8f;
    font-size:13px;
  }

  @media (max-width: 900px){
    .ip-wrap{ width:calc(100vw - 20px); }
    .ip-title{ font-size:26px; }
    .ip-sub{ font-size:13px; }
    .ip-panel{ padding:14px; }
  }
</style>

<main class="ip-page">
  <div class="ip-wrap">
    <div class="ip-label">// администрирование</div>
    <h1 class="ip-title">Калькулятор подсетей (VLSM)</h1>
    <p class="ip-sub">Введите сеть в формате CIDR и список групп ПК через пробел. Расчёт строится от самой крупной подсети к меньшей.</p>

    <section class="ip-panel">
      <form id="subnetForm" class="ip-grid">
        <div class="ip-field">
          <label for="networkAddress">Адрес сети</label>
          <input type="text" id="networkAddress" placeholder="192.168.0.0/24" value="192.168.0.0/24" required>
        </div>

        <div class="ip-field">
          <label for="userGroups">Количество ПК в каждой подсети (через пробел)</label>
          <input type="text" id="userGroups" placeholder="50 30 20" required>
        </div>

        <div class="ip-actions">
          <button class="ip-btn" type="submit">Рассчитать</button>
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
    const { baseIp } = parseNetwork(networkAddress);
    const baseDecimal = ipToDecimal(baseIp);
    let currentDecimal = baseDecimal;
    const results = [];

    const groups = userGroups.slice().sort((a, b) => b - a);
    groups.forEach((users) => {
      const requiredBits = Math.ceil(Math.log2(users + 2));
      const subnetMask = 32 - requiredBits;
      const subnetSize = 2 ** requiredBits;
      if (subnetMask < 1 || subnetMask > 32) {
        throw new Error(`Невозможно создать подсеть для ${users} ПК`);
      }

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

    return results;
  }

  function renderResults(results) {
    return `
      <table class="ip-table">
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
    const userGroups = userGroupsRaw
      .split(/\s+/)
      .map(Number)
      .filter((n) => Number.isFinite(n) && n > 0);

    if (!userGroups.length) {
      outputNode.innerHTML = '<p class="ip-error">Добавьте хотя бы одно число для подсети.</p>';
      return;
    }

    try {
      const results = calculateSubnets(networkAddress, userGroups);
      outputNode.innerHTML = renderResults(results);
    } catch (error) {
      outputNode.innerHTML = `<p class="ip-error">Ошибка: ${String(error.message || error)}</p>`;
    }
  });
</script>
