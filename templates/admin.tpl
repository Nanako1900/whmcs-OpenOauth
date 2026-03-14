<h2>Nanako OAuth2 - Linked Accounts</h2>

<p>Total linked accounts: {$total_links}</p>

<table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">
    <tr>
        <th>ID</th>
        <th>WHMCS User</th>
        <th>OAuth UID</th>
        <th>OAuth Email</th>
        <th>OAuth Username</th>
        <th>Linked At</th>
        <th>Actions</th>
    </tr>
    {foreach from=$links item=link}
    <tr>
        <td>{$link.id}</td>
        <td>
            <a href="clientssummary.php?userid={$link.client_id}">{$link.client_name}</a>
            <br><small>{$link.whmcs_email}</small>
        </td>
        <td>{$link.oauth_uid}</td>
        <td>{$link.email}</td>
        <td>{$link.username}</td>
        <td>{$link.created_at}</td>
        <td>
            <a href="{$modulelink}&action=unlink&id={$link.id}"
               onclick="return confirm('Are you sure you want to remove this OAuth link?')">
                Unlink
            </a>
        </td>
    </tr>
    {/foreach}
</table>
