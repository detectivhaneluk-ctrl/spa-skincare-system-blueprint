<style>
/* Client workspace shell — Jobs-Pro / calm SaaS (2026) */
/* Failsafe: #client-ref-rdv must stay a real block even if nested-main DOM repair breaks scoped chains */
#client-ref-rdv.client-ref-rdv {
  display: block;
  box-sizing: border-box;
}
.client-ref-surface {
  --cr-surface: rgba(255, 255, 255, 0.78);
  --cr-border: rgba(15, 23, 42, 0.09);
  --cr-border-strong: rgba(15, 23, 42, 0.14);
  --cr-text: #1e293b;
  --cr-muted: #64748b;
  --cr-accent: #1a6359;
  --cr-accent-soft: rgba(26, 99, 89, 0.12);
  --cr-radius: 14px;
  --cr-ease: cubic-bezier(0.25, 0.1, 0.25, 1);
}
.client-ref-surface .client-ref-title-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 1.25rem 1.5rem;
  margin-bottom: 0.35rem;
  padding-bottom: 1.25rem;
  border-bottom: 1px solid var(--cr-border);
}
.client-ref-surface .client-ref-title {
  margin: 0;
  font-size: 1.65rem;
  font-weight: 600;
  letter-spacing: -0.035em;
  color: var(--cr-text);
  line-height: 1.15;
}
.client-ref-surface .client-ref-title-meta {
  text-align: right;
  font-size: 0.875rem;
  line-height: 1.55;
  color: var(--cr-muted);
}
.client-ref-surface .client-ref-meta-label {
  color: var(--cr-muted);
  font-weight: 500;
  margin-right: 0.4rem;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.client-ref-surface .client-ref-meta-footnote {
  text-align: right;
  max-width: 22rem;
  margin: 0.45rem 0 0 auto;
  font-size: 0.75rem;
  color: #94a3b8;
  line-height: 1.45;
}
.client-ref-surface .client-ref-tabs {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.2rem 0.35rem;
  margin: 0 0 1.35rem;
  padding: 0;
  border-bottom: 1px solid var(--cr-border);
}
.client-ref-surface .client-ref-tab {
  display: inline-flex;
  align-items: center;
  padding: 0.5rem 0.85rem;
  margin-bottom: -1px;
  text-decoration: none;
  color: #475569;
  border: 1px solid transparent;
  border-bottom: none;
  font-size: 0.8125rem;
  font-weight: 500;
  border-radius: 10px 10px 0 0;
  transition: background 0.15s var(--cr-ease), color 0.15s var(--cr-ease), border-color 0.15s var(--cr-ease);
}
.client-ref-surface .client-ref-tab:hover {
  background: rgba(15, 23, 42, 0.04);
  color: var(--cr-text);
}
.client-ref-surface .client-ref-tab--active {
  border-color: var(--cr-border);
  border-bottom-color: #f8fafc;
  background: #f8fafc;
  color: var(--cr-text);
  font-weight: 600;
  cursor: default;
}
.client-ref-surface .client-ref-tab--disabled {
  opacity: 0.42;
  cursor: not-allowed;
  pointer-events: none;
}
.client-ref-surface .client-ref-body {
  display: grid;
  grid-template-columns: minmax(240px, 280px) minmax(0, 1fr);
  gap: 1.5rem 1.75rem;
  align-items: start;
}
@media (max-width: 960px) {
  .client-ref-surface .client-ref-body { grid-template-columns: 1fr; }
  .client-ref-surface .client-ref-title-meta { text-align: left; }
  .client-ref-surface .client-ref-meta-footnote { text-align: left; margin-left: 0; }
}
.client-ref-surface .client-ref-sidebar {
  border: 1px solid var(--cr-border);
  border-radius: var(--cr-radius);
  padding: 1.15rem 1.2rem 1.25rem;
  background: var(--cr-surface);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}
.client-ref-surface .client-ref-sidebar-search input[type="text"] {
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  margin-bottom: 0.4rem;
  padding: 0.5rem 0.65rem;
  border: 1px solid var(--cr-border);
  border-radius: 10px;
  font-size: 0.875rem;
  background: rgba(255, 255, 255, 0.9);
}
.client-ref-surface .client-ref-sidebar-search button {
  width: 100%;
  padding: 0.45rem 0.75rem;
  border-radius: 10px;
  border: 1px solid var(--cr-border-strong);
  background: #fff;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--cr-text);
  cursor: pointer;
}
.client-ref-surface .client-ref-sidebar-search button:hover {
  background: #f8fafc;
}
.client-ref-surface .client-ref-sidebar-label {
  display: block;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cr-muted);
  margin-bottom: 0.35rem;
}
.client-ref-surface .client-ref-back { margin: 0.85rem 0; font-size: 0.875rem; }
.client-ref-surface .client-ref-back a { color: var(--cr-accent); text-decoration: none; font-weight: 500; }
.client-ref-surface .client-ref-back a:hover { text-decoration: underline; }
.client-ref-surface .client-ref-avatar {
  height: 96px;
  background: linear-gradient(145deg, #f1f5f9 0%, #e2e8f0 100%);
  border: 1px dashed var(--cr-border-strong);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0.65rem 0 1rem;
  color: var(--cr-muted);
  font-size: 0.8125rem;
}
.client-ref-surface .client-ref-avatar--photo {
  border-style: solid;
  border-color: var(--cr-border-strong);
  padding: 0;
  overflow: hidden;
  background: #f8fafc;
}
.client-ref-surface .client-ref-avatar--photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.client-ref-surface .client-ref-sidebar-heading {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--cr-muted);
  margin: 1.1rem 0 0.45rem 0;
  border-top: 1px solid var(--cr-border);
  padding-top: 0.85rem;
}
.client-ref-surface .client-ref-sidebar-heading:first-of-type { border-top: 0; padding-top: 0; margin-top: 0; }
.client-ref-surface .client-ref-sidebar-dl { margin: 0; font-size: 0.8125rem; }
.client-ref-surface .client-ref-sidebar-dl dt { font-weight: 600; margin-top: 0.4rem; color: #475569; font-size: 0.75rem; }
.client-ref-surface .client-ref-sidebar-dl dd { margin: 0.1rem 0 0; color: var(--cr-text); font-weight: 500; }
.client-ref-surface .client-ref-main { min-width: 0; }
.client-ref-surface .client-ref-block {
  margin-top: 1.35rem;
  padding-top: 1.15rem;
  border-top: 1px solid var(--cr-border);
}
.client-ref-surface .client-ref-block:first-child { border-top: 0; padding-top: 0; margin-top: 0; }
.client-ref-surface .client-ref-block--primary { border-top: 1px solid var(--cr-border-strong); }
.client-ref-surface .client-ref-block-title {
  font-size: 0.9375rem;
  font-weight: 600;
  letter-spacing: -0.02em;
  margin: 0 0 0.65rem 0;
  color: var(--cr-text);
}
.client-ref-surface .client-ref-subblock-title { font-size: 0.875rem; margin: 1rem 0 0.5rem 0; font-weight: 600; color: #334155; }
.client-ref-surface .client-ref-inline-dl { display: grid; grid-template-columns: auto 1fr; gap: 0.35rem 1.25rem; margin: 0; font-size: 0.875rem; }
.client-ref-surface .client-ref-inline-dl dt { font-weight: 600; margin: 0; color: var(--cr-muted); }
.client-ref-surface .client-ref-inline-dl dd { margin: 0; color: var(--cr-text); }
.client-ref-surface .client-ref-actions-row { display: flex; flex-wrap: wrap; gap: 0.5rem 0.65rem; margin: 0.85rem 0 0.15rem; }

.client-ref-surface .client-ref-quick-book {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.75rem;
    margin: 0 0 1rem;
    padding: 0.65rem 0.85rem;
    border-radius: 8px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(255, 255, 255, 0.65);
}
.client-ref-surface .client-ref-quick-book__hint { margin: 0; flex: 1 1 12rem; }
.client-ref-surface .client-ref-quick-book__disabled {
    opacity: 0.55;
    cursor: not-allowed;
    pointer-events: none;
}
.client-ref-surface .client-ref-details-main .client-ref-details-save-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
  margin-bottom: 1rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid var(--cr-border);
}
.client-ref-surface .client-ref-details-form .client-ref-block-title { margin-top: 1.25rem; }
.client-ref-surface .client-ref-details-form .client-ref-block-title:first-child { margin-top: 0; }
.client-ref-surface .client-ref-details-form > h2 {
  font-size: 1rem;
  margin: 1.25rem 0 0.65rem 0;
  font-weight: 600;
  color: var(--cr-text);
}

/* Appointments workspace (summary page + /clients/{id}/appointments) */
.client-ref-surface .client-ref-rdv {
  margin-top: 1.5rem;
  padding-top: 0;
  border-top: none;
}
.client-ref-surface .client-ref-rdv__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem 1.25rem;
  margin-bottom: 1.1rem;
}
.client-ref-surface .client-ref-rdv__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
  letter-spacing: -0.025em;
  color: var(--cr-text);
}
.client-ref-surface .client-ref-rdv__lede {
  margin: 0.35rem 0 0;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: var(--cr-muted);
  max-width: 42rem;
}
.client-ref-surface .client-ref-rdv__cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.55rem 1.1rem;
  border-radius: 11px;
  font-size: 0.8125rem;
  font-weight: 600;
  letter-spacing: -0.01em;
  text-decoration: none;
  color: #fff;
  background: var(--cr-text);
  border: 1px solid var(--cr-text);
  transition: background 0.15s var(--cr-ease), transform 0.12s var(--cr-ease);
  white-space: nowrap;
}
.client-ref-surface .client-ref-rdv__cta:hover {
  background: #1e293b;
  border-color: #1e293b;
}
.client-ref-surface .client-ref-rdv__cta:active { transform: scale(0.99); }
.client-ref-surface .client-ref-rdv__summary {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem 0.55rem;
  margin-bottom: 1.1rem;
}
.client-ref-surface .client-ref-rdv__chip {
  display: inline-flex;
  align-items: baseline;
  gap: 0.4rem;
  padding: 0.35rem 0.65rem;
  border-radius: 999px;
  border: 1px solid var(--cr-border);
  background: rgba(255, 255, 255, 0.65);
  font-size: 0.75rem;
}
.client-ref-surface .client-ref-rdv__chip-k { color: var(--cr-muted); font-weight: 500; }
.client-ref-surface .client-ref-rdv__chip-v { font-weight: 700; color: var(--cr-text); letter-spacing: -0.02em; }
.client-ref-surface .client-ref-rdv__filter-card {
  border: 1px solid var(--cr-border);
  border-radius: var(--cr-radius);
  padding: 1.1rem 1.2rem 1.15rem;
  background: var(--cr-surface);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  margin-bottom: 1.1rem;
}
.client-ref-surface .client-ref-rdv__filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
  gap: 0.85rem 1rem;
  margin-bottom: 0.85rem;
}
.client-ref-surface .client-ref-rdv__field label {
  display: block;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--cr-muted);
  margin-bottom: 0.35rem;
}
.client-ref-surface .client-ref-rdv__field select,
.client-ref-surface .client-ref-rdv__field input[type="date"] {
  width: 100%;
  box-sizing: border-box;
  padding: 0.5rem 0.6rem;
  border: 1px solid var(--cr-border);
  border-radius: 10px;
  font-size: 0.875rem;
  background: rgba(255, 255, 255, 0.95);
  color: var(--cr-text);
}
.client-ref-surface .client-ref-rdv__field select:focus,
.client-ref-surface .client-ref-rdv__field input[type="date"]:focus {
  outline: none;
  border-color: rgba(13, 148, 136, 0.45);
  box-shadow: 0 0 0 3px var(--cr-accent-soft);
}
.client-ref-surface .client-ref-rdv__filter-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.65rem;
  align-items: center;
}
.client-ref-surface .client-ref-rdv__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.45rem 0.95rem;
  border-radius: 10px;
  font-size: 0.8125rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  border: 1px solid transparent;
  transition: background 0.15s var(--cr-ease), border-color 0.15s var(--cr-ease);
}
.client-ref-surface .client-ref-rdv__btn--primary {
  background: var(--cr-text);
  color: #fff;
  border-color: var(--cr-text);
}
.client-ref-surface .client-ref-rdv__btn--primary:hover { background: #1e293b; border-color: #1e293b; }
.client-ref-surface .client-ref-rdv__btn--ghost {
  background: rgba(255, 255, 255, 0.85);
  color: #475569;
  border-color: var(--cr-border);
}
.client-ref-surface .client-ref-rdv__btn--ghost:hover { background: #fff; border-color: var(--cr-border-strong); }
.client-ref-surface .client-ref-rdv__table-card {
  border: 1px solid var(--cr-border);
  border-radius: var(--cr-radius);
  background: var(--cr-surface);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
  overflow: hidden;
}
.client-ref-surface .client-ref-rdv__table-wrap { overflow-x: auto; }
.client-ref-surface .client-ref-rdv__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8125rem;
}
.client-ref-surface .client-ref-rdv__table thead th {
  text-align: left;
  padding: 0.65rem 0.85rem;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cr-muted);
  background: rgba(248, 250, 252, 0.85);
  border-bottom: 1px solid var(--cr-border);
  white-space: nowrap;
}
.client-ref-surface .client-ref-rdv__table tbody td {
  padding: 0.65rem 0.85rem;
  border-bottom: 1px solid rgba(15, 23, 42, 0.06);
  color: var(--cr-text);
  vertical-align: middle;
}
.client-ref-surface .client-ref-rdv__table tbody tr:last-child td { border-bottom: none; }
.client-ref-surface .client-ref-rdv__table tbody tr:hover td { background: rgba(15, 23, 42, 0.02); }
.client-ref-surface .client-ref-rdv__id {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  color: #334155;
}
.client-ref-surface .client-ref-rdv__status {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border-radius: 6px;
  background: rgba(15, 23, 42, 0.05);
  font-size: 0.75rem;
  font-weight: 600;
  color: #334155;
}
.client-ref-surface .client-ref-rdv__link {
  font-weight: 600;
  color: var(--cr-accent);
  text-decoration: none;
  white-space: nowrap;
}
.client-ref-surface .client-ref-rdv__link:hover { text-decoration: underline; }
.client-ref-surface .client-ref-rdv__col-action { width: 1%; text-align: right; }
.client-ref-surface .client-ref-rdv__empty {
  padding: 2.5rem 1.5rem;
  text-align: center;
}
.client-ref-surface .client-ref-rdv__empty-title {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--cr-text);
  letter-spacing: -0.02em;
}
.client-ref-surface .client-ref-rdv__empty-text {
  margin: 0.4rem auto 0;
  max-width: 26rem;
  font-size: 0.8125rem;
  line-height: 1.55;
  color: var(--cr-muted);
}
.client-ref-surface .client-ref-rdv__empty-cta { margin: 1rem 0 0; }
.client-ref-surface .client-ref-rdv__empty-cta a {
  font-weight: 600;
  color: var(--cr-accent);
  text-decoration: none;
}
.client-ref-surface .client-ref-rdv__empty-cta a:hover { text-decoration: underline; }
.client-ref-surface .client-ref-rdv__pagination {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--cr-border);
  background: rgba(248, 250, 252, 0.5);
}
.client-ref-surface .client-ref-rdv__page-link {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--cr-accent);
  text-decoration: none;
}
.client-ref-surface .client-ref-rdv__page-link:hover { text-decoration: underline; }
.client-ref-surface .client-ref-rdv__page-meta {
  font-size: 0.8125rem;
  color: var(--cr-muted);
  font-variant-numeric: tabular-nums;
}
.client-ref-surface .client-ref-rdv__list-meta {
  margin: 0;
  padding: 0.65rem 1rem;
  font-size: 0.75rem;
  color: var(--cr-muted);
  border-top: 1px solid var(--cr-border);
  background: rgba(248, 250, 252, 0.35);
}

/* Dedicated /clients/{id}/appointments surface (scoped; summary embed unchanged) */
.client-ref--appointments-page.client-ref-surface .client-ref-main--appointments {
  min-width: 0;
}
.client-ref--appointments-page.client-ref-surface .client-ref-title-row--appointments-page {
  border-bottom-color: var(--cr-border-strong);
}
.client-ref--appointments-page.client-ref-surface .client-ref-sidebar--appointments {
  position: sticky;
  top: 0.75rem;
}
.client-ref--appointments-page.client-ref-surface .client-ref-sidebar-quickfacts {
  margin-bottom: 0.35rem;
}
.client-ref--appointments-page.client-ref-surface .client-ref-sidebar-heading--inline {
  margin-top: 0.65rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--cr-border);
}
.client-ref--appointments-page.client-ref-surface .client-ref-sidebar-dl--compact dt {
  margin-top: 0.35rem;
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--cr-muted);
}
.client-ref--appointments-page.client-ref-surface .client-ref-sidebar-dl--compact dd {
  font-size: 0.8125rem;
  word-break: break-word;
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv--dedicated-page {
  margin-top: 0;
  padding-top: 0;
  border-top: none;
}
.client-ref--appointments-page.client-ref-surface .client-ref-appts-workspace {
  border: 1px solid var(--cr-border-strong);
  border-radius: var(--cr-radius);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.95) 0%, rgba(248, 250, 252, 0.65) 100%);
  box-shadow: 0 2px 12px rgba(15, 23, 42, 0.06);
  padding: 1.35rem 1.4rem 1.5rem;
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__head--page-toolbar {
  align-items: center;
  margin-bottom: 1.25rem;
  padding-bottom: 1.15rem;
  border-bottom: 1px solid var(--cr-border);
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__title {
  font-size: 1.35rem;
  font-weight: 700;
  letter-spacing: -0.04em;
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__lede--page {
  max-width: 36rem;
  margin-top: 0.45rem;
  font-size: 0.875rem;
}
.client-ref-surface .client-ref-rdv__head-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem 0.65rem;
}
.client-ref-surface .client-ref-rdv__head-actions:empty {
  display: none;
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__btn-print {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0.95rem;
  border-radius: 10px;
  font-size: 0.8125rem;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid var(--cr-border-strong);
  background: rgba(255, 255, 255, 0.9);
  color: #334155;
  transition: background 0.15s var(--cr-ease), border-color 0.15s var(--cr-ease);
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__btn-print:hover {
  background: #fff;
  border-color: var(--cr-text);
  color: var(--cr-text);
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__filter-card-top {
  margin: -0.15rem 0 0.85rem 0;
  padding-bottom: 0.65rem;
  border-bottom: 1px solid rgba(15, 23, 42, 0.06);
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__filter-card-title {
  margin: 0;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--cr-muted);
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__results-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--cr-border);
  background: rgba(248, 250, 252, 0.75);
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__results-label {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--cr-muted);
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__results-count {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--cr-text);
  font-variant-numeric: tabular-nums;
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__empty--dedicated {
  min-height: 14rem;
  padding: 3rem 2rem 3.25rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__empty--dedicated .client-ref-rdv__empty-title {
  font-size: 1.125rem;
  font-weight: 700;
  letter-spacing: -0.03em;
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__empty--dedicated .client-ref-rdv__empty-text {
  max-width: 22rem;
  margin-top: 0.65rem;
  font-size: 0.875rem;
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__empty--dedicated .client-ref-rdv__empty-cta {
  margin-top: 1.5rem;
}
.client-ref--appointments-page.client-ref-surface .client-ref-rdv__cta--empty {
  padding: 0.65rem 1.35rem;
  font-size: 0.875rem;
}

/* Dedicated Client Details (/clients/{id}/edit) */
/* Neutralize app-shell__main content column centering (app.css max-width + margin: auto) */
main.app-shell__main.main.client-resume-page.client-ref--details-page {
  max-width: none;
  margin-left: 0;
  margin-right: 0;
  width: 100%;
  box-sizing: border-box;
}

.client-ref--details-page.client-ref-surface .client-ref-main--details {
  min-width: 0;
}
.client-ref--details-page.client-ref-surface .client-ref-title-row--details-page {
  border-bottom-color: var(--cr-border-strong);
}
.client-ref--details-page.client-ref-surface .client-ref-sidebar--details {
  position: sticky;
  top: 0.75rem;
}
.client-ref--details-page.client-ref-surface .client-ref-sidebar-quickfacts {
  margin-bottom: 0.35rem;
}
.client-ref--details-page.client-ref-surface .client-ref-sidebar-heading--inline {
  margin-top: 0.65rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--cr-border);
}
.client-ref--details-page.client-ref-surface .client-ref-sidebar-dl--compact dt {
  margin-top: 0.35rem;
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--cr-muted);
}
.client-ref--details-page.client-ref-surface .client-ref-sidebar-dl--compact dd {
  font-size: 0.8125rem;
  word-break: break-word;
}
.client-ref--details-page.client-ref-surface .client-ref-details-workspace {
  border: 1px solid var(--cr-border-strong);
  border-radius: var(--cr-radius);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.97) 0%, rgba(248, 250, 252, 0.72) 100%);
  box-shadow: 0 2px 12px rgba(15, 23, 42, 0.06);
  padding: 1.35rem 1.5rem 1.5rem;
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
}
.client-ref--details-page .client-ref-details-errors {
  margin: 0 0 1rem 0;
}
.client-ref--details-page .client-ref-details-errors .form-errors {
  margin: 0;
}
.client-ref--details-page.client-ref-surface .client-ref-details-actionbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding-bottom: 1.1rem;
  margin-bottom: 1rem;
  border-bottom: 1px solid var(--cr-border);
}
.client-ref--details-page.client-ref-surface .client-ref-details-actionbar--footer {
  margin-top: 1.75rem;
  margin-bottom: 0;
  padding-top: 1.2rem;
  padding-bottom: 0;
  border-top: 1px solid var(--cr-border);
  border-bottom: none;
}
.client-ref--details-page.client-ref-surface .client-ref-details-actionbar__primary {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.65rem;
  align-items: center;
}
.client-ref--details-page.client-ref-surface .client-ref-details-btn-save {
  background: var(--cr-text);
  color: #fff;
  border-color: var(--cr-text);
  font-weight: 600;
}
.client-ref--details-page.client-ref-surface .client-ref-details-btn-save:hover {
  background: #1e293b;
  border-color: #1e293b;
  color: #fff;
}
.client-ref--details-page.client-ref-surface .client-ref-details-btn-cancel {
  background: rgba(255, 255, 255, 0.92);
  color: #334155;
  border: 1px solid var(--cr-border-strong);
}
.client-ref--details-page.client-ref-surface .client-ref-details-btn-cancel:hover {
  background: #fff;
  border-color: var(--cr-text);
  color: var(--cr-text);
}
.client-ref--details-page.client-ref-surface .client-ref-details-idline {
  margin: 0 0 1.15rem 0;
  font-size: 0.8125rem;
  line-height: 1.55;
  color: var(--cr-muted);
}
.client-ref--details-page.client-ref-surface .client-ref-details-page-title {
  margin: 0 0 1.25rem 0;
  font-size: 1.2rem;
  font-weight: 700;
  letter-spacing: -0.03em;
  color: var(--cr-text);
}
.client-ref--details-page.client-ref-surface .client-ref-details-field-group {
  margin-bottom: 1.65rem;
  padding-bottom: 1.2rem;
  border-bottom: 1px solid rgba(15, 23, 42, 0.06);
}
.client-ref--details-page.client-ref-surface .client-ref-details-field-group:last-child {
  border-bottom: none;
  margin-bottom: 0;
  padding-bottom: 0;
}
.client-ref--details-page.client-ref-surface .client-ref-details-field-group-title {
  margin: 0 0 1rem 0;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #475569;
}
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row {
  margin-bottom: 1.05rem;
}
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row:last-child {
  margin-bottom: 0;
}
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row > label:first-child {
  display: block;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--cr-muted);
  margin-bottom: 0.35rem;
}
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row input[type="text"],
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row input[type="email"],
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row input[type="date"],
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row input[type="number"],
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row input[type="tel"],
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row select,
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row textarea {
  width: 100%;
  max-width: 38rem;
  box-sizing: border-box;
  padding: 0.5rem 0.65rem;
  border: 1px solid var(--cr-border);
  border-radius: 10px;
  font-size: 0.875rem;
  background: rgba(255, 255, 255, 0.95);
  color: var(--cr-text);
}
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row input:focus,
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row select:focus,
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row textarea:focus {
  outline: none;
  border-color: rgba(13, 148, 136, 0.45);
  box-shadow: 0 0 0 3px var(--cr-accent-soft);
}
.client-ref--details-page.client-ref-surface .client-ref-details-fields .form-row .error {
  display: block;
  margin-top: 0.35rem;
  font-size: 0.8125rem;
  color: #b91c1c;
}
.client-ref--details-page.client-ref-surface .client-ref-details-fields .hint {
  font-size: 0.8125rem;
  color: var(--cr-muted);
  margin: 0.35rem 0 0.75rem 0;
  max-width: 38rem;
}
.client-ref--details-page.client-ref-surface .client-ref-details-fields .client-ref-block-title {
  margin: 0 0 0.85rem 0;
  font-size: 0.9375rem;
  font-weight: 600;
  letter-spacing: -0.02em;
  color: var(--cr-text);
}

@media print {
  .client-ref--appointments-page .client-ref-sidebar,
  .client-ref--appointments-page .client-ref-tabs,
  .client-ref--appointments-page .client-ref-rdv__filter-card,
  .client-ref--appointments-page .client-ref-rdv__summary,
  .client-ref--appointments-page .client-ref-rdv__btn-print {
    display: none !important;
  }
  .client-ref--appointments-page .client-ref-appts-workspace {
    border: none;
    box-shadow: none;
    padding: 0;
    background: #fff;
  }
  .client-ref--appointments-page .client-ref-title-row {
    border-bottom: 1px solid #ccc;
  }
}

/* Dedicated client secondary tabs: Sales, Billing, Photos, Mail marketing, Documents */
.client-ref--client-tab.client-ref-surface .client-ref-main--client-tab {
  min-width: 0;
}
.client-ref--client-tab.client-ref-surface .client-ref-title-row--secondary-tab {
  border-bottom-color: var(--cr-border-strong);
}
.client-ref--client-tab.client-ref-surface .client-ref-sidebar--client-tab {
  position: sticky;
  top: 0.75rem;
}
.client-ref--client-tab.client-ref-surface .client-ref-sidebar-quickfacts .client-ref-sidebar-heading--inline {
  margin-top: 0.65rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--cr-border);
}
.client-ref--client-tab.client-ref-surface .client-ref-sidebar-dl--compact dt {
  margin-top: 0.35rem;
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--cr-muted);
}
.client-ref--client-tab.client-ref-surface .client-ref-sidebar-dl--compact dd {
  font-size: 0.8125rem;
  word-break: break-word;
}
.client-ref-tab-workspace {
  border: 1px solid var(--cr-border-strong);
  border-radius: var(--cr-radius);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.96) 0%, rgba(248, 250, 252, 0.7) 100%);
  box-shadow: 0 2px 12px rgba(15, 23, 42, 0.06);
  padding: 1.35rem 1.4rem 1.5rem;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}
.client-ref-tab-workspace__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem 1.25rem;
  margin-bottom: 1.15rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--cr-border);
}
.client-ref-tab-workspace__title {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 700;
  letter-spacing: -0.035em;
  color: var(--cr-text);
}
.client-ref-tab-workspace__lede {
  margin: 0.4rem 0 0;
  max-width: 40rem;
  font-size: 0.875rem;
  line-height: 1.55;
  color: var(--cr-muted);
}
.client-ref-tab-workspace__subhead {
  margin: 0 0 0.65rem 0;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #475569;
}
.client-ref-tab-workspace__muted {
  margin: 0 0 0.75rem 0;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: var(--cr-muted);
  max-width: 42rem;
}
.client-ref-tab-workspace__muted code {
  font-size: 0.75rem;
}
.client-ref-tab-workspace__cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.55rem 1.1rem;
  border-radius: 11px;
  font-size: 0.8125rem;
  font-weight: 600;
  text-decoration: none;
  color: #fff;
  background: var(--cr-text);
  border: 1px solid var(--cr-text);
  white-space: nowrap;
}
.client-ref-tab-workspace__cta:hover {
  background: #1e293b;
  border-color: #1e293b;
  color: #fff;
}
.client-ref-tab-workspace__cta--inline {
  display: inline-flex;
}
.client-ref-tab-workspace__filter-card {
  border: 1px solid var(--cr-border);
  border-radius: 12px;
  padding: 1rem 1.1rem 1.05rem;
  background: rgba(255, 255, 255, 0.75);
  margin-bottom: 1.1rem;
}
.client-ref-tab-workspace__filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
  gap: 0.85rem 1rem;
  margin-bottom: 0.75rem;
}
.client-ref-tab-workspace__field label {
  display: block;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--cr-muted);
  margin-bottom: 0.35rem;
}
.client-ref-tab-workspace__field input[type="text"],
.client-ref-tab-workspace__field input[type="date"],
.client-ref-tab-workspace__field select {
  width: 100%;
  box-sizing: border-box;
  padding: 0.5rem 0.6rem;
  border: 1px solid var(--cr-border);
  border-radius: 10px;
  font-size: 0.875rem;
  background: rgba(255, 255, 255, 0.95);
}
.client-ref-tab-workspace__filter-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.65rem;
  align-items: center;
}
.client-ref-tab-workspace__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.45rem 0.95rem;
  border-radius: 10px;
  font-size: 0.8125rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  border: 1px solid transparent;
  background: transparent;
  color: inherit;
}
.client-ref-tab-workspace__btn--primary {
  background: var(--cr-text);
  color: #fff;
  border-color: var(--cr-text);
}
.client-ref-tab-workspace__btn--primary:hover {
  background: #1e293b;
  border-color: #1e293b;
}
.client-ref-tab-workspace__btn--ghost {
  background: rgba(255, 255, 255, 0.9);
  color: #475569;
  border-color: var(--cr-border);
}
.client-ref-tab-workspace__btn--ghost:hover {
  background: #fff;
  border-color: var(--cr-border-strong);
}
.client-ref-tab-workspace__btn--disabled,
.client-ref-tab-workspace__btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  pointer-events: none;
}
.client-ref-tab-workspace__panel {
  border: 1px solid var(--cr-border);
  border-radius: 12px;
  overflow: hidden;
  background: rgba(255, 255, 255, 0.82);
}
.client-ref-tab-workspace__table-wrap {
  overflow-x: auto;
}
.client-ref-tab-workspace__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8125rem;
}
.client-ref-tab-workspace__table thead th {
  text-align: left;
  padding: 0.65rem 0.85rem;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cr-muted);
  background: rgba(248, 250, 252, 0.9);
  border-bottom: 1px solid var(--cr-border);
}
.client-ref-tab-workspace__table tbody td {
  padding: 0.6rem 0.85rem;
  border-bottom: 1px solid rgba(15, 23, 42, 0.06);
  vertical-align: middle;
}
.client-ref-tab-workspace__table tbody tr:last-child td {
  border-bottom: none;
}
.client-ref-tab-workspace__col-action {
  width: 1%;
  text-align: right;
  white-space: nowrap;
}
.client-ref-tab-workspace__link {
  font-weight: 600;
  color: var(--cr-accent);
  text-decoration: none;
}
.client-ref-tab-workspace__link:hover {
  text-decoration: underline;
}
.client-ref-tab-workspace__empty {
  padding: 2rem 1.25rem;
  text-align: center;
}
.client-ref-tab-workspace__empty--spacious {
  min-height: 12rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2.5rem 1.5rem;
}
.client-ref-tab-workspace__empty-title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--cr-text);
}
.client-ref-tab-workspace__empty-text {
  margin: 0.5rem 0 0;
  max-width: 26rem;
  font-size: 0.875rem;
  line-height: 1.55;
  color: var(--cr-muted);
}
.client-ref-tab-workspace__pagination {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--cr-border);
  background: rgba(248, 250, 252, 0.5);
}
.client-ref-tab-workspace__page-link {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--cr-accent);
  text-decoration: none;
}
.client-ref-tab-workspace__page-link:hover {
  text-decoration: underline;
}
.client-ref-tab-workspace__page-meta {
  font-size: 0.8125rem;
  color: var(--cr-muted);
}
.client-ref-sales-workspace__products {
  margin-top: 1.5rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--cr-border);
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace {
  display: flex;
  flex-direction: column;
  padding: 1.5rem 1.5rem 1.65rem;
  min-height: min(70vh, 52rem);
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__head {
  align-items: center;
  margin-bottom: 1.35rem;
  padding-bottom: 1.15rem;
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__criteria {
  margin-bottom: 1.25rem;
  padding: 1.15rem 1.2rem 1.2rem;
  border: 1px solid var(--cr-border-strong);
  background: rgba(255, 255, 255, 0.88);
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__criteria-head {
  margin: 0 0 1rem 0;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid rgba(15, 23, 42, 0.06);
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__criteria-title {
  margin: 0;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--cr-muted);
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__criteria-grid {
  grid-template-columns: repeat(auto-fill, minmax(12.5rem, 1fr));
  gap: 1rem 1.15rem;
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__field-hint {
  display: block;
  margin-top: 0.35rem;
  font-size: 0.75rem;
  line-height: 1.4;
  color: #94a3b8;
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__criteria-actions {
  margin-top: 0.35rem;
  justify-content: flex-end;
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__results-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 16rem;
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__results-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.8rem 1.05rem;
  border-bottom: 1px solid var(--cr-border);
  background: rgba(248, 250, 252, 0.85);
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__results-label {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--cr-muted);
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__results-count {
  font-size: 0.9375rem;
  font-weight: 700;
  color: var(--cr-text);
  font-variant-numeric: tabular-nums;
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__empty-results {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 14rem;
  padding: 2.75rem 1.5rem 3rem;
}
.client-ref--tab-sales.client-ref-surface .client-ref-sales-workspace__add-order {
  padding: 0.6rem 1.25rem;
  font-size: 0.875rem;
}

/* Billing tab — reference-style sections + disabled save */
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace {
  padding: 1.5rem 1.5rem 1.25rem;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__page-head {
  margin-bottom: 1.35rem;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__block {
  margin-bottom: 1.65rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid rgba(15, 23, 42, 0.07);
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__block--flush {
  border-bottom: none;
  padding-bottom: 0;
  margin-bottom: 1.25rem;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__block-title {
  margin-bottom: 0.5rem;
  font-size: 0.8125rem;
  letter-spacing: 0.06em;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__block-lede {
  margin-top: 0;
  margin-bottom: 1rem;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__add-card {
  border: 2px dashed var(--cr-border-strong);
  border-radius: var(--cr-radius);
  padding: 1.35rem 1.4rem 1.4rem;
  background: rgba(248, 250, 252, 0.65);
  text-align: center;
  max-width: 28rem;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__add-card-label {
  display: block;
  font-size: 1rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--cr-text);
  margin-bottom: 0.35rem;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__add-card-note {
  margin: 0 0 1rem 0;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: var(--cr-muted);
  max-width: 24rem;
  margin-left: auto;
  margin-right: auto;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__add-card-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.55rem 1.35rem;
  border-radius: 11px;
  font-size: 0.875rem;
  font-weight: 600;
  border: 1px solid var(--cr-border-strong);
  background: rgba(255, 255, 255, 0.85);
  color: #94a3b8;
  cursor: not-allowed;
  opacity: 0.65;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__empty-slot {
  border: 1px solid var(--cr-border);
  border-radius: 12px;
  padding: 1.25rem 1.2rem;
  background: rgba(255, 255, 255, 0.72);
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__empty-slot--compact {
  padding: 1rem 1.1rem;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__empty-slot-title {
  margin: 0 0 0.35rem 0;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--cr-text);
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__empty-slot-text {
  margin: 0;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: var(--cr-muted);
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__credit-toggle {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.75rem;
  margin-top: 0.75rem;
  padding: 0.85rem 1rem;
  border-radius: 12px;
  border: 1px solid var(--cr-border);
  background: rgba(248, 250, 252, 0.8);
  font-size: 0.875rem;
  color: var(--cr-text);
  cursor: not-allowed;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__credit-toggle input {
  accent-color: var(--cr-accent);
  cursor: not-allowed;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__credit-badge {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--cr-muted);
  padding: 0.2rem 0.45rem;
  border-radius: 6px;
  background: rgba(15, 23, 42, 0.06);
}
.client-ref-billing-workspace__dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.35rem 1.25rem;
  margin: 0;
  font-size: 0.875rem;
}
.client-ref-billing-workspace__dl dt {
  font-weight: 600;
  color: var(--cr-muted);
  margin: 0;
}
.client-ref-billing-workspace__dl dd {
  margin: 0;
  font-weight: 600;
  color: var(--cr-text);
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__save-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 1rem 1.25rem;
  margin-top: 0.5rem;
  padding-top: 1.35rem;
  border-top: 1px solid var(--cr-border-strong);
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__save-note {
  flex: 1 1 14rem;
  margin: 0;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: var(--cr-muted);
  text-align: right;
}
.client-ref--tab-billing.client-ref-surface .client-ref-billing-workspace__save-btn:disabled {
  opacity: 0.42;
  cursor: not-allowed;
  pointer-events: none;
}

/* Photos tab — large placeholder stage */
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace {
  padding: 1.5rem 1.5rem 1.65rem;
  min-height: min(72vh, 48rem);
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__stage {
  margin-top: 0.5rem;
  border: 1px solid var(--cr-border);
  border-radius: var(--cr-radius);
  background: rgba(255, 255, 255, 0.75);
  padding: 2.5rem 1.5rem 2.75rem;
  text-align: center;
  min-height: 22rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__placeholder {
  width: min(100%, 20rem);
  aspect-ratio: 4 / 3;
  max-height: 14rem;
  margin-bottom: 1.5rem;
  border: 2px dashed var(--cr-border-strong);
  border-radius: 16px;
  background: linear-gradient(160deg, #f8fafc 0%, #f1f5f9 100%);
  display: flex;
  align-items: center;
  justify-content: center;
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__placeholder-icon {
  display: block;
  width: 3.25rem;
  height: 2.5rem;
  border: 2px solid #cbd5e1;
  border-radius: 8px;
  position: relative;
  box-shadow: inset 0 0 0 3px rgba(255, 255, 255, 0.9);
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__placeholder-icon::before {
  content: "";
  position: absolute;
  top: 0.45rem;
  left: 50%;
  transform: translateX(-50%);
  width: 0.65rem;
  height: 0.65rem;
  border-radius: 50%;
  background: #94a3b8;
  opacity: 0.5;
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__placeholder-icon::after {
  content: "";
  position: absolute;
  bottom: 0.4rem;
  left: 50%;
  transform: translateX(-50%);
  width: 1.35rem;
  height: 0.85rem;
  border: 2px solid #94a3b8;
  border-radius: 3px;
  opacity: 0.45;
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__empty-title {
  margin: 0;
  font-size: 1.125rem;
  font-weight: 700;
  letter-spacing: -0.03em;
  color: var(--cr-text);
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__empty-text {
  margin: 0.65rem 0 0;
  max-width: 26rem;
  font-size: 0.875rem;
  line-height: 1.55;
  color: var(--cr-muted);
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__cta-wrap {
  margin-top: 1.75rem;
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__add-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.6rem 1.5rem;
  border-radius: 11px;
  font-size: 0.875rem;
  font-weight: 600;
  border: 1px solid var(--cr-border-strong);
  background: rgba(248, 250, 252, 0.95);
  color: #94a3b8;
  cursor: not-allowed;
  opacity: 0.7;
}
.client-ref--tab-photos.client-ref-surface .client-ref-photos-workspace__cta-note {
  margin: 0.65rem 0 0;
  font-size: 0.75rem;
  color: #94a3b8;
}

/* Mail marketing — minimal empty panel */
.client-ref--tab-mail-marketing.client-ref-surface .client-ref-mail-workspace {
  padding: 1.5rem 1.5rem 1.65rem;
  min-height: min(58vh, 36rem);
}
.client-ref--tab-mail-marketing.client-ref-surface .client-ref-mail-workspace__head {
  align-items: center;
  margin-bottom: 1.5rem;
}
.client-ref--tab-mail-marketing.client-ref-surface .client-ref-mail-workspace__empty-panel {
  border: 1px solid var(--cr-border);
  border-radius: var(--cr-radius);
  background: rgba(255, 255, 255, 0.78);
  padding: 2.75rem 1.75rem 2.5rem;
  text-align: center;
  min-height: 16rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.client-ref--tab-mail-marketing.client-ref-surface .client-ref-mail-workspace__empty-visual {
  width: 3.5rem;
  height: 3.5rem;
  margin-bottom: 1.25rem;
  border-radius: 12px;
  background: linear-gradient(135deg, var(--cr-accent-soft) 0%, rgba(13, 148, 136, 0.06) 100%);
  border: 1px solid rgba(13, 148, 136, 0.2);
}
.client-ref--tab-mail-marketing.client-ref-surface .client-ref-mail-workspace__empty-title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--cr-text);
  letter-spacing: -0.02em;
}
.client-ref--tab-mail-marketing.client-ref-surface .client-ref-mail-workspace__empty-text {
  margin: 0.6rem 0 0;
  max-width: 28rem;
  font-size: 0.875rem;
  line-height: 1.55;
  color: var(--cr-muted);
}
.client-ref--tab-mail-marketing.client-ref-surface .client-ref-mail-workspace__opt-line {
  margin: 1.35rem 0 0;
  max-width: 32rem;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: #64748b;
}

/* Documents tab — Type / Status focus */
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace {
  padding: 1.5rem 1.5rem 1.65rem;
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__api-hint {
  margin: 0 0 1.15rem 0;
  font-size: 0.8125rem;
  color: var(--cr-muted);
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__api-hint code {
  font-size: 0.75rem;
  word-break: break-all;
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__empty {
  border: 1px dashed var(--cr-border-strong);
  border-radius: var(--cr-radius);
  padding: 2.5rem 1.5rem;
  text-align: center;
  background: rgba(248, 250, 252, 0.5);
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__empty-title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--cr-text);
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__empty-text {
  margin: 0.55rem auto 0;
  max-width: 28rem;
  font-size: 0.875rem;
  line-height: 1.55;
  color: var(--cr-muted);
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__panel {
  overflow: hidden;
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__results-bar {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--cr-border);
  background: rgba(248, 250, 252, 0.85);
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__results-label {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--cr-muted);
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__results-count {
  font-size: 0.9375rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--cr-text);
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__type {
  display: block;
  font-weight: 600;
  color: var(--cr-text);
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__type-code {
  display: block;
  margin-top: 0.2rem;
}
.client-ref--tab-documents.client-ref-surface .client-ref-documents-workspace__type-code code {
  font-size: 0.75rem;
  color: var(--cr-muted);
}
.client-ref-documents-workspace__load-error {
  margin: 0 0 1rem 0;
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  border: 1px solid rgba(185, 28, 28, 0.25);
  background: rgba(254, 242, 242, 0.85);
  color: #991b1b;
  font-size: 0.875rem;
}
.client-ref-documents-workspace__upload {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.65rem 1rem;
  margin-bottom: 1.25rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--cr-border);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.72);
}
.client-ref-documents-workspace__upload-label {
  display: block;
  width: 100%;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cr-muted);
  margin-bottom: 0.35rem;
}
.client-ref-documents-workspace__empty--inline {
  text-align: left;
  padding: 0.85rem 0;
  min-height: 0;
}
.client-ref-documents-workspace__subhead--secondary {
  margin-top: 1.5rem;
}
.client-ref--tab-mail-marketing.client-ref-surface .client-ref-mail-workspace__subhead-spaced {
  margin-top: 1.35rem;
}

/* --- Client details edit: left-aligned form column + contextual sidebar rail --- */
.client-ref--details-page.client-ref-surface .client-ref-details-workspace:has(.client-ref-details-fields--hig) {
  background: #f2f2f7;
  border-color: rgba(0, 0, 0, 0.06);
}

.client-ref--details-page.client-ref-surface .client-ref-details-workspace.client-ref-details-workspace--master-detail {
  border: none;
  border-radius: 0;
  background: #f2f2f7;
  box-shadow: none;
  backdrop-filter: none;
  -webkit-backdrop-filter: none;
  padding: 24px 32px 32px 32px;
}

.client-ref-details-master-detail {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: flex-start;
  gap: 32px;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  margin: 0;
}

.client-ref-details-form-column {
  flex: 1 1 auto;
  max-width: 800px;
  min-width: 0;
  margin: 0;
  margin-left: 0;
  margin-right: 0;
}

.client-ref-details-context-aside {
  flex: 0 0 350px;
  width: 350px;
  max-width: 100%;
  box-sizing: border-box;
  min-height: 500px;
  background: transparent;
  position: sticky;
  top: 1rem;
  min-width: 0;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.client-ref-details-context-aside__inner {
  box-sizing: border-box;
  background: #f5f5f7;
  border: 1px solid #e5e5ea;
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.client-ref-details-context-aside__header {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 24px;
  padding-bottom: 20px;
  border-bottom: 1px solid #e5e5ea;
}

.client-ref-details-context-aside__avatar {
  flex-shrink: 0;
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: #e5e5ea;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.client-ref-details-context-aside__avatar--photo {
  background: #fff;
}

.client-ref-details-context-aside__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.client-ref-details-context-aside__avatar-initials {
  font-size: 1.5rem;
  font-weight: 600;
  color: #86868b;
  letter-spacing: -0.02em;
}

.client-ref-details-context-aside__identity {
  min-width: 0;
}

.client-ref-details-context-aside__eyebrow {
  margin: 0 0 4px 0;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #86868b;
}

.client-ref-details-context-aside__name {
  margin: 0;
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.02em;
  color: var(--cr-text, #1d1d1f);
  line-height: 1.25;
  word-break: break-word;
}

.client-ref-details-context-aside__section {
  margin-top: 22px;
}

.client-ref-details-context-aside__section--first {
  margin-top: 0;
}

.client-ref-details-context-aside__section-title {
  margin: 0 0 12px 0;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #86868b;
}

.client-ref-details-context-aside__metrics {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px 20px;
  margin: 0;
}

.client-ref-details-context-aside__metric {
  margin: 0;
}

.client-ref-details-context-aside__metric--wide {
  grid-column: 1 / -1;
}

.client-ref-details-context-aside__metric dt {
  margin: 0 0 4px 0;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #86868b;
}

.client-ref-details-context-aside__metric dd {
  margin: 0;
  font-size: 15px;
  font-weight: 500;
  color: var(--cr-text, #1d1d1f);
  font-variant-numeric: tabular-nums;
  line-height: 1.35;
  word-break: break-word;
}

.client-ref-details-context-aside__empty {
  margin: 0;
  font-size: 13px;
  line-height: 1.5;
  color: #86868b;
}

.client-ref-details-context-aside__bookings {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.client-ref-details-context-aside__booking {
  margin: 0;
  padding: 12px 14px;
  background: #fff;
  border: 1px solid #e5e5ea;
  border-radius: 10px;
}

.client-ref-details-context-aside__booking-service {
  display: block;
  font-size: 14px;
  font-weight: 600;
  color: var(--cr-text, #1d1d1f);
  letter-spacing: -0.01em;
}

.client-ref-details-context-aside__booking-meta {
  display: block;
  margin-top: 4px;
  font-size: 12px;
  color: #86868b;
  line-height: 1.4;
}

@media (max-width: 1024px) {
  .client-ref-details-master-detail {
    flex-direction: column;
    align-items: stretch;
  }

  .client-ref-details-form-column {
    max-width: none;
    width: 100%;
  }

  .client-ref-details-context-aside {
    flex: 1 1 auto;
    width: 100%;
    min-height: 0;
    position: static;
  }
}

.client-ref--details-page.client-ref-surface .client-ref-details-fields--hig {
  margin-top: 0.25rem;
}

.client-ref-hig-card {
  width: 100%;
  max-width: 100%;
  margin: 0;
  box-sizing: border-box;
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid rgba(0, 0, 0, 0.06);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  padding: 32px;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.client-ref--details-page.client-ref-surface .client-ref-details-fields--hig .client-ref-details-field-group {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px 24px;
  margin: 32px 0 0 0 !important;
  padding: 0 !important;
  border: none !important;
}

.client-ref--details-page.client-ref-surface .client-ref-details-fields--hig .client-ref-details-field-group:first-child {
  margin-top: 0 !important;
}

.client-ref--details-page.client-ref-surface .client-ref-details-fields--hig .client-ref-details-field-group-title {
  grid-column: 1 / -1;
  margin: 0 0 0 0;
  padding: 0;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #86868b;
  line-height: 1.2;
}

.client-ref-details-layout-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 20px 24px;
  align-items: start;
  width: 100%;
  box-sizing: border-box;
}

.client-ref--details-page.client-ref-surface .client-ref-details-fields--hig .client-ref-details-field-group > .client-ref-details-layout-grid {
  grid-column: 1 / -1;
}

.client-ref-hig-cell {
  min-width: 0;
  box-sizing: border-box;
}

.client-ref-hig-cell .client-ref-hig-address-panel,
.client-ref-hig-cell .client-ref-hig-delivery-panel {
  grid-column: unset;
  width: 100%;
}

.client-ref-hig-field {
  margin: 0 !important;
  padding: 0 !important;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 6px;
}

.client-ref-hig-field--full {
  grid-column: span 2;
}

.client-ref-hig-subsection-label {
  grid-column: 1 / -1;
  margin: 0;
  padding: 0;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #86868b;
  line-height: 1.2;
}

.client-ref-hig-panel-heading {
  margin: 0 0 16px 0;
  padding: 0;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #86868b;
}

.client-ref-hig-address-panel,
.client-ref-hig-delivery-panel {
  grid-column: 1 / -1;
  box-sizing: border-box;
  background: #f5f5f7;
  border-radius: 12px;
  padding: 24px;
}

.client-ref-hig-panel-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px 24px;
}

.client-ref-hig-panel-grid--delivery {
  margin-top: 4px;
}

.client-ref-hig-card .client-ref-hig-field > label[for] {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #86868b;
}

.client-ref-hig-checkbox-row {
  justify-content: center;
  min-height: 44px;
}

.client-ref-hig-checkbox-label {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 15px;
  font-weight: 400;
  text-transform: none;
  letter-spacing: normal;
  color: var(--cr-text, #1d1d1f);
  cursor: pointer;
  min-height: 44px;
}

.client-ref-hig-checkbox-label input[type='checkbox'] {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
  margin: 0;
}

.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field input[type='text'],
.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field input[type='email'],
.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field input[type='date'],
.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field input[type='number'],
.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field input[type='tel'],
.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field select {
  box-sizing: border-box;
  width: 100%;
  max-width: none;
  height: 44px;
  padding: 0 16px;
  margin: 0;
  border: 1px solid rgba(0, 0, 0, 0.12);
  border-radius: 8px;
  background: #ffffff;
  font-size: 15px;
  font-family: inherit;
  color: var(--cr-text, #1d1d1f);
  -webkit-appearance: none;
  appearance: none;
}

.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field select {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2386868B' d='M1.41 0L6 4.58 10.59 0 12 1.41l-6 6-6-6z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 14px center;
  padding-right: 36px;
}

.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field textarea {
  box-sizing: border-box;
  width: 100%;
  max-width: none;
  min-height: 88px;
  padding: 12px 16px;
  margin: 0;
  border: 1px solid rgba(0, 0, 0, 0.12);
  border-radius: 8px;
  background: #ffffff;
  font-size: 15px;
  line-height: 1.45;
  font-family: inherit;
  color: var(--cr-text, #1d1d1f);
  resize: vertical;
}

.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field textarea.client-ref-hig-autosize {
  min-height: 44px;
  resize: none;
  overflow-y: auto;
  max-height: 50vh;
  field-sizing: content;
}

.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field input:focus,
.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field select:focus,
.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field textarea:focus {
  outline: none;
  border-color: rgba(0, 122, 255, 0.55);
  box-shadow: 0 0 0 3px rgba(0, 122, 255, 0.25);
}

.client-ref--details-page.client-ref-surface .client-ref-hig-card .client-ref-hig-field .error {
  display: block;
  margin: 0;
  font-size: 13px;
  color: #b91c1c;
}

.client-ref--details-page.client-ref-surface .client-ref-hig-card p.hint.client-ref-hig-field {
  margin: 0 !important;
  padding: 0;
  font-size: 13px;
  line-height: 1.45;
  color: var(--cr-muted, #64748b);
}

.client-ref-hig-address-panel .client-ref-hig-field input[type='text'],
.client-ref-hig-delivery-panel .client-ref-hig-panel-grid .client-ref-hig-field input[type='text'] {
  background: #ffffff;
}

@media (max-width: 560px) {
  .client-ref--details-page.client-ref-surface .client-ref-details-fields--hig .client-ref-details-field-group,
  .client-ref-hig-panel-grid {
    grid-template-columns: 1fr;
  }

  .client-ref-details-layout-grid {
    grid-template-columns: 1fr;
  }

  .client-ref-hig-cell {
    grid-column: 1 / -1 !important;
  }

  .client-ref-hig-field--full,
  .client-ref-hig-field {
    grid-column: 1 / -1;
  }
}

.client-ref-details-form:has(.client-ref-details-fields--hig) .client-ref-details-btn-save {
  background: #007aff !important;
  border-color: #007aff !important;
  color: #fff !important;
}

.client-ref-details-form:has(.client-ref-details-fields--hig) .client-ref-details-btn-save:hover {
  background: #0066d6 !important;
  border-color: #0066d6 !important;
  color: #fff !important;
}

.client-ref-delivery-switch-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  min-height: 44px;
  padding: 0;
  margin-bottom: 8px;
}

.client-ref-delivery-switch-label {
  flex: 1;
  font-size: 1rem;
  font-weight: 400;
  color: var(--cr-text);
  letter-spacing: -0.02em;
}

.client-ref-ios-switch {
  position: relative;
  display: inline-flex;
  flex-shrink: 0;
  cursor: pointer;
}

.client-ref-ios-switch input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
  margin: 0;
}

.client-ref-ios-switch-ui {
  display: block;
  width: 51px;
  height: 31px;
  border-radius: 16px;
  background: #e5e5ea;
  position: relative;
  transition: background 0.28s cubic-bezier(0.25, 0.1, 0.25, 1);
  box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.04);
}

.client-ref-ios-switch-ui::after {
  content: '';
  position: absolute;
  width: 27px;
  height: 27px;
  border-radius: 50%;
  background: #fff;
  top: 2px;
  left: 2px;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12), 0 0 0 0.5px rgba(0, 0, 0, 0.04);
  transition: transform 0.28s cubic-bezier(0.25, 0.1, 0.25, 1);
}

.client-ref-ios-switch input:checked + .client-ref-ios-switch-ui {
  background: #28cd41;
}

.client-ref-ios-switch input:checked + .client-ref-ios-switch-ui::after {
  transform: translateX(20px);
}

.client-ref-delivery-expand {
  display: grid;
  grid-template-rows: 1fr;
  transition: grid-template-rows 0.35s cubic-bezier(0.25, 0.1, 0.25, 1);
}

.client-ref-delivery-expand--collapsed {
  grid-template-rows: 0fr;
}

.client-ref-delivery-expand-inner {
  min-height: 0;
  overflow: hidden;
}

.client-ref-delivery-expand-inner .form-row {
  padding-top: 0;
}

@media (prefers-reduced-motion: reduce) {
  .client-ref-delivery-expand {
    transition: none;
  }

  .client-ref-ios-switch-ui,
  .client-ref-ios-switch-ui::after {
    transition: none;
  }
}
</style>
