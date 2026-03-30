<style>
    .settings-establishment {
        display: grid;
        gap: 1rem;
    }

    .settings-establishment__hero {
        border: 1px solid #e5e7eb;
        border-radius: 0.7rem;
        padding: 1rem 1.1rem;
        background: #fff;
    }

    .settings-establishment__title {
        margin: 0;
        font-size: 1.2rem;
        color: #111827;
    }

    .settings-establishment__lead {
        margin: 0.45rem 0 0;
        color: #4b5563;
        font-size: 0.92rem;
        max-width: 62ch;
    }

    .settings-establishment-grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .settings-establishment-card {
        border: 1px solid #e5e7eb;
        border-radius: 0.7rem;
        padding: 1rem 1.1rem;
        background: #fff;
    }

    .settings-establishment-card--full {
        grid-column: 1 / -1;
    }

    .settings-establishment-card__title {
        margin: 0;
        font-size: 1rem;
        color: #111827;
    }

    .settings-establishment-card__help {
        margin: 0.4rem 0 0.9rem;
        color: #6b7280;
        font-size: 0.86rem;
    }

    .settings-establishment-summary {
        display: grid;
        gap: 0.65rem;
    }

    .settings-establishment-summary__row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        align-items: baseline;
        color: #374151;
        font-size: 0.9rem;
    }

    .settings-establishment-summary__key {
        min-width: 11rem;
        font-weight: 600;
        color: #1f2937;
    }

    .settings-establishment-summary__value {
        color: #4b5563;
    }

    .settings-establishment-actions {
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
        margin-top: 0.8rem;
    }

    .settings-establishment-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2.2rem;
        border-radius: 0.5rem;
        padding: 0.45rem 0.85rem;
        font-size: 0.86rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid #d1d5db;
        color: #111827;
        background: #fff;
    }

    .settings-establishment-btn--primary {
        border-color: #111827;
        color: #fff;
        background: #111827;
        cursor: pointer;
    }

    .settings-establishment-btn--muted {
        background: #f9fafb;
    }

    .settings-establishment-btn--small {
        min-height: 1.9rem;
        padding: 0.35rem 0.65rem;
        font-size: 0.8rem;
    }

    .settings-establishment-note {
        color: #6b7280;
        font-size: 0.86rem;
        margin: 0;
    }

    .settings-establishment-form-grid {
        display: grid;
        gap: 0.9rem;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .settings-establishment-form-grid .setting-row {
        min-width: 0;
    }

    .settings-establishment-form-grid .setting-row--full {
        grid-column: 1 / -1;
    }

    .settings-establishment .setting-row label {
        display: block;
        margin-bottom: 0.35rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: #374151;
    }

    .settings-establishment .setting-row input,
    .settings-establishment .setting-row select {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        padding: 0.55rem 0.7rem;
        font-size: 0.92rem;
        background: #fff;
    }

    .settings-establishment .setting-row input:focus,
    .settings-establishment .setting-row select:focus {
        outline: none;
        border-color: #9ca3af;
        box-shadow: 0 0 0 2px rgba(17, 24, 39, 0.08);
    }

    .settings-establishment-meta {
        margin: 0.2rem 0 0;
        color: #6b7280;
        font-size: 0.85rem;
    }

    .settings-establishment-hours-table-wrap {
        overflow-x: auto;
    }

    .settings-establishment-hours-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }

    .settings-establishment-hours-table th,
    .settings-establishment-hours-table td {
        border-bottom: 1px solid #e5e7eb;
        padding: 0.55rem 0.45rem;
        text-align: left;
        vertical-align: middle;
    }

    .settings-establishment-hours-table td input {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #d1d5db;
        border-radius: 0.45rem;
        padding: 0.45rem 0.55rem;
        font-size: 0.86rem;
    }

    .settings-establishment-hours-table td form {
        margin: 0;
    }

    .settings-establishment-hours-table th {
        color: #374151;
        font-weight: 700;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    @media (max-width: 980px) {
        .settings-establishment-grid,
        .settings-establishment-form-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
