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
console.log("One-tap Quick Draft onboarding tests passed.");
