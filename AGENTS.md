# Project Instructions

This is a PHP cryptocurrency trading app that uses the Binance API to buy and sell crypto.

## Important safety rules

- Do not expose, print, commit, log, or hardcode API keys, API secrets, database passwords, tokens, or credentials.
- Binance credentials are stored outside the web root at:
  - `/etc/web-applications/trading-app/binance.php`
- Database credentials are stored outside the web root at:
  - `/etc/web-applications/trading-app/database.php`
- The app-side database connection file is:
  - `/var/www/html/php/crypto-con.php`
- The current Binance PHP library is:
  - `/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php`

## Coding preferences

- Use PHP with mysqli.
- Avoid PDO unless explicitly requested.
- Avoid broad refactors.
- Make one focused change at a time.
- Prefer small, reviewable patches.
- Do not change live trading behavior without explicit approval.
- Do not place real orders in tests.
- Add safety checks around order creation, balances, precision, and error handling.

## Git workflow

- Check `git status` before changes.
- Review diffs before committing.
- Do not commit secrets or local-only config files.
