# DETORA Travel Explorer

DETORA is a static travel-planning website for exploring destinations, checking suggested travel modes, estimating trip budgets, and starting a booking.

## Pages

- `web.html` — landing page
- `destinations.html` — destination directory
- `travel mode.html` — travel mode suggestions
- `budget.html` — trip budget calculator
- `booknow.html` — booking form and travel partner links
- `login.html` — registration form

The root `index.html` redirects visitors to the landing page. Destination photos are stored in `images/`; some other page backgrounds and destination photos load from external image services.

## Run locally

The registration and booking forms use `app.js` and the `api.php` backend. Run the website with PHP 8.1+ and PDO SQLite enabled:

```sh
php -S 127.0.0.1:8000
```

Then open `http://127.0.0.1:8000/`. The PHP process must be able to write to a private data directory outside the web root. Set `DETORA_DATA_DIR` to choose that directory; otherwise the backend uses `../detora-data`. Passwords are stored as password hashes, and booking and registration requests are stored in SQLite.

GitHub Pages only serves static files and does not execute PHP. To use the forms online, deploy this project to a PHP-capable web host and configure a writable private data directory.
