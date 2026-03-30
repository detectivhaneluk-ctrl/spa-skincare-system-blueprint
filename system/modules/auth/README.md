# auth

Authentication and security.

## Responsibility
- Login, logout, session management
- Password reset, 2FA
- Session history, login attempts

## Dependencies
- `/system/core` (auth, permissions)
- `/system/settings`

## Boundaries
- Does not import from other business modules
- Consumes core auth service; does not reimplement authentication logic
- Permission checks via core permissions engine
