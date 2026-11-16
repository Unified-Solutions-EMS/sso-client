"use strict";(()=>{function Me(a,e,t){return{plan_hash:a,index:e,code:t}}var m=class extends Error{constructor(t,i){super(i);this.status=t}},J="The assistant is not reachable right now. Try again in a minute.",k=class{constructor(e){this.settings=e}async bootstrap(e=null){let t=e===null?this.settings.bootstrap:`${this.settings.bootstrap}${this.settings.bootstrap.includes("?")?"&":"?"}conversation=${encodeURIComponent(String(e))}`,i=await this.request("GET",t);return i.status===204?null:await this.read(i)}async send(e,t,i=null,n=[]){let r={message:e,page:{url:location.href,title:document.title}};return t&&(r.setup_app=t),i!==null&&(r.conversation_id=i),n.length>0&&(r.attachments=n),await this.json("POST",this.settings.messages,r)}async escalate(e,t){let i={question:t};return e!==null&&(i.conversation_id=e),await this.json("POST",this.sibling("escalate",this.settings.escalate),i)}async updates(e,t){let i=this.sibling("updates",this.settings.updates),n=`conversation=${encodeURIComponent(String(e))}&after=${encodeURIComponent(String(t))}`,r=await this.request("GET",`${i}${i.includes("?")?"&":"?"}${n}`);return await this.read(r)}sibling(e,t){return t||this.settings.messages.replace(/messages\/?$/,e)}async upload(e,t){let i=new FormData;i.append("file",e,e.name),i.append("page[url]",location.href),i.append("page[title]",document.title),t&&i.append("setup_app",t);let n=await this.request("POST",this.settings.uploads,i);return await this.read(n)}async uploadStatus(e){let t=await this.request("GET",`${this.settings.uploads.replace(/\/+$/,"")}/${encodeURIComponent(String(e))}`);return await this.read(t)}async undo(e){return await this.json("POST",this.settings.undo.replace("{action}",String(e)),{})}async review(e,t,i){let n=this.settings.review.replace("{plan}",encodeURIComponent(e)).replace("{verb}",t);return await this.json("POST",n,i)}async choose(e,t,i,n){return this.review(e,"choose",Me(t,i,n))}canRecordFeedback(){return!!(this.settings.feedback&&this.settings.feedback_follow_up)}async feedback(e,t,i){if(!this.settings.feedback)return null;let n={conversation_id:e,helpful:i};return t!==null&&typeof t=="number"&&(n.message_id=t),await this.json("POST",this.settings.feedback,n)}async feedbackFollowUp(e,t){return this.settings.feedback_follow_up?await this.json("POST",this.settings.feedback_follow_up.replace("{feedback}",String(e)),{follow_up:t}):null}async clear(){return await this.json("DELETE",this.settings.conversation,{})}async json(e,t,i){let n=await this.request(e,t,JSON.stringify(i),"application/json");return this.read(n)}async request(e,t,i,n){let r={Accept:"application/json","X-Requested-With":"XMLHttpRequest"};this.settings.csrf&&(r["X-CSRF-TOKEN"]=this.settings.csrf),n&&(r["Content-Type"]=n);try{return await fetch(t,{method:e,headers:r,body:i,credentials:"same-origin"})}catch{throw new m(0,J)}}async read(e){let t=null;try{t=await e.json()}catch{t=null}if(e.status===204)return{};if(!e.ok){let i=t!=null?t:{},n=typeof i.error=="string"&&i.error||typeof i.message=="string"&&i.message||(e.status===419||e.status===401?"Your session has expired. Refresh the page and try again.":e.status>=500?J:"That did not go through. Try again.");throw new m(e.status,n)}return t!=null?t:{}}};function s(a,e={},...t){let i=document.createElement(a);for(let[n,r]of Object.entries(e))r==null||r===!1||(typeof r=="function"?i.addEventListener(n.replace(/^on/,"").toLowerCase(),r):r===!0?i.setAttribute(n,""):n==="class"?i.className=r:i.setAttribute(n,r));for(let n of t)n==null||n===!1||i.append(typeof n=="string"?document.createTextNode(n):n);return i}function h(a,e=16){let t="http://www.w3.org/2000/svg",i=document.createElementNS(t,"svg");i.setAttribute("viewBox","0 0 24 24"),i.setAttribute("width",String(e)),i.setAttribute("height",String(e)),i.setAttribute("fill","none"),i.setAttribute("stroke","currentColor"),i.setAttribute("stroke-width","1.8"),i.setAttribute("stroke-linecap","round"),i.setAttribute("stroke-linejoin","round"),i.setAttribute("aria-hidden","true");let n=document.createElementNS(t,"path");return n.setAttribute("d",a),i.append(n),i}var g={sparkles:"M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3zM5 18l.7 1.8L7.5 20.5l-1.8.7L5 23l-.7-1.8-1.8-.7 1.8-.7L5 18zM19 2l.6 1.4L21 4l-1.4.6L19 6l-.6-1.4L17 4l1.4-.6L19 2z",close:"M6 6l12 12M18 6L6 18",send:"M5 12h14M13 6l6 6-6 6",paperclip:"M21 12.5l-8.5 8.5a5.5 5.5 0 01-7.8-7.8l9.2-9.2a3.5 3.5 0 015 5l-9.2 9.2a1.5 1.5 0 01-2.1-2.1l8.5-8.5",copy:"M9 9h10v12H9zM5 15V3h10",check:"M5 12l5 5L20 7",alert:"M12 8v5M12 16v.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z",clock:"M12 7v5l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z",plus:"M12 5v14M5 12h14"};function _e(a){let e=[],t=/\*\*([^*\n]+?)\*\*/g,i=0,n;for(;(n=t.exec(a))!==null;){n.index>i&&e.push(document.createTextNode(a.slice(i,n.index)));let r=document.createElement("strong");r.textContent=n[1],e.push(r),i=n.index+n[0].length}return i<a.length&&e.push(document.createTextNode(a.slice(i))),e}function b(a){let e=document.createDocumentFragment();return a.replace(/\r\n/g,`
`).split(/\n{2,}/).forEach(i=>{let n=document.createElement("p");i.split(`
`).forEach((d,o)=>{o>0&&n.append(document.createElement("br")),n.append(..._e(d))}),e.append(n)}),e}function Q(a){if(!a)return"";let e=new Date(a);if(Number.isNaN(e.getTime()))return"";let t=new Date,i=d=>new Date(d.getFullYear(),d.getMonth(),d.getDate()).getTime(),n=Math.round((i(t)-i(e))/864e5);if(n===0)return"Today";if(n===1)return"Yesterday";let r={month:"short",day:"numeric"};return e.getFullYear()!==t.getFullYear()&&(r.year="numeric"),e.toLocaleDateString(void 0,r)}function E(a){if(!a)return"";let e=new Date(a);if(Number.isNaN(e.getTime()))return"";let t=e.toDateString()===new Date().toDateString(),i=e.toLocaleTimeString(void 0,{hour:"numeric",minute:"2-digit"});return t?i:`${e.toLocaleDateString(void 0,{month:"short",day:"numeric"})} ${i}`}function F(a){return typeof a!="number"||!Number.isFinite(a)||a<0?"":a<1024?`${a} B`:a<1024*1024?`${Math.round(a/1024)} KB`:`${(a/(1024*1024)).toFixed(1)} MB`}var H={leaveOut:"Leave it out",saving:"Saving your choice"};function Z(a){return a.changes.filter(e=>!!e.choice).length}function ee(a){return a===1?"1 still needs a choice":`${a} still need a choice`}function te(a,e,t){var o,u;let i=(u=(o=e.choice)==null?void 0:o.candidates)!=null?u:[],n=s("div",{class:"choices",role:"radiogroup","aria-label":e.title}),r=[];i.forEach((c,p)=>{let f=s("button",{type:"button",class:"choice",role:"radio","aria-checked":"false",tabindex:p===0?"0":"-1","data-code":c.code,id:`ua-choice-${a}-${e.index}-${p}`},s("span",{class:"dot","aria-hidden":"true"}),s("span",{class:"choice-text"},s("span",{class:"choice-label"},c.label),c.detail?s("span",{class:"choice-detail"},c.detail):null));f.addEventListener("click",()=>{r.forEach(v=>v.setAttribute("aria-checked",v===f?"true":"false")),t(c.code)}),f.addEventListener("keydown",v=>{let y=v.key==="ArrowDown"||v.key==="ArrowRight"?1:v.key==="ArrowUp"||v.key==="ArrowLeft"?-1:0;if(y===0)return;v.preventDefault();let w=r[(p+y+r.length)%r.length];r.forEach(X=>X.setAttribute("tabindex",X===w?"0":"-1")),w.focus()}),r.push(f),n.append(f)});let d=s("button",{type:"button",class:"btn ghost leave-out","aria-label":`${H.leaveOut}: ${e.title}`},H.leaveOut);return d.addEventListener("click",()=>t(null)),s("div",{class:"choice-block"},n,d)}function O(a,e){var t,i;a.classList.toggle("busy",e),a.setAttribute("aria-busy",e?"true":"false"),a.querySelectorAll("button").forEach(n=>n.disabled=e),(t=a.querySelector(".choice-status"))==null||t.remove(),e&&((i=a.querySelector(".body"))==null||i.append(s("div",{class:"choice-status",role:"status"},H.saving)))}function ie(a,e){var t,i;(t=a.querySelector(".row-error"))==null||t.remove(),(i=a.querySelector(".body"))==null||i.append(s("div",{class:"row-error",role:"alert"},e))}function S(){let a=window.Intercom;return typeof a=="function"?a:null}function B(){return S()!==null}function I(a){D({hide_default_launcher:a})}function D(a){var e;(e=S())==null||e("update",a)}function N(){let a=window.intercomSettings;return a&&typeof a=="object"?a:{}}function ne(a){var e;(e=S())==null||e("onHide",a)}function se(a){let e=S();if(!e)return!1;if(e("update",{hide_default_launcher:!1}),a==="")return e("showNewMessage",""),!0;let t=!1,i=()=>{t||(t=!0,e("showNewMessage",a))};return e("onShow",i),e("showNewMessage",a),window.setTimeout(i,900),!0}var re=60,z=20,Ce=12,Fe=160,He=40,ae=200,oe=.4,Oe=150,Be=150,$=250,Ie='#intercom-container, .intercom-lightweight-app, [id^="intercom-"], [class*="intercom-"]',R="data-unified-assistant-scroller",A="--ua-scroller-padding";function le(a){return a.closest(Ie)?!0:a instanceof HTMLIFrameElement&&a.src.includes("intercom")}function De(a){let e=getComputedStyle(a).position;return e==="fixed"||e==="sticky"}function de(a){let e=getComputedStyle(a).overflowY;return e==="auto"||e==="scroll"}function Ne(){return[document.documentElement,document.body].every(a=>{let e=getComputedStyle(a).overflowY;return e!=="hidden"&&e!=="clip"})}var L=class{constructor(e,t){this.host=e;this.onLift=t;this.lifted=!1;this.scroller=null;this.active=!1;this.held=!1;this.timers={resize:null,scroll:null,mutation:null};this.observer=null;let i=N(),n=Number(i.vertical_padding);this.basePadding=Number.isFinite(n)&&n>=z?n:z,this.padding=this.basePadding}start(){this.active=!0,window.addEventListener("resize",()=>this.schedule("resize",Oe),{passive:!0}),window.addEventListener("scroll",()=>this.schedule("scroll",Be),{passive:!0,capture:!0}),this.observer=new MutationObserver(e=>{e.some(t=>this.isForeignMutation(t))&&this.schedule("mutation",$)}),this.observer.observe(document.body,{childList:!0,subtree:!0,attributes:!0,attributeFilter:["class","style","hidden"]}),ne(()=>{this.held=!1,this.schedule("mutation",$)}),this.measure(),document.readyState!=="complete"&&window.addEventListener("load",()=>this.schedule("mutation",$),{once:!0})}setActive(e){this.active=e,e?this.measure():this.cancelAll()}hold(){this.held=!0,this.cancelAll()}measure(){if(!this.active||this.held)return;let e=document.documentElement.clientWidth,t=window.innerHeight,i=this.findBarTop(e,t),n=this.basePadding;if(i!==null){let r=Math.max(this.basePadding,Math.round(t-i+Ce));r<=t*oe&&(n=r)}this.apply(n),this.markScroller(e,t)}markScroller(e,t){let i=this.findScroller(e,t);if(i===this.scroller)return;this.scroller&&(this.scroller.removeAttribute(R),this.scroller.style.removeProperty(A)),this.scroller=i;let n=parseFloat(getComputedStyle(i).paddingBottom);i.style.setProperty(A,`${Number.isFinite(n)?n:0}px`),i.setAttribute(R,"")}findScroller(e,t){if(Ne())return document.body;for(let[n,r]of this.probePoints(e,t))for(let d of document.elementsFromPoint(n,r)){if(!this.isForeign(d))continue;let o=null,u=d;for(;u&&u!==document.body;)u instanceof HTMLElement&&de(u)&&(o=u),u=u.parentElement;if(o)return o}let i=document.querySelector("main");return i instanceof HTMLElement&&de(i)?i:document.body}apply(e){e!==this.padding&&(this.padding=e,D({vertical_padding:e}));let t=e-this.basePadding;this.host.style.setProperty("--ua-lift",`${t}px`);let i=t>0;i!==this.lifted&&(this.lifted=i,this.onLift(i))}probePoints(e,t){let i=N(),n=Number(i.horizontal_padding),r=Number.isFinite(n)&&n>=0?n:z,d=i.alignment==="left",o=[r,r+re/2,r+re].map(c=>d?c:e-c),u=[];for(let c of o)for(let p=1;p<=Fe;p+=He){let f=t-p;c>=0&&f>=0&&c<e&&f<t&&u.push([c,f])}return u}findBarTop(e,t){let i=new Set,n=null;for(let[r,d]of this.probePoints(e,t))for(let o of document.elementsFromPoint(r,d)){if(i.has(o)||(i.add(o),!this.isForeign(o)))continue;let u=this.pinnedBarRect(o,t);u&&(n===null||u.top<n)&&(n=u.top)}return n}pinnedBarRect(e,t){let i=[],n=e;for(;n&&n!==document.body&&n!==document.documentElement;){if(i.push(n),De(n)){for(let d=i.length-1;d>=0;d--){let o=i[d].getBoundingClientRect();if(o.height>0&&o.bottom>=t-ae&&o.top<t&&o.height<=t*oe)return o}let r=n.getBoundingClientRect();return r.height>0&&r.bottom>=t-ae?r:null}n=n.parentElement}return null}isForeignMutation(e){return e.type==="childList"?[...e.addedNodes,...e.removedNodes].some(i=>i instanceof Element&&i!==this.host&&!le(i))||this.isForeign(e.target):this.isForeign(e.target)}isForeign(e){let t=e instanceof Element?e:e.parentElement;return!t||t===document.documentElement||t===document.body||t===this.host||this.host.contains(t)?!1:!le(t)}schedule(e,t){if(!this.active||this.held)return;let i=this.timers[e];i!==null&&window.clearTimeout(i),this.timers[e]=window.setTimeout(()=>{this.timers[e]=null,this.measure()},t)}cancelAll(){for(let e of Object.keys(this.timers)){let t=this.timers[e];t!==null&&(window.clearTimeout(t),this.timers[e]=null)}}};var x={working:"Working on it",stillWorking:"Still working\u2026 this one is taking a little longer",almostThere:"Almost there",tryAgain:"Try again",dismiss:"Dismiss",checkingAgain:"Checking again\u2026"},ue=8e3,ce=2e4,ze=5e3,$e=6e4,U="draft:";function pe(a){return`${U}${a===null?"new":String(a)}`}function q(a){var e;try{return(e=sessionStorage.getItem(a))!=null?e:""}catch{return""}}function _(a,e){try{e.trim()===""?sessionStorage.removeItem(a):sessionStorage.setItem(a,e)}catch{}}function he(a,e){q(a).trim()===e.trim()&&_(a,"")}function ge(a){return a instanceof m&&a.status===409}function fe(a){var i,n,r;let e=typeof a.error=="string"?a.error.trim():"";return e===""||!!((i=a.reply)!=null&&i.trim())||((r=(n=a.actions)==null?void 0:n.length)!=null?r:0)>0||!!a.review||!!a.handoff?null:e}function Ue(a){return a>=ce?x.almostThere:a>=ue?x.stillWorking:x.working}var P=class{constructor(){this.timers=[]}start(e){this.stop(),this.timers=[ue,ce].map(t=>window.setTimeout(()=>e(Ue(t)),t))}stop(){this.timers.forEach(e=>window.clearTimeout(e)),this.timers=[]}};function qe(a,e){let t=(a!=null?a:[])[(a!=null?a:[]).length-1];return!t||t.role!=="assistant"?!1:!e.some(i=>String(i.id)===String(t.id))}var M=class{constructor(e,t,i,n){this.load=e;this.shown=t;this.onNewer=i;this.onGiveUp=n;this.timer=null;this.startedAt=0}get running(){return this.timer!==null}start(){this.stop(),this.startedAt=Date.now(),this.schedule()}stop(){this.timer!==null&&(window.clearTimeout(this.timer),this.timer=null)}schedule(){this.timer=window.setTimeout(()=>void this.tick(),ze)}async tick(){var t;let e=null;try{e=await this.load()}catch{e=null}if(this.timer!==null){if(e&&qe((t=e.conversation)==null?void 0:t.messages,this.shown())){this.timer=null,this.onNewer(e);return}if(Date.now()-this.startedAt>=$e){this.timer=null,this.onGiveUp();return}this.schedule()}}};function me(a,e,t=!1){let i=s("div",{class:"bubble error"});i.append(b(a));let n=s("div",{class:"turn assistant failed",role:"alert"},i);return n.append(t?Ke():be(e)),n}function ve(a,e){var t,i;(t=a.querySelector(".checking"))==null||t.remove(),(i=a.querySelector(".retry-row"))==null||i.remove(),a.append(be(e))}function Ke(){return s("div",{class:"waiting checking",role:"status"},s("span",{class:"dots"},s("span"),s("span"),s("span")),x.checkingAgain)}function be(a){return s("div",{class:"retry-row"},s("button",{class:"btn secondary retry",type:"button",onclick:()=>a.onRetry()},x.tryAgain),s("button",{class:"btn ghost dismiss",type:"button",onclick:()=>a.onDismiss()},x.dismiss))}var ye=`
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
  :host(:not([data-theme="light"])) { ${xe()} }
}
:host([data-theme="dark"]) { ${xe()} }

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
/* Our team: a teammate's reply, the lines about the conversation, files */
.turn.staff { align-items: flex-start; }
.staff-head { display: flex; align-items: center; gap: 6px; padding-left: 2px; font-size: 12px; color: rgb(var(--ua-text-secondary)); }
.avatar {
  display: inline-flex; align-items: center; justify-content: center; flex: none;
  width: 24px; height: 24px; border-radius: 999px;
  background: rgb(var(--ua-primary)); color: rgb(var(--ua-on-accent));
  font-size: 10px; font-weight: 600; letter-spacing: 0.02em;
}
.staff-name { font-weight: 600; color: rgb(var(--ua-text)); }
.tag {
  font-size: 10px; font-weight: 600; line-height: 1.4; padding: 1px 6px; border-radius: 999px;
  background: rgb(var(--ua-primary-subtle)); color: rgb(var(--ua-primary));
}
.staff .bubble {
  background: rgb(var(--ua-surface)); border: 1px solid rgb(var(--ua-primary) / 0.45);
  border-bottom-left-radius: 4px; box-shadow: var(--ua-shadow-sm);
}
.system-line { align-self: center; max-width: 88%; font-size: 12px; color: rgb(var(--ua-text-muted)); text-align: center; padding: 2px 8px; }
.attachments { display: flex; flex-wrap: wrap; gap: 6px; max-width: 88%; }
.user .attachments { justify-content: flex-end; }
.file-chip {
  display: inline-flex; align-items: center; gap: 6px; max-width: 100%; min-height: 32px;
  padding: 4px 10px; border: 1px solid rgb(var(--ua-border)); border-radius: 999px;
  background: rgb(var(--ua-surface)); color: rgb(var(--ua-text)); font-size: 12px; text-decoration: none;
}
.file-chip svg { flex: none; color: rgb(var(--ua-text-muted)); }
a.file-chip:hover, a.file-chip:focus-visible { border-color: rgb(var(--ua-primary)); color: rgb(var(--ua-primary)); }
.file-chip .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px; }
.file-chip .size { color: rgb(var(--ua-text-muted)); white-space: nowrap; }
.pending-files { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.pending-files[hidden] { display: none; }
.pending-files .file-chip { padding-right: 2px; }
.pending-files .remove {
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; border-radius: 999px; color: rgb(var(--ua-text-muted));
}
.pending-files .remove:hover { background: rgb(var(--ua-bg-muted)); color: rgb(var(--ua-text)); }
/* A message that got no answer: Try again / Dismiss under the error */
.retry-row { display: flex; flex-wrap: wrap; gap: 8px; padding-left: 4px; }
.retry-row .btn { min-height: 44px; }
.turn.failed .checking { margin-top: 2px; }

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

/* A file SSO is reading */
.file-turn .chips { width: auto; max-width: 88%; }
.chip.file .dots { flex: none; width: 16px; justify-content: center; }
.chip.file .text > div:first-child { overflow-wrap: anywhere; }
.chip.file.failed { border-color: rgb(var(--ua-danger) / 0.4); background: rgb(var(--ua-danger-subtle)); }
.chip.file.failed .mark { color: rgb(var(--ua-danger)); }
.chip.file.failed .text .meta { color: rgb(var(--ua-danger)); }

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
.change.choosing { flex-direction: column; }
.change.choosing .body { width: 100%; }
.choice-block { display: flex; flex-direction: column; gap: 6px; margin-top: 8px; }
.choices { display: flex; flex-direction: column; gap: 6px; }
.choice {
  display: flex; align-items: center; gap: 10px; width: 100%; min-height: 44px; padding: 8px 10px;
  border: 1px solid rgb(var(--ua-border-strong)); border-radius: var(--ua-radius);
  background: rgb(var(--ua-surface)); color: rgb(var(--ua-text)); text-align: left; cursor: pointer;
  transition: background-color var(--ua-fast) var(--ua-ease), border-color var(--ua-fast) var(--ua-ease);
}
.choice:hover:not(:disabled) { background: rgb(var(--ua-bg-muted)); }
.choice[aria-checked="true"] { border-color: rgb(var(--ua-primary)); background: rgb(var(--ua-primary-subtle)); }
.choice:disabled { cursor: default; opacity: 0.7; }
.choice .dot { flex: none; width: 16px; height: 16px; border-radius: 9999px; border: 1.5px solid rgb(var(--ua-border-strong)); background: rgb(var(--ua-surface)); }
.choice[aria-checked="true"] .dot { border-color: rgb(var(--ua-primary)); box-shadow: inset 0 0 0 3px rgb(var(--ua-surface)); background: rgb(var(--ua-primary)); }
.choice-text { display: flex; flex-direction: column; min-width: 0; }
.choice-label { font-weight: 500; overflow-wrap: anywhere; }
.choice-detail { font-size: 12px; color: rgb(var(--ua-text-muted)); }
.leave-out { align-self: flex-start; min-height: 44px; text-decoration: underline; text-underline-offset: 2px; }
.change.busy { opacity: 0.85; }
.choice-status { margin-top: 6px; font-size: 12px; color: rgb(var(--ua-text-secondary)); }
.row-error { margin-top: 6px; font-size: 12px; color: rgb(var(--ua-danger)); }
.still-need { margin-top: 2px; }
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
`;function xe(){return`
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
  --ua-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.4);`}var T={reading:"Reading file\u2026",ready:"Read",tooSlow:"That file took too long to read. Try a smaller file.",failed:"That file could not be read. Try a clearer copy, or a PDF, picture, spreadsheet, document or text file.",gone:"That file is no longer here. Add it again."},We=2e3,je=5e3,Ye=3e4,we=3e5;function Ge(a){return a>=we?null:Math.min(a<Ye?We:je,we-a)}function Te(a){return(a==null?void 0:a.status)==="reading"}async function ke(a,e={}){var r,d;let t=(r=e.now)!=null?r:()=>Date.now(),i=(d=e.sleep)!=null?d:o=>new Promise(u=>window.setTimeout(u,o)),n=t();for(;;){let o=Ge(t()-n);if(o===null)return{status:"failed",error:T.tooSlow};await i(o);try{let u=(await a()).upload;if((u==null?void 0:u.status)==="ready")return{status:"ready",upload:u};if((u==null?void 0:u.status)==="failed")return{status:"failed",error:u.error||T.failed}}catch(u){if(u instanceof m&&u.status>=400&&u.status<500&&u.status!==408&&u.status!==429)return{status:"failed",error:u.status===404?T.gone:u.message}}}}var l={launcher:"Assistant",title:"Assistant",subtitle:"Does tasks for you. For questions about how something works, use Help.",intro:"I'm your assistant. Tell me what to do and I'll do it: add people, set up facilities, fill a shift, change a setting. If you want to know how something works, Help is the place to ask.",placeholder:"Tell me what to do",send:"Send",attach:"Add a file",close:"Close",working:"Working on it",askHelpInstead:"Ask Help instead",handoff:"I can't do that one, but Help can take it from here.",askHelp:"Ask Help",teamHandoff:"I can't finish this one, but our team can.",talkToTeam:"Talk to our team",teamTag:"Unified",removeFile:"Remove",noHelp:"Open Help from the menu to send this.",copy:"Copy",copied:"Copied",undo:"Undo",undone:"Undone",undoing:"Undoing",reviewTitle:"Review these changes",reviewHint:"Take out anything you do not want, then approve.",needsCare:"Needs extra care. Check to include it.",approve:"Approve and apply",setAside:"Set aside",remove:"Remove",applying:"Applying your changes.",setAsideDone:"Set aside. Ask me when you want to pick it up again.",nothingLeft:"Nothing left to apply.",fileAdded:"I added a file",uploading:"Adding your file",enterHint:"Enter to send, Shift+Enter for a new line",conversations:"Conversations",newConversation:"New conversation",noConversations:"No previous conversations yet.",untitled:"Untitled conversation",reopened:"Reopened",thingsICanDo:"Things I can do",ideas:"Ideas",hideIdeas:"Hide ideas",introTitle:"Meet your Assistant",introBody:"Tell it what to do and it does it: add people, set up facilities, fill a shift, change a setting.",tryIt:"Try it",dismiss:"Dismiss",walkthrough:"Walkthrough",feedbackQuestion:"Did I handle that correctly?",yes:"Yes",no:"No",feedbackThanks:"Thanks for letting me know.",feedbackSorry:"Sorry about that. Our team will look at this conversation.",feedbackFollowUp:"Would you like someone from our team to follow up with you?",followUpYes:"Yes, please",followUpNo:"No thanks",feedbackWillContact:"Thanks. We'll be in touch.",feedbackNoted:"Thanks for letting us know."},Ve=4e3,C="unified-assistant:",j="open",Y="intro-dismissed",Xe=1500,Je=600,Pe="tour:",Qe=[j,Y];function Ze(a){return typeof a.viewer=="string"&&a.viewer.trim()!==""?a.viewer.trim():null}function et(a,e){return e!==null?a.startsWith(`${e}:`):Qe.includes(a)||a.startsWith(Pe)||a.startsWith(U)}function tt(a){try{let e=[];for(let t=0;t<sessionStorage.length;t++){let i=sessionStorage.key(t);i!==null&&i.startsWith(C)&&!et(i.slice(C.length),a)&&e.push(i)}e.forEach(t=>sessionStorage.removeItem(t))}catch{}}function Ee(a){if(!a||!Array.isArray(a.steps))return null;let e=a.steps.filter(t=>!!t&&typeof t.text=="string"&&t.text.trim()!=="").map(t=>({text:t.text,kind:t.kind==="action"?"action":"question",suggestions:(Array.isArray(t.suggestions)?t.suggestions:[]).filter(i=>i&&typeof i.prompt=="string"&&i.prompt.trim()!=="")}));return e.length>0?{steps:e,closing:typeof a.closing=="string"?a.closing:""}:null}var G="data-unified-assistant",Se="unified-assistant-dock";function it(){if(document.getElementById(Se))return;let a=document.createElement("style");a.id=Se;let e=`html[${G}="docked"] [${R}]`,t=i=>`padding-bottom: calc(var(${A}, 0px) + ${i}px) !important;`;a.textContent=`${e} { ${t(150)} }
@media (max-width: 639px) { ${e} { ${t(120)} } }`,document.head.append(a)}var Re=["drafted","refined","proposed","draft","pending"],nt=["rejected","set_aside"],K=["approved","executing","applied","completed"],st="unified-assistant:action",rt=5e3,at=2e4;function ot(a){var i;let e=a.trim().split(/\s+/).filter(Boolean);return(e.length>1?e[0][0]+e[e.length-1][0]:((i=e[0])!=null?i:"?").slice(0,2)).toUpperCase()}function Ae(a){return s("div",{class:"attachments"},...a.map(e=>{let t=[h(g.paperclip,14),s("span",{class:"name"},e.name),e.size?s("span",{class:"size"},F(e.size)):null];return e.url&&/^https?:\/\//i.test(e.url)?s("a",{class:"file-chip",href:e.url,target:"_blank",rel:"noopener noreferrer"},...t):s("span",{class:"file-chip"},...t)}))}function W(a){return(a!=null?a:[]).filter(e=>typeof e.summary=="string"&&e.summary.trim()!=="")}function Le(a,e){let t={actions:a};e!==void 0&&(t.undone=e),window.dispatchEvent(new CustomEvent(st,{detail:t}))}var V=class{constructor(e){this.messages=[];this.pendingReview=null;this.conversation=null;this.conversations=[];this.suggestions=[];this.ideasOpen=!1;this.suggestionsEl=null;this.panelOpen=!1;this.open=!1;this.busy=!1;this.booted=!1;this.waitingRow=null;this.failedRow=null;this.retryHandlers=null;this.slowTimer=new P;this.lastFocus=null;this.lifted=!1;this.callout=null;this.introPending=!1;this.intro=null;this.introTimer=null;this.tour=null;this.viewer=null;this.tourActive=!1;this.tourStep=0;this.tourPillsEl=null;this.tourAdvanceTimer=null;this.askFeedback=!1;this.feedbackCard=null;this.support=null;this.pendingFiles=[];this.pollTimer=null;var t;this.api=new k(e),this.setupApp=(t=e.setup_app)!=null?t:null,this.host=document.createElement("unified-assistant"),this.root=this.host.attachShadow({mode:"open"}),this.syncTheme(),this.build(),this.busyPoller=new M(()=>{var i,n;return this.api.bootstrap((n=(i=this.conversation)==null?void 0:i.id)!=null?n:null)},()=>this.messages,i=>this.applyBootstrap(i),()=>this.busyGaveUp()),this.avoidance=new L(this.host,i=>{this.lifted=i,this.syncDock()})}async boot(){var i,n,r;let e;try{e=await this.api.bootstrap()}catch{return}if(!e||e.enabled===!1)return;this.booted=!0,this.viewer=Ze(e),tt(this.viewer),this.askFeedback=((i=e.feedback)==null?void 0:i.ask)===!0&&!!this.api.canRecordFeedback(),this.tour=((n=e.intro)==null?void 0:n.show)===!0?Ee(e.tour):null,document.body.append(this.host),this.applyBootstrap(e),this.launcher.hidden=!1,this.syncDock(),this.avoidance.start();let t=!1;try{t=sessionStorage.getItem(this.storageKey(j))==="1"}catch{t=!1}((r=e.intro)==null?void 0:r.show)===!0&&this.offerIntro(e.intro),t&&this.setOpen(!0,!1),window.addEventListener("pagehide",()=>I(!1)),document.addEventListener("visibilitychange",()=>this.reschedulePoll())}openDrawer(){this.booted&&this.setOpen(!0)}closeDrawer(){this.setOpen(!1)}ask(e){this.booted&&(this.setOpen(!0),this.send(e))}setSetupApp(e){this.setupApp=e}dismissIntro(){if(this.introTimer!==null&&(window.clearTimeout(this.introTimer),this.introTimer=null),this.badge.hidden=!0,this.hideCallout(),!!this.introPending){this.introPending=!1,this.intro=null;try{sessionStorage.setItem(this.storageKey(Y),"1")}catch{}}}offerIntro(e){var i;let t=!1;try{t=sessionStorage.getItem(this.storageKey(Y))==="1"}catch{t=!1}t||(this.intro=e,this.introPending=!0,this.badge.textContent=String((i=e.badge)!=null?i:1),this.badge.hidden=!1,this.introTimer=window.setTimeout(()=>{this.introTimer=null,this.introPending&&!this.open&&this.showCallout(e)},Xe))}showCallout(e){var t,i,n;this.hideCallout(),this.callout=s("div",{class:"callout",role:"region","aria-labelledby":"ua-callout-title"},s("div",{class:"callout-head"},s("div",{class:"callout-title",id:"ua-callout-title"},((t=e.title)==null?void 0:t.trim())||l.introTitle),s("button",{class:"icon-btn dismiss",type:"button","aria-label":l.dismiss,onclick:()=>this.dismissIntro()},h(g.close,16))),s("div",{class:"callout-body"},((i=e.body)==null?void 0:i.trim())||l.introBody),s("div",{class:"callout-actions"},s("button",{class:"btn primary",type:"button",onclick:()=>this.tryIt()},((n=e.cta)==null?void 0:n.trim())||l.tryIt))),this.root.append(this.callout),window.requestAnimationFrame(()=>{var r;return(r=this.callout)==null?void 0:r.classList.add("in")})}hideCallout(){var e;(e=this.callout)==null||e.remove(),this.callout=null}tryIt(){this.setOpen(!0),!(!this.tour||this.tourActive||this.busy)&&(this.tourActive=!0,this.tourStep=0,this.ideasOpen=!1,this.renderSuggestions(),this.renderTourStep(),this.saveTour())}storageKey(e){return this.viewer===null?`${C}${e}`:`${C}${this.viewer}:${e}`}tourKey(){return this.conversation?this.storageKey(`${Pe}${String(this.conversation.id)}`):null}saveTour(){let e=this.tourKey();if(!(!e||!this.tour))try{sessionStorage.setItem(e,JSON.stringify({step:this.tourStep,tour:this.tour}))}catch{}}cancelTourAdvance(){this.tourAdvanceTimer!==null&&(window.clearTimeout(this.tourAdvanceTimer),this.tourAdvanceTimer=null)}clearStoredTour(){this.cancelTourAdvance();let e=this.tourKey();if(e)try{sessionStorage.removeItem(e)}catch{}}resumeTour(){this.tourActive=!1,this.tourPillsEl=null;let e=this.tourKey();if(!e)return;let t=null;try{let n=sessionStorage.getItem(e);t=n?JSON.parse(n):null}catch{t=null}let i=Ee(t==null?void 0:t.tour);!i||typeof(t==null?void 0:t.step)!="number"||t.step<0||t.step>=i.steps.length||(this.tour=i,this.tourActive=!0,this.tourStep=t.step,this.renderSuggestions(),this.renderTourStep())}renderTourStep(){if(!this.tour)return;let e=this.tour.steps[this.tourStep],t=e.suggestions.map(i=>{var r;let n=s("button",{class:"pill",type:"button"},((r=i.label)==null?void 0:r.trim())||i.prompt);return n.addEventListener("click",()=>{var d;(d=this.tourPillsEl)==null||d.remove(),this.tourPillsEl=null,this.send(i.prompt)}),n});this.tourPillsEl=t.length>0?s("div",{class:"tour-pills"},...t):null,this.list.append(this.renderTourBubble(e.text,this.tourPillsEl)),this.scrollToEnd()}renderTourBubble(e,t){let i=s("div",{class:"bubble"});return i.append(b(e)),s("div",{class:"turn assistant tour"},s("div",{class:"tour-label"},h(g.sparkles,12),l.walkthrough),i,t)}advanceTour(e){var d;if(!this.tourActive||!this.tour||this.tourAdvanceTimer!==null)return;let t=this.tour.steps[this.tourStep],i=this.pendingReview!==null&&Re.includes(this.pendingReview.status),n=e.review!==null&&e.review!==void 0&&K.includes(e.review.status);(t.kind==="action"?W(e.actions).length>0||n:((d=e.turn_state)!=null?d:"complete")==="complete"&&!i)&&(this.tourAdvanceTimer=window.setTimeout(()=>{this.tourAdvanceTimer=null,this.nextTourStep()},Je))}nextTourStep(){var e;if(!(!this.tourActive||!this.tour)){if((e=this.tourPillsEl)==null||e.remove(),this.tourPillsEl=null,this.tourStep+=1,this.tourStep<this.tour.steps.length){this.renderTourStep(),this.saveTour();return}this.tour.closing.trim()!==""&&this.list.append(this.renderTourBubble(this.tour.closing,null)),this.endTour(),this.scrollToEnd()}}endTour(){var e;this.clearStoredTour(),this.cancelTourAdvance(),this.tourActive=!1,(e=this.tourPillsEl)==null||e.remove(),this.tourPillsEl=null,this.renderSuggestions()}build(){let e=document.createElement("style");e.textContent=ye,this.launcher=s("button",{class:"launcher",type:"button",hidden:!0,"aria-label":l.launcher,title:l.launcher,"aria-haspopup":"dialog","aria-expanded":"false",onclick:()=>this.setOpen(!0)},h(g.sparkles,20),s("span",{class:"tip","aria-hidden":"true"},l.launcher)),this.badge=s("span",{class:"badge",hidden:!0}),this.launcher.append(this.badge),this.scrim=s("div",{class:"scrim",onclick:()=>this.setOpen(!1)}),this.list=s("div",{class:"list",role:"log","aria-live":"polite","aria-relevant":"additions"}),this.input=s("textarea",{rows:"1",placeholder:l.placeholder,"aria-label":l.placeholder,onkeydown:r=>this.onComposerKey(r),oninput:()=>{this.autosize(),_(this.draftKey(),this.input.value)}}),this.sendButton=s("button",{class:"icon-btn send",type:"button","aria-label":l.send,onclick:()=>void this.send()},h(g.send)),this.pendingEl=s("div",{class:"pending-files",hidden:!0,"aria-live":"polite"}),this.fileInput=s("input",{type:"file",class:"sr",tabindex:"-1","aria-hidden":"true",onchange:()=>void this.onFilePicked()}),this.attachButton=s("button",{class:"icon-btn",type:"button","aria-label":l.attach,title:l.attach,onclick:()=>this.fileInput.click()},h(g.paperclip)),this.historyButton=s("button",{class:"icon-btn history",type:"button","aria-label":l.conversations,title:l.conversations,"aria-expanded":"false",onclick:()=>this.setPanelOpen(!this.panelOpen)},h(g.clock));let t=s("header",{class:"header"},s("div",{class:"title-wrap"},s("h2",{id:"ua-title"},h(g.sparkles,16),l.title),s("div",{class:"subtitle"},l.subtitle)),this.historyButton,s("button",{class:"icon-btn",type:"button","aria-label":l.close,onclick:()=>this.setOpen(!1)},h(g.close)));this.reopenedBar=s("div",{class:"reopened",hidden:!0,role:"status"}),this.panelList=s("div",{class:"threads"}),this.panel=s("section",{class:"panel","aria-label":l.conversations,hidden:!0},s("div",{class:"panel-head"},s("div",{class:"panel-title"},l.conversations)),s("div",{class:"panel-body"},s("button",{class:"btn secondary new-conversation",type:"button",onclick:()=>void this.newConversation()},h(g.plus,14),l.newConversation),this.panelList));let i=s("footer",{class:"footer"},this.pendingEl,s("div",{class:"composer"},this.attachButton,this.input,this.sendButton),s("div",{class:"footer-row"},s("span",{},l.enterHint),s("span",{class:"footer-links"},s("button",{class:"link ideas",type:"button","aria-pressed":"false",onclick:()=>this.toggleIdeas()},l.ideas),s("button",{class:"link",type:"button",onclick:()=>this.askHelpInstead()},l.askHelpInstead))),this.fileInput),n=s("div",{class:"body"},this.reopenedBar,this.list,i,this.panel);this.drawer=s("section",{class:"drawer",role:"dialog","aria-modal":"true","aria-labelledby":"ua-title","aria-hidden":"true"},t,n),this.drawer.addEventListener("keydown",r=>{if(r.key==="Escape"){if(this.panelOpen){r.stopPropagation(),this.setPanelOpen(!1);return}this.setOpen(!1)}}),this.root.append(e,this.launcher,this.scrim,this.drawer)}setOpen(e,t=!0,i=!0){if(e!==this.open){if(this.open=e,e?this.hideCallout():this.introPending&&this.intro&&this.introTimer===null&&this.showCallout(this.intro),this.drawer.classList.toggle("open",e),this.scrim.classList.toggle("open",e),this.drawer.setAttribute("aria-hidden",e?"false":"true"),this.launcher.setAttribute("aria-expanded",e?"true":"false"),this.launcher.hidden=e,this.syncDock(),i&&I(e),this.booted&&this.avoidance.setActive(!e),e&&!this.introPending&&(this.badge.hidden=!0),this.reschedulePoll(),t)try{sessionStorage.setItem(this.storageKey(j),e?"1":"0")}catch{}e?(this.lastFocus=document.activeElement,this.scrollToEnd(),window.setTimeout(()=>this.input.focus(),50)):this.lastFocus instanceof HTMLElement&&this.lastFocus.isConnected?this.lastFocus.focus():this.launcher.focus()}}renderMessages(){this.list.replaceChildren(),this.messages.length===0&&this.list.append(this.renderTurn({id:"intro",role:"assistant",content:l.intro}));for(let e of this.messages)this.list.append(this.renderTurn(e));this.pendingReview&&!this.messages.some(e=>{var t,i;return((t=e.review)==null?void 0:t.id)===((i=this.pendingReview)==null?void 0:i.id)})&&this.list.append(s("div",{class:"turn assistant"},this.renderReview(this.pendingReview))),this.renderSuggestions(),this.scrollToEnd()}applyBootstrap(e){var t,i,n,r,d,o,u;this.messages=((i=(t=e.conversation)==null?void 0:t.messages)!=null?i:[]).slice(),this.pendingReview=(n=e.review)!=null?n:null,this.conversation=(r=e.conversation)!=null?r:null,this.conversations=((d=e.conversations)!=null?d:[]).slice(),this.suggestions=((o=e.suggestions)!=null?o:[]).filter(c=>c&&typeof c.prompt=="string"&&c.prompt.trim()!==""),this.ideasOpen=!1,this.waitingRow=null,this.tourActive=!1,this.tourPillsEl=null,this.feedbackCard=null,this.busyPoller.stop(),this.failedRow=null,this.support=(u=e.support)!=null?u:null,this.pendingFiles=[],this.renderPendingFiles(),this.input.value=q(this.draftKey()),this.autosize(),this.renderMessages(),this.resumeTour(),this.renderThreads(),e.notice&&this.appendNotice(e.notice),this.syncPolling()}setPanelOpen(e){e!==this.panelOpen&&(this.panelOpen=e,this.panel.hidden=!e,this.historyButton.setAttribute("aria-expanded",e?"true":"false"),this.list.setAttribute("aria-hidden",e?"true":"false"),e?(this.renderThreads(),window.setTimeout(()=>{var t;return(t=this.panel.querySelector("button"))==null?void 0:t.focus()},30)):this.historyButton.focus())}renderThreads(){var i;let e=this.conversation?String(this.conversation.id):null,t=this.conversations.filter(n=>String(n.id)!==e);if(this.panelList.replaceChildren(),t.length===0){this.panelList.append(s("div",{class:"threads-empty"},l.noConversations));return}for(let n of t){let r=n.message_count===1?"1 message":`${n.message_count} messages`,d=[Q(n.last_activity_at),r].filter(Boolean).join(" \xB7 "),o=s("button",{class:"thread",type:"button","data-conversation":String(n.id)},s("div",{class:"thread-title"},((i=n.title)==null?void 0:i.trim())||l.untitled),s("div",{class:"thread-meta"},d));o.addEventListener("click",()=>void this.openConversation(n)),this.panelList.append(o)}}async newConversation(){if(!this.busy){this.setBusy(!0);try{let e=await this.api.clear();this.setBusy(!1),this.clearStoredTour(),this.applyBootstrap(e),this.reopenedBar.hidden=!0,this.setPanelOpen(!1),this.input.focus()}catch(e){this.setBusy(!1),this.appendNotice(this.errorText(e))}}}async openConversation(e){var t,i,n;if(!this.busy){this.setBusy(!0);try{let r=await this.api.bootstrap(e.id);if(this.setBusy(!1),!r)return;this.applyBootstrap(r),this.reopenedBar.replaceChildren(h(g.clock,14),`${l.reopened}: ${((i=(t=r.conversation)==null?void 0:t.title)==null?void 0:i.trim())||((n=e.title)==null?void 0:n.trim())||l.untitled}`),this.reopenedBar.hidden=!1,this.setPanelOpen(!1)}catch(r){this.setBusy(!1),this.appendNotice(this.errorText(r))}}}toggleIdeas(){this.ideasOpen=!this.ideasOpen,this.renderSuggestions(),this.scrollToEnd()}renderSuggestions(){var n;(n=this.suggestionsEl)==null||n.remove(),this.suggestionsEl=null;let e=this.drawer.querySelector(".link.ideas"),t=this.suggestions.length>0&&!this.tourActive&&(this.ideasOpen||this.messages.length===0);if(e&&(e.hidden=this.suggestions.length===0||this.tourActive,e.setAttribute("aria-pressed",this.ideasOpen?"true":"false"),e.textContent=this.ideasOpen?l.hideIdeas:l.ideas),!t)return;let i=this.suggestions.map(r=>{var o;let d=s("button",{class:"pill",type:"button"},((o=r.label)==null?void 0:o.trim())||r.prompt);return d.addEventListener("click",()=>{this.ideasOpen=!1,this.send(r.prompt)}),d});this.suggestionsEl=s("div",{class:"suggestions"},s("div",{class:"suggestions-title"},l.thingsICanDo),s("div",{class:"pills"},...i)),this.list.append(this.suggestionsEl)}renderTurn(e){if(e.role==="system")return s("div",{class:"system-line",role:"status","data-id":String(e.id)},e.content);if(e.role==="staff")return this.renderStaffTurn(e);let t=s("div",{class:`turn ${e.role}`,"data-id":String(e.id)});if(e.role==="assistant"&&e.activity&&e.activity.length>0&&t.append(s("div",{class:"activity"},...e.activity.map(r=>s("div",{},r)))),e.content){let r=s("div",{class:`bubble${e.tone?` ${e.tone}`:""}`});r.append(b(e.content)),t.append(r)}e.attachments&&e.attachments.length>0&&t.append(Ae(e.attachments));let i=W(e.actions);i.length>0&&t.append(s("div",{class:"chips"},...i.map(r=>this.renderChip(r)))),e.review&&t.append(this.renderReview(e.review)),e.handoff&&t.append(this.renderHandoff(e.handoff));let n=E(e.at);return n&&t.append(s("div",{class:"time"},n)),t}renderStaffTurn(e){var r;let t=((r=e.author)==null?void 0:r.trim())||l.teamTag,i=s("div",{class:"turn staff","data-id":String(e.id)},s("div",{class:"staff-head"},s("span",{class:"avatar","aria-hidden":"true"},ot(t)),s("span",{class:"staff-name"},t),s("span",{class:"tag"},l.teamTag)));if(e.content){let d=s("div",{class:"bubble"});d.append(b(e.content)),i.append(d)}e.attachments&&e.attachments.length>0&&i.append(Ae(e.attachments));let n=E(e.at);return n&&i.append(s("div",{class:"time"},n)),i}renderChip(e){let t=!!e.undone_at,i=s("div",{class:`chip${t?" undone":""}`}),n=s("span",{class:"mark"},h(g.check,16)),r=[e.app,E(e.at)].filter(Boolean).join(" \xB7 "),d=s("div",{class:"text"},s("div",{},e.summary),r?s("div",{class:"meta"},r):null);if(i.append(n,d),t)i.append(s("span",{class:"status"},l.undone));else if(e.undoable){let o=s("button",{class:"btn ghost sm",type:"button"},l.undo);o.addEventListener("click",()=>void this.undo(e,i,o)),i.append(o)}return i}async undo(e,t,i){var n;i.disabled=!0,i.textContent=l.undoing;try{let r=await this.api.undo(e.id);if(r.ok===!1||r.error)throw new m(200,r.error||r.message||"That did not go through.");e.undone_at=(n=r.undone_at)!=null?n:new Date().toISOString(),t.classList.add("undone"),i.replaceWith(s("span",{class:"status"},l.undone)),Le([e],e.id)}catch(r){i.disabled=!1,i.textContent=l.undo,this.appendNotice(this.errorText(r))}}renderHandoff(e){var i;if((i=this.support)!=null&&i.enabled)return this.renderTeamHandoff(e);let t=s("div",{class:"card"},s("div",{class:"card-title"},l.handoff));if(B())t.append(s("div",{class:"quote"},e.question),s("div",{class:"row-actions"},s("button",{class:"btn primary",type:"button",onclick:()=>this.handOffToHelp(e.question)},l.askHelp)));else{let n=s("button",{class:"btn secondary sm",type:"button"},h(g.copy,14),l.copy);n.addEventListener("click",()=>{var r;(r=navigator.clipboard)==null||r.writeText(e.question).then(()=>{n.replaceChildren(h(g.check,14),l.copied)})}),t.append(s("div",{class:"quote"},e.question),s("div",{class:"row-actions"},n,s("span",{class:"hint"},l.noHelp)))}return t}renderTeamHandoff(e){var n;let t=s("div",{class:"card"},s("div",{class:"card-title"},l.teamHandoff));if(((n=this.support)==null?void 0:n.status)==="needs_teammate")return t;let i=s("button",{class:"btn primary",type:"button"},l.talkToTeam);return i.addEventListener("click",()=>void this.talkToTeam(e.question,i)),t.append(s("div",{class:"row-actions"},i)),t}async talkToTeam(e,t){var i,n;if(!this.busy){t.disabled=!0,this.setBusy(!0);try{let r=await this.api.escalate((n=(i=this.conversation)==null?void 0:i.id)!=null?n:null,e);this.setBusy(!1),this.applyBootstrap(r)}catch(r){this.setBusy(!1),t.disabled=!1,this.appendNotice(this.errorText(r))}}}renderReview(e){let t=s("div",{class:"card review","data-plan":e.id}),i=!Re.includes(e.status);t.append(s("div",{class:"card-title"},l.reviewTitle),s("div",{class:"hint"},l.reviewHint)),e.changes.length===0&&t.append(s("div",{class:"hint"},l.nothingLeft));let n=s("div",{class:"changes"}),r=new Map;for(let d of e.changes)n.append(this.renderChange(e,d,r,i,t));if(t.append(n),i)t.classList.add("done"),t.append(s("div",{class:"hint"},nt.includes(e.status)?l.setAsideDone:l.applying));else{let d=Z(e),o=s("button",{class:"btn primary",type:"button",disabled:e.changes.length===0||d>0},l.approve),u=s("button",{class:"btn secondary",type:"button"},l.setAside);if(o.addEventListener("click",()=>{let c=[...r.entries()].filter(([,p])=>p.checked).map(([p])=>p);this.reviewAction(e,"approve",{plan_hash:e.plan_hash,confirmed_danger:c},t,l.applying)}),u.addEventListener("click",()=>{this.reviewAction(e,"set-aside",{},t,l.setAsideDone)}),t.append(s("div",{class:"row-actions"},o,u)),d>0){let c=s("div",{class:"hint still-need",id:`ua-still-need-${e.id}`},ee(d));o.setAttribute("aria-describedby",c.id),t.append(c)}}return t}renderChange(e,t,i,n,r){let d=s("div",{class:"body"},s("div",{class:"what"},t.title)),o=t.from?`${t.from} to ${t.to}`:t.to;if(o&&d.append(s("div",{class:"delta"},o)),t.why&&d.append(s("div",{class:"why"},t.why)),(t.needs_confirmation||t.risk==="high")&&!n){let p=s("input",{type:"checkbox",id:`ua-care-${e.id}-${t.index}`});i.set(t.index,p),d.append(s("label",{class:"care",for:p.id},p,l.needsCare))}if(t.choice&&!n){let p=s("div",{class:"change choosing"},d);return d.append(te(e.id,t,f=>void this.chooseAction(e,t,f,p,r))),p}let c=s("div",{class:"change"},d);if(!n){let p=s("button",{class:"icon-btn remove",type:"button","aria-label":`${l.remove}: ${t.title}`,title:l.remove},h(g.close,14));p.addEventListener("click",()=>{this.reviewAction(e,"remove",{plan_hash:e.plan_hash,index:t.index},r,null)}),c.append(p)}return c}async chooseAction(e,t,i,n,r){var o;let d=[...r.querySelectorAll("button")].filter(u=>!n.contains(u)&&!u.disabled);d.forEach(u=>u.disabled=!0),(o=n.querySelector(".row-error"))==null||o.remove(),O(n,!0);try{let u=await this.api.choose(e.id,e.plan_hash,t.index,i);if(u.error)throw new m(200,u.error);this.applyReviewResult(e,"choose",u,r,null)}catch(u){O(n,!1),n.querySelectorAll('[role="radio"]').forEach(c=>c.setAttribute("aria-checked","false")),d.forEach(c=>c.disabled=!1),ie(n,this.errorText(u))}}async reviewAction(e,t,i,n,r){let d=[...n.querySelectorAll("button")].filter(o=>!o.disabled);d.forEach(o=>o.disabled=!0);try{let o=await this.api.review(e.id,t,i);if(o.error)throw new m(200,o.error);this.applyReviewResult(e,t,o,n,r)}catch(o){d.forEach(u=>u.disabled=!1),this.appendNotice(this.errorText(o))}}applyReviewResult(e,t,i,n,r){var d;if(i.review){let o=i.review;this.pendingReview=o,this.messages.forEach(u=>{var c;((c=u.review)==null?void 0:c.id)===o.id&&(u.review=o)}),n.replaceWith(this.renderReview(o))}else this.pendingReview=null,this.messages.forEach(o=>{var u;((u=o.review)==null?void 0:u.id)===e.id&&(o.review={...e,status:t==="set-aside"?"set_aside":"approved"})}),n.classList.add("done"),n.querySelectorAll(".row-actions, .remove, .care, .choice-block, .still-need").forEach(o=>o.remove()),n.append(s("div",{class:"hint"},r!=null?r:l.applying));t==="approve"&&(this.advanceTour({review:(d=i.review)!=null?d:{...e,status:"approved"},turn_state:"complete"}),(!i.review||K.includes(i.review.status))&&this.offerFeedback(null)),i.reply&&this.push({id:`r-${Date.now()}`,role:"assistant",content:i.reply,at:new Date().toISOString()}),i.notice&&this.appendNotice(i.notice)}onComposerKey(e){e.key==="Enter"&&!e.shiftKey&&!e.isComposing&&(e.preventDefault(),this.send())}autosize(){this.input.style.height="auto",this.input.style.height=`${Math.min(this.input.scrollHeight,140)}px`}async send(e){var o,u;let t=this.pendingFiles.slice(),n=(e!=null?e:this.input.value).trim()||(t.length>0?`${l.fileAdded}: ${t.map(c=>c.name).join(", ")}`:"");if(!n||this.busy)return;e===void 0&&(this.input.value="",this.autosize()),this.dropFeedbackCard(),this.dropFailedTurn();let r=`u-${Date.now()}`,d=this.draftKey();this.push({id:r,role:"user",content:n,at:new Date().toISOString(),attachments:t.length>0?t.map(c=>({id:c.id,name:c.name,size:c.size})):null}),this.dismissIntro(),this.setBusy(!0),this.slowTimer.start(c=>this.setWaitingLabel(c));try{let c=await this.api.send(n,this.setupApp,(u=(o=this.conversation)==null?void 0:o.id)!=null?u:null,t.map(f=>f.id)),p=fe(c);if(p!==null){this.showFailedTurn(n,r,p,!1);return}this.pendingFiles=this.pendingFiles.filter(f=>!t.some(v=>v.id===f.id)),this.renderPendingFiles(),he(d,n),this.acceptTurn(c),this.advanceTour(c)}catch(c){this.showFailedTurn(n,r,this.errorText(c),ge(c))}finally{this.slowTimer.stop(),this.setBusy(!1)}}draftKey(){var e,t;return this.storageKey(pe((t=(e=this.conversation)==null?void 0:e.id)!=null?t:null))}showFailedTurn(e,t,i,n){this.input.value.trim()===""&&(this.input.value=e,this.autosize()),_(this.draftKey(),this.input.value);let r={onRetry:()=>{this.dropFailedTurn(t),this.input.value.trim()===e&&(this.input.value="",this.autosize()),this.send(e)},onDismiss:()=>{this.dropFailedTurn(t),this.input.focus()}};this.failedRow=me(i,r,n),this.failedRow.dataset.retry=t,this.retryHandlers=r,this.list.append(this.failedRow),this.scrollToEnd(),n&&this.busyPoller.start()}busyGaveUp(){this.failedRow&&this.retryHandlers&&ve(this.failedRow,this.retryHandlers)}dropFailedTurn(e){var t,i;this.busyPoller.stop(),(t=this.failedRow)==null||t.remove(),this.failedRow=null,this.retryHandlers=null,e!==void 0&&(this.messages=this.messages.filter(n=>n.id!==e),(i=this.list.querySelector(`[data-id="${e}"]`))==null||i.remove())}acceptTurn(e){var d,o,u,c,p,f,v,y,w;let t=W(e.actions),i=((d=this.support)==null?void 0:d.status)==="needs_teammate";e.support&&(this.support=e.support);let n={id:(o=e.message_id)!=null?o:`a-${Date.now()}`,role:"assistant",content:(u=e.reply)!=null?u:"",at:new Date().toISOString(),activity:(c=e.activity)!=null?c:null,actions:t,review:(p=e.review)!=null?p:null,handoff:(f=e.handoff)!=null?f:null};e.error&&(n.content=n.content||e.error,n.tone="error"),e.review&&(this.pendingReview=e.review),(n.content||(v=n.actions)!=null&&v.length||n.review||n.handoff)&&this.push(n),e.notice&&this.appendNotice(e.notice),t.length>0&&Le(t);let r=e.review!==null&&e.review!==void 0&&K.includes(e.review.status);((y=e.turn_state)!=null?y:"complete")==="complete"&&(t.length>0||r)&&this.offerFeedback(typeof e.message_id=="number"?e.message_id:null),this.syncPolling(!i&&((w=this.support)==null?void 0:w.status)==="needs_teammate")}syncPolling(e=!1){var i;if(!(this.booted&&((i=this.support)==null?void 0:i.enabled)===!0&&this.support.status==="needs_teammate"&&this.conversation!==null)){this.stopPolling();return}(e||this.pollTimer===null)&&this.schedulePoll(e?0:this.pollDelay())}reschedulePoll(){this.pollTimer!==null&&this.schedulePoll(this.pollDelay())}pollDelay(){return this.open&&document.visibilityState!=="hidden"?rt:at}schedulePoll(e){this.stopPolling(),this.pollTimer=window.setTimeout(()=>void this.pollTeam(),e)}stopPolling(){this.pollTimer!==null&&(window.clearTimeout(this.pollTimer),this.pollTimer=null)}async pollTeam(){var t,i,n,r,d;this.pollTimer=null;let e=(i=(t=this.conversation)==null?void 0:t.id)!=null?i:null;if(e!==null){try{let o=await this.api.updates(e,this.lastSeenId());if(String((r=(n=this.conversation)==null?void 0:n.id)!=null?r:"")!==String(e))return;for(let u of(d=o.messages)!=null?d:[])this.messages.some(c=>String(c.id)===String(u.id))||(this.push(u),!this.open&&u.role==="staff"&&(this.badge.textContent="1",this.badge.hidden=!1));o.status&&this.support&&(this.support={...this.support,status:o.status})}catch{}this.pollTimer===null&&this.syncPolling()}}lastSeenId(){var t,i;let e=this.messages.map(n=>typeof n.id=="number"?n.id:Number.NaN).filter(n=>Number.isFinite(n));return e.length>0?Math.max(...e):(i=(t=this.support)==null?void 0:t.last_message_id)!=null?i:0}renderPendingFiles(){this.pendingEl.replaceChildren(...this.pendingFiles.map(e=>{let t=s("button",{class:"remove",type:"button","aria-label":`${l.removeFile} ${e.name}`,title:l.removeFile},h(g.close,14));return t.addEventListener("click",()=>{this.pendingFiles=this.pendingFiles.filter(i=>i.id!==e.id),this.renderPendingFiles(),this.input.focus()}),s("span",{class:"file-chip"},h(g.paperclip,14),s("span",{class:"name"},e.name),s("span",{class:"size"},F(e.size)),t)})),this.pendingEl.hidden=this.pendingFiles.length===0}offerFeedback(e){var d,o;let t=(o=(d=this.conversation)==null?void 0:d.id)!=null?o:null;if(!this.askFeedback||t===null)return;this.dropFeedbackCard();let i=s("div",{class:"card feedback-card"}),n=s("div",{class:"turn assistant feedback"},i);this.feedbackCard=n;let r=u=>{i.querySelectorAll("button").forEach(c=>c.disabled=!0),this.api.feedback(t,e,u).then(c=>{if(!c||c.ok===!1){this.dropFeedbackCard();return}if(u){this.showFeedbackClosing(i,l.feedbackThanks,!0);return}this.askFollowUp(i,typeof c.id=="number"?c.id:null)}).catch(()=>this.dropFeedbackCard())};i.append(s("div",{class:"question"},l.feedbackQuestion),s("div",{class:"row-actions"},s("button",{class:"btn secondary sm",type:"button",onclick:()=>r(!0)},l.yes),s("button",{class:"btn secondary sm",type:"button",onclick:()=>r(!1)},l.no))),this.list.append(n),this.scrollToEnd()}askFollowUp(e,t){if(t===null){this.showFeedbackClosing(e,l.feedbackNoted,!1);return}let i=n=>{e.querySelectorAll("button").forEach(r=>r.disabled=!0),this.api.feedbackFollowUp(t,n).then(()=>this.showFeedbackClosing(e,n?l.feedbackWillContact:l.feedbackNoted,!1)).catch(()=>this.showFeedbackClosing(e,l.feedbackNoted,!1))};e.replaceChildren(s("div",{class:"hint"},l.feedbackSorry),s("div",{class:"question"},l.feedbackFollowUp),s("div",{class:"row-actions"},s("button",{class:"btn primary sm",type:"button",onclick:()=>i(!0)},l.followUpYes),s("button",{class:"btn secondary sm",type:"button",onclick:()=>i(!1)},l.followUpNo))),this.scrollToEnd()}showFeedbackClosing(e,t,i){e.replaceChildren(s("div",{class:"hint"},t)),e.classList.add("answered"),i&&window.setTimeout(()=>{var n;(n=this.feedbackCard)!=null&&n.contains(e)&&this.dropFeedbackCard()},Ve)}dropFeedbackCard(){var e;(e=this.feedbackCard)==null||e.remove(),this.feedbackCard=null}async onFilePicked(){var t,i;let e=(t=this.fileInput.files)==null?void 0:t[0];if(this.fileInput.value="",!(!e||this.busy)){this.setBusy(!0,l.uploading);try{let n=await this.api.upload(e,this.setupApp);if(n.ok===!1||n.error)throw new m(200,n.error||"That file did not go through.");let r=(i=n.upload)!=null?i:null;if(r&&Te(r)&&(this.setBusy(!0,l.uploading,!1),!await this.waitForFile(r.id,e.name))){this.setBusy(!1);return}if(this.setBusy(!1),this.support!==null&&(r!=null&&r.id)){this.pendingFiles=[...this.pendingFiles,{id:r.id,name:e.name,size:e.size}],this.renderPendingFiles(),this.input.focus();return}let d=r!=null&&r.id?`${l.fileAdded} (file ${r.id})`:l.fileAdded;await this.send(`${d}: ${e.name}`)}catch(n){this.setBusy(!1),this.push({id:`e-${Date.now()}`,role:"assistant",content:this.errorText(n),tone:"error",at:new Date().toISOString()})}}}async waitForFile(e,t){var u;let i=s("span",{class:"status"},T.reading),n=s("span",{class:"dots","aria-hidden":"true"},s("span"),s("span"),s("span")),r=s("div",{class:"chip file reading",role:"status"},n,s("div",{class:"text"},s("div",{},t)),i),d=s("div",{class:"turn user file-turn"},s("div",{class:"chips"},r));this.list.append(d),this.scrollToEnd();let o=await ke(()=>this.api.uploadStatus(e));return o.status==="ready"?(d.remove(),!0):(r.classList.remove("reading"),r.classList.add("failed"),r.removeAttribute("role"),n.replaceWith(s("span",{class:"mark"},h(g.alert,16))),i.remove(),(u=r.querySelector(".text"))==null||u.append(s("div",{class:"meta",role:"alert"},o.error)),this.scrollToEnd(),!1)}askHelpInstead(){this.handOffToHelp("")||this.appendNotice(l.noHelp)}handOffToHelp(e){return B()?(this.avoidance.hold(),this.setOpen(!1,!0,!1),se(e)):!1}syncDock(){let e=this.booted&&!this.open,t=document.documentElement;e?(t.setAttribute(G,this.lifted?"lifted":"docked"),it()):t.removeAttribute(G)}push(e){var t;this.messages.push(e),this.messages.length===1&&((t=this.list.querySelector('[data-id="intro"]'))==null||t.remove()),this.list.append(this.renderTurn(e)),this.renderSuggestions(),this.scrollToEnd()}setBusy(e,t=l.working,i=!0){var n;this.busy=e,this.sendButton.disabled=e,this.attachButton.disabled=e,(n=this.waitingRow)==null||n.remove(),this.waitingRow=null,e&&i&&(this.waitingRow=s("div",{class:"turn assistant"},s("div",{class:"waiting",role:"status"},s("span",{class:"dots"},s("span"),s("span"),s("span")),s("span",{class:"waiting-label"},t))),this.list.append(this.waitingRow),this.scrollToEnd()),this.panel.querySelectorAll("button").forEach(r=>r.disabled=e)}setWaitingLabel(e){var i;let t=(i=this.waitingRow)==null?void 0:i.querySelector(".waiting-label");t&&(t.textContent=e)}appendNotice(e){this.list.append(s("div",{class:"notice"},e)),this.scrollToEnd()}errorText(e){return e instanceof m?e.message:"That did not go through. Try again."}scrollToEnd(){window.requestAnimationFrame(()=>{this.list.scrollTop=this.list.scrollHeight})}syncTheme(){let e=()=>{let t=document.documentElement,i=t.classList.contains("dark")||t.dataset.theme==="dark",n=t.classList.contains("light")||t.dataset.theme==="light";this.host.dataset.theme=i?"dark":n?"light":"",this.host.dataset.theme||delete this.host.dataset.theme};e(),new MutationObserver(e).observe(document.documentElement,{attributes:!0,attributeFilter:["class","data-theme"]})}};function lt(){var t;let a=(t=document.currentScript)!=null?t:document.querySelector("script[data-unified-assistant]"),e=a==null?void 0:a.dataset.unifiedAssistant;if(!e)return null;try{return JSON.parse(e)}catch{return null}}(function(){if(window.UnifiedAssistant)return;let e=lt();if(!e)return;let t=new V(e);window.UnifiedAssistant={open:()=>t.openDrawer(),close:()=>t.closeDrawer(),ask:n=>t.ask(n),setSetupApp:n=>t.setSetupApp(n),dismissIntro:()=>t.dismissIntro()};let i=()=>void t.boot();document.readyState==="loading"?document.addEventListener("DOMContentLoaded",i,{once:!0}):i()})();})();
