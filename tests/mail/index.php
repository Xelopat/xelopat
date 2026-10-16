<?php
// Route https://xelopat.ru/tests/mail to this file; preserve query and POST.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$action = isset($_GET['action']) ? $_GET['action'] : 'probe';
$via = isset($_GET['via']) ? $_GET['via'] : 'direct';
if (!is_string($action) || !is_string($via)) { http_response_code(400); exit; }
$allowed = array('direct','png','img','object','inline-svg','img-event','css-url','css-import','css-import-pixel','css-selector');
if (!in_array($via, $allowed, true)) $via = 'direct';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
function qa_event($stage, $via, $method) {
    error_log('WEBVIEW_QA ' . json_encode(array('utc'=>gmdate('c'),'stage'=>$stage,'via'=>$via,'method'=>$method)));
}
if ($action === 'executed') {
    header('Content-Type: text/plain; charset=utf-8');
    if ($method !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
    if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 256) { http_response_code(413); exit; }
    if (file_get_contents('php://input', false, null, 0, 257) !== 'WEBVIEW_QA_EXECUTED') { http_response_code(400); exit; }
    qa_event('EXECUTED', $via, $method);
    echo 'OK'; exit;
}
if ($method !== 'GET' && $method !== 'HEAD') { header('Allow: GET, HEAD'); http_response_code(405); exit; }
if ($action === 'health') {
    header('Content-Type: text/plain; charset=utf-8');
    if ($method !== 'HEAD') echo 'WEBVIEW_QA_OK';
    exit;
}
if ($action === 'svg') {
    header('Content-Type: image/svg+xml; charset=utf-8');
    qa_event('SVG_REQUEST', $via, $method);
    if ($method === 'HEAD') exit;
    echo <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="360" height="80" viewBox="0 0 360 80"><rect width="360" height="80" fill="#eee"/><text id="svg_state" x="12" y="45" font-size="18">SVG: JS не выполнен</text><script><![CDATA[(function(){var s=document.getElementById('svg_state');s.textContent='SVG: JS ВЫПОЛНЕН';s.setAttribute('fill','red');var via=new URLSearchParams(location.search).get('via')||'direct';var endpoint='https://xelopat.ru/tests/mail?action=executed&via='+encodeURIComponent(via);if(navigator.sendBeacon){navigator.sendBeacon(endpoint,'WEBVIEW_QA_EXECUTED')}else{fetch(endpoint,{method:'POST',body:'WEBVIEW_QA_EXECUTED',mode:'no-cors'}).catch(function(){})}})();]]></script></svg>
SVG;
    exit;
}
if ($action === 'png' || $action === 'pixel') {
    header('Content-Type: image/png');
    if ($action === 'png') $stage = 'PNG_REQUEST';
    elseif ($via === 'css-selector') $stage = 'CSS_CANARY_MATCH';
    else $stage = 'CSS_RESOURCE_REQUEST';
    qa_event($stage, $via, $method);
    if ($method === 'HEAD') exit;
    echo base64_decode($action === 'png' ? 'iVBORw0KGgoAAAANSUhEUgAAAeAAAADwCAIAAABXFyDtAAALoklEQVR4nO3beXSW1Z3A8UsSLAPioLgwpXbC4i6yI6IxoOC+b10UFLWLtY1sIoqIG9SlVErFQWVRmFatsnasVWRRFHcFgqwioKiAMEYgIYVg5tC3w2EMCdPCOf0Jn89fee/7PPc+z8s531weXqqtXFeeAIgn6599AQDsmEADBCXQAEEJNEBQAg0QlEADBCXQAEEJNEBQAg0QlEADBCXQAEEJNEBQAg0QlEADBCXQAEEJNEBQAg0QlEADBCXQAEHl7MrJped12H1Xwj+uxqRpu+Xj+/7krv4YIniy06jdMs8FA5ftlnnYRRNuyf3HTrSDBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCEmiAoAQaICiBBghKoAGCqrZyXfk/+xoA2AE7aICgBBogKIEGCEqgAYISaICgBBogKIEGCEqgAYISaICgBBogKIEGCEqgAYISaICgBBogKIEGCEqgAYISaICgBBogKIEGCEqgAYISaICgBBogKIEGCEqgAYISaICgBBogKIEGCEqgAYISaICg9vZAt26XF3Y2YC+Xsysn97n1tvYn551xWqeU0vmXXNa2TZube/dKKQ289/6WLZqf3qljs9ZtmzQ5NnPwKe3zu3bp/LeR8vINxcUF11/39jvvNcjNveSiCzLHXP2T63r36N656zVvzZyRUvra6QsXLa56ucyR4yZM/M8nnqpePaesrOyqzlece/ZZmfGnx42/c8Avp7/wXN26dSu7o22XV1xccnPvXq1btfzDM2OfHjc+Ozt7v9q17+zfr94hh2x/WOYuOuTnV/EpvTTjlYIePWe/9cZrb7z5mweH7rPPPlvKttzYo1uzpset37ChT99+XxQV7V+nzj0D7qq9775Vf+Bjx08YN2FScUnxjd27ndjuhIpL/L0TAntsoFs0a1o49/0zTutUXFycnZ0zu7AwMz67sPAn116dUqpevfqYkcO3P2XbyIKFi64ruOG+gQPG/O73mUCXlJR89tnKI484vOLBGX94ZmzVy6WUXpk585nxEx4b/vB+tWuvW7/+pz8vqFevXuuWLVJK06a/1PnyH7w045WLLji/sjvatuKixYt79enbp1ePyVOmPjH6sZycnEdHjurb/44Rwx6qeBdVBLq4uPg/Hnk0J6d6Sqlv/9tHj3j0O/Xrf/Txiut+UfDshHHDHhneqmWLrl06j3p8zCPDR/bsVlDFp/3fX3wxYdIfx4wavmz5Rz/v1v1PE8dXXOLvmhDYkx9xNG/WdO68eVsTOacwP++k0tLSTZs2lZWVlZaWVrFLzTji8MNysnNaNG82f8HCLVu2pJRmvv5G3ontdnG5EaMe792j+361a6eU9qtd+8bu3R4dMTKlVFpaunHjxksvunDay1v35jt1WOPGq1avHvH46ILrf5aTs/XX2A+/d1mNb31ry1dfVbyLKuYZNHjIlVdcnlWtWkqpzr/WKSr6MqVU9GVRycaNmZ3v2WeekVI668zTp293Yb/o3vPFqdNSSrfdcdfEPz6bGSwq+vLyH3w/Kyvr3+odkpmn4hKVTQjsdYFu3KjRxytWlJeXvztrdqsWzY85+uj5CxbOX7CwybHH7PTc199865abemVnZTU9rsmsOXO2xuXlGad2aL+Lyy35cOlRRx657eXRRx21eMmSlNKMV2fmnXhig9zcTz75dPPmzTu9vFdfe61tm9YffLDkiMMPy4zUqlVr6G8eyM7KqngXlU3yznvvrf788zNPPy3z8o5+fX94ZddzL7r0iquu6X/LzSmltWvXHvjXXy0HHXjg2rVrt53Yt0/vocMeLpz7/mcrV55/7tmZwYYNcjOPd56f/GKH9vk7XKKyCYG97hFHtWrVGuY2WLZ8eeHcuV27XPHZylWz5szJyc5u1bJl5oDNmzd3vvrazM89byho1vS4zMimTZvmzn3/+OPbdMjP79A+/+UZr7Zs3nzWnMLb+/Xdfv6Kp1e9XEXlqbykZOtedcq06QsWLnx+8ourP1/91tvvtDuh7Q6Pz6xYVlb24dJlz44fe/6l38uMjxo9Zur0l9asWfvcpK0PFireRcWpNm3adN+vHvjt4EHbRu4b9Otf3TPwtI6n/vmFyS9Mmdo+/+TKLrveIYecd87ZP7uh2xOjH/vaWx99vGL4qMdHj3x0h0sAe5JdCnTmscOcwvdL//KXWrVqNW/WdOiwh3Nycgquvy7zbhXPoBctXnz5lVsfHOed2G70mN/NW7Dg6KOOyM7O3uHB/8/ltu6yGzeaN39+82ZNMy/nzZvfuGHDLV99tWz58glPP5V5SD3t5ZcrC/S2FYePemzcxEm53/3uwkWLmxx7TNcunS++8IK8UzpWdhcVvfDilOKS4p43bd0pl5SU3NT31oWLP+h4SoeUUqeOp95+94CUUt26ddesXXvwQQd9vmbN1x4KlZRszMnOKSkp+b+DJd1v7D3gzv4H7L//DpeoYkJgr/uaXfNmTSdMmnRY40aZv4MvW/7RqtWrv1O//k5PrFOnzqGHHpp5UlzjX2qMHT+xY4cOu77cj67uev8Dg9dv2JBSWrd+/aDBQ350Tdd335u17d8eW7Vo8erM13a6ULsT2hbOnXvZJRcPGfpQWVlZSun3Tz6VlZVd2V1UdM5ZZ/7X+LFjRg4fM3J4zZo17x1wd4Pcf3931qyU0qzZs+t/+9sppfy8k5597s8ppT8993x+3knbzl22fPlrr78x7MEhdw28p7y8PDNYXl5+0623XX1ll6ZNmlS2RGUTAnvjDrrZcU3efPudyy65OPPE4+CDDtx3u692bf+MonnT43rcUJAZyfrrk9w7b7s181b7k/N++9CwXhW+clDx9KqXSymdcHybVatWXXXtj7OzspcsXZpSWrHik08+/bRtmzaZA2rUqFH3gAOWfLi0UcMGVdxXw9zchYsWDR50/5KlH5538aUHH3TweeeclfO/G/wd3sVO3dHv1gH33Lv1p2rV7r69f0rppz++tk/ffpOnTM18K27bkf3vGtCzW8ERhx/WuFGjZ8ZPuPSiC1NK4ydOeuXVmUVFRU8+/UytmjWHPTik4hKVTQh8E1Vbue5vG7Q90hdFRYsXf9CmdavKDmjdLi/znevdYvfOBuzl9vD/Sbh/nTpV1Bkgsj18Bw3wzbWH76ABvrkEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQYISqABghJogKAEGiAogQZIMf0PSQPW4hU57akAAAAASUVORK5CYII=' : 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC');
    exit;
}
if ($action === 'css') {
    header('Content-Type: text/css; charset=utf-8');
    qa_event('CSS_IMPORT_REQUEST', $via, $method);
    if ($method === 'HEAD') exit;
    echo <<<'CSS'
.css-import{background-image:url("https://xelopat.ru/tests/mail?action=pixel&via=css-import-pixel");min-height:24px}.css-import::after{content:" / LOADED";color:green}
CSS;
    exit;
}
if ($action !== 'probe') { http_response_code(404); exit; }
header('Content-Type: text/html; charset=utf-8');
qa_event('HTML_REQUEST', $via, $method);
if ($method === 'HEAD') exit;
echo <<<'HTML'
<!doctype html><html lang="ru"><meta charset="utf-8"><body><p>Файл загружен</p><svg xmlns="http://www.w3.org/2000/svg" width="360" height="50" onload="var s=document.getElementById(&#x27;inline_state&#x27;);s.textContent=&#x27;INLINE SVG: JS ВЫПОЛНЕН&#x27;;s.setAttribute(&#x27;fill&#x27;,&#x27;red&#x27;);var endpoint=&#x27;https://xelopat.ru/tests/mail?action=executed&amp;via=&#x27;+encodeURIComponent(&#x27;inline-svg&#x27;);if(navigator.sendBeacon){navigator.sendBeacon(endpoint,&#x27;WEBVIEW_QA_EXECUTED&#x27;)}else{fetch(endpoint,{method:&#x27;POST&#x27;,body:&#x27;WEBVIEW_QA_EXECUTED&#x27;,mode:&#x27;no-cors&#x27;}).catch(function(){})}"><text id="inline_state" x="0" y="30" font-size="17">INLINE SVG: JS не выполнен</text></svg></body></html>
HTML;
