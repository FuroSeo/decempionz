"use strict";
/* The game code lives in app.js (loaded with defer); markup and small boot scripts stay in index.html.
   Tests that inspect client source read both, so markers can be found wherever they live. */
const fs = require("fs");
const path = require("path");

module.exports = function readAppSource(root) {
  return fs.readFileSync(path.join(root, "index.html"), "utf8") + "\n" + fs.readFileSync(path.join(root, "app.js"), "utf8");
};
