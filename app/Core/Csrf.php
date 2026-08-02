<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection for admin panel forms.
 *
 * The REST API is exempt because it authenticates with a Bearer JWT rather than
 * a cookie, so there is no ambient credential for an attacker to ride.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    public const FIELD = '_csrf';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = Crypto::randomToken(32);
            Session::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    /** Hidden input for forms. */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    public static function verify(Request $request): bool
    {
        $expected = Session::get(self::SESSION_KEY);
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $provided = $request->str(self::FIELD);
        if ($provided === '') {
            $provided = (string) ($request->header('X-CSRF-Token') ?? '');
        }

        return $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * Aborts the request when the token is missing or wrong.
     */
    public static function enforce(Request $request): void
    {
        if (!$request->isPost() && !in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        if (self::verify($request)) {
            return;
        }

        if ($request->wantsJson()) {
            Response::error('Invalid or expired security token. Please reload the page.', 419);
        }

        Session::flash('danger', 'Your session expired or the form was stale. Please try again.');

        // Back to the page that held the form, not to the path that was posted to.
        //
        // Those are the same thing only for routes registered with both verbs. A
        // POST-only route - attaching a signature to a report, say - has no GET
        // handler, so redirecting to its own path produced a bare "Method Not Allowed"
        // for anyone whose session had simply expired. The flash message was written
        // and then never shown, because 405 is not a page.
        Response::redirect(self::returnTo($request));
    }

    /**
     * Where to send somebody after a rejected token.
     *
     * The referring page when it is one of ours, and the dashboard otherwise. The
     * same-origin test matters: Referer is attacker-controlled on a cross-site post,
     * and this redirect is reached precisely when a request looks like one.
     */
    private static function returnTo(Request $request): string
    {
        $referer = (string) ($request->header('Referer') ?? '');

        if ($referer !== '') {
            $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '');
            $host = parse_url($referer, PHP_URL_HOST);

            $sameHost = $host === null || $host === $request->header('Host');

            // A path only, and never the route just refused - which would loop.
            if ($sameHost && str_starts_with($path, '/') && $path !== $request->path()) {
                return $path;
            }
        }

        return '/dashboard';
    }
}
