"use strict";(()=>{var f=class extends Error{constructor(t,i){super(i);this.status=t}},N="The assistant is not reachable right now. Try again in a minute.",b=class{constructor(e){this.settings=e}async bootstrap(e=null){let t=e===null?this.settings.bootstrap:`${this.settings.bootstrap}${this.settings.bootstrap.includes("?")?"&":"?"}conversation=${encodeURIComponent(String(e))}`,i=await this.request("GET",t);return i.status===204?null:await this.read(i)}async send(e,t,i=null){let n={message:e,page:{url:location.href,title:document.title}};return t&&(n.setup_app=t),i!==null&&(n.conversation_id=i),await this.json("POST",this.settings.messages,n)}async upload(e,t){let i=new FormData;i.append("file",e,e.name),i.append("page[url]",location.href),i.append("page[title]",document.title),t&&i.append("setup_app",t);let n=await this.request("POST",this.settings.uploads,i);return await this.read(n)}async undo(e){return await this.json("POST",this.settings.undo.replace("{action}",String(e)),{})}async review(e,t,i){let n=this.settings.review.replace("{plan}",encodeURIComponent(e)).replace("{verb}",t);return await this.json("POST",n,i)}canRecordFeedback(){return!!(this.settings.feedback&&this.settings.feedback_follow_up)}async feedback(e,t,i){if(!this.settings.feedback)return null;let n={conversation_id:e,helpful:i};return t!==null&&typeof t=="number"&&(n.message_id=t),await this.json("POST",this.settings.feedback,n)}async feedbackFollowUp(e,t){return this.settings.feedback_follow_up?await this.json("POST",this.settings.feedback_follow_up.replace("{feedback}",String(e)),{follow_up:t}):null}async clear(){return await this.json("DELETE",this.settings.conversation,{})}async json(e,t,i){let n=await this.request(e,t,JSON.stringify(i),"application/json");return this.read(n)}async request(e,t,i,n){let r={Accept:"application/json","X-Requested-With":"XMLHttpRequest"};this.settings.csrf&&(r["X-CSRF-TOKEN"]=this.settings.csrf),n&&(r["Content-Type"]=n);try{return await fetch(t,{method:e,headers:r,body:i,credentials:"same-origin"})}catch{throw new f(0,N)}}async read(e){let t=null;try{t=await e.json()}catch{t=null}if(e.status===204)return{};if(!e.ok){let i=t!=null?t:{},n=typeof i.error=="string"&&i.error||typeof i.message=="string"&&i.message||(e.status===419||e.status===401?"Your session has expired. Refresh the page and try again.":e.status>=500?N:"That did not go through. Try again.");throw new f(e.status,n)}return t!=null?t:{}}};function m(){let a=window.Intercom;return typeof a=="function"?a:null}function T(){return m()!==null}function E(a){S({hide_default_launcher:a})}function S(a){var e;(e=m())==null||e("update",a)}function A(){let a=window.intercomSettings;return a&&typeof a=="object"?a:{}}function D(a){var e;(e=m())==null||e("onHide",a)}function F(a){let e=m();if(!e)return!1;if(e("update",{hide_default_launcher:!1}),a==="")return e("showNewMessage",""),!0;let t=!1,i=()=>{t||(t=!0,e("showNewMessage",a))};return e("onShow",i),e("showNewMessage",a),window.setTimeout(i,900),!0}var z=60,L=20,Z=12,ee=160,te=40,$=200,U=.4,ie=150,ne=150,R=250,se='#intercom-container, .intercom-lightweight-app, [id^="intercom-"], [class*="intercom-"]',x="data-unified-assistant-scroller",w="--ua-scroller-padding";function q(a){return a.closest(se)?!0:a instanceof HTMLIFrameElement&&a.src.includes("intercom")}function re(a){let e=getComputedStyle(a).position;return e==="fixed"||e==="sticky"}function K(a){let e=getComputedStyle(a).overflowY;return e==="auto"||e==="scroll"}function ae(){return[document.documentElement,document.body].every(a=>{let e=getComputedStyle(a).overflowY;return e!=="hidden"&&e!=="clip"})}var y=class{constructor(e,t){this.host=e;this.onLift=t;this.lifted=!1;this.scroller=null;this.active=!1;this.held=!1;this.timers={resize:null,scroll:null,mutation:null};this.observer=null;let i=A(),n=Number(i.vertical_padding);this.basePadding=Number.isFinite(n)&&n>=L?n:L,this.padding=this.basePadding}start(){this.active=!0,window.addEventListener("resize",()=>this.schedule("resize",ie),{passive:!0}),window.addEventListener("scroll",()=>this.schedule("scroll",ne),{passive:!0,capture:!0}),this.observer=new MutationObserver(e=>{e.some(t=>this.isForeignMutation(t))&&this.schedule("mutation",R)}),this.observer.observe(document.body,{childList:!0,subtree:!0,attributes:!0,attributeFilter:["class","style","hidden"]}),D(()=>{this.held=!1,this.schedule("mutation",R)}),this.measure(),document.readyState!=="complete"&&window.addEventListener("load",()=>this.schedule("mutation",R),{once:!0})}setActive(e){this.active=e,e?this.measure():this.cancelAll()}hold(){this.held=!0,this.cancelAll()}measure(){if(!this.active||this.held)return;let e=document.documentElement.clientWidth,t=window.innerHeight,i=this.findBarTop(e,t),n=this.basePadding;if(i!==null){let r=Math.max(this.basePadding,Math.round(t-i+Z));r<=t*U&&(n=r)}this.apply(n),this.markScroller(e,t)}markScroller(e,t){let i=this.findScroller(e,t);if(i===this.scroller)return;this.scroller&&(this.scroller.removeAttribute(x),this.scroller.style.removeProperty(w)),this.scroller=i;let n=parseFloat(getComputedStyle(i).paddingBottom);i.style.setProperty(w,`${Number.isFinite(n)?n:0}px`),i.setAttribute(x,"")}findScroller(e,t){if(ae())return document.body;for(let[n,r]of this.probePoints(e,t))for(let d of document.elementsFromPoint(n,r)){if(!this.isForeign(d))continue;let l=null,u=d;for(;u&&u!==document.body;)u instanceof HTMLElement&&K(u)&&(l=u),u=u.parentElement;if(l)return l}let i=document.querySelector("main");return i instanceof HTMLElement&&K(i)?i:document.body}apply(e){e!==this.padding&&(this.padding=e,S({vertical_padding:e}));let t=e-this.basePadding;this.host.style.setProperty("--ua-lift",`${t}px`);let i=t>0;i!==this.lifted&&(this.lifted=i,this.onLift(i))}probePoints(e,t){let i=A(),n=Number(i.horizontal_padding),r=Number.isFinite(n)&&n>=0?n:L,d=i.alignment==="left",l=[r,r+z/2,r+z].map(c=>d?c:e-c),u=[];for(let c of l)for(let p=1;p<=ee;p+=te){let v=t-p;c>=0&&v>=0&&c<e&&v<t&&u.push([c,v])}return u}findBarTop(e,t){let i=new Set,n=null;for(let[r,d]of this.probePoints(e,t))for(let l of document.elementsFromPoint(r,d)){if(i.has(l)||(i.add(l),!this.isForeign(l)))continue;let u=this.pinnedBarRect(l,t);u&&(n===null||u.top<n)&&(n=u.top)}return n}pinnedBarRect(e,t){let i=[],n=e;for(;n&&n!==document.body&&n!==document.documentElement;){if(i.push(n),re(n)){for(let d=i.length-1;d>=0;d--){let l=i[d].getBoundingClientRect();if(l.height>0&&l.bottom>=t-$&&l.top<t&&l.height<=t*U)return l}let r=n.getBoundingClientRect();return r.height>0&&r.bottom>=t-$?r:null}n=n.parentElement}return null}isForeignMutation(e){return e.type==="childList"?[...e.addedNodes,...e.removedNodes].some(i=>i instanceof Element&&i!==this.host&&!q(i))||this.isForeign(e.target):this.isForeign(e.target)}isForeign(e){let t=e instanceof Element?e:e.parentElement;return!t||t===document.documentElement||t===document.body||t===this.host||this.host.contains(t)?!1:!q(t)}schedule(e,t){if(!this.active||this.held)return;let i=this.timers[e];i!==null&&window.clearTimeout(i),this.timers[e]=window.setTimeout(()=>{this.timers[e]=null,this.measure()},t)}cancelAll(){for(let e of Object.keys(this.timers)){let t=this.timers[e];t!==null&&(window.clearTimeout(t),this.timers[e]=null)}}};function s(a,e={},...t){let i=document.createElement(a);for(let[n,r]of Object.entries(e))r==null||r===!1||(typeof r=="function"?i.addEventListener(n.replace(/^on/,"").toLowerCase(),r):r===!0?i.setAttribute(n,""):n==="class"?i.className=r:i.setAttribute(n,r));for(let n of t)n==null||n===!1||i.append(typeof n=="string"?document.createTextNode(n):n);return i}function h(a,e=16){let t="http://www.w3.org/2000/svg",i=document.createElementNS(t,"svg");i.setAttribute("viewBox","0 0 24 24"),i.setAttribute("width",String(e)),i.setAttribute("height",String(e)),i.setAttribute("fill","none"),i.setAttribute("stroke","currentColor"),i.setAttribute("stroke-width","1.8"),i.setAttribute("stroke-linecap","round"),i.setAttribute("stroke-linejoin","round"),i.setAttribute("aria-hidden","true");let n=document.createElementNS(t,"path");return n.setAttribute("d",a),i.append(n),i}var g={sparkles:"M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3zM5 18l.7 1.8L7.5 20.5l-1.8.7L5 23l-.7-1.8-1.8-.7 1.8-.7L5 18zM19 2l.6 1.4L21 4l-1.4.6L19 6l-.6-1.4L17 4l1.4-.6L19 2z",close:"M6 6l12 12M18 6L6 18",send:"M5 12h14M13 6l6 6-6 6",paperclip:"M21 12.5l-8.5 8.5a5.5 5.5 0 01-7.8-7.8l9.2-9.2a3.5 3.5 0 015 5l-9.2 9.2a1.5 1.5 0 01-2.1-2.1l8.5-8.5",copy:"M9 9h10v12H9zM5 15V3h10",check:"M5 12l5 5L20 7",clock:"M12 7v5l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z",plus:"M12 5v14M5 12h14"};function oe(a){let e=[],t=/\*\*([^*\n]+?)\*\*/g,i=0,n;for(;(n=t.exec(a))!==null;){n.index>i&&e.push(document.createTextNode(a.slice(i,n.index)));let r=document.createElement("strong");r.textContent=n[1],e.push(r),i=n.index+n[0].length}return i<a.length&&e.push(document.createTextNode(a.slice(i))),e}function C(a){let e=document.createDocumentFragment();return a.replace(/\r\n/g,`
`).split(/\n{2,}/).forEach(i=>{let n=document.createElement("p");i.split(`
`).forEach((d,l)=>{l>0&&n.append(document.createElement("br")),n.append(...oe(d))}),e.append(n)}),e}function j(a){if(!a)return"";let e=new Date(a);if(Number.isNaN(e.getTime()))return"";let t=new Date,i=d=>new Date(d.getFullYear(),d.getMonth(),d.getDate()).getTime(),n=Math.round((i(t)-i(e))/864e5);if(n===0)return"Today";if(n===1)return"Yesterday";let r={month:"short",day:"numeric"};return e.getFullYear()!==t.getFullYear()&&(r.year="numeric"),e.toLocaleDateString(void 0,r)}function M(a){if(!a)return"";let e=new Date(a);if(Number.isNaN(e.getTime()))return"";let t=e.toDateString()===new Date().toDateString(),i=e.toLocaleTimeString(void 0,{hour:"numeric",minute:"2-digit"});return t?i:`${e.toLocaleDateString(void 0,{month:"short",day:"numeric"})} ${i}`}var Y=`
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
  :host(:not([data-theme="light"])) { ${W()} }
}
:host([data-theme="dark"]) { ${W()} }

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
/* Unseen first-run badge on the launcher */
.launcher .badge {
  position: absolute; top: -4px; right: -4px;
  min-width: 18px; height: 18px; padding: 0 5px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 9999px;
  background: rgb(var(--ua-primary)); color: rgb(var(--ua-on-accent));
  font-size: 11px; font-weight: 600; line-height: 1;
  box-shadow: 0 0 0 2px rgb(var(--ua-bg-base));
}
.launcher .badge[hidden] { display: none; }

/* First-run callout: a speech bubble above the launcher column, arrow at the launcher */
.callout {
  position: fixed; right: 20px; bottom: calc(92px + 44px + 14px + var(--ua-lift, 0px)); z-index: 2147483000;
  width: 300px; max-width: calc(100vw - 32px);
  padding: 14px 14px 12px;
  border: 1px solid rgb(var(--ua-border)); border-radius: var(--ua-radius-lg);
  background: rgb(var(--ua-surface-raised)); color: rgb(var(--ua-text));
  box-shadow: var(--ua-shadow);
  opacity: 0; transform: translateY(8px); pointer-events: none;
  transition: opacity var(--ua-base) var(--ua-ease), transform var(--ua-base) var(--ua-ease);
}
.callout.in { opacity: 1; transform: none; pointer-events: auto; }
.callout[hidden] { display: none; }
.callout::after, .callout::before {
  content: ""; position: absolute; top: 100%; right: 14px;
  border: 8px solid transparent; border-top-color: rgb(var(--ua-surface-raised)); border-bottom: 0;
}
.callout::before { border-top-color: rgb(var(--ua-border)); margin-top: 1px; }
.callout-head { display: flex; align-items: flex-start; gap: 8px; }
.callout-title { flex: 1; min-width: 0; font-size: 14px; font-weight: 600; line-height: 1.35; padding-top: 4px; }
.callout .dismiss { flex: none; width: 28px; height: 28px; margin: -4px -6px 0 0; }
.callout-body { margin-top: 6px; font-size: 13px; line-height: 1.45; color: rgb(var(--ua-text-secondary)); }
.callout-actions { margin-top: 12px; display: flex; }
@media (max-width: 639px) {
  .callout { right: 16px; left: 16px; width: auto; bottom: calc(80px + 40px + 14px + var(--ua-lift, 0px)); }
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
  .drawer, .scrim, .launcher, .callout, .dots span { transition: none !important; animation: none !important; }
  .callout { transform: none; }
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
/* Walkthrough bubbles: assistant look, a small label above, the step's pills under */
.turn.tour .bubble { color: rgb(var(--ua-text-secondary)); }
.tour-label {
  display: inline-flex; align-items: center; gap: 4px; padding-left: 4px;
  font-size: 11px; font-weight: 500; color: rgb(var(--ua-text-muted));
}
.tour-label svg { color: rgb(var(--ua-primary)); }
.tour-pills { display: flex; flex-wrap: wrap; gap: 8px; max-width: 88%; }
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
.feedback-card { gap: 8px; }
.feedback-card .question { font-weight: 500; color: rgb(var(--ua-text)); }
.feedback-card.answered { opacity: 0.85; }

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
`;function W(){return`
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
  --ua-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.4);`}var o={launcher:"Assistant",title:"Assistant",subtitle:"Does tasks for you. For questions about how something works, use Help.",intro:"I'm your assistant. Tell me what to do and I'll do it: add people, set up facilities, fill a shift, change a setting. If you want to know how something works, Help is the place to ask.",placeholder:"Tell me what to do",send:"Send",attach:"Add a file",close:"Close",working:"Working on it",askHelpInstead:"Ask Help instead",handoff:"I can't do that one, but Help can take it from here.",askHelp:"Ask Help",noHelp:"Open Help from the menu to send this.",copy:"Copy",copied:"Copied",undo:"Undo",undone:"Undone",undoing:"Undoing",reviewTitle:"Review these changes",reviewHint:"Take out anything you do not want, then approve.",needsCare:"Needs extra care. Check to include it.",approve:"Approve and apply",setAside:"Set aside",remove:"Remove",applying:"Applying your changes.",setAsideDone:"Set aside. Ask me when you want to pick it up again.",nothingLeft:"Nothing left to apply.",fileAdded:"I added a file",uploading:"Adding your file",enterHint:"Enter to send, Shift+Enter for a new line",conversations:"Conversations",newConversation:"New conversation",noConversations:"No previous conversations yet.",untitled:"Untitled conversation",reopened:"Reopened",thingsICanDo:"Things I can do",ideas:"Ideas",hideIdeas:"Hide ideas",introTitle:"Meet your Assistant",introBody:"Tell it what to do and it does it: add people, set up facilities, fill a shift, change a setting.",tryIt:"Try it",dismiss:"Dismiss",walkthrough:"Walkthrough",feedbackQuestion:"Did I handle that correctly?",yes:"Yes",no:"No",feedbackThanks:"Thanks for letting me know.",feedbackSorry:"Sorry about that. Our team will look at this conversation.",feedbackFollowUp:"Would you like someone from our team to follow up with you?",followUpYes:"Yes, please",followUpNo:"No thanks",feedbackWillContact:"Thanks. We'll be in touch.",feedbackNoted:"Thanks for letting us know."},le=4e3,k="unified-assistant:",H="open",O="intro-dismissed",de=1500,ue=600,Q="tour:",ce=[H,O];function pe(a){return typeof a.viewer=="string"&&a.viewer.trim()!==""?a.viewer.trim():null}function he(a,e){return e!==null?a.startsWith(`${e}:`):ce.includes(a)||a.startsWith(Q)}function ge(a){try{let e=[];for(let t=0;t<sessionStorage.length;t++){let i=sessionStorage.key(t);i!==null&&i.startsWith(k)&&!he(i.slice(k.length),a)&&e.push(i)}e.forEach(t=>sessionStorage.removeItem(t))}catch{}}function G(a){if(!a||!Array.isArray(a.steps))return null;let e=a.steps.filter(t=>!!t&&typeof t.text=="string"&&t.text.trim()!=="").map(t=>({text:t.text,kind:t.kind==="action"?"action":"question",suggestions:(Array.isArray(t.suggestions)?t.suggestions:[]).filter(i=>i&&typeof i.prompt=="string"&&i.prompt.trim()!=="")}));return e.length>0?{steps:e,closing:typeof a.closing=="string"?a.closing:""}:null}var _="data-unified-assistant",V="unified-assistant-dock";function fe(){if(document.getElementById(V))return;let a=document.createElement("style");a.id=V;let e=`html[${_}="docked"] [${x}]`,t=i=>`padding-bottom: calc(var(${w}, 0px) + ${i}px) !important;`;a.textContent=`${e} { ${t(150)} }
@media (max-width: 639px) { ${e} { ${t(120)} } }`,document.head.append(a)}var X=["drafted","refined","proposed","draft","pending"],ve=["rejected","set_aside"],I=["approved","executing","applied","completed"],be="unified-assistant:action";function P(a){return(a!=null?a:[]).filter(e=>typeof e.summary=="string"&&e.summary.trim()!=="")}function J(a,e){let t={actions:a};e!==void 0&&(t.undone=e),window.dispatchEvent(new CustomEvent(be,{detail:t}))}var B=class{constructor(e){this.messages=[];this.pendingReview=null;this.conversation=null;this.conversations=[];this.suggestions=[];this.ideasOpen=!1;this.suggestionsEl=null;this.panelOpen=!1;this.open=!1;this.busy=!1;this.booted=!1;this.waitingRow=null;this.lastFocus=null;this.lifted=!1;this.callout=null;this.introPending=!1;this.intro=null;this.introTimer=null;this.tour=null;this.viewer=null;this.tourActive=!1;this.tourStep=0;this.tourPillsEl=null;this.tourAdvanceTimer=null;this.askFeedback=!1;this.feedbackCard=null;var t;this.api=new b(e),this.setupApp=(t=e.setup_app)!=null?t:null,this.host=document.createElement("unified-assistant"),this.root=this.host.attachShadow({mode:"open"}),this.syncTheme(),this.build(),this.avoidance=new y(this.host,i=>{this.lifted=i,this.syncDock()})}async boot(){var i,n,r;let e;try{e=await this.api.bootstrap()}catch{return}if(!e||e.enabled===!1)return;this.booted=!0,this.viewer=pe(e),ge(this.viewer),this.askFeedback=((i=e.feedback)==null?void 0:i.ask)===!0&&!!this.api.canRecordFeedback(),this.tour=((n=e.intro)==null?void 0:n.show)===!0?G(e.tour):null,document.body.append(this.host),this.applyBootstrap(e),this.launcher.hidden=!1,this.syncDock(),this.avoidance.start();let t=!1;try{t=sessionStorage.getItem(this.storageKey(H))==="1"}catch{t=!1}((r=e.intro)==null?void 0:r.show)===!0&&this.offerIntro(e.intro),t&&this.setOpen(!0,!1),window.addEventListener("pagehide",()=>E(!1))}openDrawer(){this.booted&&this.setOpen(!0)}closeDrawer(){this.setOpen(!1)}ask(e){this.booted&&(this.setOpen(!0),this.send(e))}setSetupApp(e){this.setupApp=e}dismissIntro(){if(this.introTimer!==null&&(window.clearTimeout(this.introTimer),this.introTimer=null),this.badge.hidden=!0,this.hideCallout(),!!this.introPending){this.introPending=!1,this.intro=null;try{sessionStorage.setItem(this.storageKey(O),"1")}catch{}}}offerIntro(e){var i;let t=!1;try{t=sessionStorage.getItem(this.storageKey(O))==="1"}catch{t=!1}t||(this.intro=e,this.introPending=!0,this.badge.textContent=String((i=e.badge)!=null?i:1),this.badge.hidden=!1,this.introTimer=window.setTimeout(()=>{this.introTimer=null,this.introPending&&!this.open&&this.showCallout(e)},de))}showCallout(e){var t,i,n;this.hideCallout(),this.callout=s("div",{class:"callout",role:"region","aria-labelledby":"ua-callout-title"},s("div",{class:"callout-head"},s("div",{class:"callout-title",id:"ua-callout-title"},((t=e.title)==null?void 0:t.trim())||o.introTitle),s("button",{class:"icon-btn dismiss",type:"button","aria-label":o.dismiss,onclick:()=>this.dismissIntro()},h(g.close,16))),s("div",{class:"callout-body"},((i=e.body)==null?void 0:i.trim())||o.introBody),s("div",{class:"callout-actions"},s("button",{class:"btn primary",type:"button",onclick:()=>this.tryIt()},((n=e.cta)==null?void 0:n.trim())||o.tryIt))),this.root.append(this.callout),window.requestAnimationFrame(()=>{var r;return(r=this.callout)==null?void 0:r.classList.add("in")})}hideCallout(){var e;(e=this.callout)==null||e.remove(),this.callout=null}tryIt(){this.setOpen(!0),!(!this.tour||this.tourActive||this.busy)&&(this.tourActive=!0,this.tourStep=0,this.ideasOpen=!1,this.renderSuggestions(),this.renderTourStep(),this.saveTour())}storageKey(e){return this.viewer===null?`${k}${e}`:`${k}${this.viewer}:${e}`}tourKey(){return this.conversation?this.storageKey(`${Q}${String(this.conversation.id)}`):null}saveTour(){let e=this.tourKey();if(!(!e||!this.tour))try{sessionStorage.setItem(e,JSON.stringify({step:this.tourStep,tour:this.tour}))}catch{}}cancelTourAdvance(){this.tourAdvanceTimer!==null&&(window.clearTimeout(this.tourAdvanceTimer),this.tourAdvanceTimer=null)}clearStoredTour(){this.cancelTourAdvance();let e=this.tourKey();if(e)try{sessionStorage.removeItem(e)}catch{}}resumeTour(){this.tourActive=!1,this.tourPillsEl=null;let e=this.tourKey();if(!e)return;let t=null;try{let n=sessionStorage.getItem(e);t=n?JSON.parse(n):null}catch{t=null}let i=G(t==null?void 0:t.tour);!i||typeof(t==null?void 0:t.step)!="number"||t.step<0||t.step>=i.steps.length||(this.tour=i,this.tourActive=!0,this.tourStep=t.step,this.renderSuggestions(),this.renderTourStep())}renderTourStep(){if(!this.tour)return;let e=this.tour.steps[this.tourStep],t=e.suggestions.map(i=>{var r;let n=s("button",{class:"pill",type:"button"},((r=i.label)==null?void 0:r.trim())||i.prompt);return n.addEventListener("click",()=>{var d;(d=this.tourPillsEl)==null||d.remove(),this.tourPillsEl=null,this.send(i.prompt)}),n});this.tourPillsEl=t.length>0?s("div",{class:"tour-pills"},...t):null,this.list.append(this.renderTourBubble(e.text,this.tourPillsEl)),this.scrollToEnd()}renderTourBubble(e,t){let i=s("div",{class:"bubble"});return i.append(C(e)),s("div",{class:"turn assistant tour"},s("div",{class:"tour-label"},h(g.sparkles,12),o.walkthrough),i,t)}advanceTour(e){var d;if(!this.tourActive||!this.tour||this.tourAdvanceTimer!==null)return;let t=this.tour.steps[this.tourStep],i=this.pendingReview!==null&&X.includes(this.pendingReview.status),n=e.review!==null&&e.review!==void 0&&I.includes(e.review.status);(t.kind==="action"?P(e.actions).length>0||n:((d=e.turn_state)!=null?d:"complete")==="complete"&&!i)&&(this.tourAdvanceTimer=window.setTimeout(()=>{this.tourAdvanceTimer=null,this.nextTourStep()},ue))}nextTourStep(){var e;if(!(!this.tourActive||!this.tour)){if((e=this.tourPillsEl)==null||e.remove(),this.tourPillsEl=null,this.tourStep+=1,this.tourStep<this.tour.steps.length){this.renderTourStep(),this.saveTour();return}this.tour.closing.trim()!==""&&this.list.append(this.renderTourBubble(this.tour.closing,null)),this.endTour(),this.scrollToEnd()}}endTour(){var e;this.clearStoredTour(),this.cancelTourAdvance(),this.tourActive=!1,(e=this.tourPillsEl)==null||e.remove(),this.tourPillsEl=null,this.renderSuggestions()}build(){let e=document.createElement("style");e.textContent=Y,this.launcher=s("button",{class:"launcher",type:"button",hidden:!0,"aria-label":o.launcher,title:o.launcher,"aria-haspopup":"dialog","aria-expanded":"false",onclick:()=>this.setOpen(!0)},h(g.sparkles,20),s("span",{class:"tip","aria-hidden":"true"},o.launcher)),this.badge=s("span",{class:"badge",hidden:!0}),this.launcher.append(this.badge),this.scrim=s("div",{class:"scrim",onclick:()=>this.setOpen(!1)}),this.list=s("div",{class:"list",role:"log","aria-live":"polite","aria-relevant":"additions"}),this.input=s("textarea",{rows:"1",placeholder:o.placeholder,"aria-label":o.placeholder,onkeydown:r=>this.onComposerKey(r),oninput:()=>this.autosize()}),this.sendButton=s("button",{class:"icon-btn send",type:"button","aria-label":o.send,onclick:()=>void this.send()},h(g.send)),this.fileInput=s("input",{type:"file",class:"sr",tabindex:"-1","aria-hidden":"true",onchange:()=>void this.onFilePicked()}),this.attachButton=s("button",{class:"icon-btn",type:"button","aria-label":o.attach,title:o.attach,onclick:()=>this.fileInput.click()},h(g.paperclip)),this.historyButton=s("button",{class:"icon-btn history",type:"button","aria-label":o.conversations,title:o.conversations,"aria-expanded":"false",onclick:()=>this.setPanelOpen(!this.panelOpen)},h(g.clock));let t=s("header",{class:"header"},s("div",{class:"title-wrap"},s("h2",{id:"ua-title"},h(g.sparkles,16),o.title),s("div",{class:"subtitle"},o.subtitle)),this.historyButton,s("button",{class:"icon-btn",type:"button","aria-label":o.close,onclick:()=>this.setOpen(!1)},h(g.close)));this.reopenedBar=s("div",{class:"reopened",hidden:!0,role:"status"}),this.panelList=s("div",{class:"threads"}),this.panel=s("section",{class:"panel","aria-label":o.conversations,hidden:!0},s("div",{class:"panel-head"},s("div",{class:"panel-title"},o.conversations)),s("div",{class:"panel-body"},s("button",{class:"btn secondary new-conversation",type:"button",onclick:()=>void this.newConversation()},h(g.plus,14),o.newConversation),this.panelList));let i=s("footer",{class:"footer"},s("div",{class:"composer"},this.attachButton,this.input,this.sendButton),s("div",{class:"footer-row"},s("span",{},o.enterHint),s("span",{class:"footer-links"},s("button",{class:"link ideas",type:"button","aria-pressed":"false",onclick:()=>this.toggleIdeas()},o.ideas),s("button",{class:"link",type:"button",onclick:()=>this.askHelpInstead()},o.askHelpInstead))),this.fileInput),n=s("div",{class:"body"},this.reopenedBar,this.list,i,this.panel);this.drawer=s("section",{class:"drawer",role:"dialog","aria-modal":"true","aria-labelledby":"ua-title","aria-hidden":"true"},t,n),this.drawer.addEventListener("keydown",r=>{if(r.key==="Escape"){if(this.panelOpen){r.stopPropagation(),this.setPanelOpen(!1);return}this.setOpen(!1)}}),this.root.append(e,this.launcher,this.scrim,this.drawer)}setOpen(e,t=!0,i=!0){if(e!==this.open){if(this.open=e,e?this.hideCallout():this.introPending&&this.intro&&this.introTimer===null&&this.showCallout(this.intro),this.drawer.classList.toggle("open",e),this.scrim.classList.toggle("open",e),this.drawer.setAttribute("aria-hidden",e?"false":"true"),this.launcher.setAttribute("aria-expanded",e?"true":"false"),this.launcher.hidden=e,this.syncDock(),i&&E(e),this.booted&&this.avoidance.setActive(!e),t)try{sessionStorage.setItem(this.storageKey(H),e?"1":"0")}catch{}e?(this.lastFocus=document.activeElement,this.scrollToEnd(),window.setTimeout(()=>this.input.focus(),50)):this.lastFocus instanceof HTMLElement&&this.lastFocus.isConnected?this.lastFocus.focus():this.launcher.focus()}}renderMessages(){this.list.replaceChildren(),this.messages.length===0&&this.list.append(this.renderTurn({id:"intro",role:"assistant",content:o.intro}));for(let e of this.messages)this.list.append(this.renderTurn(e));this.pendingReview&&!this.messages.some(e=>{var t,i;return((t=e.review)==null?void 0:t.id)===((i=this.pendingReview)==null?void 0:i.id)})&&this.list.append(s("div",{class:"turn assistant"},this.renderReview(this.pendingReview))),this.renderSuggestions(),this.scrollToEnd()}applyBootstrap(e){var t,i,n,r,d,l;this.messages=((i=(t=e.conversation)==null?void 0:t.messages)!=null?i:[]).slice(),this.pendingReview=(n=e.review)!=null?n:null,this.conversation=(r=e.conversation)!=null?r:null,this.conversations=((d=e.conversations)!=null?d:[]).slice(),this.suggestions=((l=e.suggestions)!=null?l:[]).filter(u=>u&&typeof u.prompt=="string"&&u.prompt.trim()!==""),this.ideasOpen=!1,this.waitingRow=null,this.tourActive=!1,this.tourPillsEl=null,this.feedbackCard=null,this.renderMessages(),this.resumeTour(),this.renderThreads(),e.notice&&this.appendNotice(e.notice)}setPanelOpen(e){e!==this.panelOpen&&(this.panelOpen=e,this.panel.hidden=!e,this.historyButton.setAttribute("aria-expanded",e?"true":"false"),this.list.setAttribute("aria-hidden",e?"true":"false"),e?(this.renderThreads(),window.setTimeout(()=>{var t;return(t=this.panel.querySelector("button"))==null?void 0:t.focus()},30)):this.historyButton.focus())}renderThreads(){var i;let e=this.conversation?String(this.conversation.id):null,t=this.conversations.filter(n=>String(n.id)!==e);if(this.panelList.replaceChildren(),t.length===0){this.panelList.append(s("div",{class:"threads-empty"},o.noConversations));return}for(let n of t){let r=n.message_count===1?"1 message":`${n.message_count} messages`,d=[j(n.last_activity_at),r].filter(Boolean).join(" \xB7 "),l=s("button",{class:"thread",type:"button","data-conversation":String(n.id)},s("div",{class:"thread-title"},((i=n.title)==null?void 0:i.trim())||o.untitled),s("div",{class:"thread-meta"},d));l.addEventListener("click",()=>void this.openConversation(n)),this.panelList.append(l)}}async newConversation(){if(!this.busy){this.setBusy(!0);try{let e=await this.api.clear();this.setBusy(!1),this.clearStoredTour(),this.applyBootstrap(e),this.reopenedBar.hidden=!0,this.setPanelOpen(!1),this.input.focus()}catch(e){this.setBusy(!1),this.appendNotice(this.errorText(e))}}}async openConversation(e){var t,i,n;if(!this.busy){this.setBusy(!0);try{let r=await this.api.bootstrap(e.id);if(this.setBusy(!1),!r)return;this.applyBootstrap(r),this.reopenedBar.replaceChildren(h(g.clock,14),`${o.reopened}: ${((i=(t=r.conversation)==null?void 0:t.title)==null?void 0:i.trim())||((n=e.title)==null?void 0:n.trim())||o.untitled}`),this.reopenedBar.hidden=!1,this.setPanelOpen(!1)}catch(r){this.setBusy(!1),this.appendNotice(this.errorText(r))}}}toggleIdeas(){this.ideasOpen=!this.ideasOpen,this.renderSuggestions(),this.scrollToEnd()}renderSuggestions(){var n;(n=this.suggestionsEl)==null||n.remove(),this.suggestionsEl=null;let e=this.drawer.querySelector(".link.ideas"),t=this.suggestions.length>0&&!this.tourActive&&(this.ideasOpen||this.messages.length===0);if(e&&(e.hidden=this.suggestions.length===0||this.tourActive,e.setAttribute("aria-pressed",this.ideasOpen?"true":"false"),e.textContent=this.ideasOpen?o.hideIdeas:o.ideas),!t)return;let i=this.suggestions.map(r=>{var l;let d=s("button",{class:"pill",type:"button"},((l=r.label)==null?void 0:l.trim())||r.prompt);return d.addEventListener("click",()=>{this.ideasOpen=!1,this.send(r.prompt)}),d});this.suggestionsEl=s("div",{class:"suggestions"},s("div",{class:"suggestions-title"},o.thingsICanDo),s("div",{class:"pills"},...i)),this.list.append(this.suggestionsEl)}renderTurn(e){let t=s("div",{class:`turn ${e.role}`,"data-id":String(e.id)});if(e.role==="assistant"&&e.activity&&e.activity.length>0&&t.append(s("div",{class:"activity"},...e.activity.map(r=>s("div",{},r)))),e.content){let r=s("div",{class:`bubble${e.tone?` ${e.tone}`:""}`});r.append(C(e.content)),t.append(r)}let i=P(e.actions);i.length>0&&t.append(s("div",{class:"chips"},...i.map(r=>this.renderChip(r)))),e.review&&t.append(this.renderReview(e.review)),e.handoff&&t.append(this.renderHandoff(e.handoff));let n=M(e.at);return n&&t.append(s("div",{class:"time"},n)),t}renderChip(e){let t=!!e.undone_at,i=s("div",{class:`chip${t?" undone":""}`}),n=s("span",{class:"mark"},h(g.check,16)),r=[e.app,M(e.at)].filter(Boolean).join(" \xB7 "),d=s("div",{class:"text"},s("div",{},e.summary),r?s("div",{class:"meta"},r):null);if(i.append(n,d),t)i.append(s("span",{class:"status"},o.undone));else if(e.undoable){let l=s("button",{class:"btn ghost sm",type:"button"},o.undo);l.addEventListener("click",()=>void this.undo(e,i,l)),i.append(l)}return i}async undo(e,t,i){var n;i.disabled=!0,i.textContent=o.undoing;try{let r=await this.api.undo(e.id);if(r.ok===!1||r.error)throw new f(200,r.error||r.message||"That did not go through.");e.undone_at=(n=r.undone_at)!=null?n:new Date().toISOString(),t.classList.add("undone"),i.replaceWith(s("span",{class:"status"},o.undone)),J([e],e.id)}catch(r){i.disabled=!1,i.textContent=o.undo,this.appendNotice(this.errorText(r))}}renderHandoff(e){let t=s("div",{class:"card"},s("div",{class:"card-title"},o.handoff));if(T())t.append(s("div",{class:"quote"},e.question),s("div",{class:"row-actions"},s("button",{class:"btn primary",type:"button",onclick:()=>this.handOffToHelp(e.question)},o.askHelp)));else{let i=s("button",{class:"btn secondary sm",type:"button"},h(g.copy,14),o.copy);i.addEventListener("click",()=>{var n;(n=navigator.clipboard)==null||n.writeText(e.question).then(()=>{i.replaceChildren(h(g.check,14),o.copied)})}),t.append(s("div",{class:"quote"},e.question),s("div",{class:"row-actions"},i,s("span",{class:"hint"},o.noHelp)))}return t}renderReview(e){let t=s("div",{class:"card review","data-plan":e.id}),i=!X.includes(e.status);t.append(s("div",{class:"card-title"},o.reviewTitle),s("div",{class:"hint"},o.reviewHint)),e.changes.length===0&&t.append(s("div",{class:"hint"},o.nothingLeft));let n=s("div",{class:"changes"}),r=new Map;for(let d of e.changes)n.append(this.renderChange(e,d,r,i,t));if(t.append(n),i)t.classList.add("done"),t.append(s("div",{class:"hint"},ve.includes(e.status)?o.setAsideDone:o.applying));else{let d=s("button",{class:"btn primary",type:"button",disabled:e.changes.length===0},o.approve),l=s("button",{class:"btn secondary",type:"button"},o.setAside);d.addEventListener("click",()=>{let u=[...r.entries()].filter(([,c])=>c.checked).map(([c])=>c);this.reviewAction(e,"approve",{plan_hash:e.plan_hash,confirmed_danger:u},t,o.applying)}),l.addEventListener("click",()=>{this.reviewAction(e,"set-aside",{},t,o.setAsideDone)}),t.append(s("div",{class:"row-actions"},d,l))}return t}renderChange(e,t,i,n,r){let d=s("div",{class:"body"},s("div",{class:"what"},t.title)),l=t.from?`${t.from} to ${t.to}`:t.to;if(l&&d.append(s("div",{class:"delta"},l)),t.why&&d.append(s("div",{class:"why"},t.why)),(t.needs_confirmation||t.risk==="high")&&!n){let p=s("input",{type:"checkbox",id:`ua-care-${e.id}-${t.index}`});i.set(t.index,p),d.append(s("label",{class:"care",for:p.id},p,o.needsCare))}let c=s("div",{class:"change"},d);if(!n){let p=s("button",{class:"icon-btn remove",type:"button","aria-label":`${o.remove}: ${t.title}`,title:o.remove},h(g.close,14));p.addEventListener("click",()=>{this.reviewAction(e,"remove",{plan_hash:e.plan_hash,index:t.index},r,null)}),c.append(p)}return c}async reviewAction(e,t,i,n,r){var d;n.querySelectorAll("button").forEach(l=>l.disabled=!0);try{let l=await this.api.review(e.id,t,i);if(l.error)throw new f(200,l.error);if(l.review){let u=l.review;this.pendingReview=u,this.messages.forEach(c=>{var p;((p=c.review)==null?void 0:p.id)===u.id&&(c.review=u)}),n.replaceWith(this.renderReview(u))}else this.pendingReview=null,this.messages.forEach(u=>{var c;((c=u.review)==null?void 0:c.id)===e.id&&(u.review={...e,status:t==="set-aside"?"set_aside":"approved"})}),n.classList.add("done"),n.querySelectorAll(".row-actions, .remove, .care").forEach(u=>u.remove()),n.append(s("div",{class:"hint"},r!=null?r:o.applying));t==="approve"&&(this.advanceTour({review:(d=l.review)!=null?d:{...e,status:"approved"},turn_state:"complete"}),(!l.review||I.includes(l.review.status))&&this.offerFeedback(null)),l.reply&&this.push({id:`r-${Date.now()}`,role:"assistant",content:l.reply,at:new Date().toISOString()}),l.notice&&this.appendNotice(l.notice)}catch(l){n.querySelectorAll("button").forEach(u=>u.disabled=!1),this.appendNotice(this.errorText(l))}}onComposerKey(e){e.key==="Enter"&&!e.shiftKey&&!e.isComposing&&(e.preventDefault(),this.send())}autosize(){this.input.style.height="auto",this.input.style.height=`${Math.min(this.input.scrollHeight,140)}px`}async send(e){var i,n;let t=(e!=null?e:this.input.value).trim();if(!(!t||this.busy)){e===void 0&&(this.input.value="",this.autosize()),this.dropFeedbackCard(),this.push({id:`u-${Date.now()}`,role:"user",content:t,at:new Date().toISOString()}),this.dismissIntro(),this.setBusy(!0);try{let r=await this.api.send(t,this.setupApp,(n=(i=this.conversation)==null?void 0:i.id)!=null?n:null);this.acceptTurn(r),this.advanceTour(r)}catch(r){this.push({id:`e-${Date.now()}`,role:"assistant",content:this.errorText(r),tone:"error",at:new Date().toISOString()})}finally{this.setBusy(!1)}}}acceptTurn(e){var r,d,l,u,c,p,v;let t=P(e.actions),i={id:(r=e.message_id)!=null?r:`a-${Date.now()}`,role:"assistant",content:(d=e.reply)!=null?d:"",at:new Date().toISOString(),activity:(l=e.activity)!=null?l:null,actions:t,review:(u=e.review)!=null?u:null,handoff:(c=e.handoff)!=null?c:null};e.error&&(i.content=i.content||e.error,i.tone="error"),e.review&&(this.pendingReview=e.review),(i.content||(p=i.actions)!=null&&p.length||i.review||i.handoff)&&this.push(i),e.notice&&this.appendNotice(e.notice),t.length>0&&J(t);let n=e.review!==null&&e.review!==void 0&&I.includes(e.review.status);((v=e.turn_state)!=null?v:"complete")==="complete"&&(t.length>0||n)&&this.offerFeedback(typeof e.message_id=="number"?e.message_id:null)}offerFeedback(e){var d,l;let t=(l=(d=this.conversation)==null?void 0:d.id)!=null?l:null;if(!this.askFeedback||t===null)return;this.dropFeedbackCard();let i=s("div",{class:"card feedback-card"}),n=s("div",{class:"turn assistant feedback"},i);this.feedbackCard=n;let r=u=>{i.querySelectorAll("button").forEach(c=>c.disabled=!0),this.api.feedback(t,e,u).then(c=>{if(!c||c.ok===!1){this.dropFeedbackCard();return}if(u){this.showFeedbackClosing(i,o.feedbackThanks,!0);return}this.askFollowUp(i,typeof c.id=="number"?c.id:null)}).catch(()=>this.dropFeedbackCard())};i.append(s("div",{class:"question"},o.feedbackQuestion),s("div",{class:"row-actions"},s("button",{class:"btn secondary sm",type:"button",onclick:()=>r(!0)},o.yes),s("button",{class:"btn secondary sm",type:"button",onclick:()=>r(!1)},o.no))),this.list.append(n),this.scrollToEnd()}askFollowUp(e,t){if(t===null){this.showFeedbackClosing(e,o.feedbackNoted,!1);return}let i=n=>{e.querySelectorAll("button").forEach(r=>r.disabled=!0),this.api.feedbackFollowUp(t,n).then(()=>this.showFeedbackClosing(e,n?o.feedbackWillContact:o.feedbackNoted,!1)).catch(()=>this.showFeedbackClosing(e,o.feedbackNoted,!1))};e.replaceChildren(s("div",{class:"hint"},o.feedbackSorry),s("div",{class:"question"},o.feedbackFollowUp),s("div",{class:"row-actions"},s("button",{class:"btn primary sm",type:"button",onclick:()=>i(!0)},o.followUpYes),s("button",{class:"btn secondary sm",type:"button",onclick:()=>i(!1)},o.followUpNo))),this.scrollToEnd()}showFeedbackClosing(e,t,i){e.replaceChildren(s("div",{class:"hint"},t)),e.classList.add("answered"),i&&window.setTimeout(()=>{var n;(n=this.feedbackCard)!=null&&n.contains(e)&&this.dropFeedbackCard()},le)}dropFeedbackCard(){var e;(e=this.feedbackCard)==null||e.remove(),this.feedbackCard=null}async onFilePicked(){var t,i;let e=(t=this.fileInput.files)==null?void 0:t[0];if(this.fileInput.value="",!(!e||this.busy)){this.setBusy(!0,o.uploading);try{let n=await this.api.upload(e,this.setupApp);if(n.ok===!1||n.error)throw new f(200,n.error||"That file did not go through.");this.setBusy(!1);let r=(i=n.upload)!=null&&i.id?`${o.fileAdded} (file ${n.upload.id})`:o.fileAdded;await this.send(`${r}: ${e.name}`)}catch(n){this.setBusy(!1),this.push({id:`e-${Date.now()}`,role:"assistant",content:this.errorText(n),tone:"error",at:new Date().toISOString()})}}}askHelpInstead(){this.handOffToHelp("")||this.appendNotice(o.noHelp)}handOffToHelp(e){return T()?(this.avoidance.hold(),this.setOpen(!1,!0,!1),F(e)):!1}syncDock(){let e=this.booted&&!this.open,t=document.documentElement;e?(t.setAttribute(_,this.lifted?"lifted":"docked"),fe()):t.removeAttribute(_)}push(e){var t;this.messages.push(e),this.messages.length===1&&((t=this.list.querySelector('[data-id="intro"]'))==null||t.remove()),this.list.append(this.renderTurn(e)),this.renderSuggestions(),this.scrollToEnd()}setBusy(e,t=o.working){var i;this.busy=e,this.sendButton.disabled=e,this.attachButton.disabled=e,(i=this.waitingRow)==null||i.remove(),this.waitingRow=null,e&&(this.waitingRow=s("div",{class:"turn assistant"},s("div",{class:"waiting"},s("span",{class:"dots"},s("span"),s("span"),s("span")),t)),this.list.append(this.waitingRow),this.scrollToEnd()),this.panel.querySelectorAll("button").forEach(n=>n.disabled=e)}appendNotice(e){this.list.append(s("div",{class:"notice"},e)),this.scrollToEnd()}errorText(e){return e instanceof f?e.message:"That did not go through. Try again."}scrollToEnd(){window.requestAnimationFrame(()=>{this.list.scrollTop=this.list.scrollHeight})}syncTheme(){let e=()=>{let t=document.documentElement,i=t.classList.contains("dark")||t.dataset.theme==="dark",n=t.classList.contains("light")||t.dataset.theme==="light";this.host.dataset.theme=i?"dark":n?"light":"",this.host.dataset.theme||delete this.host.dataset.theme};e(),new MutationObserver(e).observe(document.documentElement,{attributes:!0,attributeFilter:["class","data-theme"]})}};function me(){var t;let a=(t=document.currentScript)!=null?t:document.querySelector("script[data-unified-assistant]"),e=a==null?void 0:a.dataset.unifiedAssistant;if(!e)return null;try{return JSON.parse(e)}catch{return null}}(function(){if(window.UnifiedAssistant)return;let e=me();if(!e)return;let t=new B(e);window.UnifiedAssistant={open:()=>t.openDrawer(),close:()=>t.closeDrawer(),ask:n=>t.ask(n),setSetupApp:n=>t.setSetupApp(n),dismissIntro:()=>t.dismissIntro()};let i=()=>void t.boot();document.readyState==="loading"?document.addEventListener("DOMContentLoaded",i,{once:!0}):i()})();})();
