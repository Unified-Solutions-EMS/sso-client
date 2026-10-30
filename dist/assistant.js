"use strict";(()=>{var g=class extends Error{constructor(t,a){super(a);this.status=t}},k="The assistant is not reachable right now. Try again in a minute.",f=class{constructor(e){this.settings=e}async bootstrap(){let e=await this.request("GET",this.settings.bootstrap);return e.status===204?null:await this.read(e)}async send(e,t){let a={message:e,page:{url:location.href,title:document.title}};return t&&(a.setup_app=t),await this.json("POST",this.settings.messages,a)}async upload(e){let t=new FormData;t.append("file",e,e.name);let a=await this.request("POST",this.settings.uploads,t);return await this.read(a)}async undo(e){return await this.json("POST",this.settings.undo.replace("{action}",String(e)),{})}async review(e,t,a){let i=this.settings.review.replace("{plan}",encodeURIComponent(e)).replace("{verb}",t);return await this.json("POST",i,a)}async clear(){await this.request("DELETE",this.settings.conversation)}async json(e,t,a){let i=await this.request(e,t,JSON.stringify(a),"application/json");return this.read(i)}async request(e,t,a,i){let s={Accept:"application/json","X-Requested-With":"XMLHttpRequest"};this.settings.csrf&&(s["X-CSRF-TOKEN"]=this.settings.csrf),i&&(s["Content-Type"]=i);try{return await fetch(t,{method:e,headers:s,body:a,credentials:"same-origin"})}catch{throw new g(0,k)}}async read(e){let t=null;try{t=await e.json()}catch{t=null}if(e.status===204)return{};if(!e.ok){let a=t!=null?t:{},i=typeof a.error=="string"&&a.error||typeof a.message=="string"&&a.message||(e.status===419||e.status===401?"Your session has expired. Refresh the page and try again.":e.status>=500?k:"That did not go through. Try again.");throw new g(e.status,i)}return t!=null?t:{}}};function n(l,e={},...t){let a=document.createElement(l);for(let[i,s]of Object.entries(e))s==null||s===!1||(typeof s=="function"?a.addEventListener(i.replace(/^on/,"").toLowerCase(),s):s===!0?a.setAttribute(i,""):i==="class"?a.className=s:a.setAttribute(i,s));for(let i of t)i==null||i===!1||a.append(typeof i=="string"?document.createTextNode(i):i);return a}function c(l,e=16){let t="http://www.w3.org/2000/svg",a=document.createElementNS(t,"svg");a.setAttribute("viewBox","0 0 24 24"),a.setAttribute("width",String(e)),a.setAttribute("height",String(e)),a.setAttribute("fill","none"),a.setAttribute("stroke","currentColor"),a.setAttribute("stroke-width","1.8"),a.setAttribute("stroke-linecap","round"),a.setAttribute("stroke-linejoin","round"),a.setAttribute("aria-hidden","true");let i=document.createElementNS(t,"path");return i.setAttribute("d",l),a.append(i),a}var h={sparkles:"M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3zM5 18l.7 1.8L7.5 20.5l-1.8.7L5 23l-.7-1.8-1.8-.7 1.8-.7L5 18zM19 2l.6 1.4L21 4l-1.4.6L19 6l-.6-1.4L17 4l1.4-.6L19 2z",close:"M6 6l12 12M18 6L6 18",send:"M5 12h14M13 6l6 6-6 6",paperclip:"M21 12.5l-8.5 8.5a5.5 5.5 0 01-7.8-7.8l9.2-9.2a3.5 3.5 0 015 5l-9.2 9.2a1.5 1.5 0 01-2.1-2.1l8.5-8.5",copy:"M9 9h10v12H9zM5 15V3h10",check:"M5 12l5 5L20 7"};function E(l){let e=document.createDocumentFragment();return l.replace(/\r\n/g,`
`).split(/\n{2,}/).forEach(a=>{let i=document.createElement("p");a.split(`
`).forEach((o,d)=>{d>0&&i.append(document.createElement("br")),i.append(document.createTextNode(o))}),e.append(i)}),e}function b(l){if(!l)return"";let e=new Date(l);if(Number.isNaN(e.getTime()))return"";let t=e.toDateString()===new Date().toDateString(),a=e.toLocaleTimeString(void 0,{hour:"numeric",minute:"2-digit"});return t?a:`${e.toLocaleDateString(void 0,{month:"short",day:"numeric"})} ${a}`}function m(){let l=window.Intercom;return typeof l=="function"?l:null}function T(){return m()!==null}function x(l){var e;(e=m())==null||e("update",{hide_default_launcher:l})}function w(l){let e=m();return e?(e("update",{hide_default_launcher:!1}),e("showNewMessage",l),!0):!1}var S=`
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
  :host(:not([data-theme="light"])) { ${L()} }
}
:host([data-theme="dark"]) { ${L()} }

*, *::before, *::after { box-sizing: border-box; }
button, input, textarea { font: inherit; color: inherit; }
button { cursor: pointer; background: none; border: 0; padding: 0; }
button:disabled { cursor: default; opacity: 0.5; }
p { margin: 0; }
p + p { margin-top: 0.5em; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

:focus-visible { outline: 2px solid rgb(var(--ua-ring)); outline-offset: 2px; }

/* Launcher */
.launcher {
  position: fixed; right: 92px; bottom: 20px; z-index: 2147483000;
  display: inline-flex; align-items: center; gap: 8px;
  height: 44px; padding: 0 16px 0 14px;
  border-radius: 9999px;
  background: rgb(var(--ua-primary)); color: rgb(var(--ua-on-accent));
  font-weight: 600; font-size: 14px;
  box-shadow: var(--ua-shadow);
  transition: background-color var(--ua-fast) var(--ua-ease), transform var(--ua-fast) var(--ua-ease);
}
.launcher:hover { background: rgb(var(--ua-primary-hover)); }
.launcher:active { transform: translateY(1px); }
.launcher[hidden] { display: none; }
@media (max-width: 639px) {
  .launcher { right: 84px; bottom: 16px; height: 40px; padding: 0 14px 0 12px; }
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
`;function L(){return`
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
  --ua-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.4);`}var r={launcher:"Assistant",title:"Assistant",subtitle:"Does tasks for you. For questions about how something works, use Help.",intro:"I'm your assistant. Tell me what to do and I'll do it: add people, set up facilities, fill a shift, change a setting. If you want to know how something works, Help is the place to ask.",placeholder:"Tell me what to do",send:"Send",attach:"Add a file",close:"Close",working:"Working on it",askHelpInstead:"Ask Help instead",handoff:"I can't do that one, but Help can take it from here.",askHelp:"Ask Help",noHelp:"Open Help from the menu to send this.",copy:"Copy",copied:"Copied",undo:"Undo",undone:"Undone",undoing:"Undoing",reviewTitle:"Review these changes",reviewHint:"Take out anything you do not want, then approve.",needsCare:"Needs extra care. Check to include it.",approve:"Approve and apply",setAside:"Set aside",remove:"Remove",applying:"Applying your changes.",setAsideDone:"Set aside. Ask me when you want to pick it up again.",nothingLeft:"Nothing left to apply.",fileAdded:"I added a file",uploading:"Adding your file",enterHint:"Enter to send, Shift+Enter for a new line"},R="unified-assistant:open",y=class{constructor(e){this.messages=[];this.pendingReview=null;this.open=!1;this.busy=!1;this.booted=!1;this.waitingRow=null;this.lastFocus=null;var t;this.api=new f(e),this.setupApp=(t=e.setup_app)!=null?t:null,this.host=document.createElement("unified-assistant"),this.root=this.host.attachShadow({mode:"open"}),this.syncTheme(),this.build()}async boot(){var a,i,s;let e;try{e=await this.api.bootstrap()}catch{return}if(!e||e.enabled===!1)return;this.messages=((i=(a=e.conversation)==null?void 0:a.messages)!=null?i:[]).slice(),this.pendingReview=(s=e.review)!=null?s:null,this.booted=!0,document.body.append(this.host),this.renderMessages(),this.launcher.hidden=!1,e.notice&&this.list.append(n("div",{class:"notice"},e.notice));let t=!1;try{t=sessionStorage.getItem(R)==="1"}catch{t=!1}t&&this.setOpen(!0,!1),window.addEventListener("pagehide",()=>x(!1))}openDrawer(){this.booted&&this.setOpen(!0)}closeDrawer(){this.setOpen(!1)}ask(e){this.booted&&(this.setOpen(!0),this.send(e))}setSetupApp(e){this.setupApp=e}build(){let e=document.createElement("style");e.textContent=S,this.launcher=n("button",{class:"launcher",type:"button",hidden:!0,"aria-haspopup":"dialog","aria-expanded":"false",onclick:()=>this.setOpen(!0)},c(h.sparkles,18),r.launcher),this.scrim=n("div",{class:"scrim",onclick:()=>this.setOpen(!1)}),this.list=n("div",{class:"list",role:"log","aria-live":"polite","aria-relevant":"additions"}),this.input=n("textarea",{rows:"1",placeholder:r.placeholder,"aria-label":r.placeholder,onkeydown:i=>this.onComposerKey(i),oninput:()=>this.autosize()}),this.sendButton=n("button",{class:"icon-btn send",type:"button","aria-label":r.send,onclick:()=>void this.send()},c(h.send)),this.fileInput=n("input",{type:"file",class:"sr",tabindex:"-1","aria-hidden":"true",onchange:()=>void this.onFilePicked()}),this.attachButton=n("button",{class:"icon-btn",type:"button","aria-label":r.attach,title:r.attach,onclick:()=>this.fileInput.click()},c(h.paperclip));let t=n("header",{class:"header"},n("div",{class:"title-wrap"},n("h2",{id:"ua-title"},c(h.sparkles,16),r.title),n("div",{class:"subtitle"},r.subtitle)),n("button",{class:"icon-btn",type:"button","aria-label":r.close,onclick:()=>this.setOpen(!1)},c(h.close))),a=n("footer",{class:"footer"},n("div",{class:"composer"},this.attachButton,this.input,this.sendButton),n("div",{class:"footer-row"},n("span",{},r.enterHint),n("button",{class:"link",type:"button",onclick:()=>this.askHelpInstead()},r.askHelpInstead)),this.fileInput);this.drawer=n("section",{class:"drawer",role:"dialog","aria-modal":"true","aria-labelledby":"ua-title","aria-hidden":"true"},t,this.list,a),this.drawer.addEventListener("keydown",i=>{i.key==="Escape"&&this.setOpen(!1)}),this.root.append(e,this.launcher,this.scrim,this.drawer)}setOpen(e,t=!0){if(e!==this.open){if(this.open=e,this.drawer.classList.toggle("open",e),this.scrim.classList.toggle("open",e),this.drawer.setAttribute("aria-hidden",e?"false":"true"),this.launcher.setAttribute("aria-expanded",e?"true":"false"),this.launcher.hidden=e,x(e),t)try{sessionStorage.setItem(R,e?"1":"0")}catch{}e?(this.lastFocus=document.activeElement,this.scrollToEnd(),window.setTimeout(()=>this.input.focus(),50)):this.lastFocus instanceof HTMLElement&&this.lastFocus.isConnected?this.lastFocus.focus():this.launcher.focus()}}renderMessages(){this.list.replaceChildren(),this.messages.length===0&&this.list.append(this.renderTurn({id:"intro",role:"assistant",content:r.intro}));for(let e of this.messages)this.list.append(this.renderTurn(e));this.pendingReview&&!this.messages.some(e=>{var t,a;return((t=e.review)==null?void 0:t.id)===((a=this.pendingReview)==null?void 0:a.id)})&&this.list.append(n("div",{class:"turn assistant"},this.renderReview(this.pendingReview))),this.scrollToEnd()}renderTurn(e){let t=n("div",{class:`turn ${e.role}`,"data-id":String(e.id)});if(e.role==="assistant"&&e.activity&&e.activity.length>0&&t.append(n("div",{class:"activity"},...e.activity.map(i=>n("div",{},i)))),e.content){let i=n("div",{class:`bubble${e.tone?` ${e.tone}`:""}`});i.append(E(e.content)),t.append(i)}e.actions&&e.actions.length>0&&t.append(n("div",{class:"chips"},...e.actions.map(i=>this.renderChip(i)))),e.review&&t.append(this.renderReview(e.review)),e.handoff&&t.append(this.renderHandoff(e.handoff));let a=b(e.at);return a&&t.append(n("div",{class:"time"},a)),t}renderChip(e){let t=!!e.undone_at,a=n("div",{class:`chip${t?" undone":""}`}),i=n("span",{class:"mark"},c(h.check,16)),s=[e.app,b(e.at)].filter(Boolean).join(" \xB7 "),o=n("div",{class:"text"},n("div",{},e.summary),s?n("div",{class:"meta"},s):null);if(a.append(i,o),t)a.append(n("span",{class:"status"},r.undone));else if(e.undoable){let d=n("button",{class:"btn ghost sm",type:"button"},r.undo);d.addEventListener("click",()=>void this.undo(e,a,d)),a.append(d)}return a}async undo(e,t,a){var i;a.disabled=!0,a.textContent=r.undoing;try{let s=await this.api.undo(e.id);if(s.ok===!1||s.error)throw new g(200,s.error||s.message||"That did not go through.");e.undone_at=(i=s.undone_at)!=null?i:new Date().toISOString(),t.classList.add("undone"),a.replaceWith(n("span",{class:"status"},r.undone))}catch(s){a.disabled=!1,a.textContent=r.undo,this.appendNotice(this.errorText(s))}}renderHandoff(e){let t=n("div",{class:"card"},n("div",{class:"card-title"},r.handoff));if(T())t.append(n("div",{class:"quote"},e.question),n("div",{class:"row-actions"},n("button",{class:"btn primary",type:"button",onclick:()=>{w(e.question),this.setOpen(!1)}},r.askHelp)));else{let a=n("button",{class:"btn secondary sm",type:"button"},c(h.copy,14),r.copy);a.addEventListener("click",()=>{var i;(i=navigator.clipboard)==null||i.writeText(e.question).then(()=>{a.replaceChildren(c(h.check,14),r.copied)})}),t.append(n("div",{class:"quote"},e.question),n("div",{class:"row-actions"},a,n("span",{class:"hint"},r.noHelp)))}return t}renderReview(e){let t=n("div",{class:"card review","data-plan":e.id}),a=e.status!=="proposed"&&e.status!=="draft"&&e.status!=="pending";t.append(n("div",{class:"card-title"},r.reviewTitle),n("div",{class:"hint"},r.reviewHint)),e.changes.length===0&&t.append(n("div",{class:"hint"},r.nothingLeft));let i=n("div",{class:"changes"}),s=new Map;for(let o of e.changes)i.append(this.renderChange(e,o,s,a,t));if(t.append(i),a)t.classList.add("done"),t.append(n("div",{class:"hint"},e.status==="set_aside"?r.setAsideDone:r.applying));else{let o=n("button",{class:"btn primary",type:"button",disabled:e.changes.length===0},r.approve),d=n("button",{class:"btn secondary",type:"button"},r.setAside);o.addEventListener("click",()=>{let p=[...s.entries()].filter(([,u])=>u.checked).map(([u])=>u);this.reviewAction(e,"approve",{plan_hash:e.plan_hash,confirmed_danger:p},t,r.applying)}),d.addEventListener("click",()=>{this.reviewAction(e,"set-aside",{},t,r.setAsideDone)}),t.append(n("div",{class:"row-actions"},o,d))}return t}renderChange(e,t,a,i,s){let o=n("div",{class:"body"},n("div",{class:"what"},t.title)),d=t.from?`${t.from} to ${t.to}`:t.to;if(d&&o.append(n("div",{class:"delta"},d)),t.why&&o.append(n("div",{class:"why"},t.why)),(t.needs_confirmation||t.risk==="high")&&!i){let v=n("input",{type:"checkbox",id:`ua-care-${e.id}-${t.index}`});a.set(t.index,v),o.append(n("label",{class:"care",for:v.id},v,r.needsCare))}let u=n("div",{class:"change"},o);if(!i){let v=n("button",{class:"icon-btn remove",type:"button","aria-label":`${r.remove}: ${t.title}`,title:r.remove},c(h.close,14));v.addEventListener("click",()=>{this.reviewAction(e,"remove",{plan_hash:e.plan_hash,index:t.index},s,null)}),u.append(v)}return u}async reviewAction(e,t,a,i,s){i.querySelectorAll("button").forEach(o=>o.disabled=!0);try{let o=await this.api.review(e.id,t,a);if(o.error)throw new g(200,o.error);if(o.review){let d=o.review;this.pendingReview=d,this.messages.forEach(p=>{var u;((u=p.review)==null?void 0:u.id)===d.id&&(p.review=d)}),i.replaceWith(this.renderReview(d))}else this.pendingReview=null,this.messages.forEach(d=>{var p;((p=d.review)==null?void 0:p.id)===e.id&&(d.review={...e,status:t==="set-aside"?"set_aside":"approved"})}),i.classList.add("done"),i.querySelectorAll(".row-actions, .remove, .care").forEach(d=>d.remove()),i.append(n("div",{class:"hint"},s!=null?s:r.applying));o.reply&&this.push({id:`r-${Date.now()}`,role:"assistant",content:o.reply,at:new Date().toISOString()}),o.notice&&this.appendNotice(o.notice)}catch(o){i.querySelectorAll("button").forEach(d=>d.disabled=!1),this.appendNotice(this.errorText(o))}}onComposerKey(e){e.key==="Enter"&&!e.shiftKey&&!e.isComposing&&(e.preventDefault(),this.send())}autosize(){this.input.style.height="auto",this.input.style.height=`${Math.min(this.input.scrollHeight,140)}px`}async send(e){let t=(e!=null?e:this.input.value).trim();if(!(!t||this.busy)){e===void 0&&(this.input.value="",this.autosize()),this.push({id:`u-${Date.now()}`,role:"user",content:t,at:new Date().toISOString()}),this.setBusy(!0);try{let a=await this.api.send(t,this.setupApp);this.acceptTurn(a)}catch(a){this.push({id:`e-${Date.now()}`,role:"assistant",content:this.errorText(a),tone:"error",at:new Date().toISOString()})}finally{this.setBusy(!1)}}}acceptTurn(e){var a,i,s,o,d,p,u;let t={id:(a=e.message_id)!=null?a:`a-${Date.now()}`,role:"assistant",content:(i=e.reply)!=null?i:"",at:new Date().toISOString(),activity:(s=e.activity)!=null?s:null,actions:(o=e.actions)!=null?o:null,review:(d=e.review)!=null?d:null,handoff:(p=e.handoff)!=null?p:null};e.error&&(t.content=t.content||e.error,t.tone="error"),e.review&&(this.pendingReview=e.review),(t.content||(u=t.actions)!=null&&u.length||t.review||t.handoff)&&this.push(t),e.notice&&this.appendNotice(e.notice)}async onFilePicked(){var t;let e=(t=this.fileInput.files)==null?void 0:t[0];if(this.fileInput.value="",!(!e||this.busy)){this.setBusy(!0,r.uploading);try{let a=await this.api.upload(e);if(a.ok===!1||a.error)throw new g(200,a.error||"That file did not go through.");this.setBusy(!1),await this.send(`${r.fileAdded}: ${e.name}`)}catch(a){this.setBusy(!1),this.push({id:`e-${Date.now()}`,role:"assistant",content:this.errorText(a),tone:"error",at:new Date().toISOString()})}}}askHelpInstead(){w("")?this.setOpen(!1):this.appendNotice(r.noHelp)}push(e){var t;this.messages.push(e),this.messages.length===1&&((t=this.list.querySelector('[data-id="intro"]'))==null||t.remove()),this.list.append(this.renderTurn(e)),this.scrollToEnd()}setBusy(e,t=r.working){var a;this.busy=e,this.sendButton.disabled=e,this.attachButton.disabled=e,(a=this.waitingRow)==null||a.remove(),this.waitingRow=null,e&&(this.waitingRow=n("div",{class:"turn assistant"},n("div",{class:"waiting"},n("span",{class:"dots"},n("span"),n("span"),n("span")),t)),this.list.append(this.waitingRow),this.scrollToEnd())}appendNotice(e){this.list.append(n("div",{class:"notice"},e)),this.scrollToEnd()}errorText(e){return e instanceof g?e.message:"That did not go through. Try again."}scrollToEnd(){window.requestAnimationFrame(()=>{this.list.scrollTop=this.list.scrollHeight})}syncTheme(){let e=()=>{let t=document.documentElement,a=t.classList.contains("dark")||t.dataset.theme==="dark",i=t.classList.contains("light")||t.dataset.theme==="light";this.host.dataset.theme=a?"dark":i?"light":"",this.host.dataset.theme||delete this.host.dataset.theme};e(),new MutationObserver(e).observe(document.documentElement,{attributes:!0,attributeFilter:["class","data-theme"]})}};function A(){var t;let l=(t=document.currentScript)!=null?t:document.querySelector("script[data-unified-assistant]"),e=l==null?void 0:l.dataset.unifiedAssistant;if(!e)return null;try{return JSON.parse(e)}catch{return null}}(function(){if(window.UnifiedAssistant)return;let e=A();if(!e)return;let t=new y(e);window.UnifiedAssistant={open:()=>t.openDrawer(),close:()=>t.closeDrawer(),ask:i=>t.ask(i),setSetupApp:i=>t.setSetupApp(i)};let a=()=>void t.boot();document.readyState==="loading"?document.addEventListener("DOMContentLoaded",a,{once:!0}):a()})();})();
