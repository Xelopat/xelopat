import{f as k,r as i,j as t}from"./index-BbRcCN7A.js";import{I as z}from"./Icon-4514FPnY.js";const R="data:image/svg+xml,%3csvg%20xmlns='http://www.w3.org/2000/svg'%20width='14'%20height='18'%20viewBox='0%200%2014%2018'%20fill='none'%3e%3cpath%20d='M6.99967%209.00008C7.45801%209.00008%207.85037%208.83689%208.17676%208.5105C8.50315%208.18411%208.66634%207.79175%208.66634%207.33341C8.66634%206.87508%208.50315%206.48272%208.17676%206.15633C7.85037%205.82994%207.45801%205.66675%206.99967%205.66675C6.54134%205.66675%206.14898%205.82994%205.82259%206.15633C5.4962%206.48272%205.33301%206.87508%205.33301%207.33341C5.33301%207.79175%205.4962%208.18411%205.82259%208.5105C6.14898%208.83689%206.54134%209.00008%206.99967%209.00008ZM6.99967%2015.1251C8.69412%2013.5695%209.95106%2012.1563%2010.7705%2010.8855C11.59%209.61466%2011.9997%208.48619%2011.9997%207.50008C11.9997%205.98619%2011.517%204.74661%2010.5518%203.78133C9.58648%202.81605%208.40245%202.33341%206.99967%202.33341C5.5969%202.33341%204.41287%202.81605%203.44759%203.78133C2.48231%204.74661%201.99967%205.98619%201.99967%207.50008C1.99967%208.48619%202.4094%209.61466%203.22884%2010.8855C4.04829%2012.1563%205.30523%2013.5695%206.99967%2015.1251ZM6.99967%2017.3334C4.76356%2015.4306%203.09342%2013.6633%201.98926%2012.0313C0.885091%2010.3994%200.333008%208.88897%200.333008%207.50008C0.333008%205.41675%201.00315%203.75703%202.34342%202.52091C3.6837%201.2848%205.23579%200.666748%206.99967%200.666748C8.76356%200.666748%2010.3156%201.2848%2011.6559%202.52091C12.9962%203.75703%2013.6663%205.41675%2013.6663%207.50008C13.6663%208.88897%2013.1143%2010.3994%2012.0101%2012.0313C10.9059%2013.6633%209.23579%2015.4306%206.99967%2017.3334Z'%20fill='white'/%3e%3c/svg%3e";function E({value:s,onChange:p,style:g={},placeholder:b="Город"}){const x=k(),[r,a]=i.useState(s?.name||""),[c,l]=i.useState([]),[u,o]=i.useState(!1),[y,f]=i.useState(!1),d=i.useRef(null),m=i.useRef(null);i.useEffect(()=>{a(s?.name||"")},[s?.id]);const h=i.useCallback(async e=>{f(!0);try{const j=await(await x("/cities?q="+encodeURIComponent(e))).json();l(j?.results||[])}catch{l([])}finally{f(!1)}},[x]),w=e=>{const n=e.target.value;a(n),s&&p(null),clearTimeout(m.current),n.trim().length>=1?m.current=setTimeout(()=>{h(n.trim()),o(!0)},300):(l([]),o(!1))},C=()=>{r.trim().length>=1&&!s&&(h(r.trim()),o(!0))},v=e=>{p({id:e.id,name:e.name}),a(e.name),o(!1)};return i.useEffect(()=>{const e=n=>{d.current&&!d.current.contains(n.target)&&o(!1)};return document.addEventListener("mousedown",e),()=>document.removeEventListener("mousedown",e)},[]),t.jsxs("div",{ref:d,className:"city-select-root",style:{position:"relative",width:"100%",...g},children:[t.jsx("style",{children:`
        .city-select-input-wrap {
          display: flex;
          height: 32px;
          align-items: center;
          gap: 10px;
          border: 1px solid rgba(255, 255, 255, 0.1);
          border-radius: 15px;
          padding: 0 20px 0 0;
          background: rgba(255, 255, 255, 0.05);
        }
        @media (min-width: 360px) {
          .city-select-input-wrap { height: 40px; }
        }
        @media (min-width: 768px) {
          .city-select-input-wrap { height: 50px; }
        }
        .city-select-icon-box {
          display: flex;
          width: 40px;
          padding: 2px;
          justify-content: center;
          align-items: center;
          flex-shrink: 0;
          align-self: stretch;
          border-radius: 15px 0 0 15px;
          border: 1px solid rgba(255, 255, 255, 0.1);
          background: rgba(255, 255, 255, 0.1);
          box-sizing: border-box;
          margin: -1px;
        }
        @media (min-width: 768px) {
          .city-select-icon-box { width: 50px; }
        }
        .city-select-field {
          flex: 1;
          height: 100%;
          border: none;
          outline: none;
          background: transparent;
          box-sizing: border-box;
          font-family: Roboto, system-ui, sans-serif;
          font-size: 12px;
          font-weight: 400;
          line-height: normal;
          color: #FFF;
          appearance: none;
        }
        @media (min-width: 360px) {
          .city-select-field { font-size: 14px; }
        }
        @media (min-width: 768px) {
          .city-select-field { font-size: 16px; }
        }
        .city-select-field::placeholder {
          color: rgba(255, 255, 255, 0.30);
        }
        .city-select-dropdown {
          position: absolute;
          top: 100%;
          left: 0;
          right: 0;
          z-index: 100;
          margin-top: 4px;
          max-height: 200px;
          overflow-y: auto;
          border-radius: 12px;
          border: 1px solid rgba(255, 255, 255, 0.1);
          background: #1a1a1a;
        }
        .city-select-option {
          padding: 10px 16px;
          color: #fff;
          font-family: Roboto, system-ui, sans-serif;
          font-size: 14px;
          cursor: pointer;
          border: none;
          background: none;
          width: 100%;
          text-align: left;
          display: block;
        }
        .city-select-option:hover {
          background: rgba(141, 60, 255, 0.2);
        }
      `}),t.jsxs("div",{className:"city-select-input-wrap",children:[t.jsx("div",{className:"city-select-icon-box",children:t.jsx(z,{source:R,width:17,height:17})}),t.jsx("input",{className:"city-select-field",placeholder:b,value:r,onChange:w,onFocus:C,autoComplete:"off"})]}),u&&c.length>0&&t.jsx("div",{className:"city-select-dropdown",children:c.map(e=>t.jsx("button",{className:"city-select-option",type:"button",onMouseDown:n=>n.preventDefault(),onClick:()=>v(e),children:e.name},e.id))}),u&&!y&&c.length===0&&r.trim().length>=1&&!s&&t.jsx("div",{className:"city-select-dropdown",children:t.jsx("div",{style:{padding:"10px 16px",color:"rgba(255,255,255,0.4)",fontSize:14},children:"Ничего не найдено"})})]})}export{R as A,E as C};
