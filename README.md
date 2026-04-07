# SPA & SKINCARE ՊՐԵՄԻՈՒՄ ՀԱՄԱԿԱՐԳ — Cursor Ready Blueprint

## Maintainers: authoritative vs archival (English)

- **Canonical runnable app and structure:** `system/README.md`
- **Product information architecture (live):** ten primary workspaces in the tenant app (Overview, Calendar, Clients, Team, Catalog, Sales, Inventory, Marketing, Reports, Admin). **`/settings` is Admin — policies, defaults, and control-plane only**, not the home for day-to-day operational CRUD. Spec: `system/docs/BUSINESS-IA-CANONICAL-LAW-01.md`.
- **Production HTTP document root:** `system/public` only — `system/docs/DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01.md`
- **Canonical “what is live vs historical” index:** `system/docs/MAINTAINER-RUNTIME-TRUTH.md`
- **Archived blueprint** (vision-era; may not match current PHP modules): `archive/blueprint-reference/`
- **Archived Cursor exports** (not release-verified): `archive/cursor-context/`

The Armenian sections below describe the original blueprint package. For implementation and audits, prefer `system/` docs and code over `archive/`.

**Fence (read before Armenian text):** Bullets that mention keeping a **settings-centered** or **configuration-centralized** product describe **historical blueprint goals** (configurability as a design principle), **not** the live tenant UI model. The runnable app follows **ten primary workspaces**; **`/settings` is Admin — policy and defaults only** — see the English Maintainers bullet and `system/docs/BUSINESS-IA-CANONICAL-LAW-01.md`. If Armenian wording appears to tell Cursor to center the whole product on Settings, **ignore that reading** for current execution.

---

Այս փաթեթը նախատեսված է այնպես, որ այն դրվի նախագծի արմատային պանակի մեջ, և Cursor-ը կարողանա արագ հասկանալ համակարգի ճարտարապետությունը, մոդուլները, կախվածությունները, հիմքային կանոնները և կառուցման հերթականությունը։

## Փաթեթի նպատակը (պատմական նկարագրություն — տե՛ս անգլերեն Fence-ը վերևում)
- տալ ամբողջ համակարգի ամբողջական քարտեզը,
- ֆիքսել մոդուլային վերջնական կառուցվածքը,
- բաժանել տեսանելի մոդուլները և անտեսանելի համակարգային միջուկը,
- սահմանել կառուցման ճիշտ հերթականությունը,
- պահել համակարգը կարգավորումներով կառավարվող,
- տալ Cursor-ի համար մեկնարկային հստակ կոնտեքստ։

## Ինչպես օգտագործել
1. Այս ամբողջ պանակը գցեք ձեր նախագծի արմատային պանակի մեջ։
2. **Պատմական նախագծային փաստաթղթերը** գտնվում են `archive/blueprint-reference/` պանակում։ **Գործող հավելվածը**՝ `system/`։
3. Դրանից հետո Cursor-ին հանձնարարեք աշխատել միայն այս փաթեթի կանոններով։
4. Յուրաքանչյուր նոր կոդային քայլից առաջ Cursor-ին հիշեցրեք՝
   - չկոտրել մոդուլային կառուցվածքը,
   - չխառնել միջուկը UI-ի հետ,
   - ավելացնել ամեն նոր ֆայլ ամբողջ համակարգի կառուցվածքի մեջ,
   - պահել կարգավորումների-կենտրոնացված մոտեցումը։

## Գլխավոր փաստաթղթերը (պատմական — `archive/blueprint-reference/`)
- `archive/blueprint-reference/01-SYSTEM-VISION.md`
- `archive/blueprint-reference/02-FULL-SITEMAP.md`
- `archive/blueprint-reference/03-MODULAR-STRUCTURE.md`
- `archive/blueprint-reference/04-FOLDER-MAP.md`
- `archive/blueprint-reference/05-CORE-ENGINE.md`
- `archive/blueprint-reference/06-WORKFLOWS.md`
- `archive/blueprint-reference/07-DATA-MAP.md`
- `archive/blueprint-reference/08-RBAC.md`
- `archive/blueprint-reference/09-SETTINGS-DRIVEN.md`
- `archive/blueprint-reference/10-UI-UX-STANDARDS.md`
- `archive/blueprint-reference/11-BUILD-PHASES.md`
- `archive/blueprint-reference/12-CURSOR-STARTER-PROMPT.md`

## Մոդուլների առանձին բաժիններ
Գործող կոդը՝ `system/modules/` (տե՛ս `system/modules/README.md`)։ `archive/`-ի մոդուլային նկարագրությունները պատմական են։

## Մեքենայորեն ընթերցվող ֆայլեր (պատմական — `archive/cursor-context/`)
- `archive/cursor-context/system_manifest.json`
- `archive/cursor-context/module_dependencies.json`
- `archive/cursor-context/domain_map.json`

## Կարևոր կանոն
Այս փաթեթը ոչ թե ուղղակի փաստաթուղթ է, այլ նախագծի ճարտարապետական սահմանադրություն։
Cursor-ը պետք է աշխատի սրա հիման վրա, ոչ թե պատահական որոշումներով։
