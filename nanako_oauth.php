<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Module configuration.
 */
function nanako_oauth_config()
{
    return [
        'name' => 'Nanako OAuth2 Login',
        'description' => 'Integrate Nanako OAuth2 server for third-party login.',
        'version' => '1.0.0',
        'author' => 'Nanako',
        'fields' => [
            'server_url' => [
                'FriendlyName' => 'OAuth Server URL',
                'Type' => 'text',
                'Size' => 60,
                'Description' => 'Base URL of the OAuth server, e.g. https://auth.example.com',
            ],
            'client_id' => [
                'FriendlyName' => 'Client ID',
                'Type' => 'text',
                'Size' => 60,
            ],
            'client_secret' => [
                'FriendlyName' => 'Client Secret',
                'Type' => 'password',
                'Size' => 60,
            ],
            'scopes' => [
                'FriendlyName' => 'Scopes',
                'Type' => 'text',
                'Size' => 60,
                'Default' => 'profile email phone',
            ],
            'button_text' => [
                'FriendlyName' => 'Login Button Text',
                'Type' => 'text',
                'Size' => 60,
                'Default' => '使用 Nanako 账号登录',
            ],
            'auto_register' => [
                'FriendlyName' => 'Enable Auto Registration',
                'Type' => 'yesno',
                'Description' => 'Automatically create WHMCS account for new OAuth users',
            ],
            'admin_login' => [
                'FriendlyName' => 'Enable Admin OAuth Login',
                'Type' => 'yesno',
                'Description' => 'Allow administrators to login via OAuth',
            ],
        ],
    ];
}

/**
 * Module activation — create database table.
 */
function nanako_oauth_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_nanako_oauth_links')) {
            Capsule::schema()->create('mod_nanako_oauth_links', function ($table) {
                $table->increments('id');
                $table->integer('client_id')->unsigned()->index();
                $table->string('oauth_uid', 255)->unique();
                $table->string('email', 255)->nullable();
                $table->string('username', 255)->nullable();
                $table->string('avatar', 512)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        return ['status' => 'success', 'description' => 'Nanako OAuth2 module activated successfully.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Failed to create database table: ' . $e->getMessage()];
    }
}

/**
 * Module deactivation — drop database table.
 */
function nanako_oauth_deactivate()
{
    try {
        Capsule::schema()->dropIfExists('mod_nanako_oauth_links');

        return ['status' => 'success', 'description' => 'Nanako OAuth2 module deactivated successfully.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Failed to drop database table: ' . $e->getMessage()];
    }
}

/**
 * Admin area output — display linked accounts list.
 */
function nanako_oauth_output($vars)
{
    $moduleLink = $vars['modulelink'];

    // Handle unlink action with CSRF protection
    if (isset($_GET['action']) && $_GET['action'] === 'unlink' && isset($_GET['id'])) {
        $csrfToken = $_GET['token'] ?? '';
        if (empty($csrfToken) || !hash_equals($_SESSION['nanako_oauth_admin_token'] ?? '', $csrfToken)) {
            echo '<div class="errorbox"><strong>Error!</strong> Invalid security token. Please try again.</div>';
        } else {
            $id = (int)$_GET['id'];
            Capsule::table('mod_nanako_oauth_links')->where('id', $id)->delete();
            unset($_SESSION['nanako_oauth_admin_token']);
            echo '<div class="successbox"><strong>Success!</strong> OAuth link removed.</div>';
        }
    }

    // Generate CSRF token for admin unlink actions
    $adminCsrfToken = bin2hex(random_bytes(16));
    $_SESSION['nanako_oauth_admin_token'] = $adminCsrfToken;

    // Fetch all linked accounts
    $links = Capsule::table('mod_nanako_oauth_links')
        ->leftJoin('tblclients', 'mod_nanako_oauth_links.client_id', '=', 'tblclients.id')
        ->select(
            'mod_nanako_oauth_links.*',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblclients.email as whmcs_email'
        )
        ->orderBy('mod_nanako_oauth_links.created_at', 'desc')
        ->get();

    echo '<h2>OAuth Linked Accounts</h2>';
    echo '<p>Total linked accounts: ' . count($links) . '</p>';

    echo '<table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">';
    echo '<tr><th>ID</th><th>WHMCS User</th><th>OAuth UID</th><th>OAuth Email</th><th>OAuth Username</th><th>Linked At</th><th>Actions</th></tr>';

    foreach ($links as $link) {
        $clientName = htmlspecialchars(($link->firstname ?? '') . ' ' . ($link->lastname ?? ''));
        $whmcsEmail = htmlspecialchars($link->whmcs_email ?? '');
        $oauthUid = htmlspecialchars($link->oauth_uid);
        $oauthEmail = htmlspecialchars($link->email ?? '');
        $oauthUsername = htmlspecialchars($link->username ?? '');
        $createdAt = htmlspecialchars($link->created_at ?? '');

        echo "<tr>";
        echo "<td>{$link->id}</td>";
        echo "<td><a href=\"clientssummary.php?userid={$link->client_id}\">{$clientName}</a><br><small>{$whmcsEmail}</small></td>";
        echo "<td>{$oauthUid}</td>";
        echo "<td>{$oauthEmail}</td>";
        echo "<td>{$oauthUsername}</td>";
        echo "<td>{$createdAt}</td>";
        echo "<td><a href=\"{$moduleLink}&action=unlink&id={$link->id}&token=" . htmlspecialchars($adminCsrfToken) . "\" onclick=\"return confirm('Are you sure you want to remove this OAuth link?')\">Unlink</a></td>";
        echo "</tr>";
    }

    echo '</table>';
}
