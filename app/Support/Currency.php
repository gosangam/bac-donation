<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which currency a visitor is giving in.
 *
 * There is no IP database here. Country comes from an edge header if a proxy
 * sets one, and otherwise the site simply defaults to its home currency and
 * lets the donor say. A wrong guess costs one click, which is the right trade:
 * NRIs on Indian IPs, travellers and VPN users all defeat IP geolocation, and
 * a silent guess they cannot override is worse than no guess at all.
 */
class Currency
{
    /** Session key holding an explicit choice, which always wins. */
    public const SESSION_KEY = 'give.currency';

    /**
     * Headers set by common edges, in preference order. Any of these is only
     * trusted as a hint for the default — never for pricing or authorisation.
     */
    private const COUNTRY_HEADERS = [
        'CF-IPCountry',           // Cloudflare
        'X-Vercel-IP-Country',    // Vercel
        'X-AppEngine-Country',    // Google App Engine
        'X-Geo-Country',          // common nginx/Varnish convention
    ];

    /** @return array<int, string> */
    public static function supported(): array
    {
        return ['INR', 'USD'];
    }

    public static function isSupported(?string $currency): bool
    {
        return $currency !== null
            && in_array(strtoupper($currency), self::supported(), true);
    }

    public static function domestic(): string
    {
        return strtoupper(config('payments.default_currency', 'INR'));
    }

    /** The currency to price this request in. */
    public static function resolve(Request $request): string
    {
        $chosen = $request->session()->get(self::SESSION_KEY);

        if (self::isSupported($chosen)) {
            return strtoupper($chosen);
        }

        $country = self::country($request);

        if ($country === null) {
            return self::domestic();
        }

        return $country === 'IN' ? 'INR' : 'USD';
    }

    /** Remember an explicit choice. Anything unsupported is ignored, not stored. */
    public static function remember(Request $request, ?string $currency): void
    {
        if (self::isSupported($currency)) {
            $request->session()->put(self::SESSION_KEY, strtoupper($currency));
        }
    }

    private static function country(Request $request): ?string
    {
        foreach (self::COUNTRY_HEADERS as $header) {
            $value = $request->headers->get($header);

            // Cloudflare sends "XX" for requests it cannot place, and "T1" for
            // Tor. Neither says anything about where the donor is.
            if (is_string($value) && preg_match('/^[A-Za-z]{2}$/', $value)
                && ! in_array(strtoupper($value), ['XX', 'T1'], true)) {
                return strtoupper($value);
            }
        }

        return null;
    }
}
