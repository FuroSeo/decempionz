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

function validateHomepageSummary() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");

  for (const family of families) {
    const row = summary[family.mode];
    if (!row) continue;

    const expected = {
      teams: row.teams,
      players: row.players,
      tournaments: row.tournaments
    };

    for (const entry of Object.entries(expected)) {
      const metric = entry[0];
      const value = entry[1];
      const attribute = family.mode + "-" + metric;
      const pattern = new RegExp(
        '<div class="tourn-stat-val" data-home-stat="' + attribute + '">(\\d+)</div>'
      );
      const match = homepage.match(pattern);
      if (!match) {
        error("homepage: missing canonical stat marker " + attribute);
      } else if (Number(match[1]) !== value) {
        error(
          "homepage: stale " + attribute + " total " + match[1] +
          " (canonical " + value + ")"
        );
      }
    }
  }
}

validateHomepageSummary();

function validateHomepageOnboarding() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const primerIndex = homepage.indexOf("data-home-onboarding");
  const tabsIndex = homepage.indexOf("<!-- Tournament tab switcher -->");

  if (primerIndex < 0) {
    error("homepage: first-run primer missing");
  } else if (tabsIndex < 0 || primerIndex > tabsIndex) {
    error("homepage: first-run primer must appear before tournament selection");
  }
  if (!homepage.includes(
    'class="home-onboarding-help" onclick="showScreen(\'screen-howto\')"'
  )) {
    error("homepage: visible how-to action missing from first-run primer");
  }

  const onboardingKeys = [
    "home.onboarding.pick",
    "home.onboarding.draft",
    "home.onboarding.play"
  ];
  for (const key of onboardingKeys) {
    const translationCount = homepage.split("'" + key + "':").length - 1;
    if (translationCount !== 3) {
      error("homepage: expected IT/EN/ES translations for " + key);
    }
    if (!homepage.includes('data-i18n="' + key + '"')) {
      error("homepage: primer does not render " + key);
    }
  }

  const detectorMatch = homepage.match(
    /function _detectInitialLang\(stored,browserLang\) \{[\s\S]*?\n\}/
  );
  if (!detectorMatch) {
    error("homepage: first-visit language detector missing");
  } else {
    const langSandbox = Object.create(null);
    vm.runInNewContext(
      detectorMatch[0] +
        "\n;globalThis.__detectInitialLang=_detectInitialLang;",
      langSandbox,
      { filename: "index.html#_detectInitialLang", timeout: 500 }
    );
    const cases = [
      { stored: "it", browser: "en-US", expected: "it" },
      { stored: "en", browser: "es-ES", expected: "en" },
      { stored: "es", browser: "it-IT", expected: "es" },
      { stored: null, browser: "en-US", expected: "en" },
      { stored: null, browser: "es-MX", expected: "es" },
      { stored: null, browser: "ES_ar", expected: "es" },
      { stored: null, browser: "it-IT", expected: "it" },
      { stored: null, browser: "fr-FR", expected: "it" },
      { stored: "de", browser: "en-GB", expected: "en" },
      { stored: null, browser: "", expected: "it" }
    ];
    for (const testCase of cases) {
      const actual = langSandbox.__detectInitialLang(
        testCase.stored,
        testCase.browser
      );
      if (actual !== testCase.expected) {
        error(
          "homepage: language detection failed for stored=" +
          String(testCase.stored) + ", browser=" + testCase.browser +
          " (expected " + testCase.expected + ", found " + actual + ")"
        );
      }
    }
  }

  const guideEntries = Array.from(
    homepage.matchAll(/'howto\.p1':'([^\n]+)',/g),
    function (match) { return match[1]; }
  );
  const guideExpectations = [
    [
      summary.ucl.teams + " rose",
      summary.copa.teams + " rose",
      summary.wc.teams + " nazionali"
    ],
    [
      summary.ucl.teams + " squads",
      summary.copa.teams + " squads",
      summary.wc.teams + " national teams"
    ],
    [
      summary.ucl.teams + " plantillas",
      summary.copa.teams + " plantillas",
      summary.wc.teams + " selecciones"
    ]
  ];

  if (guideEntries.length !== guideExpectations.length) {
    error("homepage: expected three localized how-to introductions");
  } else {
    guideExpectations.forEach(function (fragments, index) {
      for (const fragment of fragments) {
        if (!guideEntries[index].includes(fragment)) {
          error("homepage: localized how-to copy missing canonical total " + fragment);
        }
      }
    });
  }
}

validateHomepageOnboarding();

function validatePrivacyNotice() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const inventory = fs.readFileSync(
    path.join(ROOT, "PRIVACY_DATA_INVENTORY.md"),
    "utf8"
  );

  const staleClaims = [
    "L'unico dato salvato sul tuo dispositivo è la preferenza tema",
    "The only data saved on your device is the theme preference",
    "El único dato guardado en tu dispositivo es la preferencia de tema",
    "does not collect, store, or transmit any personal data",
    "non raccoglie, non archivia e non trasmette alcun dato personale",
    "no recopila, almacena ni transmite ningún dato personal",
    "localStorage solely to save local preferences",
    "localStorage del browser esclusivamente per salvare preferenze locali",
    "localStorage del navegador exclusivamente para guardar preferencias locales"
  ];
  for (const claim of staleClaims) {
    if (homepage.includes(claim)) {
      error("privacy: stale absolute claim remains: " + claim);
    }
  }

  const localizedConcepts = [
    ["Dati e funzionalità online", "Data and online features", "Datos y funciones en línea"],
    ["classifiche Daily/Sfide", "Daily/Challenge leaderboards", "clasificaciones Daily/Retos"],
    ["riferimenti dei Duelli recenti", "recent Duel references", "referencias de Duelos recientes"],
    ["settembre 2026", "September 2026", "septiembre de 2026"]
  ];
  for (const translations of localizedConcepts) {
    for (const fragment of translations) {
      if (!homepage.includes(fragment)) {
        error("privacy: localized notice missing " + fragment);
      }
    }
  }

  const documentedKeys = [
    "gl-theme", "ucl_lang", "dcz_lang", "dcz_disclaimer", "dcz_tut",
    "dcz_stats", "dcz_trophies", "dcz_formations_won", "dcz_daily",
    "dcz_daily_run", "dcz_daily_sent", "dcz_retro_*", "dcz_last_nick",
    "dcz_duel_nick", "dcz_duels", "dcz_hof_pending"
  ];
  for (const key of documentedKeys) {
    if (!inventory.includes("`" + key + "`")) {
      error("privacy inventory: missing browser storage key " + key);
    }
  }
  for (const feature of [
    "Daily and weekly challenge leaderboards",
    "Hall of Fame submissions",
    "Draft links",
    "Duels",
    "Google Analytics 4",
    "Microsoft Clarity"
  ]) {
    if (!inventory.includes(feature)) {
      error("privacy inventory: missing data flow " + feature);
    }
  }
}

validatePrivacyNotice();

function validateVerifiedHistoricFixtures() {
  const argentinos = data.COPA_TEAMS && data.COPA_TEAMS.arj_8485;
  if (!argentinos || !Array.isArray(argentinos.players)) {
    error("copa/arj_8485: verified historic fixture missing");
    return;
  }

  const names = new Set(argentinos.players.map(function (p) { return p.n; }));
  const required = [
    "Enrique Vidallé",
    "Carmelo Villalba",
    "José Luis Pavoni",
    "Jorge Olguín",
    "Adrián Domenech",
    "Sergio Batista",
    "Emilio Commisso",
    "Mario Videla",
    "José Antonio Castro",
    "Claudio Borghi",
    "Carlos Ereros",
    "Jorge Pellegrini",
    "Renato Corsi"
  ];
  const forbidden = [
    "Traverso", "Rodas", "Navarro", "López", "Fren",
    "Caniggia", "Gareca", "Muñoz", "Giusti"
  ];

  for (const name of required) {
    if (!names.has(name)) error("copa/arj_8485: verified player missing: " + name);
  }
  for (const name of forbidden) {
    if (names.has(name)) error("copa/arj_8485: contaminated legacy player returned: " + name);
  }
  if (argentinos.players.length !== required.length) {
    error("copa/arj_8485: expected " + required.length + " verified players, found " + argentinos.players.length);
  }

  const expectedRoles = {
    "Enrique Vidallé": "GK",
    "Carmelo Villalba": "RB",
    "José Luis Pavoni": "CB",
    "Jorge Olguín": "CB",
    "Adrián Domenech": "LB",
    "Sergio Batista": "CDM",
    "Emilio Commisso": "CM",
    "Mario Videla": "CM",
    "José Antonio Castro": "RW",
    "Claudio Borghi": "CAM",
    "Carlos Ereros": "ST",
    "Jorge Pellegrini": "CB",
    "Renato Corsi": "CM"
  };
  for (const player of argentinos.players) {
    if (expectedRoles[player.n] && player.p !== expectedRoles[player.n]) {
      error("copa/arj_8485: unexpected role for " + player.n + ": " + player.p);
    }
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
