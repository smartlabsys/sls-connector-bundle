<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Smartlabsys\SlsConnectorBundle\Manifest\ManifestBuilder;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The browser side of "Sign in with Smartlab": starts an authorization-code + PKCE request and
 * keeps its state (nonce, code verifier, target path) in the session, keyed by `state` so several
 * tabs can log in at once. Pending states expire after 10 minutes.
 */
final class OidcLoginFlow
{
    public const SESSION_PENDING = '_sls_oidc_pending';

    /** Session keys set after a successful SLS login. */
    public const SESSION_SUBJECT  = '_sls.sub';
    public const SESSION_SID      = '_sls.sid';
    public const SESSION_AUTH_AT  = '_sls.auth_at';
    public const SESSION_ID_TOKEN = '_sls.id_token';
    /** The user's SLS access token (aud = this app) and its expiry — for token exchange (doc 09). */
    public const SESSION_ACCESS_TOKEN         = '_sls.access_token';
    public const SESSION_ACCESS_TOKEN_EXPIRES = '_sls.access_token_expires';

    private const PENDING_TTL = 600;
    private const MAX_PENDING = 5;

    public function __construct(
        private SlsMetadata $metadata,
        private string $clientId,
        private string $audience,
        private string $scopes,
    ) {}

    public function redirectUri(): string
    {
        return $this->audience . ManifestBuilder::ENDPOINTS['oidc_callback'];
    }

    public function authorizationUrl(SessionInterface $session, ?string $targetPath, ?string $prompt = null): string
    {
        $state    = self::random();
        $nonce    = self::random();
        $verifier = self::random() . self::random();

        $pending = array_filter(
            (array) $session->get(self::SESSION_PENDING, []),
            static fn ($entry): bool => is_array($entry) && ($entry['at'] ?? 0) > time() - self::PENDING_TTL,
        );
        $pending[$state] = ['nonce' => $nonce, 'verifier' => $verifier, 'target' => self::safeTarget($targetPath), 'at' => time()];
        $session->set(self::SESSION_PENDING, array_slice($pending, -self::MAX_PENDING, null, true));

        $query = array_filter([
            'response_type'         => 'code',
            'client_id'             => $this->clientId,
            'redirect_uri'          => $this->redirectUri(),
            'scope'                 => $this->scopes,
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt'                => $prompt,
        ]);

        return $this->metadata->endpoint('authorization_endpoint') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Takes (and forgets) the pending login for this state.
     *
     * @return array{nonce: string, verifier: string, target: ?string}|null
     */
    public function consume(SessionInterface $session, string $state): ?array
    {
        $pending = (array) $session->get(self::SESSION_PENDING, []);
        $entry   = $pending[$state] ?? null;
        unset($pending[$state]);
        $session->set(self::SESSION_PENDING, $pending);

        if (!is_array($entry) || ($entry['at'] ?? 0) <= time() - self::PENDING_TTL) {
            return null;
        }

        return ['nonce' => $entry['nonce'], 'verifier' => $entry['verifier'], 'target' => $entry['target']];
    }

    /**
     * The signed-in user's SLS access token, while it is valid for at least `$leeway` more seconds —
     * the `subject_token` for {@see \Smartlabsys\SlsConnectorBundle\Client\SlsClient::exchangeToken()}
     * when calling a sibling app on the user's behalf. Null when expired: sign in again.
     */
    public static function accessToken(SessionInterface $session, int $leeway = 30): ?string
    {
        $token   = $session->get(self::SESSION_ACCESS_TOKEN);
        $expires = (int) $session->get(self::SESSION_ACCESS_TOKEN_EXPIRES, 0);

        return is_string($token) && $expires > time() + $leeway ? $token : null;
    }

    /** Only local absolute paths — never another host (open redirect). */
    public static function safeTarget(?string $target): ?string
    {
        if ($target === null || $target === '' || $target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '\\')) {
            return null;
        }

        return $target;
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
