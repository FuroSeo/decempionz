#!/usr/bin/env node
"use strict";

const fs = require("fs");
const path = require("path");
const vm = require("vm");

const ROOT = path.resolve(__dirname, "..");
const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
const phpEngine = fs.readFileSync(path.join(ROOT, "duel-engine.php"), "utf8");
const errors = [];

function fail(message) { errors.push(message); }
function approx(actual, expected, tolerance, label) {
  if (Math.abs(actual - expected) > tolerance) {
    fail(label + ": expected " + expected + ", found " + actual);
  }
}
function section(startMarker, endMarker) {
  const start = homepage.indexOf(startMarker);
  const end = homepage.indexOf(endMarker, start);
  if (start < 0 || end < 0) throw new Error("Unable to extract " + startMarker);
  return homepage.slice(start, end);
}

const engineSource =
  section("const SLOT_COMPAT =", "/* Each formation defines positions") + "\n" +
  section("const FORMATIONS =", "function draftNameSz") + "\n" +
  section("function avg(arr)", "function pgClass(pg)") + "\n" +
  section("const TACT_MOD=", "function tacLabel(k)") + "\n" +
  section("function computeMatchXG(ea,eb,options)", "/* Rigori equi") + "\n" +
  section("function duelPenalties()", "/* Serie al meglio") + "\n" +
  section("const CHEM_CFG=", "/* ══════════════════════════════════════\n   DAILY PUZZLE") +
  "\n;globalThis.__ENGINE__={SLOT_COMPAT,SLOT_PENALTIES,slotPenalty,posGroup,evaluateLineup,selectCanonicalXI,buildCanonicalOpponent,computeMatchXG,duelSimMatch,calcChemistry};";
const sandbox = Object.create(null);
let rngState = 1;
const seededMath = Object.create(Math);
seededMath.random = function () {
  rngState = (Math.imul(1103515245, rngState) + 12345) & 0x7fffffff;
  return rngState / 2147483648;
};
sandbox.Math = seededMath;
sandbox.G = { gameMode: "ucl", dynasty: false };
sandbox.TEAMS = {};
sandbox.COPA_TEAMS = {};
sandbox.WC_TEAMS = {};
sandbox._activeTeams = () => sandbox.TEAMS;
sandbox.natFlag = code => code;
sandbox.t = key => key;
vm.runInNewContext(engineSource, sandbox, { filename: "index.html#match-engine", timeout: 1000 });
const engine = sandbox.__ENGINE__;

if (!engine) throw new Error("Match Engine v4 helpers did not load");

const canonicalRoster = [
  {n:"GK One",p:"GK",r:9,nat:"IT",club:"Fixture"},
  {n:"LB One",p:"LB",r:8,nat:"IT",club:"Fixture"},
  {n:"CB One",p:"CB",r:9,nat:"IT",club:"Fixture"},
  {n:"CB Two",p:"CB",r:8,nat:"IT",club:"Fixture"},
  {n:"RB One",p:"RB",r:8,nat:"IT",club:"Fixture"},
  {n:"DM One",p:"CDM",r:8,nat:"IT",club:"Fixture"},
  {n:"CM One",p:"CM",r:9,nat:"IT",club:"Fixture"},
  {n:"AM One",p:"CAM",r:9,nat:"IT",club:"Fixture"},
  {n:"LW One",p:"LW",r:9,nat:"IT",club:"Fixture"},
  {n:"ST One",p:"ST",r:10,nat:"IT",club:"Fixture"},
  {n:"RW One",p:"RW",r:9,nat:"IT",club:"Fixture"},
  {n:"Utility",p:"RM",r:7,nat:"IT",club:"Fixture"}
];
sandbox.TEAMS.fixture = {name:"Fixture",season:"2000-01",players:canonicalRoster};
const canonicalA = engine.buildCanonicalOpponent("fixture", "balanced");
const canonicalB = engine.buildCanonicalOpponent("fixture", "balanced");
if (!canonicalA || canonicalA.players.filter(Boolean).length !== 11 || canonicalA.evaluation.filled !== 11) {
  fail("canonical opponent builder must produce a complete XI");
} else {
  if (canonicalA.players[canonicalA.positions.indexOf("GK")].p !== "GK") fail("canonical XI must protect its goalkeeper slot");
  if (new Set(canonicalA.players.map(p => p.n)).size !== 11) fail("canonical XI must not reuse players");
  if (canonicalA.formation !== canonicalB.formation || canonicalA.players.map(p=>p.n).join("|") !== canonicalB.players.map(p=>p.n).join("|")) {
    fail("canonical opponent selection must be deterministic");
  }
}
const sparse = engine.selectCanonicalXI(canonicalRoster.slice(0,8), ["GK","LB","CB","CB","RB","LM","CM","CM","RM","ST","ST"]);
if (sparse.filter(Boolean).length !== 8) fail("sparse historical rosters must degrade without invented players");

for (const [slot, compatible] of Object.entries(engine.SLOT_COMPAT)) {
  for (const playerPos of compatible) {
    const penalty = engine.slotPenalty(slot, playerPos);
    if (slot === playerPos && penalty !== 0) {
      fail("exact positional fit must have zero penalty: " + slot);
    }
    if (slot !== playerPos && !(penalty > 0 && penalty < 0.25)) {
      fail("compatible adaptation must have a bounded non-zero penalty: " + playerPos + " -> " + slot);
    }
  }
}
if (engine.slotPenalty("GK", "ST") !== 0.25) {
  fail("emergency incompatible assignments must pay the 25% penalty");
}
for (const [position, expected] of [
  ["RWB", "DEF"], ["LWB", "DEF"], ["SW", "DEF"], ["DF", "DEF"],
  ["AM", "MID"], ["DM", "MID"], ["MF", "MID"], ["FW", "FWD"]
]) {
  if (engine.posGroup(position) !== expected) {
    fail("position alias " + position + " must resolve to " + expected);
  }
}

const positions = ["GK","LB","CB","CB","RB","LM","CM","CM","RM","ST","ST"];
const players = [
  {n:"Keeper",p:"GK",r:8},
  {n:"Left back",p:"LB",r:8},
  {n:"Centre back",p:"CB",r:9},
  {n:"Adapted right back",p:"RB",r:8},
  {n:"Right back",p:"RB",r:8},
  {n:"Wide left",p:"LW",r:9},
  {n:"Central mid",p:"CM",r:8},
  {n:"Holding mid",p:"CDM",r:9},
  {n:"Wide right",p:"RW",r:9},
  {n:"Centre forward",p:"CF",r:9},
  {n:"Elite striker",p:"ST",r:10}
];
const evaluated = engine.evaluateLineup(players, positions, "4-4-2", "balanced");

approx(evaluated.lines.GK, 8, 1e-9, "goalkeeper line");
approx(evaluated.lines.DEF, 8.01, 1e-9, "defensive line");
approx(evaluated.lines.MID, 8.345, 1e-9, "midfield line");
approx(evaluated.lines.FWD, 9.32, 1e-9, "forward line");
approx(evaluated.atk, 8.97875, 1e-9, "attack rating");
approx(evaluated.def, 8.007, 1e-9, "defence rating");
if (evaluated.score !== 84 || evaluated.fit !== 97) {
  fail("Team Score / fit fixture changed: " + evaluated.score + "/" + evaluated.fit);
}
if (evaluated.groups.MID.length !== 4 || evaluated.groups.FWD.length !== 2) {
  fail("adapted players must contribute to their occupied slot department");
}
if (evaluated.r10.fwd !== 0.04 || evaluated.r10.midAtk !== 0) {
  fail("elite positional bonuses must follow the occupied slot department");
}

const exactPlayers = players.map((player, index) => Object.assign({}, player, { p: positions[index] }));
const exact = engine.evaluateLineup(exactPlayers, positions, "4-4-2", "balanced");
if (!(exact.score > evaluated.score && exact.fit === 100)) {
  fail("natural positional fit must outperform the adapted fixture");
}

const chemPositions = ["GK","LB","CB","CB","RB","CM","CAM","CM","LW","ST","RW"];
const uclChemPlayers = [
  {nat:"ES",club:"Real Madrid"}, {nat:"ES",club:"Real Madrid"}, {nat:"UY",club:"Real Madrid"},
  {nat:"ES",club:"Real Madrid"}, {nat:"ES",club:"Real Madrid"}, {nat:"AR",club:"Real Madrid"},
  {nat:"ES",club:"Real Madrid"}, {nat:"BR",club:"Real Madrid"}, {nat:"AR",club:"Real Madrid"},
  {nat:"HU",club:"Real Madrid"}, {nat:"ES",club:"Real Madrid"}
];
const uclChem = engine.calcChemistry(uclChemPlayers, chemPositions, "ucl", {dynasty:false});
const dynastyChem = engine.calcChemistry(uclChemPlayers, chemPositions, "ucl", {dynasty:true});
if (uclChem.pct !== 6 || dynastyChem.pct !== 4) {
  fail("UCL/Dynasty Chemistry parity changed: " + uclChem.pct + "/" + dynastyChem.pct);
}
const copaChemPlayers = chemPositions.map(() => ({nat:"BR",club:"Atlético Mineiro"}));
if (engine.calcChemistry(copaChemPlayers, chemPositions, "copa", {dynasty:false}).pct !== 6) {
  fail("Copa-specific Chemistry thresholds must preserve the 6% cap fixture");
}

const natural442 = ["GK","LB","CB","CB","RB","LM","CM","CM","RM","ST","ST"];
function ratedLineup(rating, tactic) {
  return engine.evaluateLineup(
    natural442.map((position, index) => ({ n: "P" + index, p: position, r: rating })),
    natural442,
    "4-4-2",
    tactic || "balanced"
  );
}
const equalA = ratedLineup(8, "balanced");
const equalB = ratedLineup(8, "balanced");
const samples = 4000;
let equalWinsA = 0;
for (let seed = 1; seed <= samples; seed++) {
  rngState = seed;
  const match = engine.duelSimMatch(equalA, equalB);
  if (match.ga > match.gb || (match.ga === match.gb && match.pa > match.pb)) equalWinsA++;
}
const equalRate = equalWinsA / samples;
if (equalRate < 0.47 || equalRate > 0.53) {
  fail("equal teams show side bias: " + equalRate.toFixed(4));
}

const strong = ratedLineup(10, "balanced");
const weak = ratedLineup(7, "balanced");
let strongWins = 0;
for (let seed = 1; seed <= samples; seed++) {
  rngState = seed;
  const match = engine.duelSimMatch(strong, weak);
  if (match.ga > match.gb || (match.ga === match.gb && match.pa > match.pb)) strongWins++;
}
const strongRate = strongWins / samples;
if (strongRate < 0.75) {
  fail("strong XI wins too rarely: " + strongRate.toFixed(4));
}

for (const [winningTactic, losingTactic] of [
  ["attack", "defend"], ["balanced", "attack"], ["defend", "balanced"]
]) {
  const tacticalA = ratedLineup(8, winningTactic);
  const tacticalB = ratedLineup(8, losingTactic);
  rngState = 24681357;
  const match = engine.duelSimMatch(tacticalA, tacticalB);
  if (!(match.xa > match.xb)) {
    fail("tactic counter " + winningTactic + " must beat " + losingTactic + " on xG");
  }
}

const chemA = ratedLineup(8, "balanced");
const chemB = ratedLineup(8, "balanced");
chemA.chemistry = {pct:6,mul:1.06,bonds:[]};
chemB.chemistry = {pct:0,mul:1,bonds:[]};
rngState = 1357911;
const chemMatch = engine.duelSimMatch(chemA, chemB);
if (!(chemMatch.xa > chemMatch.xb)) {
  fail("a 6% Chemistry edge must create an xG advantage between equal teams");
}

const neutralXG = engine.computeMatchXG(equalA, equalB);
const reversedXG = engine.computeMatchXG(equalB, equalA);
approx(neutralXG.xa, reversedXG.xb, 1e-12, "shared xG side symmetry A");
approx(neutralXG.xb, reversedXG.xa, 1e-12, "shared xG side symmetry B");
const difficultyXG = [-0.20,0.15,0.40,0.60].map(boost => engine.computeMatchXG(equalA,equalB,{lineBoostB:boost}).xb);
if (!difficultyXG.every((value,index) => index===0 || value>difficultyXG[index-1])) {
  fail("campaign difficulty must increase opponent xG monotonically: " + difficultyXG.join(","));
}
const momentumXG = engine.computeMatchXG(equalA,equalB,{xgAddA:.12,xgAddB:-.042});
if (!(momentumXG.xa>neutralXG.xa && momentumXG.xb<neutralXG.xb)) fail("campaign momentum inputs must help only the intended side");
const coachXG = engine.computeMatchXG(equalA,equalB,{xgMulA:1.08});
if (!(coachXG.xa>neutralXG.xa && Math.abs(coachXG.xb-neutralXG.xb)<.02)) fail("coach multiplier must remain a campaign-only side A modifier");
const boundedXG = engine.computeMatchXG(strong,weak,{lineBoostA:20,lineBoostB:-20,xgMulA:4,xgAddB:-5});
if (boundedXG.xa!==2.6 || boundedXG.xb!==.13) fail("shared xG kernel must enforce Duel bounds");

for (const key of ["team.score", "team.fit", "team.attack", "team.defence"]) {
  const count = homepage.split("'" + key + "':").length - 1;
  if (count !== 3) fail("Team Score translation missing for " + key);
}
if (!homepage.includes('id="d-team-score" class="team-score-panel"') ||
    !homepage.includes("G.teamEval=_teamEval;") ||
    !homepage.includes("const teamEval=evaluateLineup(G.slotPlayers,_formPos")) {
  fail("campaign Team Score or shared evaluation binding missing");
}
if (!homepage.includes("var evaluated=evaluateLineup(team.players||[],pos")) {
  fail("browser Duel must use the shared lineup evaluator");
}
if (!phpEngine.includes("const DCZ_DUEL_ENGINE_VERSION = 'server-v3';") ||
    !phpEngine.includes("$group = dcz_duel_pos_group($slot);") ||
    !phpEngine.includes("function dcz_duel_team_chemistry") ||
    !phpEngine.includes("$xa *= (float)($a['chemistry']['mul'] ?? 1.0)")) {
  fail("authoritative PHP engine is not on Match Engine v3 Chemistry rules");
}
if (!homepage.includes("evaluated.chemistry=calcChemistry") ||
    !homepage.includes("xa*=ea.chemistry&&ea.chemistry.mul?ea.chemistry.mul:1")) {
  fail("browser Duel Chemistry parity hooks are missing");
}
if (!homepage.includes("const oppXI=_pendingMatch.oppId?buildCanonicalOpponent") ||
    !homepage.includes("const xg=computeMatchXG(teamEval,oppEval") ||
    !homepage.includes("lineBoostB:oppXI?diffCfg.oppMod+roundBonus:0") ||
    !homepage.includes("let oppPlayerPool=oppXI?oppXI.players.filter") ||
    !homepage.includes("if(oppXI){\n    _awayXI=oppXI.players.map")) {
  fail("campaign simulation, scorers and lineup must share the canonical opponent XI");
}
if (!homepage.includes("function computeMatchXG(ea,eb,options)") ||
    !homepage.includes("var xg=computeMatchXG(ea,eb),xa=xg.xa,xb=xg.xb")) {
  fail("campaign and browser Duel must call the shared Match Engine v5 xG kernel");
}

if (errors.length) {
  console.error("Match Engine v4 failures:");
  for (const message of errors) console.error("  - " + message);
  process.exit(1);
}

console.log(
  "Balance samples: equal-side A " + (equalRate * 100).toFixed(1) +
  "%, strong XI " + (strongRate * 100).toFixed(1) + "%"
);
console.log("Match Engine v5 client/parity tests passed.");
