"use strict";(()=>{var f=class extends Error{constructor(t,i){super(i);this.status=t}},I="The assistant is not reachable right now. Try again in a minute.",b=class{constructor(e){this.settings=e}async bootstrap(e=null){let t=e===null?this.settings.bootstrap:`${this.settings.bootstrap}${this.settings.bootstrap.includes("?")?"&":"?"}conversation=${encodeURIComponent(String(e))}`,i=await this.request("GET",t);return i.status===204?null:await this.read(i)}async send(e,t,i=null){let n={message:e,page:{url:location.href,title:document.title}};return t&&(n.setup_app=t),i!==null&&(n.conversation_id=i),await this.json("POST",this.settings.messages,n)}async upload(e){let t=new FormData;t.append("file",e,e.name);let i=await this.request("POST",this.settings.uploads,t);return await this.read(i)}async undo(e){return await this.json("POST",this.settings.undo.replace("{action}",String(e)),{})}async review(e,t,i){let n=this.settings.review.replace("{plan}",encodeURIComponent(e)).replace("{verb}",t);return await this.json("POST",n,i)}async clear(){return await this.json("DELETE",this.settings.conversation,{})}async json(e,t,i){let n=await this.request(e,t,JSON.stringify(i),"application/json");return this.read(n)}async request(e,t,i,n){let s={Accept:"application/json","X-Requested-With":"XMLHttpRequest"};this.settings.csrf&&(s["X-CSRF-TOKEN"]=this.settings.csrf),n&&(s["Content-Type"]=n);try{return await fetch(t,{method:e,headers:s,body:i,credentials:"same-origin"})}catch{throw new f(0,I)}}async read(e){let t=null;try{t=await e.json()}catch{t=null}if(e.status===204)return{};if(!e.ok){let i=t!=null?t:{},n=typeof i.error=="string"&&i.error||typeof i.message=="string"&&i.message||(e.status===419||e.status===401?"Your session has expired. Refresh the page and try again.":e.status>=500?I:"That did not go through. Try again.");throw new f(e.status,n)}return t!=null?t:{}}};function x(){let r=window.Intercom;return typeof r=="function"?r:null}function T(){return x()!==null}function L(r){S({hide_default_launcher:r})}function S(r){var e;(e=x())==null||e("update",r)}function A(){let r=window.intercomSettings;return r&&typeof r=="object"?r:{}}function B(r){var e;(e=x())==null||e("onHide",r)}function O(r){let e=x();if(!e)return!1;if(e("update",{hide_default_launcher:!1}),r==="")return e("showNewMessage",""),!0;let t=!1,i=()=>{t||(t=!0,e("showNewMessage",r))};return e("onShow",i),e("showNewMessage",r),window.setTimeout(i,900),!0}var D=60,R=20,Y=12,G=160,X=40,N=200,P=.4,V=150,J=150,M=250,Z='#intercom-container, .intercom-lightweight-app, [id^="intercom-"], [class*="intercom-"]';function z(r){return r.closest(Z)?!0:r instanceof HTMLIFrameElement&&r.src.includes("intercom")}function Q(r){let e=getComputedStyle(r).position;return e==="fixed"||e==="sticky"}var w=class{constructor(e,t,i){this.host=e;this.launcher=t;this.onLift=i;this.lifted=!1;this.active=!1;this.held=!1;this.timers={resize:null,scroll:null,mutation:null};this.observer=null;let n=A(),s=Number(n.vertical_padding);this.basePadding=Number.isFinite(s)&&s>=R?s:R,this.padding=this.basePadding}start(){this.active=!0,window.addEventListener("resize",()=>this.schedule("resize",V),{passive:!0}),window.addEventListener("scroll",()=>this.schedule("scroll",J),{passive:!0,capture:!0}),this.observer=new MutationObserver(e=>{e.some(t=>this.isForeignMutation(t))&&this.schedule("mutation",M)}),this.observer.observe(document.body,{childList:!0,subtree:!0,attributes:!0,attributeFilter:["class","style","hidden"]}),B(()=>{this.held=!1,this.schedule("mutation",M)}),this.measure(),document.readyState!=="complete"&&window.addEventListener("load",()=>this.schedule("mutation",M),{once:!0})}setActive(e){this.active=e,e?this.measure():this.cancelAll()}hold(){this.held=!0,this.cancelAll()}measure(){if(!this.active||this.held)return;let e=document.documentElement.clientWidth,t=window.innerHeight,i=this.findBarTop(e,t),n=this.basePadding;if(i!==null){let s=Math.max(this.basePadding,Math.round(t-i+Y));s<=t*P&&(n=s)}this.apply(n)}apply(e){e!==this.padding&&(this.padding=e,S({vertical_padding:e}));let t=e-this.basePadding;this.launcher.style.setProperty("--ua-lift",`${t}px`);let i=t>0;i!==this.lifted&&(this.lifted=i,this.onLift(i))}findBarTop(e,t){let i=A(),n=Number(i.horizontal_padding),s=Number.isFinite(n)&&n>=0?n:R,l=i.alignment==="left",d=[s,s+D/2,s+D].map(v=>l?v:e-v),u=[];for(let v=1;v<=G;v+=X)u.push(t-v);let c=new Set,g=null;for(let v of d)for(let k of u)if(!(v<0||k<0||v>=e||k>=t))for(let m of document.elementsFromPoint(v,k)){if(c.has(m)||(c.add(m),!this.isForeign(m)))continue;let E=this.pinnedBarRect(m,t);E&&(g===null||E.top<g)&&(g=E.top)}return g}pinnedBarRect(e,t){let i=[],n=e;for(;n&&n!==document.body&&n!==document.documentElement;){if(i.push(n),Q(n)){for(let l=i.length-1;l>=0;l--){let d=i[l].getBoundingClientRect();if(d.height>0&&d.bottom>=t-N&&d.top<t&&d.height<=t*P)return d}let s=n.getBoundingClientRect();return s.height>0&&s.bottom>=t-N?s:null}n=n.parentElement}return null}isForeignMutation(e){return e.type==="childList"?[...e.addedNodes,...e.removedNodes].some(i=>i instanceof Element&&i!==this.host&&!z(i))||this.isForeign(e.target):this.isForeign(e.target)}isForeign(e){let t=e instanceof Element?e:e.parentElement;return!t||t===document.documentElement||t===document.body||t===this.host||this.host.contains(t)?!1:!z(t)}schedule(e,t){if(!this.active||this.held)return;let i=this.timers[e];i!==null&&window.clearTimeout(i),this.timers[e]=window.setTimeout(()=>{this.timers[e]=null,this.measure()},t)}cancelAll(){for(let e of Object.keys(this.timers)){let t=this.timers[e];t!==null&&(window.clearTimeout(t),this.timers[e]=null)}}};function a(r,e={},...t){let i=document.createElement(r);for(let[n,s]of Object.entries(e))s==null||s===!1||(typeof s=="function"?i.addEventListener(n.replace(/^on/,"").toLowerCase(),s):s===!0?i.setAttribute(n,""):n==="class"?i.className=s:i.setAttribute(n,s));for(let n of t)n==null||n===!1||i.append(typeof n=="string"?document.createTextNode(n):n);return i}function p(r,e=16){let t="http://www.w3.org/2000/svg",i=document.createElementNS(t,"svg");i.setAttribute("viewBox","0 0 24 24"),i.setAttribute("width",String(e)),i.setAttribute("height",String(e)),i.setAttribute("fill","none"),i.setAttribute("stroke","currentColor"),i.setAttribute("stroke-width","1.8"),i.setAttribute("stroke-linecap","round"),i.setAttribute("stroke-linejoin","round"),i.setAttribute("aria-hidden","true");let n=document.createElementNS(t,"path");return n.setAttribute("d",r),i.append(n),i}var h={sparkles:"M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3zM5 18l.7 1.8L7.5 20.5l-1.8.7L5 23l-.7-1.8-1.8-.7 1.8-.7L5 18zM19 2l.6 1.4L21 4l-1.4.6L19 6l-.6-1.4L17 4l1.4-.6L19 2z",close:"M6 6l12 12M18 6L6 18",send:"M5 12h14M13 6l6 6-6 6",paperclip:"M21 12.5l-8.5 8.5a5.5 5.5 0 01-7.8-7.8l9.2-9.2a3.5 3.5 0 015 5l-9.2 9.2a1.5 1.5 0 01-2.1-2.1l8.5-8.5",copy:"M9 9h10v12H9zM5 15V3h10",check:"M5 12l5 5L20 7",clock:"M12 7v5l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z",plus:"M12 5v14M5 12h14"};function ee(r){let e=[],t=/\*\*([^*\n]+?)\*\*/g,i=0,n;for(;(n=t.exec(r))!==null;){n.index>i&&e.push(document.createTextNode(r.slice(i,n.index)));let s=document.createElement("strong");s.textContent=n[1],e.push(s),i=n.index+n[0].length}return i<r.length&&e.push(document.createTextNode(r.slice(i))),e}function _(r){let e=document.createDocumentFragment();return r.replace(/\r\n/g,`
`).split(/\n{2,}/).forEach(i=>{let n=document.createElement("p");i.split(`
`).forEach((l,d)=>{d>0&&n.append(document.createElement("br")),n.append(...ee(l))}),e.append(n)}),e}function F(r){if(!r)return"";let e=new Date(r);if(Number.isNaN(e.getTime()))return"";let t=new Date,i=l=>new Date(l.getFullYear(),l.getMonth(),l.getDate()).getTime(),n=Math.round((i(t)-i(e))/864e5);if(n===0)return"Today";if(n===1)return"Yesterday";let s={month:"short",day:"numeric"};return e.getFullYear()!==t.getFullYear()&&(s.year="numeric"),e.toLocaleDateString(void 0,s)}function H(r){if(!r)return"";let e=new Date(r);if(Number.isNaN(e.getTime()))return"";let t=e.toDateString()===new Date().toDateString(),i=e.toLocaleTimeString(void 0,{hour:"numeric",minute:"2-digit"});return t?i:`${e.toLocaleDateString(void 0,{month:"short",day:"numeric"})} ${i}`}var U=`
:host {
  --ua-bg-base: 255 255 255;
  --ua-bg-subtle: 248 250 252;
  --ua-bg-muted: 241 245 249;
  --ua-surface: 255 255 255;
  --ua-surface-raised: 255 255 255;
  --ua-border: 226 232 240;
  --ua-border-strong: 203 213 225;
  --ua-text: 15 23 42;
  --ua-text-secondary: 71 85 105;
  --ua-text-muted: 100 116 139;
  --ua-on-accent: 255 255 255;
  --ua-launcher: 17 24 39;
  --ua-primary: 37 99 235;
  --ua-primary-hover: 29 78 216;
  --ua-primary-subtle: 239 246 255;
  --ua-success: 22 163 74;
  --ua-success-subtle: 240 253 244;
  --ua-warning: 180 83 9;
  --ua-warning-subtle: 255 251 235;
  --ua-danger: 220 38 38;
  --ua-danger-subtle: 254 242 242;
  --ua-ring: 37 99 235;
  --ua-scrim: 15 23 42;
  --ua-radius: 0.5rem;
  --ua-radius-lg: 0.75rem;
  --ua-shadow: 0 10px 15px -3px rgb(15 23 42 / 0.10), 0 4px 6px -4px rgb(15 23 42 / 0.05);
  --ua-shadow-sm: 0 1px 2px 0 rgb(15 23 42 / 0.05);
  --ua-fast: 120ms;
  --ua-base: 180ms;
  --ua-ease: cubic-bezier(0.2, 0, 0, 1);
  --ua-font: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  all: initial;
  font-family: var(--ua-font);
  font-size: 14px;
  line-height: 1.45;
  color: rgb(var(--ua-text));
  -webkit-font-smoothing: antialiased;
}
@media (prefers-color-scheme: dark) {
  :host(:not([data-theme="light"])) { ${$()} }
}
:host([data-theme="dark"]) { ${$()} }

*, *::before, *::after { box-sizing: border-box; }
button, input, textarea { font: inherit; color: inherit; }
button { cursor: pointer; background: none; border: 0; padding: 0; }
button:disabled { cursor: default; opacity: 0.5; }
p { margin: 0; }
p + p { margin-top: 0.5em; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

:focus-visible { outline: 2px solid rgb(var(--ua-ring)); outline-offset: 2px; }

/* Launcher: a round icon button stacked directly above the Intercom bubble
   (which sits at its default bottom-right 20px), one column at the right edge.
   --ua-lift is how far the column was raised to clear a bar pinned to the
   bottom of the viewport (see avoid.ts); Intercom gets the same lift. */
.launcher {
  position: fixed; right: 20px; bottom: calc(92px + var(--ua-lift, 0px)); z-index: 2147483000;
  display: inline-flex; align-items: center; justify-content: center;
  width: 44px; height: 44px; padding: 0;
  border-radius: 9999px;
  background: rgb(var(--ua-launcher)); color: rgb(var(--ua-on-accent));
  box-shadow: var(--ua-shadow);
  transition: background-color var(--ua-fast) var(--ua-ease), transform var(--ua-fast) var(--ua-ease);
}
.launcher:hover, .launcher:focus-visible { background: rgb(var(--ua-primary)); }
.launcher:active { transform: translateY(1px); }
.launcher[hidden] { display: none; }
.launcher .tip {
  position: absolute; right: calc(100% + 10px); top: 50%; transform: translateY(-50%);
  padding: 4px 8px; border-radius: 6px; white-space: nowrap;
  background: rgb(var(--ua-text)); color: rgb(var(--ua-bg-base));
  font-size: 12px; font-weight: 600; line-height: 1.3;
  opacity: 0; pointer-events: none;
  transition: opacity var(--ua-fast) var(--ua-ease);
}
.launcher:hover .tip, .launcher:focus-visible .tip { opacity: 1; }
@media (max-width: 639px) {
  .launcher { right: 16px; bottom: calc(80px + var(--ua-lift, 0px)); width: 40px; height: 40px; }
}

/* Drawer */
.scrim {
  position: fixed; inset: 0; z-index: 2147483001;
  background: rgb(var(--ua-scrim) / 0.4);
  opacity: 0; pointer-events: none;
  transition: opacity var(--ua-base) var(--ua-ease);
}
.scrim.open { opacity: 1; pointer-events: auto; }
@media (min-width: 640px) { .scrim { display: none; } }

.drawer {
  position: fixed; top: 0; right: 0; bottom: 0; z-index: 2147483002;
  width: 420px; max-width: 100vw;
  display: flex; flex-direction: column;
  background: rgb(var(--ua-surface));
  border-left: 1px solid rgb(var(--ua-border));
  box-shadow: var(--ua-shadow);
  transform: translateX(100%);
  visibility: hidden;
  transition: transform var(--ua-base) var(--ua-ease), visibility 0s linear var(--ua-base);
}
.drawer.open { transform: none; visibility: visible; transition: transform var(--ua-base) var(--ua-ease); }
@media (max-width: 639px) {
  .drawer { width: 100vw; left: 0; border-left: 0; }
}
@media (prefers-reduced-motion: reduce) {
  .drawer, .scrim, .launcher, .dots span { transition: none !important; animation: none !important; }
}

.header {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 14px 16px 12px;
  border-bottom: 1px solid rgb(var(--ua-border));
}
.header .title-wrap { flex: 1; min-width: 0; }
.header h2 { margin: 0; font-size: 16px; font-weight: 600; line-height: 1.3; display: flex; align-items: center; gap: 8px; }
.header .subtitle { margin-top: 2px; font-size: 12px; color: rgb(var(--ua-text-secondary)); }
.icon-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: var(--ua-radius);
  color: rgb(var(--ua-text-secondary));
  transition: background-color var(--ua-fast) var(--ua-ease), color var(--ua-fast) var(--ua-ease);
}
.icon-btn:hover { background: rgb(var(--ua-bg-muted)); color: rgb(var(--ua-text)); }
.icon-btn[aria-expanded="true"] { background: rgb(var(--ua-bg-muted)); color: rgb(var(--ua-text)); }

/* Thin bar under the header while a previous thread is open */
.reopened {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 16px; font-size: 12px;
  background: rgb(var(--ua-primary-subtle)); color: rgb(var(--ua-text-secondary));
  border-bottom: 1px solid rgb(var(--ua-border));
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.reopened[hidden] { display: none; }
.reopened svg { flex: none; color: rgb(var(--ua-primary)); }

/* Everything under the header; the Conversations panel covers it */
.body { position: relative; flex: 1; min-height: 0; display: flex; flex-direction: column; }
.panel {
  position: absolute; left: 0; right: 0; bottom: 0; top: 0;
  display: flex; flex-direction: column;
  background: rgb(var(--ua-surface));
  z-index: 1;
}
.panel[hidden] { display: none; }
.panel-head {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 16px; border-bottom: 1px solid rgb(var(--ua-border));
}
.panel-title { font-size: 14px; font-weight: 600; }
.panel-body { flex: 1; overflow-y: auto; padding: 12px 16px 16px; display: flex; flex-direction: column; gap: 12px; background: rgb(var(--ua-bg-subtle)); }
.new-conversation { align-self: stretch; }
.threads { display: flex; flex-direction: column; border: 1px solid rgb(var(--ua-border)); border-radius: var(--ua-radius-lg); overflow: hidden; background: rgb(var(--ua-surface)); }
.threads:empty { display: none; }
.thread {
  display: flex; flex-direction: column; gap: 2px; align-items: flex-start;
  width: 100%; text-align: left; padding: 10px 12px;
  transition: background-color var(--ua-fast) var(--ua-ease);
}
.thread + .thread { border-top: 1px solid rgb(var(--ua-border)); }
.thread:hover:not(:disabled) { background: rgb(var(--ua-bg-muted)); }
.thread:focus-visible { outline-offset: -2px; }
.thread-title { font-size: 14px; font-weight: 500; color: rgb(var(--ua-text)); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; }
.thread-meta { font-size: 12px; color: rgb(var(--ua-text-muted)); }
.threads-empty { padding: 20px 12px; text-align: center; font-size: 13px; color: rgb(var(--ua-text-muted)); }

/* "Things I can do" pills */
.suggestions { display: flex; flex-direction: column; gap: 8px; width: 100%; }
.suggestions-title { font-size: 12px; font-weight: 500; color: rgb(var(--ua-text-secondary)); padding-left: 4px; }
.pills { display: flex; flex-wrap: wrap; gap: 8px; }
.pill {
  display: inline-flex; align-items: center;
  min-height: 32px; padding: 6px 12px;
  border-radius: 9999px; border: 1px solid rgb(var(--ua-border));
  background: rgb(var(--ua-bg-muted)); color: rgb(var(--ua-text));
  font-size: 13px; line-height: 1.3; text-align: left;
  transition: border-color var(--ua-fast) var(--ua-ease), background-color var(--ua-fast) var(--ua-ease);
}
.pill:hover:not(:disabled), .pill:focus-visible { border-color: rgb(var(--ua-primary)); }
.pill:active:not(:disabled) { background: rgb(var(--ua-primary-subtle)); }

/* Messages */
.list {
  flex: 1; overflow-y: auto; overscroll-behavior: contain;
  padding: 16px; display: flex; flex-direction: column; gap: 12px;
  background: rgb(var(--ua-bg-subtle));
}
.turn { display: flex; flex-direction: column; gap: 6px; max-width: 100%; }
.turn.user { align-items: flex-end; }
.turn.assistant { align-items: flex-start; }
.bubble {
  max-width: 88%; padding: 10px 12px;
  border-radius: var(--ua-radius-lg);
  font-size: 14px; word-wrap: break-word; overflow-wrap: anywhere;
}
.user .bubble { background: rgb(var(--ua-primary)); color: rgb(var(--ua-on-accent)); border-bottom-right-radius: 4px; }
.assistant .bubble {
  background: rgb(var(--ua-surface)); border: 1px solid rgb(var(--ua-border));
  border-bottom-left-radius: 4px; box-shadow: var(--ua-shadow-sm);
}
.assistant .bubble.error { border-color: rgb(var(--ua-danger) / 0.4); background: rgb(var(--ua-danger-subtle)); color: rgb(var(--ua-danger)); }
.assistant .bubble.info { background: rgb(var(--ua-primary-subtle)); }
.activity { font-size: 12px; color: rgb(var(--ua-text-muted)); padding-left: 4px; }
.activity div + div { margin-top: 2px; }
.time { font-size: 11px; color: rgb(var(--ua-text-muted)); padding: 0 4px; }
.notice { font-size: 12px; color: rgb(var(--ua-text-secondary)); text-align: center; padding: 2px 8px; }

.waiting { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; color: rgb(var(--ua-text-muted)); padding-left: 4px; }
.dots { display: inline-flex; gap: 3px; }
.dots span { width: 5px; height: 5px; border-radius: 9999px; background: rgb(var(--ua-text-muted)); animation: ua-pulse 1.2s infinite var(--ua-ease); }
.dots span:nth-child(2) { animation-delay: 0.15s; }
.dots span:nth-child(3) { animation-delay: 0.3s; }
@keyframes ua-pulse { 0%, 80%, 100% { opacity: 0.3; } 40% { opacity: 1; } }

/* Action chips */
.chips { display: flex; flex-direction: column; gap: 6px; width: 88%; }
.chip {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 8px 6px 10px;
  border: 1px solid rgb(var(--ua-border)); border-radius: var(--ua-radius);
  background: rgb(var(--ua-surface)); font-size: 12px;
}
.chip .mark { width: 16px; height: 16px; flex: none; color: rgb(var(--ua-success)); }
.chip.undone .mark { color: rgb(var(--ua-text-muted)); }
.chip .text { flex: 1; min-width: 0; }
.chip .text .meta { color: rgb(var(--ua-text-muted)); font-size: 11px; }
.chip.undone .text { text-decoration: line-through; color: rgb(var(--ua-text-muted)); }
.chip .status { color: rgb(var(--ua-text-muted)); font-size: 11px; font-weight: 500; }

/* Buttons */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  min-height: 32px; padding: 0 12px;
  border-radius: var(--ua-radius); font-size: 13px; font-weight: 500;
  border: 1px solid transparent;
  transition: background-color var(--ua-fast) var(--ua-ease), color var(--ua-fast) var(--ua-ease), border-color var(--ua-fast) var(--ua-ease);
}
.btn.sm { min-height: 26px; padding: 0 8px; font-size: 12px; }
.btn.primary { background: rgb(var(--ua-primary)); color: rgb(var(--ua-on-accent)); }
.btn.primary:hover:not(:disabled) { background: rgb(var(--ua-primary-hover)); }
.btn.secondary { background: rgb(var(--ua-surface)); border-color: rgb(var(--ua-border-strong)); color: rgb(var(--ua-text)); }
.btn.secondary:hover:not(:disabled) { background: rgb(var(--ua-bg-muted)); }
.btn.ghost { color: rgb(var(--ua-text-secondary)); }
.btn.ghost:hover:not(:disabled) { background: rgb(var(--ua-bg-muted)); color: rgb(var(--ua-text)); }

/* Cards (handoff, review) */
.card {
  width: 88%; padding: 12px;
  border: 1px solid rgb(var(--ua-border)); border-radius: var(--ua-radius-lg);
  background: rgb(var(--ua-surface)); box-shadow: var(--ua-shadow-sm);
  display: flex; flex-direction: column; gap: 10px; font-size: 13px;
}
.card .card-title { font-weight: 600; font-size: 14px; }
.card .quote {
  padding: 8px 10px; border-radius: var(--ua-radius);
  background: rgb(var(--ua-bg-muted)); color: rgb(var(--ua-text-secondary)); font-size: 13px;
  white-space: pre-wrap;
}
.card .row-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.card .hint { font-size: 12px; color: rgb(var(--ua-text-muted)); }
.changes { display: flex; flex-direction: column; border: 1px solid rgb(var(--ua-border)); border-radius: var(--ua-radius); overflow: hidden; }
.change { display: flex; gap: 8px; padding: 8px 10px; align-items: flex-start; background: rgb(var(--ua-surface)); }
.change + .change { border-top: 1px solid rgb(var(--ua-border)); }
.change .body { flex: 1; min-width: 0; }
.change .what { font-weight: 500; }
.change .delta { color: rgb(var(--ua-text-secondary)); font-size: 12px; }
.change .why { color: rgb(var(--ua-text-muted)); font-size: 12px; margin-top: 2px; }
.change .care { display: flex; align-items: center; gap: 6px; margin-top: 6px; font-size: 12px; color: rgb(var(--ua-warning)); }
.change .care input { width: 14px; height: 14px; accent-color: rgb(var(--ua-primary)); margin: 0; }
.change .remove { color: rgb(var(--ua-text-muted)); }
.change .remove:hover { color: rgb(var(--ua-danger)); }
.card.done { opacity: 0.8; }

/* Footer */
.footer { border-top: 1px solid rgb(var(--ua-border)); padding: 10px 12px 10px; background: rgb(var(--ua-surface)); }
.composer {
  display: flex; align-items: flex-end; gap: 6px;
  border: 1px solid rgb(var(--ua-border-strong)); border-radius: var(--ua-radius-lg);
  background: rgb(var(--ua-surface)); padding: 4px 4px 4px 6px;
  transition: border-color var(--ua-fast) var(--ua-ease), box-shadow var(--ua-fast) var(--ua-ease);
}
.composer:focus-within { border-color: rgb(var(--ua-ring)); box-shadow: 0 0 0 2px rgb(var(--ua-ring) / 0.25); }
.composer textarea {
  flex: 1; resize: none; border: 0; outline: none; background: transparent;
  min-height: 36px; max-height: 140px; padding: 8px 6px; font-size: 14px; line-height: 1.4;
}
.composer textarea::placeholder { color: rgb(var(--ua-text-muted)); }
.composer .icon-btn { flex: none; }
.composer .send { color: rgb(var(--ua-on-accent)); background: rgb(var(--ua-primary)); }
.composer .send:hover:not(:disabled) { background: rgb(var(--ua-primary-hover)); color: rgb(var(--ua-on-accent)); }
.footer-row { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 6px; padding: 0 4px; font-size: 11px; color: rgb(var(--ua-text-muted)); }
.footer-links { display: inline-flex; align-items: center; gap: 10px; flex: none; }
.link[hidden] { display: none; }
.link[aria-pressed="true"] { color: rgb(var(--ua-primary)); }
.link { color: rgb(var(--ua-text-secondary)); text-decoration: underline; text-underline-offset: 2px; font-size: 11px; }
.link:hover { color: rgb(var(--ua-text)); }
.upload-name { font-size: 11px; color: rgb(var(--ua-text-secondary)); }
`;function $(){return`
  --ua-bg-base: 2 6 23;
  --ua-bg-subtle: 15 23 42;
  --ua-bg-muted: 30 41 59;
  --ua-surface: 15 23 42;
  --ua-surface-raised: 30 41 59;
  --ua-border: 30 41 59;
  --ua-border-strong: 51 65 85;
  --ua-text: 248 250 252;
  --ua-text-secondary: 203 213 225;
  --ua-text-muted: 148 163 184;
  --ua-primary: 59 130 246;
  --ua-primary-hover: 96 165 250;
  --ua-primary-subtle: 30 58 138;
  --ua-success: 74 222 128;
  --ua-success-subtle: 5 46 22;
  --ua-warning: 251 191 36;
  --ua-warning-subtle: 69 26 3;
  --ua-danger: 248 113 113;
  --ua-danger-subtle: 69 10 10;
  --ua-ring: 96 165 250;
  --ua-scrim: 0 0 0;
  --ua-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.6), 0 4px 6px -4px rgb(0 0 0 / 0.5);
  --ua-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.4);`}var o={launcher:"Assistant",title:"Assistant",subtitle:"Does tasks for you. For questions about how something works, use Help.",intro:"I'm your assistant. Tell me what to do and I'll do it: add people, set up facilities, fill a shift, change a setting. If you want to know how something works, Help is the place to ask.",placeholder:"Tell me what to do",send:"Send",attach:"Add a file",close:"Close",working:"Working on it",askHelpInstead:"Ask Help instead",handoff:"I can't do that one, but Help can take it from here.",askHelp:"Ask Help",noHelp:"Open Help from the menu to send this.",copy:"Copy",copied:"Copied",undo:"Undo",undone:"Undone",undoing:"Undoing",reviewTitle:"Review these changes",reviewHint:"Take out anything you do not want, then approve.",needsCare:"Needs extra care. Check to include it.",approve:"Approve and apply",setAside:"Set aside",remove:"Remove",applying:"Applying your changes.",setAsideDone:"Set aside. Ask me when you want to pick it up again.",nothingLeft:"Nothing left to apply.",fileAdded:"I added a file",uploading:"Adding your file",enterHint:"Enter to send, Shift+Enter for a new line",conversations:"Conversations",newConversation:"New conversation",noConversations:"No previous conversations yet.",untitled:"Untitled conversation",reopened:"Reopened",thingsICanDo:"Things I can do",ideas:"Ideas",hideIdeas:"Hide ideas"},q="unified-assistant:open",y="data-unified-assistant",j="unified-assistant-dock";function te(){if(document.getElementById(j))return;let r=document.createElement("style");r.id=j,r.textContent=`html[${y}="docked"] body { padding-bottom: 150px; }
@media (max-width: 639px) { html[${y}="docked"] body { padding-bottom: 120px; } }`,document.head.append(r)}var ie=["drafted","refined","proposed","draft","pending"],ne=["rejected","set_aside"],se="unified-assistant:action";function W(r){return(r!=null?r:[]).filter(e=>typeof e.summary=="string"&&e.summary.trim()!=="")}function K(r,e){let t={actions:r};e!==void 0&&(t.undone=e),window.dispatchEvent(new CustomEvent(se,{detail:t}))}var C=class{constructor(e){this.messages=[];this.pendingReview=null;this.conversation=null;this.conversations=[];this.suggestions=[];this.ideasOpen=!1;this.suggestionsEl=null;this.panelOpen=!1;this.open=!1;this.busy=!1;this.booted=!1;this.waitingRow=null;this.lastFocus=null;this.lifted=!1;var t;this.api=new b(e),this.setupApp=(t=e.setup_app)!=null?t:null,this.host=document.createElement("unified-assistant"),this.root=this.host.attachShadow({mode:"open"}),this.syncTheme(),this.build(),this.avoidance=new w(this.host,this.launcher,i=>{this.lifted=i,this.syncDock()})}async boot(){let e;try{e=await this.api.bootstrap()}catch{return}if(!e||e.enabled===!1)return;this.booted=!0,document.body.append(this.host),this.applyBootstrap(e),this.launcher.hidden=!1,this.syncDock(),this.avoidance.start();let t=!1;try{t=sessionStorage.getItem(q)==="1"}catch{t=!1}t&&this.setOpen(!0,!1),window.addEventListener("pagehide",()=>L(!1))}openDrawer(){this.booted&&this.setOpen(!0)}closeDrawer(){this.setOpen(!1)}ask(e){this.booted&&(this.setOpen(!0),this.send(e))}setSetupApp(e){this.setupApp=e}build(){let e=document.createElement("style");e.textContent=U,this.launcher=a("button",{class:"launcher",type:"button",hidden:!0,"aria-label":o.launcher,title:o.launcher,"aria-haspopup":"dialog","aria-expanded":"false",onclick:()=>this.setOpen(!0)},p(h.sparkles,20),a("span",{class:"tip","aria-hidden":"true"},o.launcher)),this.scrim=a("div",{class:"scrim",onclick:()=>this.setOpen(!1)}),this.list=a("div",{class:"list",role:"log","aria-live":"polite","aria-relevant":"additions"}),this.input=a("textarea",{rows:"1",placeholder:o.placeholder,"aria-label":o.placeholder,onkeydown:s=>this.onComposerKey(s),oninput:()=>this.autosize()}),this.sendButton=a("button",{class:"icon-btn send",type:"button","aria-label":o.send,onclick:()=>void this.send()},p(h.send)),this.fileInput=a("input",{type:"file",class:"sr",tabindex:"-1","aria-hidden":"true",onchange:()=>void this.onFilePicked()}),this.attachButton=a("button",{class:"icon-btn",type:"button","aria-label":o.attach,title:o.attach,onclick:()=>this.fileInput.click()},p(h.paperclip)),this.historyButton=a("button",{class:"icon-btn history",type:"button","aria-label":o.conversations,title:o.conversations,"aria-expanded":"false",onclick:()=>this.setPanelOpen(!this.panelOpen)},p(h.clock));let t=a("header",{class:"header"},a("div",{class:"title-wrap"},a("h2",{id:"ua-title"},p(h.sparkles,16),o.title),a("div",{class:"subtitle"},o.subtitle)),this.historyButton,a("button",{class:"icon-btn",type:"button","aria-label":o.close,onclick:()=>this.setOpen(!1)},p(h.close)));this.reopenedBar=a("div",{class:"reopened",hidden:!0,role:"status"}),this.panelList=a("div",{class:"threads"}),this.panel=a("section",{class:"panel","aria-label":o.conversations,hidden:!0},a("div",{class:"panel-head"},a("div",{class:"panel-title"},o.conversations)),a("div",{class:"panel-body"},a("button",{class:"btn secondary new-conversation",type:"button",onclick:()=>void this.newConversation()},p(h.plus,14),o.newConversation),this.panelList));let i=a("footer",{class:"footer"},a("div",{class:"composer"},this.attachButton,this.input,this.sendButton),a("div",{class:"footer-row"},a("span",{},o.enterHint),a("span",{class:"footer-links"},a("button",{class:"link ideas",type:"button","aria-pressed":"false",onclick:()=>this.toggleIdeas()},o.ideas),a("button",{class:"link",type:"button",onclick:()=>this.askHelpInstead()},o.askHelpInstead))),this.fileInput),n=a("div",{class:"body"},this.reopenedBar,this.list,i,this.panel);this.drawer=a("section",{class:"drawer",role:"dialog","aria-modal":"true","aria-labelledby":"ua-title","aria-hidden":"true"},t,n),this.drawer.addEventListener("keydown",s=>{if(s.key==="Escape"){if(this.panelOpen){s.stopPropagation(),this.setPanelOpen(!1);return}this.setOpen(!1)}}),this.root.append(e,this.launcher,this.scrim,this.drawer)}setOpen(e,t=!0,i=!0){if(e!==this.open){if(this.open=e,this.drawer.classList.toggle("open",e),this.scrim.classList.toggle("open",e),this.drawer.setAttribute("aria-hidden",e?"false":"true"),this.launcher.setAttribute("aria-expanded",e?"true":"false"),this.launcher.hidden=e,this.syncDock(),i&&L(e),this.booted&&this.avoidance.setActive(!e),t)try{sessionStorage.setItem(q,e?"1":"0")}catch{}e?(this.lastFocus=document.activeElement,this.scrollToEnd(),window.setTimeout(()=>this.input.focus(),50)):this.lastFocus instanceof HTMLElement&&this.lastFocus.isConnected?this.lastFocus.focus():this.launcher.focus()}}renderMessages(){this.list.replaceChildren(),this.messages.length===0&&this.list.append(this.renderTurn({id:"intro",role:"assistant",content:o.intro}));for(let e of this.messages)this.list.append(this.renderTurn(e));this.pendingReview&&!this.messages.some(e=>{var t,i;return((t=e.review)==null?void 0:t.id)===((i=this.pendingReview)==null?void 0:i.id)})&&this.list.append(a("div",{class:"turn assistant"},this.renderReview(this.pendingReview))),this.renderSuggestions(),this.scrollToEnd()}applyBootstrap(e){var t,i,n,s,l,d;this.messages=((i=(t=e.conversation)==null?void 0:t.messages)!=null?i:[]).slice(),this.pendingReview=(n=e.review)!=null?n:null,this.conversation=(s=e.conversation)!=null?s:null,this.conversations=((l=e.conversations)!=null?l:[]).slice(),this.suggestions=((d=e.suggestions)!=null?d:[]).filter(u=>u&&typeof u.prompt=="string"&&u.prompt.trim()!==""),this.ideasOpen=!1,this.waitingRow=null,this.renderMessages(),this.renderThreads(),e.notice&&this.appendNotice(e.notice)}setPanelOpen(e){e!==this.panelOpen&&(this.panelOpen=e,this.panel.hidden=!e,this.historyButton.setAttribute("aria-expanded",e?"true":"false"),this.list.setAttribute("aria-hidden",e?"true":"false"),e?(this.renderThreads(),window.setTimeout(()=>{var t;return(t=this.panel.querySelector("button"))==null?void 0:t.focus()},30)):this.historyButton.focus())}renderThreads(){var i;let e=this.conversation?String(this.conversation.id):null,t=this.conversations.filter(n=>String(n.id)!==e);if(this.panelList.replaceChildren(),t.length===0){this.panelList.append(a("div",{class:"threads-empty"},o.noConversations));return}for(let n of t){let s=n.message_count===1?"1 message":`${n.message_count} messages`,l=[F(n.last_activity_at),s].filter(Boolean).join(" \xB7 "),d=a("button",{class:"thread",type:"button","data-conversation":String(n.id)},a("div",{class:"thread-title"},((i=n.title)==null?void 0:i.trim())||o.untitled),a("div",{class:"thread-meta"},l));d.addEventListener("click",()=>void this.openConversation(n)),this.panelList.append(d)}}async newConversation(){if(!this.busy){this.setBusy(!0);try{let e=await this.api.clear();this.setBusy(!1),this.applyBootstrap(e),this.reopenedBar.hidden=!0,this.setPanelOpen(!1),this.input.focus()}catch(e){this.setBusy(!1),this.appendNotice(this.errorText(e))}}}async openConversation(e){var t,i,n;if(!this.busy){this.setBusy(!0);try{let s=await this.api.bootstrap(e.id);if(this.setBusy(!1),!s)return;this.applyBootstrap(s),this.reopenedBar.replaceChildren(p(h.clock,14),`${o.reopened}: ${((i=(t=s.conversation)==null?void 0:t.title)==null?void 0:i.trim())||((n=e.title)==null?void 0:n.trim())||o.untitled}`),this.reopenedBar.hidden=!1,this.setPanelOpen(!1)}catch(s){this.setBusy(!1),this.appendNotice(this.errorText(s))}}}toggleIdeas(){this.ideasOpen=!this.ideasOpen,this.renderSuggestions(),this.scrollToEnd()}renderSuggestions(){var n;(n=this.suggestionsEl)==null||n.remove(),this.suggestionsEl=null;let e=this.drawer.querySelector(".link.ideas"),t=this.suggestions.length>0&&(this.ideasOpen||this.messages.length===0);if(e&&(e.hidden=this.suggestions.length===0,e.setAttribute("aria-pressed",this.ideasOpen?"true":"false"),e.textContent=this.ideasOpen?o.hideIdeas:o.ideas),!t)return;let i=this.suggestions.map(s=>{var d;let l=a("button",{class:"pill",type:"button"},((d=s.label)==null?void 0:d.trim())||s.prompt);return l.addEventListener("click",()=>{this.ideasOpen=!1,this.send(s.prompt)}),l});this.suggestionsEl=a("div",{class:"suggestions"},a("div",{class:"suggestions-title"},o.thingsICanDo),a("div",{class:"pills"},...i)),this.list.append(this.suggestionsEl)}renderTurn(e){let t=a("div",{class:`turn ${e.role}`,"data-id":String(e.id)});if(e.role==="assistant"&&e.activity&&e.activity.length>0&&t.append(a("div",{class:"activity"},...e.activity.map(s=>a("div",{},s)))),e.content){let s=a("div",{class:`bubble${e.tone?` ${e.tone}`:""}`});s.append(_(e.content)),t.append(s)}let i=W(e.actions);i.length>0&&t.append(a("div",{class:"chips"},...i.map(s=>this.renderChip(s)))),e.review&&t.append(this.renderReview(e.review)),e.handoff&&t.append(this.renderHandoff(e.handoff));let n=H(e.at);return n&&t.append(a("div",{class:"time"},n)),t}renderChip(e){let t=!!e.undone_at,i=a("div",{class:`chip${t?" undone":""}`}),n=a("span",{class:"mark"},p(h.check,16)),s=[e.app,H(e.at)].filter(Boolean).join(" \xB7 "),l=a("div",{class:"text"},a("div",{},e.summary),s?a("div",{class:"meta"},s):null);if(i.append(n,l),t)i.append(a("span",{class:"status"},o.undone));else if(e.undoable){let d=a("button",{class:"btn ghost sm",type:"button"},o.undo);d.addEventListener("click",()=>void this.undo(e,i,d)),i.append(d)}return i}async undo(e,t,i){var n;i.disabled=!0,i.textContent=o.undoing;try{let s=await this.api.undo(e.id);if(s.ok===!1||s.error)throw new f(200,s.error||s.message||"That did not go through.");e.undone_at=(n=s.undone_at)!=null?n:new Date().toISOString(),t.classList.add("undone"),i.replaceWith(a("span",{class:"status"},o.undone)),K([e],e.id)}catch(s){i.disabled=!1,i.textContent=o.undo,this.appendNotice(this.errorText(s))}}renderHandoff(e){let t=a("div",{class:"card"},a("div",{class:"card-title"},o.handoff));if(T())t.append(a("div",{class:"quote"},e.question),a("div",{class:"row-actions"},a("button",{class:"btn primary",type:"button",onclick:()=>this.handOffToHelp(e.question)},o.askHelp)));else{let i=a("button",{class:"btn secondary sm",type:"button"},p(h.copy,14),o.copy);i.addEventListener("click",()=>{var n;(n=navigator.clipboard)==null||n.writeText(e.question).then(()=>{i.replaceChildren(p(h.check,14),o.copied)})}),t.append(a("div",{class:"quote"},e.question),a("div",{class:"row-actions"},i,a("span",{class:"hint"},o.noHelp)))}return t}renderReview(e){let t=a("div",{class:"card review","data-plan":e.id}),i=!ie.includes(e.status);t.append(a("div",{class:"card-title"},o.reviewTitle),a("div",{class:"hint"},o.reviewHint)),e.changes.length===0&&t.append(a("div",{class:"hint"},o.nothingLeft));let n=a("div",{class:"changes"}),s=new Map;for(let l of e.changes)n.append(this.renderChange(e,l,s,i,t));if(t.append(n),i)t.classList.add("done"),t.append(a("div",{class:"hint"},ne.includes(e.status)?o.setAsideDone:o.applying));else{let l=a("button",{class:"btn primary",type:"button",disabled:e.changes.length===0},o.approve),d=a("button",{class:"btn secondary",type:"button"},o.setAside);l.addEventListener("click",()=>{let u=[...s.entries()].filter(([,c])=>c.checked).map(([c])=>c);this.reviewAction(e,"approve",{plan_hash:e.plan_hash,confirmed_danger:u},t,o.applying)}),d.addEventListener("click",()=>{this.reviewAction(e,"set-aside",{},t,o.setAsideDone)}),t.append(a("div",{class:"row-actions"},l,d))}return t}renderChange(e,t,i,n,s){let l=a("div",{class:"body"},a("div",{class:"what"},t.title)),d=t.from?`${t.from} to ${t.to}`:t.to;if(d&&l.append(a("div",{class:"delta"},d)),t.why&&l.append(a("div",{class:"why"},t.why)),(t.needs_confirmation||t.risk==="high")&&!n){let g=a("input",{type:"checkbox",id:`ua-care-${e.id}-${t.index}`});i.set(t.index,g),l.append(a("label",{class:"care",for:g.id},g,o.needsCare))}let c=a("div",{class:"change"},l);if(!n){let g=a("button",{class:"icon-btn remove",type:"button","aria-label":`${o.remove}: ${t.title}`,title:o.remove},p(h.close,14));g.addEventListener("click",()=>{this.reviewAction(e,"remove",{plan_hash:e.plan_hash,index:t.index},s,null)}),c.append(g)}return c}async reviewAction(e,t,i,n,s){n.querySelectorAll("button").forEach(l=>l.disabled=!0);try{let l=await this.api.review(e.id,t,i);if(l.error)throw new f(200,l.error);if(l.review){let d=l.review;this.pendingReview=d,this.messages.forEach(u=>{var c;((c=u.review)==null?void 0:c.id)===d.id&&(u.review=d)}),n.replaceWith(this.renderReview(d))}else this.pendingReview=null,this.messages.forEach(d=>{var u;((u=d.review)==null?void 0:u.id)===e.id&&(d.review={...e,status:t==="set-aside"?"set_aside":"approved"})}),n.classList.add("done"),n.querySelectorAll(".row-actions, .remove, .care").forEach(d=>d.remove()),n.append(a("div",{class:"hint"},s!=null?s:o.applying));l.reply&&this.push({id:`r-${Date.now()}`,role:"assistant",content:l.reply,at:new Date().toISOString()}),l.notice&&this.appendNotice(l.notice)}catch(l){n.querySelectorAll("button").forEach(d=>d.disabled=!1),this.appendNotice(this.errorText(l))}}onComposerKey(e){e.key==="Enter"&&!e.shiftKey&&!e.isComposing&&(e.preventDefault(),this.send())}autosize(){this.input.style.height="auto",this.input.style.height=`${Math.min(this.input.scrollHeight,140)}px`}async send(e){var i,n;let t=(e!=null?e:this.input.value).trim();if(!(!t||this.busy)){e===void 0&&(this.input.value="",this.autosize()),this.push({id:`u-${Date.now()}`,role:"user",content:t,at:new Date().toISOString()}),this.setBusy(!0);try{let s=await this.api.send(t,this.setupApp,(n=(i=this.conversation)==null?void 0:i.id)!=null?n:null);this.acceptTurn(s)}catch(s){this.push({id:`e-${Date.now()}`,role:"assistant",content:this.errorText(s),tone:"error",at:new Date().toISOString()})}finally{this.setBusy(!1)}}}acceptTurn(e){var n,s,l,d,u,c;let t=W(e.actions),i={id:(n=e.message_id)!=null?n:`a-${Date.now()}`,role:"assistant",content:(s=e.reply)!=null?s:"",at:new Date().toISOString(),activity:(l=e.activity)!=null?l:null,actions:t,review:(d=e.review)!=null?d:null,handoff:(u=e.handoff)!=null?u:null};e.error&&(i.content=i.content||e.error,i.tone="error"),e.review&&(this.pendingReview=e.review),(i.content||(c=i.actions)!=null&&c.length||i.review||i.handoff)&&this.push(i),e.notice&&this.appendNotice(e.notice),t.length>0&&K(t)}async onFilePicked(){var t;let e=(t=this.fileInput.files)==null?void 0:t[0];if(this.fileInput.value="",!(!e||this.busy)){this.setBusy(!0,o.uploading);try{let i=await this.api.upload(e);if(i.ok===!1||i.error)throw new f(200,i.error||"That file did not go through.");this.setBusy(!1),await this.send(`${o.fileAdded}: ${e.name}`)}catch(i){this.setBusy(!1),this.push({id:`e-${Date.now()}`,role:"assistant",content:this.errorText(i),tone:"error",at:new Date().toISOString()})}}}askHelpInstead(){this.handOffToHelp("")||this.appendNotice(o.noHelp)}handOffToHelp(e){return T()?(this.avoidance.hold(),this.setOpen(!1,!0,!1),O(e)):!1}syncDock(){let e=this.booted&&!this.open,t=document.documentElement;e?(t.setAttribute(y,this.lifted?"lifted":"docked"),te()):t.removeAttribute(y)}push(e){var t;this.messages.push(e),this.messages.length===1&&((t=this.list.querySelector('[data-id="intro"]'))==null||t.remove()),this.list.append(this.renderTurn(e)),this.renderSuggestions(),this.scrollToEnd()}setBusy(e,t=o.working){var i;this.busy=e,this.sendButton.disabled=e,this.attachButton.disabled=e,(i=this.waitingRow)==null||i.remove(),this.waitingRow=null,e&&(this.waitingRow=a("div",{class:"turn assistant"},a("div",{class:"waiting"},a("span",{class:"dots"},a("span"),a("span"),a("span")),t)),this.list.append(this.waitingRow),this.scrollToEnd()),this.panel.querySelectorAll("button").forEach(n=>n.disabled=e)}appendNotice(e){this.list.append(a("div",{class:"notice"},e)),this.scrollToEnd()}errorText(e){return e instanceof f?e.message:"That did not go through. Try again."}scrollToEnd(){window.requestAnimationFrame(()=>{this.list.scrollTop=this.list.scrollHeight})}syncTheme(){let e=()=>{let t=document.documentElement,i=t.classList.contains("dark")||t.dataset.theme==="dark",n=t.classList.contains("light")||t.dataset.theme==="light";this.host.dataset.theme=i?"dark":n?"light":"",this.host.dataset.theme||delete this.host.dataset.theme};e(),new MutationObserver(e).observe(document.documentElement,{attributes:!0,attributeFilter:["class","data-theme"]})}};function ae(){var t;let r=(t=document.currentScript)!=null?t:document.querySelector("script[data-unified-assistant]"),e=r==null?void 0:r.dataset.unifiedAssistant;if(!e)return null;try{return JSON.parse(e)}catch{return null}}(function(){if(window.UnifiedAssistant)return;let e=ae();if(!e)return;let t=new C(e);window.UnifiedAssistant={open:()=>t.openDrawer(),close:()=>t.closeDrawer(),ask:n=>t.ask(n),setSetupApp:n=>t.setSetupApp(n)};let i=()=>void t.boot();document.readyState==="loading"?document.addEventListener("DOMContentLoaded",i,{once:!0}):i()})();})();
