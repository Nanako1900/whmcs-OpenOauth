<div class="nanako-oauth-bind-card">
    <h4>Nanako OAuth Account</h4>

    {if $oauth_linked}
        <div class="bound-info">
            {if $oauth_avatar}
                <img src="{$oauth_avatar}" alt="avatar">
            {/if}
            <div>
                <strong>{$oauth_username}</strong>
                <br><small>{$oauth_email}</small>
            </div>
        </div>
        <a href="{$unbind_url}" class="nanako-oauth-btn" style="background:#ef4444;margin-top:10px;"
           onclick="return confirm('Are you sure you want to unbind your OAuth account?')">
            Unbind OAuth Account
        </a>
    {else}
        <p>Bind your Nanako account for quick login.</p>
        <a href="{$bind_url}" class="nanako-oauth-btn">
            Bind Nanako Account
        </a>
    {/if}
</div>
