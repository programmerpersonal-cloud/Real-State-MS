# Vendor assets (self-hosted)

Every third-party asset the app uses is stored here. **No CDN is contacted at
runtime** — the UI renders identically with the network unplugged.

| Asset | Version | Path | License |
|---|---|---|---|
| Bootstrap Icons | 1.11.3 | `bootstrap-icons/` (css + `fonts/*.woff2`, `*.woff`) | MIT |
| Chart.js (UMD) | 4.4.1 | `chartjs/chart.umd.min.js` | MIT |
| Inter | variable (wght 100–900) | `inter/inter.css` + `inter/fonts/*.woff2` | SIL OFL 1.1 |
| flag-icons | 7.5.0 | `flag-icons/*.svg` (18 of them) + `LICENSE` | MIT |

## How they are referenced

- Reference vendor files through the `VENDOR_URL` constant (`config/app.php`),
  never with a hard-coded CDN URL.
- Bootstrap Icons CSS is linked in the four page shells: `views/layout.php`,
  `views/public/layout.php`, `views/auth/login.php`, `views/auth/register.php`.
  Those shells also `preload` the two woff2 files so icons and text paint
  without a flash.
- Inter and Raleway are pulled in by `assets/css/design-system.css` via
  `@import '../vendor/inter/inter.css'` — that file loads first in every
  bundle, so the faces are declared before any rule uses them.
- Chart.js is loaded only by `views/admin/reports/index.php`.
- flag-icons supplies the country flags in the phone field. Only the 18
  countries in `phoneCountries()` (`includes/validation.php`) are vendored, and
  `phoneFlagUrl()` in that same file is the only place the ISO code is turned
  into a path — add a country there and drop its `4x3` SVG in beside the
  others. Emoji flags were the first attempt and were replaced: Windows ships
  no flag glyphs, so every flag rendered as the two letters of the country
  code.

Font URLs inside `bootstrap-icons.min.css` and `inter.css` are **relative to the
CSS file**, so each package must keep its `fonts/` subfolder alongside its CSS.

## Restoring / upgrading

Run `download-vendor.bat` (or the `.ps1` directly) while online. Versions are
pinned at the top of `download-vendor.ps1`; edit them there to upgrade.
