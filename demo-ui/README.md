# OpenRings demo UI

A deliberately small PHP + HTML front end that shows how to drive an
**ORswitch** from your own web app. It is a starting point to read and copy
from, not a finished product.

## Login

Every page needs a login:

1. **First run.** The first visit to `login.php` creates the administrator (10+ characters, letters and a digit).
   **Do this right after installing**: until an administrator exists, whoever opens the page first creates it.
2. **Passwords** are stored hashed (`password_hash`) in `data/users.json`. Five failed logins per user name or
   address lock that name/address for 15 minutes. Sessions are HttpOnly and SameSite=Strict, and every form has a
   CSRF token. Sign out is in the top bar.
3. `data/` holds the users and failure counters. It ships with an Apache `.htaccess` deny, but set `data_dir` in
   `config.local.php` to a folder **outside the web root** if you can (nginx ignores `.htaccess`).

There is one administrator and no user-management page. To reset it, delete `data/users.json` and open
`login.php` again.

## Pages

| Page | What it shows | Switch API used |
|---|---|---|
| `index.php` | Registered phones, calls, queues, agents | `GET /api/live` |
| `extensions.php` | List and add extensions | `GET/POST /api/d/{domain}/extensions` |
| `queues.php` | List and add queues with agents | `GET/POST /api/d/{domain}/queues` |
| `ivrs.php` | List and add IVR menus (key → queue/extension) | `GET/POST /api/d/{domain}/ivrs`, `.../meta` |
| `webrtc.php` | Browser phone: call extensions, queues, IVRs | WebSocket `/api/ws` (`phone.*`) |

All of it is documented in [../docs/API.md](../docs/API.md), section 10.

## Try it without a switch

Set `'mock' => true` in `config.php` and open `http://localhost/ringq_with_git/openrings/demo-ui/`.
The pages show sample data (changes live only in your PHP session). WebRTC needs a real switch.
You still need to create the administrator and sign in first (see above).

## Run it against a real switch

1. Run ORswitch **with the RingQ database** (the configuration pages need it; see `../INSTALL.md`), with
   `<web>` listening, e.g. `https://127.0.0.1:8443`.
2. Edit `config.php` (or create `config.local.php` returning the keys you want to change):
   `switch_url`, `admin_user` / `admin_pass` (the administrator from `orswitch.xml`), `domain`.
3. Open the demo from XAMPP. PHP logs in to the switch with `POST /api/login`, keeps the session cookie in
   your PHP session and sends `X-RQ: 1` on every change.

### The WebRTC phone

The switch refuses a WebSocket from another origin. The demo therefore asks XAMPP's Apache to forward `/orws`
to the switch (`ProxyPreserveHost On`):

1. Add `Include ".../demo-ui/apache-proxy.conf"` to `httpd.conf` (path and address are in that file) and restart Apache.
2. Create an extension with **web access** on the Extensions page (or use an existing one).
3. Open `webrtc.php` as `http://localhost/...` (microphone access needs `localhost` or HTTPS) and sign in with the
   extension number and **web password**. Status turns `registered`; dial `3000` (a queue) or `5000` (an IVR) and
   press digits on the pad to use the IVR keys.
4. Browser audio goes straight to the switch on port 8443 (UDP, or TCP as fallback), not through Apache.

The demo stores the switch's `rqsession` cookie under the demo's own host name, so do not use it on a host where
you also open the switch's portal on another port (cookies are shared across ports).

## Not included (on purpose)

One administrator only (no user management page), the switch's admin password still sits in `config.php`,
no editing or deleting, no live WebSocket dashboard, no meetings, voicemail, trunks or reports.
Serve it over HTTPS and restrict it by address if it is reachable from the internet.
