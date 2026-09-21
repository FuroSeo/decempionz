#!/usr/bin/env node
"use strict";

const fs = require("fs");
const path = require("path");
const vm = require("vm");

const homepage = fs.readFileSync(path.resolve(__dirname, "..", "index.html"), "utf8");
const start = homepage.indexOf("function quickStartPreset(mode)");
const end = homepage.indexOf("function startGameCopa()", start);
if (start < 0 || end < 0) throw new Error("Quick Draft implementation not found");

const blind = {checked:true};
const calls = [];
const sandbox = {
  G:{dynasty:true,dynastyClub:"old",blindDraft:true,formation:"5-4-1",difficulty:"hard",format:"nuovo",tactic:"attack"},
  selectedFormat:"nuovo",selectedEraT:{id:"old"},selectedDiff:"hard",
  TOURNAMENTS:[{id:"alltime",allTime:true}],
  COPA_TOURNAMENTS:[{id:"copa_alltime",allTime:true}],
  WC_TOURNAMENTS:[{id:"wc_alltime",allTime:true}],
  document:{getElementById:id=>id==="blind-draft-cb"?blind:null},
  applyTournament(era){calls.push(["era",era.id]);sandbox.G.formation=null;sandbox.G.tactic="balanced";},
  applyTournamentTheme(mode){calls.push(["theme",mode]);},
  _ga(name,data){calls.push([name,data]);},
  initDraft(){calls.push(["draft",sandbox.G.gameMode,sandbox.G.formation]);}
};
vm.createContext(sandbox);
vm.runInContext(homepage.slice(start,end),sandbox,{filename:"index.html#quick-start"});

for (const [mode,formation,era] of [["ucl","4-3-3","alltime"],["copa","4-4-2","copa_alltime"],["wc","4-3-3","wc_alltime"]]) {
  calls.length=0;blind.checked=true;sandbox.G.dynasty=true;sandbox.G.dynastyClub="old";sandbox.G.blindDraft=true;
  sandbox.quickStartPreset(mode);
  if (sandbox.G.gameMode!==mode || sandbox.G.formation!==formation) throw new Error(mode+" preset mismatch");
  if (sandbox.G.format!=="classic" || sandbox.G.difficulty!=="normal" || sandbox.G.tactic!=="balanced") throw new Error(mode+" safe defaults missing");
  if (sandbox.G.dynasty || sandbox.G.dynastyClub!==null || sandbox.G.blindDraft || blind.checked) throw new Error(mode+" leaked stale mode state");
  if (!calls.some(c=>c[0]==="era"&&c[1]===era)) throw new Error(mode+" All Time era missing");
  if (!calls.some(c=>c[0]==="quick_start"&&c[1].formation===formation)) throw new Error(mode+" analytics missing");
  if (!calls.some(c=>c[0]==="draft"&&c[1]===mode&&c[2]===formation)) throw new Error(mode+" did not enter Draft directly");
}

for (const marker of ["function quickStart(){quickStartPreset('ucl');}","function quickStartCopa(){quickStartPreset('copa');}","function quickStartWC(){quickStartPreset('wc');}"]) {
  if (!homepage.includes(marker)) throw new Error("Home CTA wrapper missing: "+marker);
}
/* Tema con localStorage bloccato (modalità privata, cookie disattivati): non deve lanciare
   e deve comunque applicare tema e icona. */
{
  const boot = homepage.match(/<body><script>\n(\(function\(\)\{var t=[^\n]*gl-theme[^\n]*\}\)\(\);)\n<\/script>/);
  if (!boot) throw new Error("Theme boot script not found");
  const tStart = homepage.indexOf("function toggleTheme(){");
  const tEnd = homepage.indexOf("// Aggiorna icona al caricamento", tStart);
  if (tStart < 0 || tEnd < 0) throw new Error("Theme toggle not found");
  const attrs = {};
  const icon = {textContent:""};
  const blocked = {getItem(){throw new Error("SecurityError");},setItem(){throw new Error("SecurityError");}};
  const themeBox = {
    localStorage:blocked,
    document:{
      body:{getAttribute:k=>attrs[k]||null,setAttribute:(k,v)=>{attrs[k]=v;}},
      getElementById:id=>id==="theme-icon"?icon:{classList:{contains:()=>false}},
      querySelectorAll:()=>[]
    },
    renderGameOverPitch(){},renderTrophyPitch(){},injectPitchLines(){}
  };
  vm.createContext(themeBox);
  vm.runInContext(boot[1], themeBox, {filename:"index.html#theme-boot"});
  vm.runInContext(homepage.slice(tStart,tEnd), themeBox, {filename:"index.html#theme"});
  themeBox.toggleTheme();
  if (attrs["data-theme"]!=="light" || icon.textContent!=="\u{1F319}") throw new Error("toggleTheme must apply theme and icon with blocked storage");
  themeBox.toggleTheme();
  if (attrs["data-theme"]!=="" || icon.textContent!=="\u2600\uFE0F") throw new Error("toggleTheme back to dark failed with blocked storage");
  const ok = {store:{"gl-theme":"light"}};
  ok.localStorage = {getItem:k=>ok.store[k]||null,setItem:(k,v)=>{ok.store[k]=v;}};
  ok.document = themeBox.document;
  for (const k of Object.keys(attrs)) delete attrs[k];
  vm.createContext(ok);
  vm.runInContext(boot[1], ok, {filename:"index.html#theme-boot"});
  if (attrs["data-theme"]!=="light") throw new Error("Stored light theme not restored");
}
/* Parità delle traduzioni: una chiave presente in una lingua e assente in un'altra fa
   comparire testo in lingua sbagliata (t() ricade sull'inglese). */
{
  const iStart = homepage.indexOf("const STRINGS={");
  const iEnd = homepage.indexOf("function t(key){", iStart);
  if (iStart < 0 || iEnd < 0) throw new Error("STRINGS dictionary not found");
  const box = {};
  vm.createContext(box);
  vm.runInContext(homepage.slice(iStart, iEnd) + ";this.S=STRINGS;", box, {filename:"index.html#strings"});
  const langs = ["it","en","es"];
  for (const a of langs) for (const b of langs) {
    if (a === b) continue;
    const missing = Object.keys(box.S[a]).filter(k => !(k in box.S[b]));
    if (missing.length) throw new Error("Translation keys in "+a+" missing from "+b+": "+missing.join(", "));
  }
  for (const lang of langs) if (!box.S[lang]["draft.rerolls"]) throw new Error("draft.rerolls missing in "+lang);
  if (/'Rerolls: <strong/.test(homepage)) throw new Error("Draft header Rerolls label must use t('draft.rerolls')");
}
/* Reroll: un doppio click nella finestra di 130 ms consumava due reroll. */
{
  const rStart = homepage.indexOf("var _rerollBusy=false;");
  const rEnd = homepage.indexOf("function finalizeDraft(){", rStart);
  if (rStart < 0 || rEnd < 0) throw new Error("draftReroll not found");
  const timers = [];
  const rbox = {
    G:{passes:3,difficulty:"easy",slotPlayers:[null,null,null],draftCards:[{n:"a"},{n:"b"},{n:"c"}],draftDiscarded:[]},
    DUEL:{mode:false},
    document:{getElementById:()=>null},
    setTimeout:(fn)=>{timers.push(fn);},
    requestAnimationFrame(fn){fn();},
    drawDraftCards(){rbox.drawn=(rbox.drawn||0)+1;rbox.G.draftCards=[{n:"x"},{n:"y"},{n:"z"}];},
    updateDraftScreen(){}
  };
  vm.createContext(rbox);
  vm.runInContext(homepage.slice(rStart, rEnd), rbox, {filename:"index.html#reroll"});
  rbox.draftReroll(); rbox.draftReroll(); rbox.draftReroll();
  if (rbox.G.passes !== 2) throw new Error("Rapid reroll clicks must consume one reroll, got "+(3-rbox.G.passes));
  if (rbox.G.draftDiscarded.length !== 3) throw new Error("Discarded pool must hold the replaced cards once");
  timers.shift()();
  if (rbox.drawn !== 1) throw new Error("Cards must be redrawn exactly once");
  rbox.draftReroll();
  if (rbox.G.passes !== 1) throw new Error("A new reroll must work after the transition ends");
  timers.shift()();
  rbox.drawDraftCards = function(){throw new Error("boom");};
  rbox.draftReroll();
  try { timers.shift()(); } catch (e) { /* atteso */ }
  rbox.G.passes = 3;
  rbox.draftReroll();
  if (rbox.G.passes !== 2) throw new Error("A failed redraw must not leave reroll locked");
}

/* Anteprima con doppio tap solo per input touch, non per il mouse su schermi touch. */
{
  const dStart = homepage.indexOf("var _draftPreviewIdx=-1;");
  const dEnd = homepage.indexOf("document.addEventListener('touchstart'", dStart);
  if (dStart < 0 || dEnd < 0) throw new Error("draftTap not found");
  const handlers = {};
  const picks = [], previews = [];
  const dbox = {
    window:{matchMedia:()=>({matches:false}),ontouchstart:null},
    document:{addEventListener:(ev,fn)=>{handlers[ev]=fn;},querySelectorAll:()=>[]},
    draftPick:i=>picks.push(i),previewCard:i=>previews.push(i)
  };
  vm.createContext(dbox);
  vm.runInContext(homepage.slice(dStart, dEnd), dbox, {filename:"index.html#draft-tap"});
  handlers.pointerdown({pointerType:"mouse"});
  dbox.draftTap(1);
  if (picks.join() !== "1" || previews.length) throw new Error("Mouse click must pick immediately even on touch-capable desktops");
  handlers.pointerdown({pointerType:"touch"});
  dbox.draftTap(2);
  if (picks.length !== 1 || previews.join() !== "2") throw new Error("First touch tap must only preview");
  dbox.draftTap(2);
  if (picks.join() !== "1,2") throw new Error("Second touch tap must confirm the pick");
  handlers.pointerdown({pointerType:"mouse"});
  dbox.draftTap(0);
  if (picks.join() !== "1,2,0") throw new Error("Switching back to mouse must pick immediately");
  const coarse = {window:{matchMedia:()=>({matches:true})},document:{addEventListener(){},querySelectorAll:()=>[]},draftPick(){},previewCard:i=>coarse.p=i};
  vm.createContext(coarse);
  vm.runInContext(homepage.slice(dStart, dEnd), coarse, {filename:"index.html#draft-tap-coarse"});
  coarse.draftTap(1);
  if (coarse.p !== 1) throw new Error("A coarse primary pointer must start in preview mode");
}
/* Il Duel non e' una campagna: il suo draft non deve contare come campagna iniziata (statistiche
   personali e globali), altrimenti la percentuale di campagne vinte cala a ogni duello. */
{
  const dStart = homepage.indexOf("function _duelInitServerDraft(){");
  const dEnd = homepage.indexOf("\nfunction ", dStart + 10);
  if (dStart < 0 || dEnd < 0) throw new Error("_duelInitServerDraft not found");
  if (homepage.slice(dStart, dEnd).includes("_dczStatsCampaignStart(")) throw new Error("Duel draft must not count as a campaign start");
  const cStart = homepage.indexOf("function initDraft(){");
  const cEnd = homepage.indexOf("\nfunction ", cStart + 10);
  const initSrc = homepage.slice(cStart, cEnd);
  if (!initSrc.includes("_dczStatsCampaignStart();")) throw new Error("Campaign draft must still count as a campaign start");
}
/* Il cambio lingua deve rigenerare anche il record Manager e la maestria (testo dinamico). */
{
  const lStart = homepage.indexOf("function applyLang(){");
  const lEnd = homepage.indexOf("/* ═", lStart);
  if (lStart < 0 || lEnd < 0) throw new Error("applyLang not found");
  if (!homepage.slice(lStart, lEnd).includes("renderManagerProgress()")) throw new Error("applyLang must refresh the Manager progress texts");
}
/* Modali: il Tab resta dentro il dialogo (focus trap). Tutorial: Esc salta, Invio/Spazio/Freccia avanzano. */
{
  const fStart = homepage.indexOf("function _modalFocusable(modal){");
  const fEnd = homepage.indexOf("document.addEventListener('keydown',function(e){", fStart);
  if (fStart < 0 || fEnd < 0) throw new Error("focus trap helpers not found");
  const mk = (name) => ({name, disabled:false, getAttribute:()=>null, getClientRects:()=>[1], focus(){focused = this;}});
  let focused = null;
  const hidden = {name:"hidden", disabled:false, getAttribute:()=>null, getClientRects:()=>[], focus(){}};
  const off = {name:"off", disabled:true, getAttribute:()=>null, getClientRects:()=>[1], focus(){}};
  const skipTab = {name:"skip", disabled:false, getAttribute:k=>k==="tabindex"?"-1":null, getClientRects:()=>[1], focus(){}};
  const a = mk("a"), b = mk("b"), c = mk("c");
  const modal = {querySelectorAll:()=>[a, hidden, b, off, skipTab, c]};
  const tbox = {};
  vm.createContext(tbox);
  vm.runInContext(homepage.slice(fStart, fEnd), tbox, {filename:"index.html#focus-trap"});
  const ev = (over) => Object.assign({key:"Tab", shiftKey:false, prevented:false, preventDefault(){this.prevented = true;}}, over);
  let e = ev({}); focused = null;
  if (!tbox._trapTab(modal, e, c) || !e.prevented || focused !== a) throw new Error("Tab on the last control must wrap to the first");
  e = ev({shiftKey:true}); focused = null;
  if (!tbox._trapTab(modal, e, a) || !e.prevented || focused !== c) throw new Error("Shift+Tab on the first control must wrap to the last");
  e = ev({}); focused = null;
  if (tbox._trapTab(modal, e, b) || e.prevented) throw new Error("Tab in the middle must keep native behavior");
  e = ev({}); focused = null;
  if (!tbox._trapTab(modal, e, {name:"outside"}) || focused !== a) throw new Error("Tab from outside the dialog must move focus inside");
  e = ev({}); if (!tbox._trapTab({querySelectorAll:()=>[]}, e, null) || !e.prevented) throw new Error("Dialog without controls must swallow Tab");
  e = ev({key:"Enter"}); if (tbox._trapTab(modal, e, c)) throw new Error("Only Tab is trapped");
  if (!/id="share-modal" role="dialog" aria-modal="true"/.test(homepage)) throw new Error("share modal must stay aria-modal");

  const tStart = homepage.indexOf("function _tutKey(e){");
  const tEnd = homepage.indexOf("function _tutMaybe(){", tStart);
  if (tStart < 0 || tEnd < 0) throw new Error("_tutKey not found");
  const kbox = {};
  vm.createContext(kbox);
  vm.runInContext(homepage.slice(tStart, tEnd), kbox, {filename:"index.html#tut-key"});
  const expectKey = {Escape:"skip", Enter:"next", " ":"next", ArrowRight:"next", a:null, Tab:null};
  for (const [key, want] of Object.entries(expectKey)) if (kbox._tutKey({key}) !== want) throw new Error("tutorial key "+JSON.stringify(key)+" must map to "+want);
  const tut = homepage.slice(homepage.indexOf("function _tutMaybe(){"), homepage.indexOf("/* ═", homepage.indexOf("function _tutMaybe(){")));
  if (!tut.includes("role','dialog'") || !tut.includes("removeEventListener('keydown',tutKeydown,true)")) throw new Error("tutorial must be an accessible dialog and release its key handler");
}
console.log("One-tap Quick Draft onboarding tests passed.");
