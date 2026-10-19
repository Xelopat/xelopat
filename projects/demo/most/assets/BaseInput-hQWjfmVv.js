import{r as t,m as O,j as s}from"./index-BbRcCN7A.js";import{I as Q}from"./Icon-4514FPnY.js";const _=t.forwardRef(function({value:u,defaultValue:B="",onChange:p,placeholder:C="",leftIcon:c,iconSize:r,disabled:S=!1,inputMode:j,type:E="text",name:I,filterValue:R=i=>i,validateManual:F=()=>!0,width:a="100%",style:k={},doneUseStatic:f=!1},W){const i=u!=null,[D,N]=t.useState(String(B)),n=i?String(u):D,[x,b]=t.useState(!1),[l,g]=t.useState(null),[V,h]=t.useState(!1),[m,d]=t.useState(!1),w=O.useRef(null),[L,M]=t.useState(typeof window<"u"&&window.innerWidth>=768?17:14);t.useEffect(()=>{if(r!==void 0)return;const e=()=>{M(window.innerWidth>=768?17:14)};return window.addEventListener("resize",e),()=>window.removeEventListener("resize",e)},[r]);const v=r!==void 0?r:L,y=()=>{const e=F(n),o=e===!0?null:e;g(o);const K=o==null||o===!0;return h(!0),d(K),o};t.useImperativeHandle(W,()=>({getErrorCode:()=>l,setErrorCode:e=>g(e??null),validateNow:y,focus:()=>w.current?.focus(),getValue:()=>n,setDone:e=>{d(!!e),h(!!e)}}));const H=t.useMemo(()=>l!=null?"error":m?f?"static":"done":x||n&&String(n).length>0?"active":"static",[V,l,x,n,f,m]),z={static:"#242424",active:"#8D3CFF",done:"#69B742",error:"#C82727"}[H],$=typeof a=="number"?`${a}px`:a,q=e=>{const o=R(String(e??""),n);i||N(o),p&&p(o)},A=()=>b(!1),G=()=>{d(!1),b(!0)},J=()=>{try{y()}catch{}A()};return s.jsxs("div",{className:"base-input-root",style:{borderColor:z,width:$,...k},children:[s.jsx("style",{children:`
        .base-input-root {
          display: flex;
          height: 32px;
          padding-right: 20px;
          align-items: center;
          gap: 10px;
          border: 1px solid rgba(255, 255, 255, 0.1) !important;
          border-radius: 15px;
          padding: 0px 20px 0px 0px;
          background: rgba(255, 255, 255, 0.05);

        }
        @media (min-width: 360px) {
          .base-input-root { height: 40px; }
        }
        @media (min-width: 768px) {
          .base-input-root { height: 50px; }
        }

        .base-input-icon-box {
          display: flex;
          width: 40px;
          padding: 2px;
          justify-content: center;
          align-items: center;
          gap: 10px;
          flex-shrink: 0;
          align-self: stretch;
          border-radius: 15px 0 0 15px;
          fill: rgba(255, 255, 255, 0.1);
          border: 1px solid rgba(255, 255, 255, .1) !important;
          background: rgba(255, 255, 255, 0.1);

          box-sizing: border-box;
        }
        @media (min-width: 768px) {
          .base-input-icon-box { width: 50px; }
        }

        .base-input-field {
          flex: 1;
          height: 100%;
          border: none;
          outline: none;
          background: transparent;
          box-sizing: border-box;
          font-family: Roboto, system-ui, sans-serif;
          font-size: 12px;
          font-style: normal;
          font-weight: 400;
          line-height: normal;
          color: #FFF;
          appearance: none;
          -webkit-appearance: none;
          -moz-appearance: none;
        }
        @media (min-width: 360px) {
          .base-input-field { font-size: 14px; }
        }
        @media (min-width: 768px) {
          .base-input-field { font-size: 16px; }
        }
        .base-input-field::placeholder {
          color: rgba(255, 255, 255, 0.30);
        }
      `}),s.jsx("div",{className:"base-input-icon-box m-[-1px]",style:{borderColor:z},children:c&&s.jsx(Q,{source:c,width:v,height:v})}),s.jsx("input",{ref:w,className:"base-input-field",name:I,type:E,inputMode:j,placeholder:C,value:n,disabled:S,onChange:e=>q(e.target.value),onBlur:J,onFocus:G,autoComplete:"off"})]})});export{_ as B};
