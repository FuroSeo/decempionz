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
console.log("One-tap Quick Draft onboarding tests passed.");
