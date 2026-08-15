# LuxEstate — Bootstrap Real Estate Template

A local, self-contained copy of the **LuxEstate** demo by [BootstrapMade](https://bootstrapmade.com/luxestate-bootstrap-real-estate-template/),
mirrored from <https://bootstrapmade.com/demo/LuxEstate/> for use as the design reference
for this Real Estate Management System.

- **Template:** LuxEstate
- **Author:** BootstrapMade.com
- **Framework:** Bootstrap v5.3.8
- **Upstream build date:** Apr 01 2026
- **Mirrored:** 2026-08-12

---

## Viewing it

The folder sits inside the XAMPP web root, so with Apache running:

```
http://localhost/Real-State-MS/Real-State-MS/templates/luxestate/
```

You can also open `index.html` directly in a browser — every asset is referenced
relatively, so it works from the filesystem too.

---

## Structure

```
templates/luxestate/
├── index.html                  Home
├── about.html                  About
├── properties.html             Property listings (grid)
├── property-details.html       Single property detail
├── services.html               Services overview
├── service-details.html        Single service detail
├── agents.html                 Agent directory
├── agent-profile.html          Single agent profile
├── contact.html                Contact + form
├── privacy.html                Privacy policy
├── terms.html                  Terms of service
├── 404.html                    Not-found page
└── assets/
    ├── css/main.css            All template styling (CSS custom properties for theming)
    ├── js/main.js              Nav, scroll, sliders, AOS-style init
    ├── img/
    │   ├── favicon.png, apple-touch-icon.png, logo.webp
    │   ├── person/             Testimonial / avatar portraits (.webp)
    │   └── real-estate/        Property + agent photography (.webp)
    └── vendor/
        ├── bootstrap/          Bootstrap 5.3.8 CSS + JS bundle
        ├── bootstrap-icons/    Icon font (CSS + woff/woff2)
        ├── swiper/             Carousels / sliders
        ├── glightbox/          Image lightbox
        ├── purecounter/        Animated stat counters
        └── php-email-form/     Client-side form validation
```

**59 files, ~4 MB.** 463 local references were verified to resolve — see *Known gaps* for the exceptions.

---

## Changes made to the downloaded files

The demo is served from BootstrapMade's infrastructure and needed four adjustments
to work standalone. Nothing else in the markup, CSS, or JS was touched.

1. **Vendor paths rewritten.** The live demo shares one vendor directory across all
   templates via `../../vendors/…`. All 108 references were repointed to the local
   `assets/vendor/…`, matching the layout of the official template download.
2. **Cloudflare email obfuscation decoded.** Addresses were wrapped in Cloudflare's
   `__cf_email__` spans and rendered as "[email protected]", decoded at runtime by a
   script on their CDN that 404s locally. All 30 addresses were decoded back to plain
   text / `mailto:` links, and the 14 decoder `<script>` tags removed.
3. **Absolute links made relative.** `404.html` linked to `/` and `/contact`, which
   resolve to the server root rather than this folder. Now `index.html` and `contact.html`.
4. **Dead pages not saved.** See below.

---

## Known gaps

These are limitations of the upstream demo, not of the mirror:

- **`blog-details.html` is linked from every page's "More Pages" dropdown but does not
  exist** — it returns 404 from BootstrapMade's own server. `about.html` likewise links
  to a missing `team.html`. The links were left in place rather than invented; delete
  them from the nav or build the pages when you adapt the template.
- **`forms/contact.php` and `forms/consultation.php` are not included.** The contact,
  agent-profile, property-details, and service-details forms post to these paths, which
  return 403 on the demo server (server-side code is never exposed). The forms will
  render and validate client-side but not submit. Wire them to this project's own PHP
  mail handling.
- **Google Fonts load from a CDN.** `Roboto`, `Montserrat`, and `Raleway` are still
  pulled from `fonts.googleapis.com`, so typography needs an internet connection.
  This project already self-hosts Raleway under `assets/vendor/raleway/` — worth doing
  the same here if you want fully offline rendering.

---

## License and attribution

LuxEstate is distributed under the [BootstrapMade license](https://bootstrapmade.com/license/).
The **free** license **requires keeping the "Designed by BootstrapMade" credit link** in
the footer of every page; removing it requires purchasing their commercial license.

That credit is present in the footer of all 12 pages here. If you carry this design into
the main application, either keep the link or buy the license before shipping.
