<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** One bounded transport attempt. No automatic resend or fallback. */
interface SmsSender
{
    public function id(): string;
    public function send(string $mobile, string $code, string $purpose): SendResult;
}

final class SendResult
{
    public readonly string $state;
    public readonly string $code;
    public readonly string $reference;

    public function __construct(string $state, string $code = '', string $reference = '')
    {
        if (!in_array($state, ['accepted', 'failed', 'uncertain'], true)) {
            throw new \InvalidArgumentException('Unknown send state.');
        }
        // Adapters must pass only allowlisted machine-readable provider fields,
        // never URLs, messages, exception text, credentials or raw response bodies.
        if (!preg_match('/\A[A-Za-z0-9_.:-]{0,64}\z/D', $code) ||
            !preg_match('/\A[0-9]{0,128}\z/D', $reference)) {
            throw new \InvalidArgumentException('Unsafe provider result metadata.');
        }
        $this->state = $state;
        $this->code = $code;
        $this->reference = $reference;
    }
}
