**English** | [中文](README.zh-CN.md)

# WHMCS OpenOAuth — OAuth2 Login Module

WHMCS addon module for integrating any standard OAuth2 server as a third-party login provider. Supports client login/registration, account binding/unbinding, and admin panel OAuth login.

**Target:** WHMCS 8.13.1 (Twenty-One template, Blend admin template)

## Features

- OAuth2 login button on the client area login page
- Auto-registration for new OAuth users (configurable)
- Auto-bind existing WHMCS accounts by matching email
- Manual bind/unbind on the client profile page
- Admin panel OAuth login (configurable, matches by admin email)
- All OAuth endpoint URLs configurable from WHMCS admin
- CSRF protection on all flows (state parameter + unbind tokens)
- Linked accounts management in WHMCS admin panel

## Requirements

- WHMCS 8.13.1
- PHP 8.1+
- cURL extension
- An OAuth2 server implementing the following endpoints:
  - `GET /oauth/authorize` — Authorization endpoint
  - `POST /oauth/token` — Token endpoint
  - `GET /oauth/userinfo` — User info endpoint (returns `sub`, `email`, `name`, `picture`)

## Installation

1. Copy the `nanako_oauth` directory to your WHMCS installation:

```
whmcs/modules/addons/nanako_oauth/
```

2. In WHMCS admin, go to **Configuration > System Settings > Addon Modules**.

3. Find **OpenOAuth Login** and click **Activate**.

4. Configure the module (see Configuration below).

5. On your OAuth2 server, register a new client with the redirect URI:

```
https://your-whmcs-domain.com/modules/addons/nanako_oauth/callback.php
```

## Configuration

| Field | Description | Default |
|-------|-------------|---------|
| OAuth Server URL | Base URL of the OAuth server (e.g. `https://auth.example.com`) | — |
| Client ID | OAuth2 client ID | — |
| Client Secret | OAuth2 client secret | — |
| Scopes | OAuth2 scopes to request | `profile email phone` |
| Login Button Text | Text displayed on the login button | `Sign in with OAuth` |
| Enable Auto Registration | Automatically create WHMCS account for new OAuth users | No |
| Enable Admin OAuth Login | Show OAuth login button on admin login page | No |

## Directory Structure

```
nanako_oauth/
├── nanako_oauth.php       # Module config, activate, deactivate, admin output
├── hooks.php              # ClientAreaHeadOutput & AdminAreaHeadOutput hooks
├── callback.php           # OAuth2 callback handler (all actions)
├── lib/
│   └── OAuthClient.php    # OAuth2 HTTP client (authorize, token, userinfo)
└── templates/
    ├── login_button.tpl   # Login button template (reference)
    ├── bind.tpl           # Bind/unbind template (reference)
    └── admin.tpl          # Admin page template (reference)
```

## How It Works

### Client Login Flow

1. User clicks the OAuth login button on the WHMCS login page.
2. `callback.php?action=login` generates a CSRF state token, stores the login intent in the session, and redirects to the OAuth authorization endpoint.
3. After authorization, the OAuth server redirects back to `callback.php` with `code` and `state` parameters.
4. The callback validates the state, exchanges the code for an access token, and fetches user info.
5. Login resolution:
   - **OAuth UID already linked** — Log in the linked WHMCS client.
   - **Email matches an existing WHMCS client** — Auto-bind and log in.
   - **No match + auto-registration enabled** — Create a new WHMCS client, bind, and log in.
   - **No match + auto-registration disabled** — Show an error message.
6. Login is established via the WHMCS `CreateSsoToken` API (one-time SSO URL).

### Account Binding

- Logged-in users see a bind/unbind card on their profile page (`clientarea.php?action=details`).
- Binding initiates the same OAuth flow but stores the intent as `bind`.
- Unbinding requires a CSRF token and removes the link from the database.

### Admin Login

- When enabled, an OAuth button appears on the admin login page.
- After authorization, the admin is matched by email against `tbladmins`.
- Only matches existing admins — does not create new admin accounts.

## Database

The module creates one table on activation:

**`mod_nanako_oauth_links`**

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT | Primary key |
| client_id | INT UNSIGNED | WHMCS client ID (indexed) |
| oauth_uid | VARCHAR(255) | OAuth user identifier (unique) |
| email | VARCHAR(255) | OAuth email |
| username | VARCHAR(255) | OAuth display name |
| avatar | VARCHAR(512) | OAuth avatar URL |
| created_at | DATETIME | Link creation time |
| updated_at | DATETIME | Last update time |

The table is dropped on module deactivation.

## Security

- **CSRF protection** — All OAuth flows use a random `state` parameter stored in the session and validated with `hash_equals()`. Unbind actions use separate CSRF tokens.
- **No secret exposure** — Client secret is stored server-side in WHMCS config and never sent to the browser.
- **SSL enforcement** — All OAuth HTTP requests use cURL with `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST`.
- **XSS prevention** — All user-supplied data injected into the page uses DOM API (`createElement`/`textContent`) or `json_encode()` for JS context. No raw `innerHTML` with user data.
- **No debug info leakage** — Errors are logged to WHMCS activity log; users see generic messages.
- **Session handling** — Delegates entirely to WHMCS's session manager. No manual `session_start()`.
- **Login method** — Uses the official `CreateSsoToken` API with time-limited one-time tokens. No direct session manipulation.

## OAuth Server Response Format

**Token endpoint** (`POST /oauth/token`) — Standard OAuth2 token response:
```json
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

**Userinfo endpoint** (`GET /oauth/userinfo`) — Must return at minimum:
```json
{
  "sub": "unique-user-id",
  "email": "user@example.com",
  "name": "Display Name",
  "picture": "https://example.com/avatar.jpg"
}
```

The `sub` field (or `id` as fallback) is used as the unique identifier for linking accounts.

## License

MIT
