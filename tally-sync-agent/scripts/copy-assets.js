// tsc only compiles .ts -> .js; the settings window's HTML needs to land
// next to its compiled renderer.js in dist/ too. Plain Node so this works
// identically on macOS (dev) and Windows (where this actually ships).
const fs = require('fs');
const path = require('path');

const src = path.join(__dirname, '..', 'src', 'settings-window', 'index.html');
const destDir = path.join(__dirname, '..', 'dist', 'settings-window');
const dest = path.join(destDir, 'index.html');

fs.mkdirSync(destDir, { recursive: true });
fs.copyFileSync(src, dest);
console.log(`Copied ${src} -> ${dest}`);
