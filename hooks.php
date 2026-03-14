<?php

/**
 * Nanako OAuth2 Hooks
 *
 * Target: WHMCS 8.13.1 with Twenty-One template (Bootstrap 4)
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Helper: load module config from database.
 */
function nanako_oauth_get_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = Capsule::table('tbladdonmodules')
            ->where('module', 'nanako_oauth')
            ->pluck('value', 'setting')
            ->toArray();
    }
    return $config;
}

/**
 * Inject CSS, flash messages, login button, and profile bind/unbind.
 *
 * ClientAreaHeadOutput returns a string injected into <head>.
 * All DOM manipulation is done via DOMContentLoaded JS.
 *
 * Twenty-One template (WHMCS 8.13.1) login page structure:
 *   <div id="login">
 *     <form method="post" action="..." role="form">
 *       <input name="username" type="email" class="form-control">
 *       <input name="password" type="password" class="form-control pw-input">
 *       <button type="submit" ...>Login</button>
 *     </form>
 *   </div>
 *
 * Profile page structure:
 *   clientarea.php?action=details
 *   Contains forms with class "main-content"
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    $config = nanako_oauth_get_config();
    $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
    $callbackUrl = $systemUrl . '/modules/addons/nanako_oauth/callback.php';
    $buttonText = htmlspecialchars($config['button_text'] ?? '使用 Nanako 账号登录');

    // ---- CSS ----
    $output = '<style>
.nanako-oauth-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 10px 20px;
    margin: 10px 0;
    background: #6366f1;
    color: #fff !important;
    border: none;
    border-radius: 6px;
    font-size: 15px;
    font-weight: 500;
    text-decoration: none !important;
    cursor: pointer;
    transition: background 0.2s;
    box-sizing: border-box;
}
.nanako-oauth-btn:hover {
    background: #4f46e5;
    color: #fff !important;
    text-decoration: none !important;
}
.nanako-oauth-btn svg {
    width: 20px;
    height: 20px;
    fill: currentColor;
    flex-shrink: 0;
}
.nanako-oauth-divider {
    display: flex;
    align-items: center;
    text-align: center;
    margin: 15px 0;
    color: #999;
    font-size: 13px;
}
.nanako-oauth-divider::before,
.nanako-oauth-divider::after {
    content: "";
    flex: 1;
    border-bottom: 1px solid #ddd;
}
.nanako-oauth-divider::before { margin-right: 10px; }
.nanako-oauth-divider::after { margin-left: 10px; }
.nanako-oauth-flash {
    padding: 10px 15px;
    margin: 10px 0;
    border-radius: 4px;
    font-size: 14px;
}
.nanako-oauth-flash.error {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
}
.nanako-oauth-flash.success {
    background: #f0fdf4;
    color: #16a34a;
    border: 1px solid #bbf7d0;
}
.nanako-oauth-bind-card {
    padding: 20px;
    margin: 20px 0;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f9fafb;
}
.nanako-oauth-bind-card h4 {
    margin-top: 0;
    margin-bottom: 12px;
}
.nanako-oauth-bind-card .bound-info {
    display: flex;
    align-items: center;
    gap: 12px;
}
.nanako-oauth-bind-card .bound-info img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
}
</style>';

    // ---- Flash messages ----
    // Do NOT call session_start() — WHMCS init.php already manages the session.

    if (!empty($_SESSION['nanako_oauth_error'])) {
        $msg = json_encode($_SESSION['nanako_oauth_error']);
        unset($_SESSION['nanako_oauth_error']);
        $output .= "<script>document.addEventListener('DOMContentLoaded',function(){
            var t=document.querySelector('form.login-form .card-body')||document.querySelector('.main-content');
            if(t){var d=document.createElement('div');d.className='nanako-oauth-flash error';d.textContent={$msg};t.prepend(d);}
        });</script>";
    }

    if (!empty($_SESSION['nanako_oauth_success'])) {
        $msg = json_encode($_SESSION['nanako_oauth_success']);
        unset($_SESSION['nanako_oauth_success']);
        $output .= "<script>document.addEventListener('DOMContentLoaded',function(){
            var t=document.querySelector('.main-content');
            if(t){var d=document.createElement('div');d.className='nanako-oauth-flash success';d.textContent={$msg};t.prepend(d);}
        });</script>";
    }

    // ---- Login page: inject OAuth button ----
    // WHMCS 8.13.1 Twenty-One login structure:
    //   form.login-form > .card > .card-body (contains inputs + submit)
    //                           > .card-footer (contains register link)
    // We inject the OAuth button inside .card-body, after the submit row.
    $jsLoginUrl = json_encode($callbackUrl . '?action=login');
    $jsButtonText = json_encode($config['button_text'] ?? '使用 Nanako 账号登录');

    $output .= <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    var loginForm = document.querySelector('form.login-form');
    if (!loginForm) return;
    var cardBody = loginForm.querySelector('.card-body');
    if (!cardBody) return;
    if (cardBody.querySelector('.nanako-oauth-divider')) return;

    var loginUrl = {$jsLoginUrl};
    var buttonText = {$jsButtonText};

    var container = document.createElement('div');
    container.style.clear = 'both';
    container.style.paddingTop = '10px';

    var divider = document.createElement('div');
    divider.className = 'nanako-oauth-divider';
    divider.textContent = 'OR';
    container.appendChild(divider);

    var btn = document.createElement('a');
    btn.href = loginUrl;
    btn.className = 'nanako-oauth-btn';
    btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';
    var span = document.createElement('span');
    span.textContent = buttonText;
    btn.appendChild(span);
    container.appendChild(btn);

    cardBody.appendChild(container);
});
</script>
HTML;

    // ---- Profile page: inject bind/unbind ----
    if (!empty($_SESSION['uid'])) {
        $clientId = (int)$_SESSION['uid'];

        // Only query DB if we're likely on the profile page
        // (hook runs on every page, but JS will only inject on the right page)
        $link = Capsule::table('mod_nanako_oauth_links')
            ->where('client_id', $clientId)
            ->first();

        if ($link) {
            $unbindToken = bin2hex(random_bytes(16));
            $_SESSION['nanako_oauth_unbind_token'] = $unbindToken;

            // Use json_encode for all values injected into JS to prevent XSS.
            // json_encode escapes ', ", <, >, &, \, and unicode characters.
            $jsUsername = json_encode($link->username ?: $link->email ?: $link->oauth_uid);
            $jsAvatar = json_encode($link->avatar ?? '');
            $jsUnbindUrl = json_encode($callbackUrl . '?action=unbind&token=' . $unbindToken);

            $output .= <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('action') !== 'details') return;
    if (document.querySelector('.nanako-oauth-bind-card')) return;

    var target = document.querySelector('.main-content');
    if (!target) return;

    var username = {$jsUsername};
    var avatar = {$jsAvatar};
    var unbindUrl = {$jsUnbindUrl};

    var card = document.createElement('div');
    card.className = 'nanako-oauth-bind-card';

    var h4 = document.createElement('h4');
    h4.textContent = 'Nanako OAuth Account';
    card.appendChild(h4);

    var info = document.createElement('div');
    info.className = 'bound-info';
    if (avatar) {
        var img = document.createElement('img');
        img.src = avatar;
        img.alt = 'avatar';
        info.appendChild(img);
    }
    var detail = document.createElement('div');
    var strong = document.createElement('strong');
    strong.textContent = username;
    detail.appendChild(strong);
    detail.appendChild(document.createElement('br'));
    var small = document.createElement('small');
    small.textContent = 'Linked';
    detail.appendChild(small);
    info.appendChild(detail);
    card.appendChild(info);

    var btn = document.createElement('a');
    btn.href = unbindUrl;
    btn.className = 'nanako-oauth-btn';
    btn.style.background = '#ef4444';
    btn.style.marginTop = '10px';
    btn.textContent = 'Unbind OAuth Account';
    btn.onclick = function() { return confirm('Are you sure you want to unbind your OAuth account?'); };
    card.appendChild(btn);

    target.appendChild(card);
});
</script>
HTML;
        } else {
            $jsBindUrl = json_encode($callbackUrl . '?action=bind');

            $output .= <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('action') !== 'details') return;
    if (document.querySelector('.nanako-oauth-bind-card')) return;

    var target = document.querySelector('.main-content');
    if (!target) return;

    var bindUrl = {$jsBindUrl};

    var card = document.createElement('div');
    card.className = 'nanako-oauth-bind-card';

    var h4 = document.createElement('h4');
    h4.textContent = 'Nanako OAuth Account';
    card.appendChild(h4);

    var p = document.createElement('p');
    p.textContent = 'Bind your Nanako account for quick login.';
    card.appendChild(p);

    var btn = document.createElement('a');
    btn.href = bindUrl;
    btn.className = 'nanako-oauth-btn';
    btn.textContent = 'Bind Nanako Account';
    card.appendChild(btn);

    target.appendChild(card);
});
</script>
HTML;
        }
    }

    return $output;
});

/**
 * Inject OAuth login button on admin login page (Blend template).
 */
add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    $config = nanako_oauth_get_config();

    if (empty($config['admin_login']) || $config['admin_login'] !== 'on') {
        return '';
    }

    $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
    $callbackUrl = htmlspecialchars($systemUrl . '/modules/addons/nanako_oauth/callback.php?action=admin_login');
    $buttonText = htmlspecialchars($config['button_text'] ?? '使用 Nanako 账号登录');

    // WHMCS 8.13.1 Blend admin template: form#frmlogin
    return <<<HTML
<style>
.nanako-admin-oauth-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 10px 20px;
    margin: 10px 0;
    background: #6366f1;
    color: #fff !important;
    border: none;
    border-radius: 6px;
    font-size: 15px;
    font-weight: 500;
    text-decoration: none !important;
    cursor: pointer;
    transition: background 0.2s;
}
.nanako-admin-oauth-btn:hover {
    background: #4f46e5;
    color: #fff !important;
    text-decoration: none !important;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var loginForm = document.getElementById('frmlogin');
    if (!loginForm) return;

    var container = document.createElement('div');
    container.style.textAlign = 'center';
    container.style.margin = '15px 0';
    container.innerHTML = '<hr style="margin:15px 0">'
        + '<a href="{$callbackUrl}" class="nanako-admin-oauth-btn">{$buttonText}</a>';
    loginForm.after(container);
});
</script>
HTML;
});
