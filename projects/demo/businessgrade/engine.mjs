export const catalog = [
  {id:'quote', title:'КП из спецификации', category:'Продажи', stage:1, demo:'quote', price:'24 900 ₽', effort:'12–16 часов', buyer:'Оптовые поставщики и дистрибьюторы', input:'Спецификация XLSX/CSV и согласованный прайс.', output:'Черновик КП с ценами, итогом и списком позиций для проверки.', metric:'Полное время подготовки одного КП и число ошибок.', scope:'Один формат входного файла, один прайс и один шаблон КП. До 200 строк. Менеджер подтверждает результат.', alternative:'Сначала проверяем импорт товаров и генератор документов в действующей CRM, 1С и готовые модули.', note:'Цена первых двух ограниченных пилотов. Стандартное внедрение после проверки гипотезы: 39 900 ₽.'},
  {id:'inbox', title:'Заявка в карточку клиента', category:'Продажи', stage:1, demo:'inbox', price:'20–45 тыс. ₽', effort:'8–16 часов', buyer:'Компании с потоком входящих писем и обращений', input:'Входящее письмо или форма обращения.', output:'Контакт, тема, срок и черновик карточки с недостающими данными.', metric:'Время до регистрации заявки; доля пропущенных обращений.', scope:'Один канал входа и один согласованный набор полей. Запись в CRM подключается после проверки на копии данных.', alternative:'Битрикс24 уже поддерживает почту в CRM. Продаём настройку или дополнительную обработку полей, если базовой функции недостаточно.', note:'Ориентир нашего будущего пилота; лицензия CRM и сложные интеграции считаются отдельно.'},
  {id:'prices', title:'Сверка прайсов поставщиков', category:'Закупки', stage:1, demo:'prices', price:'25–45 тыс. ₽', effort:'10–18 часов', buyer:'Закупщики, оптовики и снабжение', input:'Два или три прайса с точными артикулами и единицами.', output:'Сравнение цен, изменения и строки, которые требуют уточнения.', metric:'Время сравнения и подтверждённая разница закупочной стоимости.', scope:'Одна товарная группа, до трёх форматов прайса. Не подбираем технические аналоги автоматически.', alternative:'Сначала проверяем существующие функции импорта и сравнения в учётной системе.', note:'Стоимость и наличие сравниваем на одну дату; доставку, НДС и условия оплаты согласуем отдельно.'},
  {id:'invoice', title:'Документ в таблицу', category:'Документы', stage:2, demo:'invoice', price:'35–65 тыс. ₽', effort:'14–26 часов', buyer:'Бухгалтерия, снабжение и офис', input:'Счёт, акт или накладная одного согласованного типа.', output:'Реквизиты и строки в таблице, проверка сумм и журнал исключений.', metric:'Время переноса документа и точность критических полей.', scope:'Один тип документа. Сначала текстовый PDF; OCR для сканов оценивается дополнительно. Человек подтверждает реквизиты.', alternative:'Проверяем ЭДО, импорт в 1С и готовое распознавание документов. Yandex Vision OCR может быть частью решения.', note:'В демо разбирается текст по правилам. Загрузка PDF и распознавание изображений в прототипе не подключены.'},
  {id:'knowledge', title:'Поиск по базе знаний', category:'Команда', stage:2, demo:'knowledge', price:'50–100 тыс. ₽', effort:'25–45 часов', buyer:'Отделы поддержки, продаж и внутренние службы', input:'Утверждённые регламенты, инструкции и FAQ.', output:'Ответ или выдержка с ссылкой на документ; отказ, если источник не найден.', metric:'Время поиска и доля верных ответов на контрольных вопросах.', scope:'Одна команда и одна база. Права доступа, версия документов и список запрещённых ответов входят в проект.', alternative:'Проверяем Алису Про, встроенный поиск и возможности действующей корпоративной платформы.', note:'В демо работает поиск по словам по четырём учебным документам. LLM и реальные документы не подключены.'},
  {id:'reports', title:'Отчёт руководителю', category:'Управление', stage:2, demo:null, price:'30–60 тыс. ₽', effort:'12–24 часа', buyer:'Руководители отделов и собственники', input:'Регулярные выгрузки продаж, оплат и заявок.', output:'Единый отчёт с согласованными показателями и проверкой полноты данных.', metric:'Часы подготовки отчёта и совпадение цифр с учётной системой.', scope:'До трёх выгрузок и 5–7 показателей. Сначала согласуем определения выручки, маржи и периода.', alternative:'Предпочтительно настроить DataLens или отчёты действующей CRM, если этого достаточно.', note:'DataLens поддерживает файловые источники CSV и XLSX. Генерацию свободных выводов подключаем только после проверки расчётов.'},
  {id:'followup', title:'Контроль следующих действий', category:'Продажи', stage:2, demo:null, price:'15–35 тыс. ₽', effort:'6–14 часов', buyer:'Небольшие отделы продаж', input:'Сделки, согласованные сроки и статусы.', output:'Задачи менеджеру и черновики напоминаний по выбранным событиям.', metric:'Просроченные действия и время реакции менеджера.', scope:'Одна воронка и до пяти правил. Внешние сообщения отправляет сотрудник после проверки.', alternative:'Сначала используем роботов и задачи существующей CRM.', note:'Не обещаем рост выручки без измерения на сопоставимом периоде.'},
  {id:'productdata', title:'Порядок в товарном каталоге', category:'Закупки', stage:2, demo:null, price:'20–40 тыс. ₽', effort:'8–16 часов', buyer:'Поставщики и магазины с каталогом товаров', input:'Таблица с артикулами, названиями, единицами и атрибутами.', output:'Единый формат, дубли и поля для проверки.', metric:'Доля заполненных полей и сокращение ручных исправлений.', scope:'Один каталог до 1 000 строк и согласованные правила. Дубли не объединяем без подтверждения.', alternative:'Проверяем штатный импорт и массовое редактирование товарной системы.', note:'Технические характеристики берём из утверждённых источников, а не придумываем моделью.'},
  {id:'reconcile', title:'Сверка заказа и счёта', category:'Документы', stage:2, demo:null, price:'30–60 тыс. ₽', effort:'12–24 часа', buyer:'Снабжение, бухгалтерия и продажи', input:'Заказ и счёт с совпадающими идентификаторами.', output:'Различия в артикулах, количестве, единицах и цене.', metric:'Время сверки и найденные подтверждённые расхождения.', scope:'Два согласованных формата, одна валюта и правила округления. Результат подтверждает ответственный.', alternative:'Проверяем возможности учётной системы и ЭДО.', note:'Проверка расхождений не заменяет бухгалтерскую и юридическую экспертизу.'},
  {id:'meetings', title:'Встреча в протокол и задачи', category:'Команда', stage:2, demo:null, price:'15–35 тыс. ₽', effort:'6–12 часов', buyer:'Проектные команды и руководители', input:'Разрешённая запись или расшифровка встречи.', output:'Черновик решений, задач, сроков и ответственных.', metric:'Время подготовки протокола и точность задач.', scope:'Одна платформа встреч и один шаблон протокола. Участник проверяет выводы до публикации.', alternative:'Телемост и другие платформы уже создают конспекты. Начинаем с настройки и передачи подтверждённых задач.', note:'Проверяем условия записи разговоров и доступ к материалам.'},
  {id:'calls', title:'Разбор звонков продаж', category:'Продажи', stage:3, demo:null, price:'25–60 тыс. ₽', effort:'10–24 часа', buyer:'Отделы продаж с регулярными звонками', input:'Записи разрешённых звонков и критерии качества.', output:'Расшифровка, резюме и замечания по утверждённому скрипту.', metric:'Время проверки и совпадение оценок с ручным аудитом.', scope:'Один скрипт и 20 контрольных звонков. Отдельно проверяем телефонию и точность распознавания.', alternative:'В первую очередь проверяем CoPilot в Битрикс24: расшифровка, резюме, поля CRM и речевая аналитика уже есть.', note:'Не используем автоматическую оценку как единственное основание кадровых решений.'},
  {id:'support', title:'Помощник оператора поддержки', category:'Команда', stage:3, demo:null, price:'45–90 тыс. ₽', effort:'20–40 часов', buyer:'Поддержка со значимым потоком повторных вопросов', input:'Обращение клиента и утверждённая база ответов.', output:'Предложенный ответ, источник и передача сложного вопроса специалисту.', metric:'Время подготовки ответа и ошибки на тестовых обращениях.', scope:'Одна очередь и ограниченный набор тем. Оператор подтверждает ответ и решает исключения.', alternative:'Проверяем готовые помощники контакт-центра и встроенные функции helpdesk.', note:'Самостоятельный голосовой продавец с широкими полномочиями не входит в первый каталог внедрений.'}
];

export const priceBook = [
  {sku:'CAB-315',name:'Кабель ВВГнг-LS 3×1,5',unit:'м',cents:8250,aliases:['ВВГнг-LS 3х1.5']},
  {sku:'CAB-325',name:'Кабель ВВГнг-LS 3×2,5',unit:'м',cents:13500,aliases:[]},
  {sku:'AUTO-16',name:'Автоматический выключатель 16 A',unit:'шт',cents:24500,aliases:[]}
];
export const examples = {
 quote:'CAB-315;100;м\nCAB-325;250;м\nAUTO-16;8;шт\nUNKNOWN-01;5;шт',
 inbox:'Компания: ООО «Учебный заказчик»\nКонтакт: demo@example.com\nЗапрос: Пришлите КП на кабель CAB-315, 100 метров.\nСрок: 20.10.2026\nКомментарий: Нужна доставка в Москву.',
 priceA:'CAB-315;82,50;м\nCAB-325;135,00;м\nAUTO-16;245,00;шт',
 priceB:'CAB-315;79,90;м\nCAB-325;145,00;м\nAUTO-16;0;шт\nCAB-410;190,00;м',
 invoice:'Счёт № 103 от 13.10.2026\nПоставщик: ООО «Учебный поставщик»\nИНН: 7700000009\n\nАртикул;Количество;Цена;Сумма\nCAB-315;100;82,50;8250,00\nAUTO-16;8;245,00;1960,00\n\nИтого: 11200,00'
};
export const knowledge = [
 {id:'REG-01',title:'Условия оплаты',version:'Учебная редакция 1',text:'Для стандартного заказа предоплата составляет 50%. Оставшиеся 50% оплачиваются до отгрузки. Индивидуальная отсрочка согласуется с коммерческим директором.'},
 {id:'REG-02',title:'Отгрузка со склада',version:'Учебная редакция 1',text:'Отгрузка складского товара выполняется в течение двух рабочих дней после полной оплаты. Срок доставки подтверждается отдельно при согласовании перевозчика.'},
 {id:'REG-03',title:'Согласование скидки',version:'Учебная редакция 1',text:'Скидку более 10% согласует коммерческий директор. Менеджер прикладывает расчёт маржи и описание заказа. Согласование фиксируется в карточке сделки.'},
 {id:'REG-04',title:'Возврат товара',version:'Учебная редакция 1',text:'Запрос на возврат передаётся специалисту по качеству вместе с номером заказа и описанием причины. Условия возврата проверяются по договору. Ответ клиенту подтверждает ответственный специалист.'}
];

const norm=x=>String(x??'').trim().toUpperCase();
export function numberValue(x) {
 const t=String(x??'').replace(/[\s\u00a0]/g,'').replace(',','.');
 return /^\d+(?:\.\d{1,3})?$/.test(t) ? Number(t) : NaN;
}
const cents=x=>{const n=Math.round(numberValue(x)*100);return Number.isSafeInteger(n)&&n<=10000000000000?n:NaN;};
export function lines(text,max=200) {
 const r=String(text).split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
 if(r.length>max) throw new Error(`В демо можно обработать до ${max} строк.`);
 return r;
}
export function makeQuote(text) {
 let total=0;
 const rows=lines(text).map((raw,i)=>{
   const parts=raw.split(';').map(x=>x.trim()), [sku,rawQty,unit]=parts;
   const item=priceBook.find(x=>norm(x.sku)===norm(sku)||x.aliases.some(a=>norm(a)===norm(sku)));
   const qty=numberValue(rawQty);
   let status='Подтверждено по учебному прайсу',problem=false;
   if(parts.length!==3){status='Нужно три поля: артикул;количество;единица';problem=true;}
   else if(!Number.isFinite(qty)||qty<=0||qty>100000000){status='Проверить количество';problem=true;}
   else if(!item){status='Артикул отсутствует в прайсе';problem=true;}
   else if(unit!==item.unit){status='Проверить единицу измерения';problem=true;}
   else if(unit==='шт'&&!Number.isInteger(qty)){status='Для штук требуется целое количество';problem=true;}
   const amount=problem?null:Math.round(item.cents*qty);
   if(amount!==null) total+=amount;
   return {row:i+1,sku,rawQty,qty,unit,name:item?.name??'Не определено',price:problem?null:item.cents,amount,status,problem,raw};
 });
 return {rows,total,unresolved:rows.filter(x=>x.problem).length};
}
export function parseRequest(text) {
 if(text.trim().length>12000) throw new Error('Сократите текст до 12 000 знаков.');
 const field=label=>text.match(new RegExp('^'+label+'\\s*:\\s*(.+)$','mi'))?.[1]?.trim()??'';
 const email=text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i)?.[0]??'';
 const company=field('Компания'),request=field('Запрос')||text.trim();
 const deadline=field('Срок')||text.match(/\b\d{2}\.\d{2}\.\d{4}\b/)?.[0]||'';
 const lower=request.toLowerCase();
 const department=/не\s*работает|неисправ|поддержк|ошибк/.test(lower)?'Поддержка':/оплат|плат[её]ж|акт сверки/.test(lower)?'Бухгалтерия':/куп|заказ|(?:^|[^а-яa-z])кп(?:[^а-яa-z]|$)|кабел|прайс|цена/.test(lower)?'Продажи':'Уточнить вручную';
 const missing=[];
 if(!company)missing.push('название компании');
 if(!email)missing.push('контактный email');
 if(!deadline)missing.push('нужный срок');
 if(department==='Уточнить вручную')missing.push('тип обращения');
 return {company,email,deadline,request,department,missing};
}
export function comparePrices(a,b) {
 const parse=text=>{
   const map=new Map();
   for(const raw of lines(text)){
     const p=raw.split(';').map(x=>x.trim());
     const [sku,price,unit]=p,key=norm(sku);
     const prev=map.get(key),item={sku,price:cents(price),unit,invalid:p.length!==3||!sku||!unit||!Number.isFinite(cents(price))||cents(price)<=0};
     if(prev)item.duplicate=true;
     map.set(key,item);
   }
   return map;
 };
 const am=parse(a),bm=parse(b);
 return [...new Set([...am.keys(),...bm.keys()])].map(key=>{
   const x=am.get(key),y=bm.get(key);let message='',delta=null,winner=null;
   if(x?.duplicate||y?.duplicate)message='Повторный артикул: проверить строки';
   else if(x?.invalid||y?.invalid)message='Проверить цену или формат';
   else if(!x||!y)message='Есть только у одного поставщика';
   else if(x.unit!==y.unit)message='Единицы различаются';
   else {delta=y.price-x.price;winner=delta<0?'Б':delta>0?'А':'Одинаково';message=delta===0?'Цены одинаковые':`Ниже цена у ${winner}`;}
   return {sku:x?.sku||y.sku,a:x?.price??null,b:y?.price??null,unit:x?.unit||y?.unit||'',delta,winner,message,problem:winner===null};
 });
}
export function validInn(inn) {
 if(!/^(?:\d{10}|\d{12})$/.test(inn)||/^0+$/.test(inn))return false;
 const check=weights=>weights.reduce((n,w,i)=>n+w*Number(inn[i]),0)%11%10;
 if(inn.length===10)return check([2,4,10,3,5,9,4,6,8])===Number(inn[9]);
 return check([7,2,4,10,3,5,9,4,6,8])===Number(inn[10])&&check([3,7,2,4,10,3,5,9,4,6,8])===Number(inn[11]);
}
export function parseInvoice(text) {
 const raw=lines(text),rows=[];let sum=0;
 for(const line of raw){
   if(!line.includes(';')||/^Артикул;/i.test(line))continue;
   const p=line.split(';').map(x=>x.trim()),[sku,quantity,price,amount]=p;
   const qty=numberValue(quantity),unitPrice=cents(price),declared=cents(amount);
   const invalid=p.length!==4||!sku||!Number.isFinite(qty)||qty<=0||qty>100000000||!Number.isFinite(unitPrice)||unitPrice<=0||!Number.isFinite(declared)||!Number.isSafeInteger(Math.round(qty*unitPrice));
   const expected=invalid?null:Math.round(qty*unitPrice);
   if(expected!==null)sum+=expected;
   rows.push({sku,qty,unitPrice,declared,expected,problem:invalid||expected!==declared});
 }
 const declaredText=text.match(/^Итого\s*:\s*([\d\s,.]+)/mi)?.[1];
 const declaredTotal=declaredText?cents(declaredText):null;
 const inn=text.match(/^ИНН\s*:\s*(\d+)/mi)?.[1]??'';
 const supplier=text.match(/^Поставщик\s*:\s*(.+)/mi)?.[1]??'';
 return {rows,sum,declaredTotal,totalOk:rows.length>0&&rows.every(x=>!x.problem)&&declaredTotal===sum,inn,innOk:validInn(inn),supplier};
}
const stop=new Set('как какой какая сколько где по в на для и ли из от до с что когда у это более нужно составляет'.split(' '));
export function searchKnowledge(query) {
 const terms=(query.toLowerCase().replaceAll('ё','е').match(/[а-яa-z0-9]+/g)||[]).filter(x=>x.length>2&&!stop.has(x));
 if(!terms.length)return [];
 return knowledge.map(doc=>{
   const text=(doc.title+' '+doc.text).toLowerCase().replaceAll('ё','е');
   const score=terms.reduce((s,t)=>s+(text.includes(t)?1:0),0);
   return {...doc,score};
 }).filter(x=>x.score>0).sort((a,b)=>b.score-a.score).slice(0,2);
}
export function estimateValue({volume,before,after,hourly,setup,monthly,cashShare}) {
 const v=[volume,before,after,hourly,setup,monthly,cashShare];
 if(v.some(x=>!Number.isFinite(Number(x))||Number(x)<0)||cashShare>100)throw new Error('Введите неотрицательные значения. Доля денежного эффекта: от 0 до 100%.');
 const hours=Math.max(0,before-after)*volume/60,timeValue=hours*hourly;
 const cashValue=timeValue*cashShare/100-monthly;
 return {hours,timeValue,cashValue,payback:cashValue>0?setup/cashValue:null};
}
export function csvCell(x) {
 let s=x==null?'':String(x);
 if(/^[=+\-@\t\r]/.test(s))s="'"+s;
 return '"'+s.replaceAll('"','""')+'"';
}
export function quoteCsv(result) {
 const rows=[['Строка','Артикул','Количество','Единица','Цена без НДС','Сумма без НДС','Статус'],...result.rows.map(x=>[x.row,x.sku,x.rawQty,x.unit,x.price==null?'':(x.price/100).toFixed(2),x.amount==null?'':(x.amount/100).toFixed(2),x.status])];
 return '\uFEFF'+rows.map(x=>x.map(csvCell).join(';')).join('\r\n');
}
