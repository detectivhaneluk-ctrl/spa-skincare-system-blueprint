# Business Modules

**Runtime truth:** 21 top-level module directories (alphabetical): `appointments`, `auth`, `branches`, `clients`, `dashboard`, `documents`, `gift-cards`, `intake`, `inventory`, `marketing`, `memberships`, `notifications`, `online-booking`, `packages`, `payroll`, `public-commerce`, `reports`, `sales`, `services-resources`, `settings`, `staff`.

Each module depends only on core, shared, and approved public service contracts. Cross-module data flows through those contracts — not direct repository imports.

## Historical skeleton build order (pre-expansion)

The table below reflects the **original** skeleton sequencing; it is **not** a complete dependency graph for the current tree.

| Module | Build Order |
|--------|-------------|
| settings | 1 |
| auth | 2 |
| clients | 3 |
| staff | 4 |
| services-resources | 5 |
| appointments | 6 |
| inventory | 7 |
| sales | 8 |
| gift-cards / packages | 9 |
| reports | 10 |
| marketing | 11 |
| documents | 12 |
| dashboard | 13 |
| online-booking | 14 |

## Cross-Module Rules

- No direct cross-module repository coupling
- Inter-module data via approved service contracts (interfaces, APIs)
- See each module's README for dependencies and boundaries
