<?php
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';

$config_path = __DIR__ . '/data/site_config.json';
$config = [];
if (is_file($config_path)) {
    $json = file_get_contents($config_path);
    if ($json !== false) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }
}

function cfg(array $source, string $path, $default = null) {
    $parts = explode('.', $path);
    $value = $source;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$hero_tag = (string)cfg($config, 'hero.tag', '// личный сайт');
$hero_name = (string)cfg($config, 'hero.name', 'Xelopat');

$projects = cfg($config, 'projects', []);
if (!is_array($projects) || !$projects) {
    $projects = cfg($config, 'cards', []);
}
if (!is_array($projects)) {
    $projects = [];
}

$terminal_config = cfg($config, 'terminal', []);
if (!is_array($terminal_config)) {
    $terminal_config = [];
}
$terminal_json = json_encode($terminal_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($terminal_json === false) {
    $terminal_json = '{}';
}

$footer_text = (string)cfg($config, 'footer.text', 'xelopat · 2026');
?>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Inter:wght@400;500;700&display=swap');

  .site-shell{
    min-height:calc(100vh - 60px);
    position:relative;
    overflow:hidden;
    background:#151518;
    color:#efeff1;
  }

  .site-shell::before{
    content:"";
    position:absolute;
    inset:0;
    background-image:radial-gradient(circle at 1px 1px, rgba(51,51,64,.55) 1px, transparent 0);
    background-size:80px 80px;
    pointer-events:none;
    opacity:.7;
  }

  .site-container{
    width:min(1280px, calc(100vw - 24px));
    margin:0 auto;
    position:relative;
    z-index:1;
  }

  .hero{
    padding:52px 0 40px;
    display:grid;
    grid-template-columns:minmax(0, 1fr) 420px;
    gap:32px;
    align-items:start;
  }

  .hero-tag{
    display:inline-flex;
    align-items:center;
    background:#0f3328;
    border-radius:4px;
    padding:3px 10px;
    font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:11px;
    color:#61d1ad;
    margin-bottom:16px;
  }

  .hero-name{
    font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-weight:700;
    font-size:52px;
    line-height:1.1;
    margin:0 0 12px;
  }

  .cursor{
    display:inline-block;
    width:4px;
    height:52px;
    background:#f9c940;
    margin-left:3px;
    vertical-align:middle;
    animation:blink 1s step-end infinite;
  }

  @keyframes blink { 50% { opacity:0; } }

  .hero-divider{
    width:min(320px, 100%);
    height:1px;
    background:#333340;
    margin-bottom:0;
  }

  .terminal{
    background:#1e1e25;
    border:1px solid #333340;
    border-radius:12px;
    overflow:hidden;
    box-shadow:0 18px 40px rgba(0,0,0,.18);
  }

  .term-bar{
    background:#151518;
    padding:10px 14px;
    display:flex;
    align-items:center;
    gap:6px;
    border-bottom:1px solid #2a2a34;
  }

  .dot{ width:11px; height:11px; border-radius:50%; }

  .term-body{
    padding:14px 16px;
    height:380px;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:12px;
  }

  .term-output{
    display:flex;
    flex-direction:column;
    gap:7px;
    flex:1;
    min-height:0;
    overflow:auto;
    padding-right:2px;
  }
  .term-line{ display:flex; gap:6px; flex-wrap:wrap; line-height:1.5; word-break:break-word; }
  .term-line a{ color:#61d1ad; text-decoration:underline; }
  .term-line a:hover{ opacity:.88; }
  .term-prompt{ color:#61d1ad; }
  .term-arrow{ color:#f9c940; }
  .term-error{ color:#ff8f8f; }

  .term-input-row{
    display:flex;
    align-items:center;
    gap:8px;
    margin-top:12px;
    padding-top:10px;
    border-top:1px solid #2a2a34;
    flex-shrink:0;
  }

  .term-input{
    flex:1;
    background:transparent;
    border:none;
    color:#efeff1;
    font:inherit;
    outline:none;
    min-width:0;
  }

  .section{ padding:0 0 40px; position:relative; z-index:1; }
  .sec-label{
    font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:11px;
    color:#61d1ad;
    margin-bottom:6px;
  }
  .sec-title{ font-size:24px; font-weight:700; margin-bottom:20px; }

  .cards{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:14px;
  }

  .card{
    background:#1e1e25;
    border:1px solid #333340;
    border-radius:12px;
    overflow:hidden;
    transition:border-color .18s ease;
  }
  .card:hover{
    border-color:#555566;
  }
  .card-media{
    width:100%;
    aspect-ratio:16 / 9;
    background:#151518;
    border-bottom:1px solid #333340;
  }
  .card-media img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
  .card-inner{ padding:14px 16px; }
  .card-title{ font-size:15px; font-weight:700; margin-bottom:6px; line-height:1.4; }
  .card-desc{ font-size:12px; color:#868899; line-height:1.6; margin:0; }

  .footer-bar{
    border-top:1px solid #333340;
    padding:14px 0 28px;
    display:flex;
    justify-content:space-between;
    gap:16px;
    align-items:center;
  }
  .footer-txt,
  .footer-up{
    font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:11px;
    color:#868899;
  }
  .footer-up{
    color:#f9c940;
    text-decoration:none;
  }

  @media (max-width: 980px){
    .site-shell{
      min-height:auto;
    }
    .site-container{
      width:calc(100vw - 20px);
    }
    .hero{
      grid-template-columns:1fr;
      gap:14px;
      padding:30px 0 22px;
    }
    .hero-tag{
      margin-bottom:10px;
      font-size:12px;
    }
    .hero-name{
      font-size:clamp(36px, 10vw, 48px);
      margin:0 0 10px;
    }
    .cursor{
      width:3px;
      height:clamp(36px, 10vw, 48px);
    }
    .hero-divider{
      width:100%;
    }
    .terminal{ display:none; }
    .section{
      padding:0 0 28px;
    }
    .sec-title{
      font-size:26px;
      margin-bottom:14px;
    }
    .cards{ grid-template-columns:1fr; }
    .card-inner{
      padding:12px 13px;
    }
    .card-title{
      font-size:18px;
    }
    .card-desc{
      font-size:14px;
      line-height:1.55;
    }
    .footer-bar{
      padding:12px 0 20px;
      flex-direction:column;
      align-items:flex-start;
      gap:8px;
    }
    .footer-txt,
    .footer-up{
      font-size:12px;
    }
    .footer-up{
      align-self:flex-end;
    }
  }

  @media (max-width: 640px){
    .site-shell::before{
      background-size:58px 58px;
      opacity:.55;
    }
    .hero-name{
      font-size:clamp(32px, 12vw, 40px);
    }
    .sec-label{
      font-size:11px;
    }
    .cards{
      gap:10px;
    }
    .card-media{
      aspect-ratio:4 / 3;
    }
  }
</style>

<div class="site-shell">
  <div class="site-container">
    <section class="hero">
      <div>
        <div class="hero-tag"><?= e($hero_tag) ?></div>
        <h1 class="hero-name"><?= e($hero_name) ?><span class="cursor"></span></h1>
        <div class="hero-divider"></div>
      </div>

      <div class="terminal" id="fakeTerminal" data-terminal='<?= e($terminal_json) ?>'>
        <div class="term-bar">
          <div class="dot" style="background:#f9c940"></div>
          <div class="dot" style="background:#61d1ad"></div>
          <div class="dot" style="background:#868899"></div>
        </div>
        <div class="term-body">
          <div class="term-output" id="termOutput"></div>
          <div class="term-input-row">
            <span class="term-prompt">$</span>
            <input class="term-input" id="termInput" type="text" autocomplete="off" spellcheck="false" placeholder="">
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="sec-label">// projects</div>
      <div class="sec-title">Проекты</div>
      <div class="cards">
        <?php foreach ($projects as $project): ?>
          <?php
            if (!is_array($project)) continue;
            $title = (string)($project['title'] ?? 'Без названия');
            $description = (string)($project['description'] ?? '');
            $image = (string)($project['image'] ?? '');
          ?>
          <article class="card">
            <div class="card-media">
              <?php if ($image !== ''): ?>
                <img src="<?= e($image) ?>" alt="<?= e($title) ?>">
              <?php endif; ?>
            </div>
            <div class="card-inner">
              <div class="card-title"><?= e($title) ?></div>
              <div class="card-desc"><?= e($description) ?></div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <footer class="footer-bar">
      <span class="footer-txt"><?= e($footer_text) ?></span>
      <a class="footer-up" href="#top" onclick="window.scrollTo({top:0,behavior:'smooth'}); return false;">↑ наверх</a>
    </footer>
  </div>
</div>
<script>
(function () {
  const terminal = document.getElementById('fakeTerminal');
  if (!terminal) return;

  const output = document.getElementById('termOutput');
  const input = document.getElementById('termInput');
  const termBody = terminal.querySelector('.term-body');

  let config = {};
  try {
    config = JSON.parse(terminal.dataset.terminal || '{}');
  } catch (e) {
    config = {};
  }

  const rootNode = config.filesystem || { type: 'dir', children: {} };
  let cwd = [];
  const history = [];
  let historyIndex = -1;
  const COMMANDS = ['help', 'ls', 'cd', 'pwd', 'cat', 'tree', 'whoami', 'echo', 'clear'];
  const COMMANDS_WITH_ARG = new Set(['ls', 'cd', 'cat', 'tree', 'echo']);
  const PATH_COMMANDS = new Set(['ls', 'cd', 'cat', 'tree']);
  const LINK_PATTERN = /\[([^\]]+)\]\((\/[^)\s]+|https?:\/\/[^)\s]+)\)|(https?:\/\/[^\s]+)/g;

  function appendLinkedText(container, text) {
    const source = String(text ?? '');
    LINK_PATTERN.lastIndex = 0;
    let lastIndex = 0;
    let match;

    while ((match = LINK_PATTERN.exec(source)) !== null) {
      if (match.index > lastIndex) {
        container.appendChild(document.createTextNode(source.slice(lastIndex, match.index)));
      }

      const href = match[2] || match[3];
      const label = match[1] || href;
      const link = document.createElement('a');
      link.href = href;
      link.textContent = label;
      if (href.startsWith('http://') || href.startsWith('https://')) {
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
      }
      container.appendChild(link);
      lastIndex = LINK_PATTERN.lastIndex;
    }

    if (lastIndex < source.length) {
      container.appendChild(document.createTextNode(source.slice(lastIndex)));
    }
  }

  function printLine(parts, className) {
    const row = document.createElement('div');
    row.className = 'term-line' + (className ? ' ' + className : '');
    if (Array.isArray(parts)) {
      parts.forEach(part => {
        if (typeof part === 'string') {
          const span = document.createElement('span');
          appendLinkedText(span, part);
          row.appendChild(span);
        } else if (part && typeof part === 'object') {
          const span = document.createElement('span');
          appendLinkedText(span, part.text || '');
          if (part.className) span.className = part.className;
          row.appendChild(span);
        }
      });
    } else {
      row.textContent = String(parts ?? '');
    }
    output.appendChild(row);
    output.scrollTop = output.scrollHeight;
  }

  function printCommand(command) {
    printLine([{ text: '$', className: 'term-prompt' }, command]);
  }

  function printInfo(text) {
    printLine([{ text: '→', className: 'term-arrow' }, text]);
  }

  function printError(text) {
    printLine([{ text: '!', className: 'term-error' }, text], 'term-error');
  }

  function getNode(pathParts) {
    let node = rootNode;
    for (const part of pathParts) {
      if (!node || node.type !== 'dir' || !node.children || !node.children[part]) {
        return null;
      }
      node = node.children[part];
    }
    return node;
  }

  function normalizePath(inputPath) {
    const parts = inputPath.split('/');
    const stack = inputPath.startsWith('/') ? [] : cwd.slice();
    for (const part of parts) {
      if (!part || part === '.') continue;
      if (part === '..') {
        stack.pop();
      } else {
        stack.push(part);
      }
    }
    return stack;
  }

  function pwd() {
    return '/' + cwd.join('/');
  }

  function commonPrefix(values) {
    if (!values.length) return '';
    let prefix = values[0];
    for (let i = 1; i < values.length; i++) {
      while (!values[i].startsWith(prefix) && prefix.length > 0) {
        prefix = prefix.slice(0, -1);
      }
      if (!prefix) break;
    }
    return prefix;
  }

  function completeCommand(rawToken) {
    const token = rawToken.toLowerCase();
    const matches = COMMANDS.filter(cmd => cmd.startsWith(token));
    if (!matches.length) return false;

    if (matches.length === 1) {
      const full = matches[0];
      input.value = COMMANDS_WITH_ARG.has(full) ? full + ' ' : full;
      return true;
    }

    const prefix = commonPrefix(matches);
    if (prefix.length > rawToken.length) {
      input.value = prefix;
    }
    printInfo(matches.join('   '));
    return true;
  }

  function completePath(cmdToken, rawArg) {
    const arg = rawArg || '';
    const lastSlashIndex = arg.lastIndexOf('/');
    const baseInput = lastSlashIndex >= 0 ? arg.slice(0, lastSlashIndex + 1) : '';
    const namePrefix = lastSlashIndex >= 0 ? arg.slice(lastSlashIndex + 1) : arg;
    const baseParts = normalizePath(baseInput || '.');
    const baseNode = getNode(baseParts);

    if (!baseNode || baseNode.type !== 'dir') {
      return false;
    }

    const matches = Object.entries(baseNode.children || {})
      .filter(([name]) => name.startsWith(namePrefix))
      .map(([name, node]) => ({ name, node }));

    if (!matches.length) {
      return false;
    }

    if (matches.length === 1) {
      const item = matches[0];
      const suffix = item.node.type === 'dir' ? '/' : '';
      input.value = cmdToken + ' ' + baseInput + item.name + suffix;
      return true;
    }

    const names = matches.map(item => item.name);
    const prefix = commonPrefix(names);
    if (prefix.length > namePrefix.length) {
      input.value = cmdToken + ' ' + baseInput + prefix;
    }

    printInfo(matches.map(item => item.name + (item.node.type === 'dir' ? '/' : '')).join('   '));
    return true;
  }

  function handleTabCompletion() {
    const raw = input.value;
    const trimmed = raw.trim();
    if (!trimmed) return;

    const hasTrailingSpace = /\s$/.test(raw);
    const tokens = trimmed.split(/\s+/);

    if (tokens.length === 1 && !hasTrailingSpace) {
      completeCommand(tokens[0]);
      return;
    }

    const cmdToken = tokens[0];
    const cmd = cmdToken.toLowerCase();
    if (!PATH_COMMANDS.has(cmd)) return;
    if (tokens.length > 2) return;

    const arg = tokens.length === 1 ? '' : tokens[1];
    completePath(cmdToken, arg);
  }

  function listDir(pathParts) {
    const node = getNode(pathParts);
    if (!node) {
      printError('Путь не найден.');
      return;
    }
    if (node.type !== 'dir') {
      printInfo(pathParts[pathParts.length - 1] || '/');
      return;
    }
    const names = Object.keys(node.children || {});
    if (!names.length) {
      printInfo('(пусто)');
      return;
    }
    printInfo(names.join('   '));
  }

  function catFile(pathParts) {
    const node = getNode(pathParts);
    if (!node) {
      printError('Файл не найден.');
      return;
    }
    if (node.type !== 'file') {
      printError('Это папка, а не файл.');
      return;
    }
    const lines = String(node.content || '').split('\n');
    lines.forEach(line => printInfo(line));
  }

  function drawTree(node, prefix, name) {
    if (!node) return;
    if (name) {
      printInfo(prefix + name + (node.type === 'dir' ? '/' : ''));
    }
    if (node.type !== 'dir') return;
    const entries = Object.entries(node.children || {});
    entries.forEach(([childName, childNode], index) => {
      const branch = index === entries.length - 1 ? '└─ ' : '├─ ';
      const nextPrefix = prefix + (index === entries.length - 1 ? '   ' : '│  ');
      printInfo(prefix + branch + childName + (childNode.type === 'dir' ? '/' : ''));
      if (childNode.type === 'dir') {
        Object.entries(childNode.children || {}).forEach(([grandName, grandNode]) => {
          drawTree(grandNode, nextPrefix, grandName);
        });
      }
    });
  }

  function handleCommand(raw) {
    const command = raw.trim();
    if (!command) return;

    history.push(command);
    historyIndex = history.length;
    printCommand(command);

    const parts = command.split(/\s+/);
    const cmd = parts[0].toLowerCase();
    const arg = parts.slice(1).join(' ');

    if (cmd === 'help') {
      printInfo('help, ls [path], cd [path], pwd, cat <file>, tree [path], whoami, echo <text>, clear');
      return;
    }

    if (cmd === 'clear') {
      output.innerHTML = '';
      return;
    }

    if (cmd === 'pwd') {
      printInfo(pwd());
      return;
    }

    if (cmd === 'whoami') {
      printInfo(config.whoami || 'guest');
      return;
    }

    if (cmd === 'echo') {
      printInfo(arg || '');
      return;
    }

    if (cmd === 'ls') {
      listDir(arg ? normalizePath(arg) : cwd);
      return;
    }

    if (cmd === 'cd') {
      const target = arg ? normalizePath(arg) : [];
      const node = getNode(target);
      if (!node) {
        printError('Папка не найдена.');
        return;
      }
      if (node.type !== 'dir') {
        printError('Нельзя перейти в файл.');
        return;
      }
      cwd = target;
      printInfo('Текущая папка: ' + pwd());
      return;
    }

    if (cmd === 'cat') {
      if (!arg) {
        printError('Укажи файл.');
        return;
      }
      catFile(normalizePath(arg));
      return;
    }

    if (cmd === 'tree') {
      const target = arg ? normalizePath(arg) : cwd;
      const node = getNode(target);
      if (!node) {
        printError('Путь не найден.');
        return;
      }
      printInfo((target.length ? target[target.length - 1] : '/') + (node.type === 'dir' ? '/' : ''));
      if (node.type === 'file') return;
      const entries = Object.entries(node.children || {});
      entries.forEach(([childName, childNode], index) => {
        const branch = index === entries.length - 1 ? '└─ ' : '├─ ';
        printInfo(branch + childName + (childNode.type === 'dir' ? '/' : ''));
        if (childNode.type === 'dir') {
          drawTree(childNode, index === entries.length - 1 ? '   ' : '│  ', '');
        }
      });
      return;
    }

    printError('Неизвестная команда. Напиши help.');
  }

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Tab') {
      e.preventDefault();
      handleTabCompletion();
      return;
    }

    if (e.key === 'Enter') {
      handleCommand(input.value);
      input.value = '';
      return;
    }

    if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!history.length) return;
      historyIndex = Math.max(0, historyIndex - 1);
      input.value = history[historyIndex] || '';
    }

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!history.length) return;
      historyIndex = Math.min(history.length, historyIndex + 1);
      input.value = history[historyIndex] || '';
    }
  });

  terminal.addEventListener('click', function () {
    input.focus();
  });
})();
</script>
