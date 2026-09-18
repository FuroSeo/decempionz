#!/usr/bin/env node
"use strict";

const fs = require("fs");
const path = require("path");
const vm = require("vm");

const ROOT = path.resolve(__dirname, "..");
const source = fs.readFileSync(path.join(ROOT, "game-data.js"), "utf8");

const sandbox = Object.create(null);
vm.runInNewContext(
  source + "\n;globalThis.__DCZ_DATA__={TEAMS,COPA_TEAMS,WC_TEAMS,TOURNAMENTS,COPA_TOURNAMENTS,WC_TOURNAMENTS};",
  sandbox,
  { filename: "game-data.js", timeout: 1500 }
);

const data = sandbox.__DCZ_DATA__;
if (!data) throw new Error("Unable to load canonical game-data.js");

const ALLOWED_POSITIONS = new Set([
  "GK","CB","RB","LB","LWB","RWB","SW","DC","DF",
  "CM","CDM","CAM","AM","RM","LM","DM","MF",
  "RW","LW","ST","CF","SS","FW"
]);

const KNOWN_CROSS_FAMILY_IDS = new Set(["atm_1314"]);

const families = [
  { mode: "ucl", teams: data.TEAMS, tournaments: data.TOURNAMENTS, minTeams: 80 },
  { mode: "copa", teams: data.COPA_TEAMS, tournaments: data.COPA_TOURNAMENTS, minTeams: 50 },
  { mode: "wc", teams: data.WC_TEAMS, tournaments: data.WC_TOURNAMENTS, minTeams: 80 }
];

const errors = [];
const warnings = [];
const summary = {};
const idFamilies = new Map();

function error(message) { errors.push(message); }
function warn(message) { warnings.push(message); }

for (const family of families) {
  const mode = family.mode;
  const teams = family.teams;
  const tournaments = family.tournaments;

  if (!teams || typeof teams !== "object" || Array.isArray(teams)) {
    error(mode + ": teams registry missing/invalid");
    continue;
  }
  if (!Array.isArray(tournaments)) {
    error(mode + ": tournament list missing/invalid");
    continue;
  }

  const teamIds = Object.keys(teams);
  if (teamIds.length < family.minTeams) {
    error(mode + ": suspicious team count " + teamIds.length + " < " + family.minTeams);
  }

  const referenced = new Set();
  let playerCount = 0;
  let missingNat = 0;

  for (const id of teamIds) {
    const team = teams[id];
    if (!/^[A-Za-z0-9_]+$/.test(id)) error(mode + "/" + id + ": unsafe team id");

    if (!idFamilies.has(id)) idFamilies.set(id, []);
    idFamilies.get(id).push(mode);

    for (const key of ["name", "season", "country", "club"]) {
      if (typeof (team && team[key]) !== "string" || team[key].trim() === "") {
        error(mode + "/" + id + ": missing/invalid " + key);
      }
    }
    if (mode !== "wc" && typeof team.club === "string" && !/^[a-z0-9_]+$/.test(team.club)) {
      error(mode + "/" + id + ": invalid club slug " + team.club);
    }

    if (!Array.isArray(team.players)) {
      error(mode + "/" + id + ": players must be an array");
      continue;
    }
    if (team.players.length < 11 || team.players.length > 25) {
      error(mode + "/" + id + ": roster size " + team.players.length + " outside 11..25");
    }

    const names = new Set();
    const exact = new Set();

    team.players.forEach(function (player, index) {
      playerCount++;
      const prefix = mode + "/" + id + "/player[" + index + "]";
      if (!player || typeof player !== "object") {
        error(prefix + ": invalid player object");
        return;
      }
      if (typeof player.n !== "string" || player.n.trim() === "") {
        error(prefix + ": missing player name");
      }
      if (!ALLOWED_POSITIONS.has(player.p)) {
        error(prefix + ": unsupported position " + String(player.p));
      }
      if (!Number.isInteger(player.r) || player.r < 6 || player.r > 10) {
        error(prefix + ": rating " + String(player.r) + " outside integer 6..10");
      }
      if (player.nat == null || player.nat === "") {
        missingNat++;
      } else if (typeof player.nat !== "string" || !/^[A-Z]{2,3}$/.test(player.nat)) {
        error(prefix + ": invalid nationality code " + String(player.nat));
      }

      const collisionKey = mode + ":" + id + ":" + player.n;
      if (names.has(player.n)) {
        error(collisionKey + ": duplicate player name in one roster");
      }
      names.add(player.n);

      const exactKey = [player.n, player.p, player.r, player.nat || ""].join("|");
      if (exact.has(exactKey)) {
        error(collisionKey + ": exact duplicate player entry");
      }
      exact.add(exactKey);
    });
  }

  const tournamentIds = new Set();
  for (const tournament of tournaments) {
    if (!tournament || typeof tournament !== "object") {
      error(mode + ": invalid tournament entry");
      continue;
    }
    if (typeof tournament.id !== "string" || !/^[A-Za-z0-9_]+$/.test(tournament.id)) {
      error(mode + ": invalid tournament id " + String(tournament.id));
    } else if (tournamentIds.has(tournament.id)) {
      error(mode + "/" + tournament.id + ": duplicate tournament id");
    } else {
      tournamentIds.add(tournament.id);
    }

    if (typeof tournament.name !== "string" || tournament.name.trim() === "") {
      error(mode + "/" + (tournament.id || "?") + ": missing tournament name");
    }
    if (tournament.allTime != null && typeof tournament.allTime !== "boolean") {
      error(mode + "/" + (tournament.id || "?") + ": allTime must be boolean");
    }
    if (!Array.isArray(tournament.teams) || tournament.teams.length === 0) {
      error(mode + "/" + (tournament.id || "?") + ": tournament teams missing/empty");
      continue;
    }

    const seen = new Set();
    for (const id of tournament.teams) {
      if (seen.has(id)) error(mode + "/" + tournament.id + ": duplicate team ref " + id);
      seen.add(id);
      referenced.add(id);
      if (!Object.prototype.hasOwnProperty.call(teams, id)) {
        error(mode + "/" + tournament.id + ": missing team ref " + id);
      }
    }
  }

  for (const id of teamIds) {
    if (!referenced.has(id)) error(mode + "/" + id + ": team is not referenced by any tournament");
  }

  summary[mode] = {
    teams: teamIds.length,
    players: playerCount,
    tournaments: tournaments.length,
    playersWithoutNat: missingNat
  };
}

for (const entry of idFamilies.entries()) {
  const id = entry[0];
  const modes = entry[1];
  if (modes.length <= 1) continue;
  const sorted = modes.slice().sort();
  if (KNOWN_CROSS_FAMILY_IDS.has(id)) {
    warn(id + ": known cross-family team id (" + sorted.join(",") + "); server lookup must include tournament");
  } else {
    error(id + ": new cross-family team id collision (" + sorted.join(",") + ")");
  }
}

validateVerifiedHistoricFixtures();

console.log("Canonical game-data summary:");
for (const entry of Object.entries(summary)) {
  const mode = entry[0];
  const row = entry[1];
  console.log(
    "  " + mode + ": " + row.teams + " teams, " + row.players + " players, " +
    row.tournaments + " tournaments, " + row.playersWithoutNat + " players without nat"
  );
}

if (warnings.length) {
  console.log("\nKnown dataset warnings:");
  for (const message of warnings) console.log("  - " + message);
}

if (errors.length) {
  console.error("\nGame-data integrity failures:");
  for (const message of errors) console.error("  - " + message);
  process.exit(1);
}

console.log("\nGame-data integrity tests passed.");
