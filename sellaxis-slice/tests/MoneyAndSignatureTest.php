<?php

declare(strict_types=1);

namespace Forgeline\Tests;

use Forgeline\Domain\Money;
use Forgeline\Infra\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class MoneyAndSignatureTest extends TestCase
{
    public function test_money_rejects_float_like_construction_errors_safely(): void
    {
        $m = Money::fromString('1245.50', 'INR');
        $this->assertSame('1245.50', $m->toString());
    }

    public function test_money_addition_is_exact_not_float(): void
    {
        // 0.1 + 0.2 is the classic float trap (0.30000000000000004). bcmath
        // must give exactly "0.30".
        $a = Money::fromString('0.10', 'EUR');
        $b = Money::fromString('0.20', 'EUR');
        $this->assertSame('0.30', $a->add($b)->toString());
    }

    public function test_money_refuses_to_combine_currencies_without_a_pinned_rate(): void
    {
        $inr = Money::fromString('100.00', 'INR');
        $eur = Money::fromString('100.00', 'EUR');

        $this->expectException(\InvalidArgumentException::class);
        $inr->add($eur);
    }

    public function test_money_multiply_for_line_totals(): void
    {
        $unitPrice = Money::fromString('12.40', 'INR');
        $this->assertSame('620.00', $unitPrice->multiply(50)->toString());
    }

    public function test_signature_verification_accepts_correct_hmac(): void
    {
        $secret = 'test-secret';
        $body = '{"delivery_id":"dlv_1"}';
        $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $verifier = new WebhookSignatureVerifier($secret);
        $this->assertTrue($verifier->verify($body, $sig));
    }

    public function test_signature_verification_rejects_tampered_body(): void
    {
        $secret = 'test-secret';
        $originalBody = '{"delivery_id":"dlv_1"}';
        $sig = 'sha256=' . hash_hmac('sha256', $originalBody, $secret);

        $tamperedBody = '{"delivery_id":"dlv_2"}'; // attacker changes the payload after signing
        $verifier = new WebhookSignatureVerifier($secret);
        $this->assertFalse($verifier->verify($tamperedBody, $sig));
    }

    public function test_signature_verification_rejects_missing_header(): void
    {
        $verifier = new WebhookSignatureVerifier('test-secret');
        $this->assertFalse($verifier->verify('{}', null));
    }
}
