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
    "home.onboarding.play",
    "home.tournament_picker"
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

  if (!homepage.includes(
    'id="home-tournament-tabs" role="tablist" aria-labelledby="home-tournament-label"'
  )) {
    error("homepage: tournament selector must expose a labelled tablist");
  }
  const tournamentModes = ["ucl", "copa", "wc", "dynasty"];
  tournamentModes.forEach(function (mode, index) {
    const selected = index === 0 ? "true" : "false";
    const tabIndex = index === 0 ? "0" : "-1";
    const tabPattern = new RegExp(
      'id="tab-' + mode + '"[^>]*role="tab"[^>]*aria-selected="' + selected +
      '"[^>]*aria-controls="tourn-' + mode + '"[^>]*tabindex="' + tabIndex + '"'
    );
    if (!tabPattern.test(homepage)) {
      error("homepage: invalid initial tab semantics for " + mode);
    }
    const panelPattern = new RegExp(
      'id="tourn-' + mode + '"[^>]*role="tabpanel"[^>]*aria-labelledby="tab-' + mode + '"'
    );
    if (!panelPattern.test(homepage)) {
      error("homepage: tournament panel is not associated for " + mode);
    }
  });

  const tabTargetMatch = homepage.match(
    /function _homeTournamentTabTarget\(current,key,count\)\{[\s\S]*?\n\}/
  );
  if (!tabTargetMatch) {
    error("homepage: tournament keyboard navigation helper missing");
  } else {
    const tabSandbox = Object.create(null);
    vm.runInNewContext(
      tabTargetMatch[0] +
        "\n;globalThis.__homeTournamentTabTarget=_homeTournamentTabTarget;",
      tabSandbox,
      { filename: "index.html#_homeTournamentTabTarget", timeout: 500 }
    );
    const keyboardCases = [
      { current: 0, key: "ArrowRight", expected: 1 },
      { current: 3, key: "ArrowRight", expected: 0 },
      { current: 0, key: "ArrowLeft", expected: 3 },
      { current: 2, key: "Home", expected: 0 },
      { current: 1, key: "End", expected: 3 },
      { current: 1, key: "Enter", expected: -1 }
    ];
    keyboardCases.forEach(function (testCase) {
      const actual = tabSandbox.__homeTournamentTabTarget(
        testCase.current,
        testCase.key,
        tournamentModes.length
      );
      if (actual !== testCase.expected) {
        error(
          "homepage: tournament key " + testCase.key + " from " + testCase.current +
          " expected " + testCase.expected + ", found " + actual
        );
      }
    });
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

function validateDraftAccessibility() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const start = homepage.indexOf("function renderThreeCards()");
  const end = homepage.indexOf("\nfunction draftPick(", start);
  if (start < 0 || end < 0) {
    error("draft: unable to locate player-offer renderer");
    return;
  }
  const renderer = homepage.slice(start, end);

  if (!renderer.includes(
    "return'<button type=\"button\" onclick=\"draftTap('"
  )) {
    error("draft: player offers must use native button semantics");
  }
  if (renderer.includes("return'<div onclick=\"draftTap(")) {
    error("draft: non-keyboard clickable player offer returned");
  }
  if (!renderer.includes("+'</button>';")) {
    error("draft: player-offer button is not closed correctly");
  }
  if (!renderer.includes(
    "document.activeElement.classList.contains('draft-pick-card')"
  ) || !renderer.includes("if(firstCard)firstCard.focus();")) {
    error("draft: focus is not restored after replacing a selected offer");
  }
  if (!homepage.includes(
    ".draft-pick-card:focus-visible{outline:3px solid var(--gold2);outline-offset:3px}"
  )) {
    error("draft: keyboard focus indicator missing from player offers");
  }
}

validateDraftAccessibility();

function validateCoachAccessibility() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const start = homepage.indexOf("function renderCoachCards(pool)");
  const end = homepage.indexOf("\nfunction pickCoach(", start);
  if (start < 0 || end < 0) {
    error("coach: unable to locate coach-choice renderer");
    return;
  }
  const renderer = homepage.slice(start, end);

  if (!renderer.includes(
    "return '<button type=\"button\" class=\"coach-card\" onclick=\"pickCoach('"
  )) {
    error("coach: choices must use native button semantics");
  }
  if (renderer.includes("return '<div class=\"coach-card\"")) {
    error("coach: non-keyboard clickable choice returned");
  }
  if (!renderer.includes("+'</button>';")) {
    error("coach: choice button is not closed correctly");
  }
  if (!renderer.includes(
    "class=\"coach-row\" role=\"group\" aria-labelledby=\"d-title\""
  )) {
    error("coach: choice group is missing its localized accessible label");
  }
  if (!renderer.includes("if(firstCoach)firstCoach.focus();")) {
    error("coach: focus is not moved to the choices after the Draft transition");
  }
  if (!homepage.includes(
    ".coach-card:focus-visible{outline:3px solid var(--gold2);outline-offset:3px}"
  )) {
    error("coach: keyboard focus indicator missing from choices");
  }
}

validateCoachAccessibility();

function validateScreenLogoAccessibility() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const logoButtons = homepage.match(
    /<button type="button" class="screen-logo" aria-label="Decempionz — Home" onclick="showScreen\('screen-home'\)">Decempionz<\/button>/g
  ) || [];

  if (logoButtons.length !== 15) {
    error("screen navigation: expected 15 native logo buttons, found " + logoButtons.length);
  }
  if (/<span class="screen-logo"/.test(homepage)) {
    error("screen navigation: non-keyboard clickable logo returned");
  }
  if (!homepage.includes(
    ".screen-logo:focus-visible{outline:3px solid var(--gold2);outline-offset:3px}"
  )) {
    error("screen navigation: keyboard focus indicator missing from logo buttons");
  }
}

validateScreenLogoAccessibility();

function validateFooterActionAccessibility() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const start = homepage.indexOf('<div class="legal-footer">');
  const end = homepage.indexOf("</div>", start);
  if (start < 0 || end < 0) {
    error("footer: unable to locate legal footer");
    return;
  }
  const footer = homepage.slice(start, end);
  const expectedActions = [
    "showScreen('screen-howto')",
    "openFanProject()",
    "openPrivacy()",
    "openContatti()"
  ];

  for (const action of expectedActions) {
    if (!footer.includes(
      '<button type="button" class="legal-action" onclick="' + action + '"'
    )) {
      error("footer: native button missing for " + action);
    }
  }
  if (/<a\s+onclick=/.test(footer)) {
    error("footer: non-keyboard anchor action returned");
  }
  if (!homepage.includes(
    ".legal-footer a:focus-visible,.legal-footer .legal-action:focus-visible{outline:2px solid var(--gold2);outline-offset:2px}"
  )) {
    error("footer: keyboard focus indicator missing from actions");
  }
}

validateFooterActionAccessibility();

function validateLegalModalAccessibility() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const dialogs = [
    ["fanproject", "closeFanProject"],
    ["contatti", "closeContatti"],
    ["privacy", "closePrivacy"]
  ];

  for (const [id, closeFunction] of dialogs) {
    const openingTag = '<div id="modal-' + id + '" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-' + id + '-title" onclick="' + closeFunction + '()">';
    if (!homepage.includes(openingTag)) {
      error("legal modal: accessible dialog semantics missing for " + id);
    }
    if (!homepage.includes('<div class="modal-title" id="modal-' + id + '-title">')) {
      error("legal modal: labelled title missing for " + id);
    }
  }
  if (!homepage.includes("var _legalModalReturnFocus=null;") ||
      !homepage.includes("_legalModalReturnFocus=document.activeElement;")) {
    error("legal modal: opening trigger is not preserved");
  }
  if (!homepage.includes("var closeBtn=modal.querySelector('.modal-legal-close');") ||
      !homepage.includes("if(closeBtn)closeBtn.focus();")) {
    error("legal modal: focus is not moved into the opened dialog");
  }
  if (!homepage.includes("if(trigger&&document.contains(trigger)&&typeof trigger.focus==='function')trigger.focus();")) {
    error("legal modal: focus is not restored after closing");
  }
  if (!homepage.includes("if(e.key!=='Escape')return;") ||
      !homepage.includes("closeFanProject();}") ||
      !homepage.includes("closeContatti();return;") ||
      !homepage.includes("closePrivacy();return;")) {
    error("legal modal: Escape dismissal is incomplete");
  }
  if (!homepage.includes(
    ".modal-legal-close:focus-visible{outline:3px solid var(--gold2);outline-offset:2px}"
  )) {
    error("legal modal: close button focus indicator missing");
  }
}

validateLegalModalAccessibility();

function validateShareModalAccessibility() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");

  if (!homepage.includes(
    '<div id="share-modal" role="dialog" aria-modal="true" aria-labelledby="share-modal-title" onclick="closeShareModal()">'
  )) {
    error("share modal: accessible dialog semantics missing");
  }
  if (!homepage.includes(
    '<span class="share-modal-title" id="share-modal-title" data-i18n="share.modal_title">'
  )) {
    error("share modal: labelled localized title missing");
  }
  if (!homepage.includes(
    '<button type="button" class="share-modal-close" aria-label="Chiudi la finestra di condivisione" data-i18n-aria-label="share.close"'
  )) {
    error("share modal: close button lacks localized accessible name");
  }
  for (const translation of [
    "'share.modal_title':'📤 Condividi il risultato'",
    "'share.modal_title':'📤 Share the result'",
    "'share.modal_title':'📤 Compartir el resultado'",
    "'share.close':'Chiudi la finestra di condivisione'",
    "'share.close':'Close share dialog'",
    "'share.close':'Cerrar diálogo de compartir'"
  ]) {
    if (!homepage.includes(translation)) {
      error("share modal: missing translation " + translation);
    }
  }
  if (!homepage.includes("document.querySelectorAll('[data-i18n-aria-label]').forEach(function(el){") ||
      !homepage.includes("if(v)el.setAttribute('aria-label',v);")) {
    error("share modal: accessible name is not refreshed on language changes");
  }
  if (!homepage.includes("_shareModalReturnFocus=document.activeElement;") ||
      !homepage.includes("var closeBtn=modal.querySelector('.share-modal-close');") ||
      !homepage.includes("if(closeBtn)closeBtn.focus();")) {
    error("share modal: opening focus management is incomplete");
  }
  if (!homepage.includes("var trigger=_shareModalReturnFocus;") ||
      !homepage.includes("_shareModalReturnFocus=null;")) {
    error("share modal: closing focus management is incomplete");
  }
  if (!homepage.includes("shareModal.classList.contains('open')") ||
      !homepage.includes("closeShareModal();return;")) {
    error("share modal: Escape dismissal missing");
  }
  if (!homepage.includes(
    ".share-modal-close:focus-visible{outline:3px solid var(--gold2);outline-offset:3px}"
  )) {
    error("share modal: close button focus indicator missing");
  }
}

validateShareModalAccessibility();

function validateShareFlowLocalization() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const start = homepage.indexOf("function _shareGeneratedCopy(lang)");
  const end = homepage.indexOf("\nfunction submitToHoF()", start);
  if (start < 0 || end < 0) {
    error("share localization: unable to locate share flow");
    return;
  }
  const shareFlow = homepage.slice(start, end);
  const translationKeys = [
    "share.download", "share.native", "share.copy", "share.save_link",
    "share.saving", "share.retry", "share.link_hint", "share.link_input",
    "share.copy_link", "share.copied"
  ];
  for (const key of translationKeys) {
    const occurrences = homepage.split("'" + key + "':").length - 1;
    if (occurrences !== 3) {
      error("share localization: expected IT/EN/ES values for " + key + ", found " + occurrences);
    }
  }
  for (const binding of [
    'data-i18n="share.download"',
    'data-i18n="share.native"',
    'data-i18n="share.copy"',
    'data-i18n="share.save_link"',
    'data-i18n="share.link_hint"',
    'data-i18n-aria-label="share.link_input"',
    'data-i18n-aria-label="share.copy_link"'
  ]) {
    if (!homepage.includes(binding)) {
      error("share localization: missing markup binding " + binding);
    }
  }
  for (const dynamicUse of [
    "t('share.save_link')",
    "t('share.saving')",
    "t('share.retry')",
    "t('share.copied')"
  ]) {
    if (!shareFlow.includes(dynamicUse)) {
      error("share localization: missing dynamic localized state " + dynamicUse);
    }
  }
  for (const spanishCopy of [
    "champion:'Campeón'",
    "won:'¡Victoria!'",
    "drawCode:'E'",
    "lossCode:'D'",
    "goals:'goles'",
    "topScorer:'Máximo goleador: '",
    "challengeWinText:'¿Puedes superarlo? Inténtalo → '"
  ]) {
    if (!shareFlow.includes(spanishCopy)) {
      error("share localization: missing Spanish generated copy " + spanishCopy);
    }
  }
  const helperUses = shareFlow.match(/const copy=_shareGeneratedCopy\(d\.lang\);/g) || [];
  if (helperUses.length !== 2) {
    error("share localization: canvas and text must both use localized generated copy");
  }
  if (shareFlow.includes("d.isIt")) {
    error("share localization: binary Italian/English fallback returned");
  }
  if (!shareFlow.includes("topScorerStr,lang,grpMap,sq")) {
    error("share localization: selected language is missing from share data");
  }
}

validateShareFlowLocalization();

function validateFormationAccessibility() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const start = homepage.indexOf("function renderFormationGrid()");
  const end = homepage.indexOf("\nfunction selectRandomFormation(", start);
  if (start < 0 || end < 0) {
    error("formation: unable to locate formation picker");
    return;
  }
  const picker = homepage.slice(start, end);

  if (!picker.includes(
    "return '<button type=\"button\" class=\"form-card\" aria-pressed=\"false\""
  )) {
    error("formation: choices must use native button semantics");
  }
  if (!picker.includes(
    "+'<button type=\"button\" class=\"form-card\" aria-pressed=\"false\" id=\"fc-formation-random\""
  )) {
    error("formation: random choice must use native button semantics");
  }
  if (picker.includes("return '<div class=\"form-card\"") ||
      picker.includes("+'<div class=\"form-card\"")) {
    error("formation: non-keyboard clickable formation choice returned");
  }
  if (!picker.includes("card.setAttribute('aria-pressed','false')") ||
      !picker.includes("el.setAttribute('aria-pressed','true')")) {
    error("formation: selected state is not exposed with aria-pressed");
  }
  if (!homepage.includes(
    '.form-card:focus-visible{outline:3px solid var(--gold2);outline-offset:2px}'
  )) {
    error("formation: keyboard focus indicator missing from choices");
  }
  if (!homepage.includes(
    'id="form-grid" role="group" aria-labelledby="formation-title"'
  )) {
    error("formation: choice group is missing its accessible label");
  }
}

validateFormationAccessibility();

function validateSetupChoiceAccessibility() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const formatButtons = homepage.match(
    /<button type="button" class="format-card[^"]*" aria-pressed="(?:true|false)" id="fc-(?:coppa|classic|nuovo)" onclick="selectFormat/g
  ) || [];
  const difficultyButtons = homepage.match(
    /<button type="button" class="diff-card[^"]*" aria-pressed="(?:true|false)" id="(?:dyn-)?dc-(?:easy|normal|hard|legend)" onclick="select(?:Dyn)?Diff/g
  ) || [];

  if (formatButtons.length !== 3) {
    error("setup: all format choices must use native button semantics");
  }
  if (difficultyButtons.length !== 8) {
    error("setup: all standard and Dynasty difficulty choices must use native button semantics");
  }
  if (/<div class="(?:format-card|diff-card)/.test(homepage)) {
    error("setup: non-keyboard clickable format or difficulty choice returned");
  }
  if (!homepage.includes(
    'role="group" aria-labelledby="format-title"'
  ) || !homepage.includes(
    'role="group" aria-labelledby="format-difficulty-label"'
  ) || !homepage.includes(
    'role="group" aria-labelledby="dynasty-difficulty-label"'
  )) {
    error("setup: format or difficulty choice group is missing its accessible label");
  }
  if (!homepage.includes(
    ".format-card:focus-visible{outline:3px solid var(--gold2);outline-offset:2px}"
  ) || !homepage.includes(
    ".diff-card:focus-visible{outline:3px solid var(--gold2);outline-offset:2px}"
  )) {
    error("setup: keyboard focus indicator missing from choices");
  }
  if (!homepage.includes("function _setSingleChoice(selector,selectedEl,selectedClass)") ||
      !homepage.includes("el.setAttribute('aria-pressed','false')") ||
      !homepage.includes("selectedEl.setAttribute('aria-pressed','true')")) {
    error("setup: visual and announced selected states are not synchronized");
  }
  if (!homepage.includes(
    "_setSingleChoice('.format-card',el,'fc-sel')"
  ) || !homepage.includes(
    "_setSingleChoice('#screen-format .diff-card',el,'diff-sel')"
  ) || !homepage.includes(
    "_setSingleChoice('[id^=\"dyn-dc-\"]',el,'diff-sel')"
  )) {
    error("setup: choice handlers do not use synchronized selection state");
  }
}

validateSetupChoiceAccessibility();

function validateEraAccessibility() {
  const homepage = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  const start = homepage.indexOf("function showEraGrid()");
  const end = homepage.indexOf("\nfunction selectAllTime(", start);
  if (start < 0 || end < 0) {
    error("era: unable to locate era picker");
    return;
  }
  const picker = homepage.slice(start, end);

  if (!picker.includes(
    "return '<button type=\"button\" class=\"era-card\" aria-pressed=\"false\""
  )) {
    error("era: choices must use native button semantics");
  }
  if (picker.includes("return '<div class=\"era-card\"")) {
    error("era: non-keyboard clickable choice returned");
  }
  if (!picker.includes("+'</div></button>';")) {
    error("era: choice button is not closed correctly");
  }
  if (!picker.includes("selectedEraIdx=-1;") ||
      !picker.includes("confirmBtn.disabled=true;") ||
      !picker.includes("confirmBtn.disabled=false;")) {
    error("era: current selection and native confirm state are not reset correctly");
  }
  if (!picker.includes("_setSingleChoice('.era-card',el,'era-sel')")) {
    error("era: selected state is not exposed with aria-pressed");
  }
  if (!homepage.includes(
    '.era-card:focus-visible{outline:3px solid var(--gold2);outline-offset:2px}'
  )) {
    error("era: keyboard focus indicator missing from choices");
  }
  if (!homepage.includes(
    'id="era-grid" role="group" aria-labelledby="era-specific-label"'
  ) || !homepage.includes(
    'id="btn-era-confirm" data-i18n="era.use_this" class="btn btn-gold btn-inactive" onclick="confirmEra()" disabled'
  )) {
    error("era: choice group label or initial native confirm state missing");
  }
}

validateEraAccessibility();

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
