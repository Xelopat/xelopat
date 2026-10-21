import {catalog,examples,makeQuote,parseRequest,comparePrices,parseInvoice,searchKnowledge,estimateValue,quoteCsv} from './engine.mjs';

const $=s=>document.querySelector(s);
const e=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const rub=x=>new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB',minimumFractionDigits:0,maximumFractionDigits:2}).format(x);
const money=c=>c===null||!Number.isFinite(c)?'Уточнить':rub(c/100);
const decimal=x=>new Intl.NumberFormat('ru-RU',{maximumFractionDigits:1}).format(x);
let currentQuote=null,activeDemo='quote';

function download(name,text,type='text/plain;charset=utf-8') {
 const blob=new Blob([text],{type}),url=URL.createObjectURL(blob),a=document.createElement('a');
 a.href=url;a.download=name;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
function renderCatalog(filter='all') {
 const list=catalog.filter(x=>filter==='all'||(filter==='start'?x.stage===1:x.category===filter));
 $('#catalog').innerHTML=list.map(x=>`<article class="solution-card"><div class="card-tags"><span class="tag">${e(x.category)}</span><span class="tag ${x.stage===1?'start':''}">${x.stage===1?'Первый запуск':x.stage===2?'Следующий этап':'После проверки спроса'}</span></div><h3>${e(x.title)}</h3><p>${e(x.output)}</p><div class="card-footer"><div class="card-price">${e(x.price)}<small>ОРИЕНТИР ПИЛОТА</small></div><button type="button" class="icon-button" data-detail="${x.id}" aria-label="Подробнее: ${e(x.title)}">↗</button></div><div class="card-links">${x.demo?`<button type="button" class="text-link" data-demo="${x.demo}">Попробовать демо ↗</button>`:''}<button type="button" class="text-link" data-brief="${x.id}">Выбрать задачу →</button></div></article>`).join('');
 document.querySelectorAll('[data-filter]').forEach(b=>{const a=b.dataset.filter===filter;b.classList.toggle('active',a);b.setAttribute('aria-pressed',a);});
}
function openDetail(id) {
 const x=catalog.find(x=>x.id===id);if(!x)return;
 $('#detail-content').innerHTML=`<p class="detail-label">${e(x.category)} / ${x.stage===1?'ПЕРВЫЙ ЗАПУСК':'КАТАЛОГ ВНЕДРЕНИЙ'}</p><h2 id="detail-title">${e(x.title)}</h2><div class="detail-body"><dl><dt>Кому полезно</dt><dd>${e(x.buyer)}</dd><dt>На входе</dt><dd>${e(x.input)}</dd><dt>На выходе</dt><dd>${e(x.output)}</dd><dt>Границы пилота</dt><dd>${e(x.scope)}</dd><dt>Как проверим результат</dt><dd>${e(x.metric)}</dd><dt>Готовые инструменты</dt><dd>${e(x.alternative)}</dd></dl><div class="detail-price">${e(x.price)}<small>${e(x.note)}</small></div><p class="fineprint">Оценка нашей работы: ${e(x.effort)} при указанных границах и подготовленных данных. Трудозатраты и цена проверяются после изучения примеров.</p><div class="actions">${x.demo?`<button type="button" class="button small" data-demo="${x.demo}">Попробовать демо ↗</button>`:''}<button type="button" class="text-link" data-brief="${x.id}">Собрать задачу →</button></div></div>`;
 $('#detail-dialog').showModal();
}
const table=(head,rows)=>`<div class="table-wrap"><table><thead><tr>${head.map(x=>`<th>${e(x)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div>`;
const resultWrap=()=>`<div id="demo-result" class="result" aria-live="polite"></div>`;
function renderDemo(id,scroll=false) {
 if(!['quote','inbox','prices','invoice','knowledge'].includes(id))return;
 activeDemo=id;
 if($('#detail-dialog').open)$('#detail-dialog').close();
 document.querySelectorAll('.demo-tab').forEach(b=>{const on=b.dataset.demo===id;b.classList.toggle('active',on);b.setAttribute('aria-selected',on);b.tabIndex=on?0:-1;});
 const panel=$('#demo-panel');panel.setAttribute('aria-labelledby','tab-'+id);
 if(id==='quote')panel.innerHTML=`<h3>Из спецификации в черновик КП</h3><p class="demo-description">Точные артикулы сопоставляются с учебным прайсом. Неизвестные позиции и несовпадения единиц остаются на проверку.</p><div class="demo-inputs"><label>Артикул;количество;единица <textarea id="quote-input" class="mono" rows="5" maxlength="15000" spellcheck="false">${e(examples.quote)}</textarea></label></div><div class="demo-actions"><button type="button" class="button small" id="run-demo">Подготовить черновик ↗</button><button type="button" class="text-link" id="reset-demo">Вернуть пример</button></div>${resultWrap()}<p class="demo-note">Учебный прайс: CAB-315 = 82,50 ₽/м; CAB-325 = 135 ₽/м; AUTO-16 = 245 ₽/шт. Цены без НДС. Первая строка имеет согласованный псевдоним «ВВГнг-LS 3х1.5». Технические аналоги автоматически не подбираются.</p>`;
 if(id==='inbox')panel.innerHTML=`<h3>Заявка с понятным следующим шагом</h3><p class="demo-description">Из текста извлекаются отмеченные поля, контакт и срок. Тип обращения определяется по словам; неоднозначные заявки требуют уточнения.</p><div class="demo-inputs"><label>Письмо клиента<textarea id="inbox-input" rows="7" maxlength="12000">${e(examples.inbox)}</textarea></label></div><div class="demo-actions"><button type="button" class="button small" id="run-demo">Разобрать заявку ↗</button><button type="button" class="text-link" id="reset-demo">Вернуть пример</button></div>${resultWrap()}<p class="demo-note">Результат является черновиком карточки. Подключение почты, запись в CRM и отправка ответов в этом демо отсутствуют. Разбор свободного текста с помощью ИИ может быть частью будущего пилота.</p>`;
 if(id==='prices')panel.innerHTML=`<h3>Сравните два прайса</h3><p class="demo-description">Сравниваются только одинаковые артикулы и единицы. Нулевая цена, повторный артикул или несовпадение единиц требуют проверки.</p><div class="demo-inputs two"><label>Поставщик А: артикул;цена;единица<textarea id="price-a" class="mono" rows="5" maxlength="15000" spellcheck="false">${e(examples.priceA)}</textarea></label><label>Поставщик Б: артикул;цена;единица<textarea id="price-b" class="mono" rows="5" maxlength="15000" spellcheck="false">${e(examples.priceB)}</textarea></label></div><div class="demo-actions"><button type="button" class="button small" id="run-demo">Сравнить прайсы ↗</button><button type="button" class="text-link" id="reset-demo">Вернуть пример</button></div>${resultWrap()}<p class="demo-note">Пример сравнивает цену единицы без НДС. Наличие, доставка, отсрочка и стоимость всей корзины отдельно не учитываются. Итоговое решение о закупке принимает сотрудник.</p>`;
 if(id==='invoice')panel.innerHTML=`<h3>Проверьте строки и итог счёта</h3><p class="demo-description">Разбор текста документа, проверка контрольной цифры ИНН и арифметики строк. В примере специально указан неверный общий итог.</p><div class="demo-inputs"><label>Текст учебного счёта<textarea id="invoice-input" class="mono" rows="9" maxlength="15000" spellcheck="false">${e(examples.invoice)}</textarea></label></div><div class="demo-actions"><button type="button" class="button small" id="run-demo">Проверить счёт ↗</button><button type="button" class="text-link" id="reset-demo">Вернуть пример</button></div>${resultWrap()}<p class="demo-note">Это проверка текста по правилам. PDF/OCR и поиск в реестрах не подключены. Совпадение контрольной цифры ИНН не подтверждает существование или надёжность компании.</p>`;
 if(id==='knowledge')panel.innerHTML=`<h3>Найдите ответ в регламенте</h3><p class="demo-description">Поиск по словам в четырёх учебных документах. Результат содержит выдержку и источник; сведения за пределами базы не добавляются.</p><div class="suggestions"><button type="button" data-query="Сколько составляет предоплата?">Предоплата</button><button type="button" data-query="Кто согласует скидку более 10%?">Скидка</button><button type="button" data-query="Когда отгрузка после оплаты?">Отгрузка</button><button type="button" data-query="Какая гарантия на кабель?">Вопрос вне базы</button></div><div class="demo-inputs"><label>Ваш вопрос<input id="knowledge-query" maxlength="300" value="Кто согласует скидку более 10%?"></label></div><div class="demo-actions"><button type="button" class="button small" id="run-demo">Найти в документах ↗</button><button type="button" class="text-link" id="reset-demo">Вернуть пример</button></div>${resultWrap()}<p class="demo-note">Учебные регламенты описывают вымышленную компанию. В демо нет семантического поиска и генерации ответа моделью. Будущее внедрение требует отдельной проверки прав доступа, качества поиска и отказов.</p>`;
 $('#run-demo').addEventListener('click',runDemo);
 $('#reset-demo').addEventListener('click',()=>renderDemo(activeDemo));
 panel.querySelectorAll('[data-query]').forEach(b=>b.addEventListener('click',()=>{$('#knowledge-query').value=b.dataset.query;runDemo();}));
 if(id==='knowledge')$('#knowledge-query').addEventListener('keydown',ev=>{if(ev.key==='Enter'){ev.preventDefault();runDemo();}});
 runDemo();
 if(scroll)$('#demo').scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
}
function runDemo() {
 const target=$('#demo-result');
 try {
   if(activeDemo==='quote'){
     const r=makeQuote($('#quote-input').value);currentQuote=r;
     if(!r.rows.length){target.innerHTML='<div class="empty-state">Добавьте хотя бы одну строку спецификации.</div>';return;}
     const rows=r.rows.map(x=>`<tr class="${x.problem?'unresolved':''}"><td>${x.row}</td><td>${e(x.sku)}</td><td class="numeric">${e(x.rawQty)} ${e(x.unit)}</td><td class="numeric">${money(x.price)}</td><td class="numeric">${money(x.amount)}</td><td class="status ${x.problem?'status-warn':'status-ok'}">${e(x.status)}</td></tr>`).join('');
     target.innerHTML=`<div class="result-heading"><span>Черновик КП</span><span>${r.rows.length} строк · ${r.unresolved} на проверку</span></div>${table(['№','Артикул','Количество','Цена','Сумма','Статус'],rows)}<div class="result-summary"><p>${r.unresolved?'Сумма только найденных позиций. КП неполное.':'Сумма всех позиций по учебному прайсу.'}<br>Цены без НДС. Сотрудник проверяет черновик.</p><strong>${money(r.total)}</strong></div><button type="button" class="text-link" id="quote-download">Скачать черновик CSV ↓</button>`;
     $('#quote-download').addEventListener('click',()=>download('BusinessGrade_Quote_Draft.csv',quoteCsv(currentQuote),'text/csv;charset=utf-8'));
   }
   if(activeDemo==='inbox'){
     const text=$('#inbox-input').value;if(!text.trim()){target.innerHTML='<div class="empty-state">Добавьте текст заявки.</div>';return;}
     const r=parseRequest(text);
     const cells=[['Компания',r.company],['Email',r.email],['Срок',r.deadline],['Направление',r.department]].map(([l,v])=>`<div class="request-field"><small>${e(l)}</small>${e(v||'Уточнить')}</div>`).join('');
     target.innerHTML=`<div class="result-heading"><span>Черновик карточки обращения</span><span>Проверить перед записью в CRM</span></div><div class="request-grid">${cells}</div><p class="demo-note">${r.missing.length?'Не хватает: '+e(r.missing.join(', '))+'.':'Основные поля извлечены. Смысл заявки и срок проверяет сотрудник.'}</p><div class="draft-box"><b>Следующий шаг</b><br>${r.missing.length?'Запросить недостающие данные и уточнить тип обращения.':`Передать заявку в направление «${e(r.department)}» и назначить ответственного. Товар, наличие и обещанный срок подтвердить отдельно.`}</div>`;
   }
   if(activeDemo==='prices'){
     const r=comparePrices($('#price-a').value,$('#price-b').value);
     if(!r.length){target.innerHTML='<div class="empty-state">Добавьте строки хотя бы в один прайс.</div>';return;}
     const rows=r.map(x=>`<tr class="${x.problem?'unresolved':''}"><td>${e(x.sku)}<br><small>${e(x.unit)}</small></td><td class="numeric">${money(x.a)}</td><td class="numeric">${money(x.b)}</td><td class="numeric">${x.delta===null?'-':money(x.delta)}</td><td class="status ${x.problem?'status-warn':'status-ok'}">${e(x.message)}</td></tr>`).join('');
     target.innerHTML=`<div class="result-heading"><span>Сравнение цены единицы</span><span>Разница: Б минус А</span></div>${table(['Артикул / ед.','Поставщик А','Поставщик Б','Разница','Результат'],rows)}<p class="demo-note">Сопоставимых строк: ${r.filter(x=>!x.problem).length}. На проверку: ${r.filter(x=>x.problem).length}. Для закупки дополнительно нужны наличие и условия поставки.</p>`;
   }
   if(activeDemo==='invoice'){
     const text=$('#invoice-input').value;if(!text.trim()){target.innerHTML='<div class="empty-state">Добавьте текст счёта.</div>';return;}
     const r=parseInvoice(text);
     const rows=r.rows.map(x=>`<tr class="${x.problem?'unresolved':''}"><td>${e(x.sku)}</td><td class="numeric">${Number.isFinite(x.qty)?decimal(x.qty):'Уточнить'}</td><td class="numeric">${money(x.unitPrice)}</td><td class="numeric">${money(x.declared)}</td><td class="numeric">${money(x.expected)}</td><td class="status ${x.problem?'status-warn':'status-ok'}">${x.problem?'Проверить':'Совпадает'}</td></tr>`).join('');
     target.innerHTML=`<div class="result-heading"><span>${e(r.supplier||'Поставщик не указан')}</span><span>Учебный документ</span></div>${r.rows.length?table(['Артикул','Кол-во','Цена','В счёте','Расчёт','Статус'],rows):'<div class="empty-state">Строки формата артикул;количество;цена;сумма не найдены.</div>'}<ul class="check-list"><li class="${r.innOk?'status-ok':'status-warn'}">ИНН: ${r.innOk?'контрольная цифра совпадает':'нужно проверить'}</li><li class="${r.totalOk?'status-ok':'status-warn'}">Итог: ${r.totalOk?'совпадает':'есть расхождение или не хватает данных'}</li></ul><div class="result-summary"><p>Расчёт по ${r.rows.length} строкам<br>В документе: ${money(r.declaredTotal)}</p><strong>${money(r.sum)}</strong></div>${r.declaredTotal!==null&&r.rows.length?`<p class="demo-note">Разница между указанным итогом и расчётом: ${money(r.declaredTotal-r.sum)}.</p>`:''}`;
   }
   if(activeDemo==='knowledge'){
     const r=searchKnowledge($('#knowledge-query').value);
     target.innerHTML=r.length?`<div class="result-heading"><span>Выдержки из учебной базы</span><span>Поиск по словам</span></div>`+r.map(x=>`<article class="source-answer"><h4>${e(x.id)} / ${e(x.title)}</h4><blockquote>${e(x.text)}</blockquote><small>Источник: ${e(x.version)}. Сведения приведены целиком из найденного документа.</small></article>`).join(''):'<div class="empty-state"><b>Подходящий источник не найден.</b><br>В учебной базе нет ответа. Уточните вопрос или передайте его ответственному сотруднику.</div>';
   }
 } catch(err){target.innerHTML=`<div class="error">${e(err.message)}</div>`;}
}
function updateRoi() {
 const f=$('#roi-form'),out=$('#roi-result');
 if(!f.checkValidity()){out.innerHTML='<p class="error">Заполните поля неотрицательными числами в указанных границах.</p>';return;}
 try {
   const vals=Object.fromEntries([...new FormData(f)].map(([k,v])=>[k,Number(v)])),r=estimateValue(vals);
   out.innerHTML=`<div class="value-stats"><div class="value-stat"><span>Высвобождается в месяц</span><b>${decimal(r.hours)} ч</b></div><div class="value-stat"><span>Стоимость этого времени</span><b>${rub(r.timeValue)}</b></div></div><p class="payback">Денежный эффект после регулярных расходов: <b>${rub(r.cashValue)}/мес.</b><br>${r.payback!==null?`Оценка окупаемости: <b>${decimal(r.payback)} мес.</b> при подтверждённой доле денежного эффекта.`:'Денежная окупаемость при этих допущениях не достигается.'}</p>`;
 }catch(err){out.innerHTML=`<p class="error">${e(err.message)}</p>`;}
}
function selectBrief(id) {
 if($('#detail-dialog').open)$('#detail-dialog').close();
 $('#brief-solution').value=id;
 $('#brief').scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
}
document.addEventListener('click',ev=>{
 const detail=ev.target.closest('[data-detail]'),demo=ev.target.closest('[data-demo]'),filter=ev.target.closest('[data-filter]'),brief=ev.target.closest('[data-brief]');
 if(detail)openDetail(detail.dataset.detail);
 else if(demo){ev.preventDefault();renderDemo(demo.dataset.demo,!demo.classList.contains('demo-tab'));}
 else if(filter)renderCatalog(filter.dataset.filter);
 else if(brief)selectBrief(brief.dataset.brief);
});
document.querySelectorAll('.demo-tab').forEach((b,i,all)=>b.addEventListener('keydown',ev=>{
 let index=null;
 if(ev.key==='ArrowRight'||ev.key==='ArrowDown')index=(i+1)%all.length;
 if(ev.key==='ArrowLeft'||ev.key==='ArrowUp')index=(i-1+all.length)%all.length;
 if(ev.key==='Home')index=0;if(ev.key==='End')index=all.length-1;
 if(index!==null){ev.preventDefault();renderDemo(all[index].dataset.demo);all[index].focus();}
}));
$('.dialog-close').addEventListener('click',()=>$('#detail-dialog').close());
$('#detail-dialog').addEventListener('click',ev=>{if(ev.target===$('#detail-dialog')){const r=ev.target.getBoundingClientRect();if(ev.clientX<r.left||ev.clientX>r.right||ev.clientY<r.top||ev.clientY>r.bottom)ev.target.close();}});
$('#sources-toggle').addEventListener('click',()=>{const expanded=$('#sources').hidden;$('#sources').hidden=!expanded;$('#sources-toggle').setAttribute('aria-expanded',expanded);});
$('#roi-form').addEventListener('input',updateRoi);
$('#brief-solution').innerHTML=catalog.map(x=>`<option value="${x.id}">${e(x.title)}</option>`).join('');
$('#brief-form').addEventListener('submit',ev=>{
 ev.preventDefault();const data=new FormData(ev.target),x=catalog.find(x=>x.id===data.get('solution'));
 $('#brief-text').value=`Бриф для пилота | Обновление бизнеса / BusinessGrade\n\nСценарий: ${x.title}\nЧто делается вручную: ${data.get('task')}\nОбъём: ${data.get('frequency')} операций в месяц\nСистемы: ${data.get('systems')||'Уточнить'}\nОжидаемый результат: ${data.get('result')}\n\nПредлагаем проверить: ${x.metric}\nДо старта согласовать: примеры входных данных, формат результата, исключения, доступы и критерии приёмки.\n\nЭтот бриф подготовлен в демонстрационном прототипе. Отправка заявки не выполнялась.`;
 $('#brief-output').hidden=false;$('#brief-message').textContent='Бриф готов. Можно скопировать или скачать.';
});
$('#brief-copy').addEventListener('click',async()=>{
 try{await navigator.clipboard.writeText($('#brief-text').value);$('#brief-message').textContent='Бриф скопирован.';}
 catch{$('#brief-text').focus();$('#brief-text').select();$('#brief-message').textContent='Браузер не разрешил копирование. Текст выделен; скопируйте его вручную.';}
});
$('#brief-download').addEventListener('click',()=>download('BusinessGrade_Pilot_Brief.txt',$('#brief-text').value));
renderCatalog();renderDemo('quote');updateRoi();
