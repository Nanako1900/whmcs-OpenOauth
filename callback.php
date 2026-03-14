<?php

/**
 * Nanako OAuth2 Callback Handler
 *
 * Target: WHMCS 8.13.1
 *
 * Routing:
 *   - Has ?code= and ?state= → OAuth server callback (intent stored in session)
 *   - Has ?action= → explicit action (login/bind/unbind/admin_login)
 *   - Otherwise → redirect to homepage
 *
 * Login method: CreateSsoToken API (official WHMCS 8.x SSO mechanism)
 */

define('CLIENTAREA', true);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/OAuthClient.php';

use WHMCS\Database\Capsule;
use NanakoOAuth\OAuthClient;

// Do NOT call session_start() manually — WHMCS init.php already initializes
// its own session handler (possibly database-backed). Calling session_start()
// again could create a conflicting session.

/**
 * Load module configuration from the database.
 */
function getModuleConfig(): array
{
    return Capsule::table('tbladdonmodules')
        ->where('module', 'nanako_oauth')
        ->pluck('value', 'setting')
        ->toArray();
}

/**
 * Create an OAuthClient instance.
 * redirect_uri is a clean URL without query params.
 */
function createOAuthClient(array $config): OAuthClient
{
    $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
    $redirectUri = rtrim($systemUrl, '/') . '/modules/addons/nanako_oauth/callback.php';

    return new OAuthClient([
        'server_url' => $config['server_url'] ?? '',
        'client_id' => $config['client_id'] ?? '',
        'client_secret' => $config['client_secret'] ?? '',
        'redirect_uri' => $redirectUri,
        'scopes' => $config['scopes'] ?? 'profile email phone',
    ]);
}

/**
 * Generate a random state token for CSRF protection.
 */
function generateState(): string
{
    $state = bin2hex(random_bytes(32));
    $_SESSION['nanako_oauth_state'] = $state;
    return $state;
}

/**
 * Validate the state parameter from the callback.
 */
function validateState(string $state): bool
{
    if (empty($_SESSION['nanako_oauth_state'])) {
        return false;
    }
    $valid = hash_equals($_SESSION['nanako_oauth_state'], $state);
    unset($_SESSION['nanako_oauth_state']);
    return $valid;
}

/**
 * Get the username of the first active admin (required for localAPI).
 */
function getAdminUsername(): string
{
    $admin = Capsule::table('tbladmins')
        ->where('disabled', 0)
        ->orderBy('id', 'asc')
        ->first();

    return $admin ? $admin->username : '';
}

/**
 * Safely redirect: write session, send header, exit.
 */
function safeRedirect(string $url): void
{
    session_write_close();
    header('Location: ' . $url);
    exit;
}

/**
 * Redirect with an error flash message.
 */
function redirectWithError(string $url, string $message): void
{
    $_SESSION['nanako_oauth_error'] = $message;
    logActivity('Nanako OAuth Error: ' . $message);
    safeRedirect($url);
}

/**
 * Log a WHMCS client in via CreateSsoToken API.
 *
 * CreateSsoToken generates a one-time SSO URL (valid ~60s) that WHMCS
 * validates at /oauth/singlesignon.php to establish a real session.
 * This is the ONLY officially supported way to programmatically log in
 * a client in WHMCS 8.x (AutoAuth was removed in 8.1, direct session
 * manipulation doesn't work because login_auth_tk uses crypto hashes).
 */
function loginClient(int $clientId): void
{
    $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
    $adminUser = getAdminUsername();

    logActivity('Nanako OAuth: Attempting CreateSsoToken for client #' . $clientId . ' with admin user: ' . $adminUser);

    // Do NOT pass 'destination' — WHMCS docs say it defaults to clientarea homepage.
    // Passing an invalid destination (like 'clientarea:homepage') causes "Invalid destination" error.
    $result = localAPI('CreateSsoToken', [
        'client_id' => $clientId,
    ], $adminUser);

    logActivity('Nanako OAuth: CreateSsoToken result: ' . json_encode($result));

    if (isset($result['result']) && $result['result'] === 'success' && !empty($result['redirect_url'])) {
        logActivity('Nanako OAuth: SSO redirect URL: ' . $result['redirect_url']);
        safeRedirect($result['redirect_url']);
    }

    // If localAPI failed, try without admin username (some WHMCS configs allow this)
    $result2 = localAPI('CreateSsoToken', [
        'client_id' => $clientId,
    ]);

    logActivity('Nanako OAuth: CreateSsoToken (no admin) result: ' . json_encode($result2));

    if (isset($result2['result']) && $result2['result'] === 'success' && !empty($result2['redirect_url'])) {
        safeRedirect($result2['redirect_url']);
    }

    // All methods failed — log details for admin, show generic error to user
    logActivity('Nanako OAuth: ALL login methods failed for client #' . $clientId
        . ' | with_admin: ' . json_encode($result)
        . ' | no_admin: ' . json_encode($result2));

    redirectWithError(
        $systemUrl . '/index.php?rp=/login',
        'Login failed. Please contact support. (Ref: client #' . $clientId . ')'
    );
}

/**
 * Log a WHMCS admin in.
 */
function loginAdmin(int $adminId): void
{
    $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
    $adminPath = \WHMCS\Config\Setting::getValue('customadminpath') ?: 'admin';
    $adminUser = getAdminUsername();

    $result = localAPI('CreateSsoToken', [
        'admin_id' => $adminId,
    ], $adminUser);

    logActivity('Nanako OAuth: Admin CreateSsoToken result: ' . json_encode($result));

    if (isset($result['result']) && $result['result'] === 'success' && !empty($result['redirect_url'])) {
        safeRedirect($result['redirect_url']);
    }

    logActivity('Nanako OAuth: Admin SSO failed for admin #' . $adminId . ' | result: ' . json_encode($result));
    die('Admin OAuth login failed. Please check the WHMCS activity log for details.');
}

// ===========================================================================
// Main routing
// ===========================================================================

$config = getModuleConfig();
$systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
$loginUrl = $systemUrl . '/index.php?rp=/login';

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$action = $_GET['action'] ?? '';

// ---------------------------------------------------------------------------
// OAuth server callback: code + state present
// ---------------------------------------------------------------------------
if (!empty($code) && !empty($state)) {
    $intent = $_SESSION['nanako_oauth_intent'] ?? 'login';
    unset($_SESSION['nanako_oauth_intent']);

    logActivity('Nanako OAuth: Callback received, intent=' . $intent);

    if (!validateState($state)) {
        if ($intent === 'admin_login') {
            die('Invalid state parameter. Possible CSRF attack.');
        }
        redirectWithError($loginUrl, 'Invalid state parameter. Please try again.');
    }

    try {
        $client = createOAuthClient($config);
        $tokenData = $client->getAccessToken($code);
        $userInfo = $client->getUserInfo($tokenData['access_token']);
    } catch (\Exception $e) {
        logActivity('Nanako OAuth: Token/UserInfo error: ' . $e->getMessage());
        if ($intent === 'admin_login') {
            die('OAuth error: ' . htmlspecialchars($e->getMessage()));
        }
        redirectWithError($loginUrl, 'OAuth authentication failed: ' . $e->getMessage());
    }

    logActivity('Nanako OAuth: Got user info for sub=' . ($userInfo['sub'] ?? $userInfo['id'] ?? 'unknown'));

    // ---- Admin login ----
    if ($intent === 'admin_login') {
        if (empty($config['admin_login']) || $config['admin_login'] !== 'on') {
            die('Admin OAuth login is not enabled.');
        }
        $oauthEmail = $userInfo['email'] ?? '';
        if (empty($oauthEmail)) {
            die('OAuth server did not return an email address.');
        }
        $admin = Capsule::table('tbladmins')
            ->where('email', $oauthEmail)
            ->where('disabled', 0)
            ->first();
        if (!$admin) {
            die('No matching administrator found for: ' . htmlspecialchars($oauthEmail));
        }
        loginAdmin($admin->id);
    }

    // ---- Extract OAuth user info ----
    $oauthUid = $userInfo['sub'] ?? $userInfo['id'] ?? '';
    $oauthEmail = $userInfo['email'] ?? '';
    $oauthUsername = $userInfo['name'] ?? $userInfo['username'] ?? '';
    $oauthAvatar = $userInfo['avatar'] ?? $userInfo['picture'] ?? '';

    if (empty($oauthUid)) {
        redirectWithError($loginUrl, 'OAuth server did not return a user identifier.');
    }

    $now = date('Y-m-d H:i:s');

    // ---- Bind intent ----
    if ($intent === 'bind') {
        if (empty($_SESSION['uid'])) {
            redirectWithError($systemUrl . '/clientarea.php', 'You must be logged in to bind an OAuth account.');
        }
        $clientId = (int)$_SESSION['uid'];

        $existing = Capsule::table('mod_nanako_oauth_links')->where('oauth_uid', $oauthUid)->first();
        if ($existing && $existing->client_id !== $clientId) {
            redirectWithError($systemUrl . '/clientarea.php?action=details', 'This OAuth account is already linked to another WHMCS account.');
        }

        $userLink = Capsule::table('mod_nanako_oauth_links')->where('client_id', $clientId)->first();
        if ($userLink) {
            Capsule::table('mod_nanako_oauth_links')->where('client_id', $clientId)->update([
                'oauth_uid' => $oauthUid, 'email' => $oauthEmail,
                'username' => $oauthUsername, 'avatar' => $oauthAvatar, 'updated_at' => $now,
            ]);
        } else {
            Capsule::table('mod_nanako_oauth_links')->insert([
                'client_id' => $clientId, 'oauth_uid' => $oauthUid, 'email' => $oauthEmail,
                'username' => $oauthUsername, 'avatar' => $oauthAvatar,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $_SESSION['nanako_oauth_success'] = 'OAuth account bound successfully.';
        safeRedirect($systemUrl . '/clientarea.php?action=details');
    }

    // ---- Login intent ----

    // 1. Already linked?
    $link = Capsule::table('mod_nanako_oauth_links')->where('oauth_uid', $oauthUid)->first();
    if ($link) {
        Capsule::table('mod_nanako_oauth_links')->where('id', $link->id)->update([
            'email' => $oauthEmail, 'username' => $oauthUsername,
            'avatar' => $oauthAvatar, 'updated_at' => $now,
        ]);
        logActivity('Nanako OAuth: Found existing link, logging in client #' . $link->client_id);
        loginClient($link->client_id);
    }

    // 2. Match by email?
    if (!empty($oauthEmail)) {
        $whmcsClient = Capsule::table('tblclients')->where('email', $oauthEmail)->first();
        if ($whmcsClient) {
            Capsule::table('mod_nanako_oauth_links')->insert([
                'client_id' => $whmcsClient->id, 'oauth_uid' => $oauthUid,
                'email' => $oauthEmail, 'username' => $oauthUsername,
                'avatar' => $oauthAvatar, 'created_at' => $now, 'updated_at' => $now,
            ]);
            logActivity('Nanako OAuth: Matched by email, logging in client #' . $whmcsClient->id);
            loginClient($whmcsClient->id);
        }
    }

    // 3. Auto-register
    if (!empty($config['auto_register']) && $config['auto_register'] === 'on') {
        if (empty($oauthEmail)) {
            redirectWithError($loginUrl, 'Cannot auto-register: no email from OAuth server.');
        }

        $nameParts = explode(' ', $oauthUsername, 2);
        $firstName = $nameParts[0] ?: $oauthEmail;
        $lastName = $nameParts[1] ?? '';
        $password = bin2hex(random_bytes(16));
        $adminUser = getAdminUsername();

        $result = localAPI('AddClient', [
            'firstname' => $firstName,
            'lastname' => $lastName ?: $firstName,
            'email' => $oauthEmail,
            'password2' => $password,
            'address1' => 'N/A',
            'city' => 'N/A',
            'state' => 'N/A',
            'postcode' => '000000',
            'country' => 'CN',
            'phonenumber' => $userInfo['phone'] ?? '0000000000',
        ], $adminUser);

        logActivity('Nanako OAuth: AddClient result: ' . json_encode($result));

        if ($result['result'] === 'success') {
            $newClientId = (int)$result['clientid'];
            Capsule::table('mod_nanako_oauth_links')->insert([
                'client_id' => $newClientId, 'oauth_uid' => $oauthUid,
                'email' => $oauthEmail, 'username' => $oauthUsername,
                'avatar' => $oauthAvatar, 'created_at' => $now, 'updated_at' => $now,
            ]);
            logActivity('Nanako OAuth: Auto-registered client #' . $newClientId . ', logging in');
            loginClient($newClientId);
        } else {
            redirectWithError($loginUrl, 'Failed to create account: ' . ($result['message'] ?? 'Unknown error'));
        }
    }

    redirectWithError($loginUrl, 'No WHMCS account found for this OAuth account. Please register first or contact support.');

}

// ---------------------------------------------------------------------------
// Explicit action routing (no code/state)
// ---------------------------------------------------------------------------
switch ($action) {
    case 'login':
        $client = createOAuthClient($config);
        $state = generateState();
        $_SESSION['nanako_oauth_intent'] = 'login';
        logActivity('Nanako OAuth: Initiating login, state=' . substr($state, 0, 8) . '...');
        safeRedirect($client->getAuthorizationUrl($state));

    case 'bind':
        if (empty($_SESSION['uid'])) {
            redirectWithError($systemUrl . '/clientarea.php', 'You must be logged in to bind an OAuth account.');
        }
        $client = createOAuthClient($config);
        $state = generateState();
        $_SESSION['nanako_oauth_intent'] = 'bind';
        safeRedirect($client->getAuthorizationUrl($state));

    case 'unbind':
        if (empty($_SESSION['uid'])) {
            redirectWithError($systemUrl . '/clientarea.php', 'You must be logged in to unbind an OAuth account.');
        }
        $token = $_GET['token'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['nanako_oauth_unbind_token'] ?? '', $token)) {
            redirectWithError($systemUrl . '/clientarea.php?action=details', 'Invalid security token.');
        }
        unset($_SESSION['nanako_oauth_unbind_token']);
        $clientId = (int)$_SESSION['uid'];
        Capsule::table('mod_nanako_oauth_links')->where('client_id', $clientId)->delete();
        $_SESSION['nanako_oauth_success'] = 'OAuth account unbound successfully.';
        safeRedirect($systemUrl . '/clientarea.php?action=details');

    case 'admin_login':
        if (empty($config['admin_login']) || $config['admin_login'] !== 'on') {
            die('Admin OAuth login is not enabled.');
        }
        $client = createOAuthClient($config);
        $state = generateState();
        $_SESSION['nanako_oauth_intent'] = 'admin_login';
        safeRedirect($client->getAuthorizationUrl($state));

    default:
        safeRedirect($systemUrl . '/index.php');
}
