<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Initial scope: Iranian mobile numbers, not arbitrary international identities. */
final class Mobile
{
    public static function asciiDigits(string $value): string
    {
        return strtr($value, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
    }

    public static function normalize(string $value): string
    {
        // Accept conventional formatting, never strip arbitrary non-digits.
        $value = preg_replace('/[\s\-()]+/u', '', self::asciiDigits($value));
        if (!is_string($value) || !preg_match('/\A(?:\+98|0098|98|0)?(9[01239][0-9]{8})\z/D', $value, $matches)) {
            throw new \InvalidArgumentException('Invalid Iranian mobile number.');
        }
        // 9950 is a public trunked radio service, not a mobile identity.
        if (str_starts_with($matches[1], '9950')) {
            throw new \InvalidArgumentException('Invalid Iranian mobile number.');
        }
        return '+98' . $matches[1];
    }

    public static function local(string $value): string
    {
        return '0' . substr(self::normalize($value), 3);
    }
}
