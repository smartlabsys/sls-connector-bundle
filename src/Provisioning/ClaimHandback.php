<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning;

use Symfony\Component\HttpFoundation\Response;

/**
 * The end of the `/sls/claim` sign-in variant (Phase 13, 0.3.3): hand the fresh claim code back to
 * SLS without putting it in a URL. Instead of redirecting to `return_to?code=…&state=…` (the code
 * would land in browser history, proxy and access logs), the app answers with a small page that
 * POSTs `code` and `state` to `return_to` at once (a Continue button when scripts are off).
 *
 *     if (!ClaimHandback::isOnIssuer($returnTo, $issuer)) { throw new BadRequestHttpException(); }
 *     return ClaimHandback::response($returnTo, $code, $state, $translator->trans('sls.claim_continue'));
 *
 * SLS's claim callback takes this POST (released together with bundle 0.3.3); it still takes the
 * old GET redirect for now, but logs it as deprecated.
 */
final class ClaimHandback
{
    /**
     * Whether `$url` is on the SLS issuer itself, not merely sharing its prefix
     * (`https://sls.test.evil` is not `https://sls.test`), with no fragment. Check `return_to`
     * with it before anything else: anything else is an open redirect.
     */
    public static function isOnIssuer(string $url, string $issuer): bool
    {
        $issuer = rtrim(trim($issuer), '/');
        if ($issuer === '' || !str_starts_with($url, $issuer) || str_contains($url, '#')) {
            return false;
        }
        $rest = substr($url, strlen($issuer));

        return $rest === '' || in_array($rest[0], ['/', '?'], true);
    }

    /**
     * The auto-submitting form that POSTs `code` (and `state`, unchanged, when SLS sent one) to
     * `$returnTo`. Not cached, not framable; its CSP allows only its own script and a form post
     * to `$returnTo`'s origin.
     *
     * @param string $returnTo already checked with {@see self::isOnIssuer()}
     * @param string $continue the button label (translate it)
     * @param string $title    the page title (translate it)
     */
    public static function response(
        string $returnTo,
        #[\SensitiveParameter] string $code,
        ?string $state,
        string $continue = 'Continue',
        string $title = 'Returning to SLS',
        string $locale = 'en',
    ): Response {
        $parts = parse_url($returnTo);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !isset($parts['host'])) {
            throw new \InvalidArgumentException('return_to must be an absolute http(s) URL.');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $script = 'document.getElementById("sls-claim-handback").submit();';
        $e      = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        $html = '<!DOCTYPE html><html lang="' . $e($locale) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . $e($title) . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;margin:3rem auto;max-width:28rem;text-align:center">'
            . '<form id="sls-claim-handback" method="post" action="' . $e($returnTo) . '">'
            . '<input type="hidden" name="code" value="' . $e($code) . '">'
            . ($state !== null && $state !== '' ? '<input type="hidden" name="state" value="' . $e($state) . '">' : '')
            . '<p>' . $e($title) . '…</p>'
            . '<button type="submit">' . $e($continue) . '</button>'
            . '</form><script>' . $script . '</script></body></html>';

        return new Response($html, Response::HTTP_OK, [
            'Content-Type'            => 'text/html; charset=UTF-8',
            'Cache-Control'           => 'no-store',
            'Pragma'                  => 'no-cache',
            'Referrer-Policy'         => 'no-referrer',
            'X-Frame-Options'         => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; script-src 'sha256-" . base64_encode(hash('sha256', $script, true)) . "'; style-src 'unsafe-inline'; form-action " . $origin . "; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }
}
