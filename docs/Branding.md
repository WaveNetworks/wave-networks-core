# App Branding & Store Assets

Status: **SPEC — not built.** Written 2026-08-28 after a run of brand bugs in pwt that
every existing check passed. Review before implementing.

## Why this exists

pwt shipped a PWA icon that was a **blank white square**: a valid 512×512 PNG, served
HTTP 200, correct dimensions, correct filename. Nothing downstream noticed for months.
It had one unique colour.

The cause chain is the argument for this document:

1. The branding **favicon** was set to `branding_logo_dark.svg` — the *white* dark-mode
   mark. Nothing said a favicon shouldn't be the inverse variant.
2. That SVG draws its monogram as three `<text>` elements in `MonogramWorld5Regular`, a
   font **served nowhere** — no `assets/fonts/`, no `@font-face`. Every renderer
   substitutes a fallback serif, so the mark on screen was never the brand mark.
3. `generate_pwa_icons()` rasterised it through Imagick, which renders SVG onto an
   **opaque white** canvas by default — flattening white artwork out of existence.
4. Nothing checked the output. A flat square is invisible to every test that asks
   "is it a valid PNG of the right size?"

Four independent failures, and **not one of them is a dimension or file-type problem**.
That is what compliance here has to mean: enforce assets that *work*, not assets that
*parse*.

## Scope

One branding section per app, covering three consumers:

| Consumer | Needs |
|---|---|
| Web app + PWA | favicon, logo (light/dark), maskable icon, manifest icons, screenshots |
| Apple App Store | 1024 icon, iPhone + iPad screenshots |
| Google Play | 512 icon, feature graphic, phone + tablet screenshots, adaptive icon layers |

Reachable three ways, all sharing one validator: the **admin UI**, a **service-key API**
(`branding:read` / `branding:write` — neither scope exists today), and **MCP tools** for
agents. Today `saveBranding` is a member action only, so an agent cannot touch branding at
all; that is why a broken icon needed a human to click Save.

## Storage: the app repo, not server uploads

Assets live in the **app repo** under `assets/brand/`, not in server-side
`admin/branding/` uploads.

- They survive a rebuild. Server uploads do not.
- The mobile bundle gets them for free — `release-mobile.sh` already copies
  `assets/img/*`, and the shell must render the same art as the web app (see
  `app-model.json` `brand` and the build_shell parity gate).
- They are versioned and reviewable. A brand change arrives as a diff with history,
  instead of silently replacing a file on one host — which is how a blank icon sat in
  production unnoticed.
- Both channels work. An agent can commit the file OR call the API, and they converge on
  the same source of truth.

Note the last point is deliberately *not* "cloud agents cannot reach the API". Cloud agents
do reach their app's admin API; the one blocked host is `subtheme.com`, most likely rate
limited from LLM crawler traffic (it serves `llms.txt`, as does `nokemo.com`;
`playwithtarot.com` does not). Repo storage is chosen here for durability, parity and
reviewability — not to work around a reachability limit.

The admin UI writes through the same API, so "upload in admin" and "agent commits a file"
converge on one source of truth.

## Slots

Icons derive from ONE master. Six hand-uploaded icons drift, and drift is the failure this
whole document exists to prevent. Screenshots cannot be derived and are uploaded per class.

### Master
| Slot | Spec |
|---|---|
| `icon_master` | ≥1024×1024, PNG or path-only SVG, square |

Derived: Apple 1024 (alpha stripped), Play 512 (alpha kept), PWA 192/512, PWA maskable
192/512 (safe-zone padded), Android adaptive foreground.

### Uploaded
| Slot | Spec | Notes |
|---|---|---|
| `logo_light` / `logo_dark` | any, transparent | wordmark/lockup for in-app chrome |
| `feature_graphic` | 1024×500, no alpha | **required to publish on Play** |
| `screenshot_iphone_69` | 1290×2796 or 1320×2868 | Apple requires ≥1 iPhone size |
| `screenshot_ipad_13` | 2064×2752 or 2048×2732 | required if iPad supported |
| `screenshot_phone` | 2–8, 16:9 or 9:16, 320–3840px | Play, min 2 |
| `screenshot_tablet_7` / `_10` | Play tablet classes | required if tablet declared |
| `adaptive_background` | solid or simple art | Android adaptive icon layer |

Colours/text already exist and stay: `site_name`, `site_short_name`, `site_description`,
`theme_color_light/dark`, `background_color_light/dark`.

## Compliance rules

Two severities. **BLOCK** refuses the asset — it would be rejected by a store or ship
broken. **WARN** saves but flags. A block on one slot must never prevent saving unrelated
fields; that is how people end up bypassing the whole section.

### Structural — BLOCK
- Wrong dimensions or aspect for the slot.
- Alpha channel present on Apple 1024 or Play feature graphic (**hard store rejection**).
- Not a real image, or a format the target does not accept.
- Over the store's byte ceiling.

### Semantic — the rules that would have caught this session's bugs
- **BLOCK: flat single colour.** Sample a 16×16 grid; if every sample matches, the asset
  is blank. *Catches the shipped blank icon directly.* Already implemented in
  `generate_pwa_icons()`; lift it into the shared validator.
- **BLOCK: SVG containing `<text>`.** Its glyphs depend on a font the renderer may not
  have — the server, the phone, and the CI box are all different renderers. Require the
  text converted to paths. *Catches the wrong-monogram bug at upload instead of in a
  shipped icon.*
- **BLOCK: invisible against its own backdrop.** Composite the asset onto the surface it
  will actually sit on (`background_color_*`, or the derived icon backdrop) and require a
  minimum colour count / contrast. White-on-white is 1 colour. *Catches the favicon being
  set to the inverse-colour variant.*
- **WARN: maskable content outside the safe zone.** Anything beyond the 72/108dp circle
  gets cropped by Android launchers.
- **WARN: low effective resolution** — upscaled from a smaller source, so it will look
  soft at 1024.
- **WARN: `logo_light` and `logo_dark` identical.** One of them is wrong.

### Cross-field
- **BLOCK: publishing Play without `feature_graphic`.** Play will not accept the listing.
- **WARN: `theme_color`/`background_color` left at `#ffffff` default** while the brand mark
  is light — the exact condition that made the icon invisible and made branding colours
  useless as a backdrop source.

## API

```
apiGetBranding          -> current values, which slots are filled, per-slot spec,
                           and outstanding violations
apiValidateBrandingAsset-> dry run; returns violations without saving
apiSetBrandingAsset     -> slot + file; runs validation; BLOCK violations refuse
apiSetBrandingFields    -> colours / names / description
apiRegenerateDerived    -> re-derive from icon_master (idempotent; the repair path)
```

Scopes: `branding:read`, `branding:write`.

**Transport — undecided, needs a call.** No service-key file upload exists in core to copy
(`mediaApiActions.php` is read-only). Options: base64 in the form body (works with the
existing dispatcher; ~33% overhead, and a 1320×2868 screenshot is ~7MB encoded — check
`post_max_size`); multipart `$_FILES` on the API path (efficient, more dispatcher work);
fetch-by-URL (trivial, natural for agents, needs SSRF guarding).

## MCP

```
branding_get(app_id)
branding_set_asset(app_id, slot, path_or_url)
branding_validate(app_id)
branding_regenerate_derived(app_id)
```

Routed through the existing `monitor_call_app_admin` proxy so nokemo decrypts the target
app's key server-side and it never enters the agent's context.

⚠️ **Tools take a PATH or URL, never inline base64.** A 2MB screenshot is ~2.7MB of base64
*in the conversation* — it would blow context and cost on every upload. The MCP server runs
locally as the user and does the encoding itself.

`branding_validate` matters most for agents: an agent generating store art will produce
plausible-looking wrong assets, and the validator is what turns that into a fixable error
instead of a store rejection days later.

## Two consumers this unlocks

Once every app admin has this section, it stops being a build input and becomes a product
surface. Both consumers change requirements, so they belong in the spec rather than being
bolted on.

### 1. Customers manage their own branding

`registered_app.user_id` already makes apps customer-owned, so the section is naturally
self-service. That raises the bar in three ways:

- **Validation must explain and offer to fix, not just refuse.** "Rejected: alpha channel"
  is useless to a customer. "Apple rejects icons with transparency — flatten onto your
  background colour?" with a preview is not. Most BLOCK rules have an obvious
  remediation: strip alpha, resize, pad for the maskable safe zone, flatten onto the brand
  backdrop. Offer it.
- **Preview is the real safety net.** Show the asset as it will actually appear — home
  screen, launcher, store listing, light and dark. A human looking at a preview would have
  spotted the blank icon in one second; no amount of automated checking replaces seeing it.
  This is the single highest-value item in the section.
- **Role gating.** `saveBranding` has no role check today. Branding must be restricted to
  the app's owner/admin, not any member.

### 2. nokemo.com markets them as a portfolio

The same assets — icon, feature graphic, screenshots, name, description — are exactly what
a portfolio page needs. nokemo already holds every app in `registered_app` and can already
reach each one's admin API.

⚠️ **Publishing is opt-in and needs real consent.** These are *customers'* apps. Requirements:

- `registered_app.portfolio_visible` (default **0**) plus marketing fields the table does
  not have today: `tagline`, `description`, `category`, `live_url`, `store_urls`.
- **Screenshots frequently contain real user data.** Store screenshots are usually real UI
  with real content. Republishing a customer's screenshot on a public marketing page can
  leak their users' data. Per-asset opt-in, an explicit warning at upload, and a review
  step before anything goes public — not a single blanket toggle.
- Pre-launch apps must never appear. Gate on `status` as well as the flag.

**Mirror the assets; do not fetch live.** On 2026-08-28 three apps on this host returned
503 for ~20 minutes. A portfolio that pulls each app's assets at page render breaks
whenever any app is down, leaks that outage to a marketing page, and is slow even when
healthy. nokemo should copy assets into its own storage when they change (the API can
report an asset hash so it only re-pulls on change).

**The portfolio raises the stakes on compliance**: a showcase is only as good as its worst
icon, and the blank-icon failure mode becomes publicly visible on nokemo's own marketing.

## Open questions

1. Transport (above).
2. Does `icon_master` derivation belong in core (Imagick/GD, already there and already
   burned us) or in a build step with better tooling?
3. Should a BLOCK violation fail the **mobile build** too, alongside the existing
   `build_shell` brand-parity gate?
4. Migration: existing apps have `favicon` / `logo` / `logo_dark` in `admin/branding/`.
   Map them into the new slots, or require a one-time re-declaration per app?
5. Portfolio consent: one opt-in per app, or per asset? Screenshots carry the data-leak
   risk, so per-asset is safer — but it is more friction for the customer.
6. Where do mirrored portfolio assets live on nokemo, and what invalidates the cache —
   an asset hash returned by `apiGetBranding`, or a push on save?
