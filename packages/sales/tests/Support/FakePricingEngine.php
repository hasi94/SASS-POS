<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Support;

use Flexipos\Contracts\Pricing\BillDiscount;
use Flexipos\Contracts\Pricing\BillQuote;
use Flexipos\Contracts\Pricing\LineQuote;
use Flexipos\Contracts\Pricing\PricedLine;
use Flexipos\Contracts\Pricing\PricingEngine;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\TaxBreakdown;
use Flexipos\Contracts\Pricing\TaxQuote;

/**
 * A pricing engine that refuses on demand and records what it was asked, around
 * the real one (T-030 plan, test strategy).
 *
 * **Only for the rows that need an engine refusal the real engine cannot
 * produce** (`no_price_resolved` is unreachable through it, R-3) **and for the
 * rows that pin what Sales passes in** (the quote's instant, date, currency,
 * group and branch). Every assertion about a price, a discount or a tax is made
 * against the real engine: a fake that answered those would only test itself.
 */
final class FakePricingEngine implements PricingEngine
{
    /** @var list<LineQuote> */
    public array $quotes = [];

    /** @var list<TaxQuote> */
    public array $taxQuotes = [];

    public function __construct(
        private readonly PricingEngine $real,
        private readonly ?PricingRefused $refusal = null,
    ) {}

    /**
     * Wrap the engine the container holds, and make it the engine the container hands out.
     */
    public static function install(?PricingRefused $refusal = null): self
    {
        $fake = new self(app(PricingEngine::class), $refusal);

        app()->instance(PricingEngine::class, $fake);

        return $fake;
    }

    public function quoteLine(LineQuote $quote): PricedLine
    {
        $this->quotes[] = $quote;

        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        return $this->real->quoteLine($quote);
    }

    public function computeTax(TaxQuote $quote): TaxBreakdown
    {
        $this->taxQuotes[] = $quote;

        return $this->real->computeTax($quote);
    }

    public function billDiscount(BillQuote $quote): BillDiscount
    {
        return $this->real->billDiscount($quote);
    }
}
