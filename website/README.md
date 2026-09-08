# Dispute Guard — marketing website

Static marketing site for the Dispute Guard Shopify app. No build step, no
dependencies, no server-side code — plain HTML, one stylesheet and one small
JavaScript file.

## Structure

```
website/
├── index.html          Home — hero, problem, features, how it works,
│                       shipment states, pricing, FAQ, CTA
├── about.html          About — approach, principles, in/out of scope, onboarding
├── contact.html        Contact — support form (mailto), contact details, support FAQ
├── privacy.html        Privacy policy — 15 sections, Shopify-compliance aware
└── assets/
    ├── css/style.css   Design system: colours, layout, components, responsive rules
    ├── js/main.js      Mobile nav, footer year, contact-form mailto composer
    └── img/
        ├── logo.png    App icon, 512×512
        └── favicon.png App icon, 96×96
```

## Theme

Colours are taken from the app icon and defined once as CSS custom properties at
the top of `assets/css/style.css`:

| Token | Value | Used for |
| --- | --- | --- |
| `--navy` | `#0f2a5f` | Headings |
| `--blue-700` / `--blue-600` | `#1a4fd0` / `#2563eb` | Links, buttons, accents |
| `--cyan-500` | `#22b4f0` | Gradient highlight |
| `--grad-brand` | navy → blue → cyan | Hero, buttons, icon tiles |

Change a token there and it updates across all four pages.

## Contact form

`contact.html` has no backend. On submit, `main.js` builds a `mailto:` link to
**disputeguardapp@gmail.com** with the visitor's details pre-filled and opens
their mail client. Nothing is posted anywhere. If you later add a form endpoint,
replace the submit handler in `assets/js/main.js`.

## Publishing

Upload the contents of this folder to any static host or to a subdomain's
document root — e.g. `https://disputeguard.example.com/`. `index.html` is the
entry point and all links are relative, so it also works from a subdirectory.

Once the site is live, use these URLs in the Shopify Partner Dashboard listing:

- App URL / homepage → `/index.html`
- Privacy policy → `/privacy.html`
- Support contact → `/contact.html` or `disputeguardapp@gmail.com`

## Before going public

- Add your registered legal entity name and postal address to the privacy
  policy (see the HTML comment near the top of `privacy.html`).
- Review the "Last updated" date in `privacy.html` when you change the policy.
- Confirm the retention periods listed in the policy still match
  `CHARGEGUARD_RETENTION_DAYS` and the scheduler settings in the app.
