# SETTINGS-ENGLISH-CANONICALIZATION-AND-IA-CLEANUP-01

Date: 2026-03-24  
Scope: English-only UI canonicalization and IA cleanup for Settings views.

## English-only rule

All visible Settings UI copy in this wave is English-only.  
No French labels are used in rendered Settings sidebar or establishment workspace.

## Final canonical English menu labels

Settings directory

- General Settings
  - Establishment Information
  - Cancellation Policy
  - Appointment Settings
  - Payment Settings
  - Custom Payment Methods
  - VAT Types
  - VAT Distribution
  - Internal Notifications
  - IT Hardware
  - Security
  - Marketing Settings
  - Waitlist Settings
  - Online Booking
  - Membership Settings
- Spaces
  - New Space
- Equipment
  - New Equipment
- Staff
  - New Staff Member
  - Groups
  - Staff Hours & Payroll
- Users
  - New User
- Services
  - New Service
- Packages
  - New Package
- Series
  - New Series
- Memberships
  - New Membership
- Document Storage
  - New Document Type

## Destination types in the sidebar

- Actual settings pages (inside `General Settings`):
  - Establishment Information
  - Cancellation Policy
  - Appointment Settings
  - Payment Settings
  - Custom Payment Methods
  - VAT Types
  - VAT Distribution
  - Internal Notifications
  - IT Hardware
  - Security
  - Marketing Settings
  - Waitlist Settings
  - Online Booking
  - Membership Settings
- Related module launchers (outside `General Settings`):
  - Spaces / New Space
  - Equipment / New Equipment
  - Staff / New Staff Member, Groups, Staff Hours & Payroll
  - Services / New Service
  - Packages / New Package
  - Memberships / New Membership
- Backend pending entries:
  - Users / New User
  - Series / New Series
  - Document Storage / New Document Type

## Establishment page ownership split

- Editable settings area:
  - Establishment Information (Name, Phone, Email, Address)
  - Regional Defaults (Currency, Time Zone, Language)
  - Actions (save)
- Read-only related area:
  - Related Operational Data (Read-only):
    - Opening Hours
    - Closure Dates
    - Web / Account / Location Metadata
- Pending area:
  - Secondary Contact (explicit backend-pending block, visually de-emphasized)

## Backend safety guarantees in this wave

- No changes to SettingsController write behavior.
- No posted field name changes.
- No SettingsService key changes.
- No route contract changes.
