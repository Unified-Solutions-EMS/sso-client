"use strict";(()=>{var m=class extends Error{constructor(t,n){super(n);this.status=t}},C="The assistant is not reachable right now. Try again in a minute.",b=class{constructor(e){this.settings=e}async bootstrap(){let e=await this.request("GET",this.settings.bootstrap);return e.status===204?null:await this.read(e)}async send(e,t){let n={message:e,page:{url:location.href,title:document.title}};return t&&(n.setup_app=t),await this.json("POST",this.settings.messages,n)}async upload(e){let t=new FormData;t.append("file",e,e.name);let n=await this.request("POST",this.settings.uploads,t);return await this.read(n)}async undo(e){return await this.json("POST",this.settings.undo.replace("{action}",String(e)),{})}async review(e,t,n){let i=this.settings.review.replace("{plan}",encodeURIComponent(e)).replace("{verb}",t);return await this.json("POST",i,n)}async clear(){await this.request("DELETE",this.settings.conversation)}async json(e,t,n){let i=await this.request(e,t,JSON.stringify(n),"application/json");return this.read(i)}async request(e,t,n,i){let a={Accept:"application/json","X-Requested-With":"XMLHttpRequest"};this.settings.csrf&&(a["X-CSRF-TOKEN"]=this.settings.csrf),i&&(a["Content-Type"]=i);try{return await fetch(t,{method:e,headers:a,body:n,credentials:"same-origin"})}catch{throw new m(0,C)}}async read(e){let t=null;try{t=await e.json()}catch{t=null}if(e.status===204)return{};if(!e.ok){let n=t!=null?t:{},i=typeof n.error=="string"&&n.error||typeof n.message=="string"&&n.message||(e.status===419||e.status===401?"Your session has expired. Refresh the page and try again.":e.status>=500?C:"That did not go through. Try again.");throw new m(e.status,i)}return t!=null?t:{}}};function x(){let r=window.Intercom;return typeof r=="function"?r:null}function T(){return x()!==null}function A(r){L({hide_default_launcher:r})}function L(r){var e;(e=x())==null||e("update",r)}function S(){let r=window.intercomSettings;return r&&typeof r=="object"?r:{}}function D(r){var e;(e=x())==null||e("onHide",r)}function O(r){let e=x();if(!e)return!1;if(e("update",{hide_default_launcher:!1}),r==="")return e("showNewMessage",""),!0;let t=!1,n=()=>{t||(t=!0,e("showNewMessage",r))};return e("onShow",n),e("showNewMessage",r),window.setTimeout(n,900),!0}var N=60,R=20,K=12,Y=160,G=40,P=200,_=.4,X=150,V=150,M=250,J='#intercom-container, .intercom-lightweight-app, [id^="intercom-"], [class*="intercom-"]';function z(r){return r.closest(J)?!0:r instanceof HTMLIFrameElement&&r.src.includes("intercom")}function Z(r){let e=getComputedStyle(r).position;return e==="fixed"||e==="sticky"}var w=class{constructor(e,t,n){this.host=e;this.launcher=t;this.onLift=n;this.lifted=!1;this.active=!1;this.held=!1;this.timers={resize:null,scroll:null,mutation:null};this.observer=null;let i=S(),a=Number(i.vertical_padding);this.basePadding=Number.isFinite(a)&&a>=R?a:R,this.padding=this.basePadding}start(){this.active=!0,window.addEventListener("resize",()=>this.schedule("resize",X),{passive:!0}),window.addEventListener("scroll",()=>this.schedule("scroll",V),{passive:!0,capture:!0}),this.observer=new MutationObserver(e=>{e.some(t=>this.isForeignMutation(t))&&this.schedule("mutation",M)}),this.observer.observe(document.body,{childList:!0,subtree:!0,attributes:!0,attributeFilter:["class","style","hidden"]}),D(()=>{this.held=!1,this.schedule("mutation",M)}),this.measure(),document.readyState!=="complete"&&window.addEventListener("load",()=>this.schedule("mutation",M),{once:!0})}setActive(e){this.active=e,e?this.measure():this.cancelAll()}hold(){this.held=!0,this.cancelAll()}measure(){if(!this.active||this.held)return;let e=document.documentElement.clientWidth,t=window.innerHeight,n=this.findBarTop(e,t),i=this.basePadding;if(n!==null){let a=Math.max(this.basePadding,Math.round(t-n+K));a<=t*_&&(i=a)}this.apply(i)}apply(e){e!==this.padding&&(this.padding=e,L({vertical_padding:e}));let t=e-this.basePadding;this.launcher.style.setProperty("--ua-lift",`${t}px`);let n=t>0;n!==this.lifted&&(this.lifted=n,this.onLift(n))}findBarTop(e,t){let n=S(),i=Number(n.horizontal_padding),a=Number.isFinite(i)&&i>=0?i:R,d=n.alignment==="left",l=[a,a+N/2,a+N].map(h=>d?h:e-h),u=[];for(let h=1;h<=Y;h+=G)u.push(t-h);let c=new Set,p=null;for(let h of l)for(let k of u)if(!(h<0||k<0||h>=e||k>=t))for(let v of document.elementsFromPoint(h,k)){if(c.has(v)||(c.add(v),!this.isForeign(v)))continue;let E=this.pinnedBarRect(v,t);E&&(p===null||E.top<p)&&(p=E.top)}return p}pinnedBarRect(e,t){let n=[],i=e;for(;i&&i!==document.body&&i!==document.documentElement;){if(n.push(i),Z(i)){for(let d=n.length-1;d>=0;d--){let l=n[d].getBoundingClientRect();if(l.height>0&&l.bottom>=t-P&&l.top<t&&l.height<=t*_)return l}let a=i.getBoundingClientRect();return a.height>0&&a.bottom>=t-P?a:null}i=i.parentElement}return null}isForeignMutation(e){return e.type==="childList"?[...e.addedNodes,...e.removedNodes].some(n=>n instanceof Element&&n!==this.host&&!z(n))||this.isForeign(e.target):this.isForeign(e.target)}isForeign(e){let t=e instanceof Element?e:e.parentElement;return!t||t===document.documentElement||t===document.body||t===this.host||this.host.contains(t)?!1:!z(t)}schedule(e,t){if(!this.active||this.held)return;let n=this.timers[e];n!==null&&window.clearTimeout(n),this.timers[e]=window.setTimeout(()=>{this.timers[e]=null,this.measure()},t)}cancelAll(){for(let e of Object.keys(this.timers)){let t=this.timers[e];t!==null&&(window.clearTimeout(t),this.timers[e]=null)}}};function s(r,e={},...t){let n=document.createElement(r);for(let[i,a]of Object.entries(e))a==null||a===!1||(typeof a=="function"?n.addEventListener(i.replace(/^on/,"").toLowerCase(),a):a===!0?n.setAttribute(i,""):i==="class"?n.className=a:n.setAttribute(i,a));for(let i of t)i==null||i===!1||n.append(typeof i=="string"?document.createTextNode(i):i);return n}function g(r,e=16){let t="http://www.w3.org/2000/svg",n=document.createElementNS(t,"svg");n.setAttribute("viewBox","0 0 24 24"),n.setAttribute("width",String(e)),n.setAttribute("height",String(e)),n.setAttribute("fill","none"),n.setAttribute("stroke","currentColor"),n.setAttribute("stroke-width","1.8"),n.setAttribute("stroke-linecap","round"),n.setAttribute("stroke-linejoin","round"),n.setAttribute("aria-hidden","true");let i=document.createElementNS(t,"path");return i.setAttribute("d",r),n.append(i),n}var f={sparkles:"M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3zM5 18l.7 1.8L7.5 20.5l-1.8.7L5 23l-.7-1.8-1.8-.7 1.8-.7L5 18zM19 2l.6 1.4L21 4l-1.4.6L19 6l-.6-1.4L17 4l1.4-.6L19 2z",close:"M6 6l12 12M18 6L6 18",send:"M5 12h14M13 6l6 6-6 6",paperclip:"M21 12.5l-8.5 8.5a5.5 5.5 0 01-7.8-7.8l9.2-9.2a3.5 3.5 0 015 5l-9.2 9.2a1.5 1.5 0 01-2.1-2.1l8.5-8.5",copy:"M9 9h10v12H9zM5 15V3h10",check:"M5 12l5 5L20 7"};function Q(r){let e=[],t=/\*\*([^*\n]+?)\*\*/g,n=0,i;for(;(i=t.exec(r))!==null;){i.index>n&&e.push(document.createTextNode(r.slice(n,i.index)));let a=document.createElement("strong");a.textContent=i[1],e.push(a),n=i.index+i[0].length}return n<r.length&&e.push(document.createTextNode(r.slice(n))),e}function B(r){let e=document.createDocumentFragment();return r.replace(/\r\n/g,`
`).split(/\n{2,}/).forEach(n=>{let i=document.createElement("p");n.split(`
`).forEach((d,l)=>{l>0&&i.append(document.createElement("br")),i.append(...Q(d))}),e.append(i)}),e}function H(r){if(!r)return"";let e=new Date(r);if(Number.isNaN(e.getTime()))return"";let t=e.toDateString()===new Date().toDateString(),n=e.toLocaleTimeString(void 0,{hour:"numeric",minute:"2-digit"});return t?n:`${e.toLocaleDateString(void 0,{month:"short",day:"numeric"})} ${n}`}var $=`
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
  :host(:not([data-theme="light"])) { ${F()} }
}
:host([data-theme="dark"]) { ${F()} }

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
.footer-row { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; padding: 0 4px; font-size: 11px; color: rgb(var(--ua-text-muted)); }
.link { color: rgb(var(--ua-text-secondary)); text-decoration: underline; text-underline-offset: 2px; font-size: 11px; }
.link:hover { color: rgb(var(--ua-text)); }
.upload-name { font-size: 11px; color: rgb(var(--ua-text-secondary)); }
`;function F(){return`
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
  --ua-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.4);`}var o={launcher:"Assistant",title:"Assistant",subtitle:"Does tasks for you. For questions about how something works, use Help.",intro:"I'm your assistant. Tell me what to do and I'll do it: add people, set up facilities, fill a shift, change a setting. If you want to know how something works, Help is the place to ask.",placeholder:"Tell me what to do",send:"Send",attach:"Add a file",close:"Close",working:"Working on it",askHelpInstead:"Ask Help instead",handoff:"I can't do that one, but Help can take it from here.",askHelp:"Ask Help",noHelp:"Open Help from the menu to send this.",copy:"Copy",copied:"Copied",undo:"Undo",undone:"Undone",undoing:"Undoing",reviewTitle:"Review these changes",reviewHint:"Take out anything you do not want, then approve.",needsCare:"Needs extra care. Check to include it.",approve:"Approve and apply",setAside:"Set aside",remove:"Remove",applying:"Applying your changes.",setAsideDone:"Set aside. Ask me when you want to pick it up again.",nothingLeft:"Nothing left to apply.",fileAdded:"I added a file",uploading:"Adding your file",enterHint:"Enter to send, Shift+Enter for a new line"},U="unified-assistant:open",y="data-unified-assistant",q="unified-assistant-dock";function ee(){if(document.getElementById(q))return;let r=document.createElement("style");r.id=q,r.textContent=`html[${y}="docked"] body { padding-bottom: 150px; }
@media (max-width: 639px) { html[${y}="docked"] body { padding-bottom: 120px; } }`,document.head.append(r)}var te=["drafted","refined","proposed","draft","pending"],ne=["rejected","set_aside"],ie="unified-assistant:action";function j(r){return(r!=null?r:[]).filter(e=>typeof e.summary=="string"&&e.summary.trim()!=="")}function W(r,e){let t={actions:r};e!==void 0&&(t.undone=e),window.dispatchEvent(new CustomEvent(ie,{detail:t}))}var I=class{constructor(e){this.messages=[];this.pendingReview=null;this.open=!1;this.busy=!1;this.booted=!1;this.waitingRow=null;this.lastFocus=null;this.lifted=!1;var t;this.api=new b(e),this.setupApp=(t=e.setup_app)!=null?t:null,this.host=document.createElement("unified-assistant"),this.root=this.host.attachShadow({mode:"open"}),this.syncTheme(),this.build(),this.avoidance=new w(this.host,this.launcher,n=>{this.lifted=n,this.syncDock()})}async boot(){var n,i,a;let e;try{e=await this.api.bootstrap()}catch{return}if(!e||e.enabled===!1)return;this.messages=((i=(n=e.conversation)==null?void 0:n.messages)!=null?i:[]).slice(),this.pendingReview=(a=e.review)!=null?a:null,this.booted=!0,document.body.append(this.host),this.renderMessages(),this.launcher.hidden=!1,this.syncDock(),this.avoidance.start(),e.notice&&this.list.append(s("div",{class:"notice"},e.notice));let t=!1;try{t=sessionStorage.getItem(U)==="1"}catch{t=!1}t&&this.setOpen(!0,!1),window.addEventListener("pagehide",()=>A(!1))}openDrawer(){this.booted&&this.setOpen(!0)}closeDrawer(){this.setOpen(!1)}ask(e){this.booted&&(this.setOpen(!0),this.send(e))}setSetupApp(e){this.setupApp=e}build(){let e=document.createElement("style");e.textContent=$,this.launcher=s("button",{class:"launcher",type:"button",hidden:!0,"aria-label":o.launcher,title:o.launcher,"aria-haspopup":"dialog","aria-expanded":"false",onclick:()=>this.setOpen(!0)},g(f.sparkles,20),s("span",{class:"tip","aria-hidden":"true"},o.launcher)),this.scrim=s("div",{class:"scrim",onclick:()=>this.setOpen(!1)}),this.list=s("div",{class:"list",role:"log","aria-live":"polite","aria-relevant":"additions"}),this.input=s("textarea",{rows:"1",placeholder:o.placeholder,"aria-label":o.placeholder,onkeydown:i=>this.onComposerKey(i),oninput:()=>this.autosize()}),this.sendButton=s("button",{class:"icon-btn send",type:"button","aria-label":o.send,onclick:()=>void this.send()},g(f.send)),this.fileInput=s("input",{type:"file",class:"sr",tabindex:"-1","aria-hidden":"true",onchange:()=>void this.onFilePicked()}),this.attachButton=s("button",{class:"icon-btn",type:"button","aria-label":o.attach,title:o.attach,onclick:()=>this.fileInput.click()},g(f.paperclip));let t=s("header",{class:"header"},s("div",{class:"title-wrap"},s("h2",{id:"ua-title"},g(f.sparkles,16),o.title),s("div",{class:"subtitle"},o.subtitle)),s("button",{class:"icon-btn",type:"button","aria-label":o.close,onclick:()=>this.setOpen(!1)},g(f.close))),n=s("footer",{class:"footer"},s("div",{class:"composer"},this.attachButton,this.input,this.sendButton),s("div",{class:"footer-row"},s("span",{},o.enterHint),s("button",{class:"link",type:"button",onclick:()=>this.askHelpInstead()},o.askHelpInstead)),this.fileInput);this.drawer=s("section",{class:"drawer",role:"dialog","aria-modal":"true","aria-labelledby":"ua-title","aria-hidden":"true"},t,this.list,n),this.drawer.addEventListener("keydown",i=>{i.key==="Escape"&&this.setOpen(!1)}),this.root.append(e,this.launcher,this.scrim,this.drawer)}setOpen(e,t=!0,n=!0){if(e!==this.open){if(this.open=e,this.drawer.classList.toggle("open",e),this.scrim.classList.toggle("open",e),this.drawer.setAttribute("aria-hidden",e?"false":"true"),this.launcher.setAttribute("aria-expanded",e?"true":"false"),this.launcher.hidden=e,this.syncDock(),n&&A(e),this.booted&&this.avoidance.setActive(!e),t)try{sessionStorage.setItem(U,e?"1":"0")}catch{}e?(this.lastFocus=document.activeElement,this.scrollToEnd(),window.setTimeout(()=>this.input.focus(),50)):this.lastFocus instanceof HTMLElement&&this.lastFocus.isConnected?this.lastFocus.focus():this.launcher.focus()}}renderMessages(){this.list.replaceChildren(),this.messages.length===0&&this.list.append(this.renderTurn({id:"intro",role:"assistant",content:o.intro}));for(let e of this.messages)this.list.append(this.renderTurn(e));this.pendingReview&&!this.messages.some(e=>{var t,n;return((t=e.review)==null?void 0:t.id)===((n=this.pendingReview)==null?void 0:n.id)})&&this.list.append(s("div",{class:"turn assistant"},this.renderReview(this.pendingReview))),this.scrollToEnd()}renderTurn(e){let t=s("div",{class:`turn ${e.role}`,"data-id":String(e.id)});if(e.role==="assistant"&&e.activity&&e.activity.length>0&&t.append(s("div",{class:"activity"},...e.activity.map(a=>s("div",{},a)))),e.content){let a=s("div",{class:`bubble${e.tone?` ${e.tone}`:""}`});a.append(B(e.content)),t.append(a)}let n=j(e.actions);n.length>0&&t.append(s("div",{class:"chips"},...n.map(a=>this.renderChip(a)))),e.review&&t.append(this.renderReview(e.review)),e.handoff&&t.append(this.renderHandoff(e.handoff));let i=H(e.at);return i&&t.append(s("div",{class:"time"},i)),t}renderChip(e){let t=!!e.undone_at,n=s("div",{class:`chip${t?" undone":""}`}),i=s("span",{class:"mark"},g(f.check,16)),a=[e.app,H(e.at)].filter(Boolean).join(" \xB7 "),d=s("div",{class:"text"},s("div",{},e.summary),a?s("div",{class:"meta"},a):null);if(n.append(i,d),t)n.append(s("span",{class:"status"},o.undone));else if(e.undoable){let l=s("button",{class:"btn ghost sm",type:"button"},o.undo);l.addEventListener("click",()=>void this.undo(e,n,l)),n.append(l)}return n}async undo(e,t,n){var i;n.disabled=!0,n.textContent=o.undoing;try{let a=await this.api.undo(e.id);if(a.ok===!1||a.error)throw new m(200,a.error||a.message||"That did not go through.");e.undone_at=(i=a.undone_at)!=null?i:new Date().toISOString(),t.classList.add("undone"),n.replaceWith(s("span",{class:"status"},o.undone)),W([e],e.id)}catch(a){n.disabled=!1,n.textContent=o.undo,this.appendNotice(this.errorText(a))}}renderHandoff(e){let t=s("div",{class:"card"},s("div",{class:"card-title"},o.handoff));if(T())t.append(s("div",{class:"quote"},e.question),s("div",{class:"row-actions"},s("button",{class:"btn primary",type:"button",onclick:()=>this.handOffToHelp(e.question)},o.askHelp)));else{let n=s("button",{class:"btn secondary sm",type:"button"},g(f.copy,14),o.copy);n.addEventListener("click",()=>{var i;(i=navigator.clipboard)==null||i.writeText(e.question).then(()=>{n.replaceChildren(g(f.check,14),o.copied)})}),t.append(s("div",{class:"quote"},e.question),s("div",{class:"row-actions"},n,s("span",{class:"hint"},o.noHelp)))}return t}renderReview(e){let t=s("div",{class:"card review","data-plan":e.id}),n=!te.includes(e.status);t.append(s("div",{class:"card-title"},o.reviewTitle),s("div",{class:"hint"},o.reviewHint)),e.changes.length===0&&t.append(s("div",{class:"hint"},o.nothingLeft));let i=s("div",{class:"changes"}),a=new Map;for(let d of e.changes)i.append(this.renderChange(e,d,a,n,t));if(t.append(i),n)t.classList.add("done"),t.append(s("div",{class:"hint"},ne.includes(e.status)?o.setAsideDone:o.applying));else{let d=s("button",{class:"btn primary",type:"button",disabled:e.changes.length===0},o.approve),l=s("button",{class:"btn secondary",type:"button"},o.setAside);d.addEventListener("click",()=>{let u=[...a.entries()].filter(([,c])=>c.checked).map(([c])=>c);this.reviewAction(e,"approve",{plan_hash:e.plan_hash,confirmed_danger:u},t,o.applying)}),l.addEventListener("click",()=>{this.reviewAction(e,"set-aside",{},t,o.setAsideDone)}),t.append(s("div",{class:"row-actions"},d,l))}return t}renderChange(e,t,n,i,a){let d=s("div",{class:"body"},s("div",{class:"what"},t.title)),l=t.from?`${t.from} to ${t.to}`:t.to;if(l&&d.append(s("div",{class:"delta"},l)),t.why&&d.append(s("div",{class:"why"},t.why)),(t.needs_confirmation||t.risk==="high")&&!i){let p=s("input",{type:"checkbox",id:`ua-care-${e.id}-${t.index}`});n.set(t.index,p),d.append(s("label",{class:"care",for:p.id},p,o.needsCare))}let c=s("div",{class:"change"},d);if(!i){let p=s("button",{class:"icon-btn remove",type:"button","aria-label":`${o.remove}: ${t.title}`,title:o.remove},g(f.close,14));p.addEventListener("click",()=>{this.reviewAction(e,"remove",{plan_hash:e.plan_hash,index:t.index},a,null)}),c.append(p)}return c}async reviewAction(e,t,n,i,a){i.querySelectorAll("button").forEach(d=>d.disabled=!0);try{let d=await this.api.review(e.id,t,n);if(d.error)throw new m(200,d.error);if(d.review){let l=d.review;this.pendingReview=l,this.messages.forEach(u=>{var c;((c=u.review)==null?void 0:c.id)===l.id&&(u.review=l)}),i.replaceWith(this.renderReview(l))}else this.pendingReview=null,this.messages.forEach(l=>{var u;((u=l.review)==null?void 0:u.id)===e.id&&(l.review={...e,status:t==="set-aside"?"set_aside":"approved"})}),i.classList.add("done"),i.querySelectorAll(".row-actions, .remove, .care").forEach(l=>l.remove()),i.append(s("div",{class:"hint"},a!=null?a:o.applying));d.reply&&this.push({id:`r-${Date.now()}`,role:"assistant",content:d.reply,at:new Date().toISOString()}),d.notice&&this.appendNotice(d.notice)}catch(d){i.querySelectorAll("button").forEach(l=>l.disabled=!1),this.appendNotice(this.errorText(d))}}onComposerKey(e){e.key==="Enter"&&!e.shiftKey&&!e.isComposing&&(e.preventDefault(),this.send())}autosize(){this.input.style.height="auto",this.input.style.height=`${Math.min(this.input.scrollHeight,140)}px`}async send(e){let t=(e!=null?e:this.input.value).trim();if(!(!t||this.busy)){e===void 0&&(this.input.value="",this.autosize()),this.push({id:`u-${Date.now()}`,role:"user",content:t,at:new Date().toISOString()}),this.setBusy(!0);try{let n=await this.api.send(t,this.setupApp);this.acceptTurn(n)}catch(n){this.push({id:`e-${Date.now()}`,role:"assistant",content:this.errorText(n),tone:"error",at:new Date().toISOString()})}finally{this.setBusy(!1)}}}acceptTurn(e){var i,a,d,l,u,c;let t=j(e.actions),n={id:(i=e.message_id)!=null?i:`a-${Date.now()}`,role:"assistant",content:(a=e.reply)!=null?a:"",at:new Date().toISOString(),activity:(d=e.activity)!=null?d:null,actions:t,review:(l=e.review)!=null?l:null,handoff:(u=e.handoff)!=null?u:null};e.error&&(n.content=n.content||e.error,n.tone="error"),e.review&&(this.pendingReview=e.review),(n.content||(c=n.actions)!=null&&c.length||n.review||n.handoff)&&this.push(n),e.notice&&this.appendNotice(e.notice),t.length>0&&W(t)}async onFilePicked(){var t;let e=(t=this.fileInput.files)==null?void 0:t[0];if(this.fileInput.value="",!(!e||this.busy)){this.setBusy(!0,o.uploading);try{let n=await this.api.upload(e);if(n.ok===!1||n.error)throw new m(200,n.error||"That file did not go through.");this.setBusy(!1),await this.send(`${o.fileAdded}: ${e.name}`)}catch(n){this.setBusy(!1),this.push({id:`e-${Date.now()}`,role:"assistant",content:this.errorText(n),tone:"error",at:new Date().toISOString()})}}}askHelpInstead(){this.handOffToHelp("")||this.appendNotice(o.noHelp)}handOffToHelp(e){return T()?(this.avoidance.hold(),this.setOpen(!1,!0,!1),O(e)):!1}syncDock(){let e=this.booted&&!this.open,t=document.documentElement;e?(t.setAttribute(y,this.lifted?"lifted":"docked"),ee()):t.removeAttribute(y)}push(e){var t;this.messages.push(e),this.messages.length===1&&((t=this.list.querySelector('[data-id="intro"]'))==null||t.remove()),this.list.append(this.renderTurn(e)),this.scrollToEnd()}setBusy(e,t=o.working){var n;this.busy=e,this.sendButton.disabled=e,this.attachButton.disabled=e,(n=this.waitingRow)==null||n.remove(),this.waitingRow=null,e&&(this.waitingRow=s("div",{class:"turn assistant"},s("div",{class:"waiting"},s("span",{class:"dots"},s("span"),s("span"),s("span")),t)),this.list.append(this.waitingRow),this.scrollToEnd())}appendNotice(e){this.list.append(s("div",{class:"notice"},e)),this.scrollToEnd()}errorText(e){return e instanceof m?e.message:"That did not go through. Try again."}scrollToEnd(){window.requestAnimationFrame(()=>{this.list.scrollTop=this.list.scrollHeight})}syncTheme(){let e=()=>{let t=document.documentElement,n=t.classList.contains("dark")||t.dataset.theme==="dark",i=t.classList.contains("light")||t.dataset.theme==="light";this.host.dataset.theme=n?"dark":i?"light":"",this.host.dataset.theme||delete this.host.dataset.theme};e(),new MutationObserver(e).observe(document.documentElement,{attributes:!0,attributeFilter:["class","data-theme"]})}};function se(){var t;let r=(t=document.currentScript)!=null?t:document.querySelector("script[data-unified-assistant]"),e=r==null?void 0:r.dataset.unifiedAssistant;if(!e)return null;try{return JSON.parse(e)}catch{return null}}(function(){if(window.UnifiedAssistant)return;let e=se();if(!e)return;let t=new I(e);window.UnifiedAssistant={open:()=>t.openDrawer(),close:()=>t.closeDrawer(),ask:i=>t.ask(i),setSetupApp:i=>t.setSetupApp(i)};let n=()=>void t.boot();document.readyState==="loading"?document.addEventListener("DOMContentLoaded",n,{once:!0}):n()})();})();
