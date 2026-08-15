# Vendor assets (self-hosted)

Every third-party asset the app uses is stored here. **No CDN is contacted at
runtime** — the UI renders identically with the network unplugged.

| Asset | Version | Path | License |
|---|---|---|---|
| Bootstrap Icons | 1.11.3 | `bootstrap-icons/` (css + `fonts/*.woff2`, `*.woff`) | MIT |
| Chart.js (UMD) | 4.4.1 | `chartjs/chart.umd.min.js` | MIT |
| Inter | variable (wght 100–900) | `inter/inter.css` + `inter/fonts/*.woff2` | SIL OFL 1.1 |

## How they are referenced

- Reference vendor files through the `VENDOR_URL` constant (`config/app.php`),
  never with a hard-coded CDN URL.
- Bootstrap Icons CSS is linked in the four page shells: `views/layout.php`,
  `views/public/layout.php`, `views/auth/login.php`, `views/auth/register.php`.
  Those shells also `preload` the two woff2 files so icons and text paint
  without a flash.
- Inter is pulled in by `assets/css/style.css` via `@import '../vendor/inter/inter.css'`.
- Chart.js is loaded only by `views/admin/reports/index.php`.

Font URLs inside `bootstrap-icons.min.css` and `inter.css` are **relative to the
CSS file**, so each package must keep its `fonts/` subfolder alongside its CSS.

## Restoring / upgrading

Run `download-vendor.bat` (or the `.ps1` directly) while online. Versions are
pinned at the top of `download-vendor.ps1`; edit them there to upgrade.
