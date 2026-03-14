<?php

namespace NanakoOAuth;

class OAuthClient
{
    private $serverUrl;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $scopes;

    public function __construct(array $config)
    {
        $this->serverUrl = rtrim($config['server_url'], '/');
        $this->clientId = $config['client_id'];
        $this->clientSecret = $config['client_secret'];
        $this->redirectUri = $config['redirect_uri'];
        $this->scopes = $config['scopes'] ?? 'profile email phone';
    }

    /**
     * Generate the OAuth2 authorization URL.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scopes,
            'state' => $state,
        ];

        return $this->serverUrl . '/oauth/authorize?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token.
     */
    public function getAccessToken(string $code): array
    {
        $url = $this->serverUrl . '/oauth/token';

        $postData = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        $response = $this->httpPost($url, $postData);

        if (!isset($response['access_token'])) {
            throw new \Exception('Failed to obtain access token: ' . json_encode($response));
        }

        return $response;
    }

    /**
     * Fetch user info using access token.
     */
    public function getUserInfo(string $accessToken): array
    {
        $url = $this->serverUrl . '/oauth/userinfo';

        $response = $this->httpGet($url, $accessToken);

        if (empty($response)) {
            throw new \Exception('Failed to fetch user info');
        }

        return $response;
    }

    /**
     * Perform an HTTP POST request.
     */
    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \Exception('HTTP error ' . $httpCode . ': ' . $response);
        }

        return json_decode($response, true) ?: [];
    }

    /**
     * Perform an HTTP GET request with Bearer token.
     */
    private function httpGet(string $url, string $accessToken): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \Exception('HTTP error ' . $httpCode . ': ' . $response);
        }

        return json_decode($response, true) ?: [];
    }
}
