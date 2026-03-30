# marketing

Marketing, loyalty, communication.

## Responsibility
- Reminders, confirmations, follow-ups
- Birthday offers
- Campaigns
- Client segmentation
- Loyalty points, tier levels
- Referrals
- Reviews and ratings

## Dependencies
- `/system/core` (notifications, audit)
- `/system/settings`
- `/system/shared`
- Approved contracts: clients, appointments

## Boundaries
- Does not access clients/appointments repositories directly
- Uses notification queue from core
- Template content from settings
