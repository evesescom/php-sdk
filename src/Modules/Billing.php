<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Billing namespace. Hits ``/api/v1/billing``.
 *
 * Two documents share the word "invoice" and read in opposite directions, so
 * they are two methods rather than one with a flag:
 *
 * - ``issueInvoice()`` asks the customer to pay. It carries a payment reference
 *   and a due date.
 * - ``invoiceForDeposit()`` records a top-up that already settled. It comes
 *   back marked paid, with no due date and nothing to act on.
 *
 * Handing somebody the first when they wanted the second reads as an attempt
 * to charge them twice.
 *
 * Nothing can be issued until ``saveProfile()`` has run. The details are frozen
 * onto each document at issue time, so editing the profile later never rewrites
 * a document already in somebody's books.
 */
final class Billing
{
    /** SDK field => wire field. */
    private const PROFILE_FIELDS = [
        'kind' => 'kind',
        'legalName' => 'legal_name',
        'country' => 'country',
        'addressLine1' => 'address_line1',
        'addressLine2' => 'address_line2',
        'city' => 'city',
        'postalCode' => 'postal_code',
        'taxId' => 'tax_id',
        'vatNumber' => 'vat_number',
        'emailForInvoices' => 'email_for_invoices',
    ];

    public function __construct(private readonly Client $http) {}

    /** Who we bill. ``null`` when nothing has been set yet. */
    public function profile(): ?object
    {
        $d = self::unwrap($this->http->request('GET', '/api/v1/billing/profile'));

        return ($d['legal_name'] ?? null) ? self::toProfile($d) : null;
    }

    /**
     * Set or update the invoicing details. Do it once.
     *
     * Only the fields you pass are changed, so correcting a postcode does not
     * blank a tax number you did not mention.
     *
     * @param  array<string,mixed>  $fields  camelCase keys, see PROFILE_FIELDS
     */
    public function saveProfile(array $fields): object
    {
        $body = [];
        foreach (self::PROFILE_FIELDS as $key => $wire) {
            $value = $fields[$key] ?? null;
            if ($value !== null && $value !== '') {
                $body[$wire] = $value;
            }
        }

        return self::toProfile(self::unwrap(
            $this->http->request('PUT', '/api/v1/billing/profile', null, $body)
        ));
    }

    /**
     * Invoices issued to this account, newest first.
     *
     * @return list<object>
     */
    public function invoices(): array
    {
        $d = self::unwrap($this->http->request('GET', '/api/v1/billing/invoices'));
        $rows = is_array($d['invoices'] ?? null) ? $d['invoices'] : [];

        return array_map(self::toInvoice(...), array_values($rows));
    }

    /** One invoice in full, including the frozen seller and bill-to. */
    public function invoice(string $uuid): object
    {
        return self::toInvoice(self::unwrap(
            $this->http->request('GET', '/api/v1/billing/invoices/'.rawurlencode($uuid))
        ));
    }

    /**
     * An invoice to PAY, for money you are about to send. $10 to $10,000.
     *
     * You get a payment reference; quote it when paying and the amount lands on
     * your balance.
     */
    public function issueInvoice(int $amountCents, string $currency = 'USD', ?string $note = null): object
    {
        $body = [
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'purpose' => 'wallet_topup',
        ];
        if ($note !== null) {
            $body['note'] = $note;
        }

        return self::toInvoice(self::unwrap(
            $this->http->request('POST', '/api/v1/billing/invoices', null, $body)
        ));
    }

    /**
     * A receipt for a top-up that already settled.
     *
     * Comes back marked paid, with no due date. Shows what actually left the
     * customer's account — a $10.00 top-up charged at $10.20 is invoiced as
     * $10.20 with the card fee itemised, so it reconciles against a card
     * statement rather than against the balance.
     *
     * Idempotent: asking twice returns the same document, not a second invoice
     * number for one payment.
     */
    public function invoiceForDeposit(string $depositUuid): object
    {
        return self::toInvoice(self::unwrap(
            $this->http->request('POST', '/api/v1/billing/deposits/'.rawurlencode($depositUuid).'/invoice')
        ));
    }

    /**
     * Cancel an unpaid invoice.
     *
     * The number stays with it — freeing one for reuse is the gap an audit asks
     * about.
     */
    public function cancelInvoice(string $uuid): object
    {
        return self::toInvoice(self::unwrap(
            $this->http->request('POST', '/api/v1/billing/invoices/'.rawurlencode($uuid).'/cancel')
        ));
    }

    /** The invoice as a PDF, ready to file. Returns the raw bytes. */
    public function invoicePdf(string $uuid): string
    {
        $res = $this->http->request(
            'GET',
            '/api/v1/billing/invoices/'.rawurlencode($uuid).'/pdf',
            null,
            null,
            ['Accept' => 'application/pdf'],
        );

        return is_string($res) ? $res : '';
    }

    /**
     * @return array<string,mixed>
     */
    private static function unwrap(mixed $payload): array
    {
        if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return is_array($payload) ? $payload : [];
    }

    private static function intOr(mixed $value, int $fallback = 0): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $fallback);
    }

    private static function strOr(mixed $value, string $fallback = ''): string
    {
        return is_string($value) ? $value : $fallback;
    }

    /**
     * @param  array<string,mixed>  $d
     */
    private static function toProfile(array $d): object
    {
        $out = [];
        foreach (self::PROFILE_FIELDS as $key => $wire) {
            $out[$key] = $d[$wire] ?? null;
        }
        $out['kind'] = self::strOr($out['kind'], 'business');
        $out['legalName'] = self::strOr($out['legalName']);
        $out['country'] = self::strOr($out['country']);

        return (object) $out;
    }

    /**
     * @param  array<string,mixed>  $d
     */
    private static function toInvoice(array $d): object
    {
        $lines = [];
        foreach ((array) ($d['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $lines[] = (object) [
                'description' => self::strOr($line['description'] ?? null),
                'quantity' => self::intOr($line['quantity'] ?? null, 1),
                'unitPriceCents' => self::intOr($line['unit_price_cents'] ?? null, self::intOr($line['amount_cents'] ?? null)),
                'amountCents' => self::intOr($line['amount_cents'] ?? null),
            ];
        }

        $tax = is_array($d['tax'] ?? null) ? $d['tax'] : [];
        $payment = is_array($d['payment'] ?? null) ? $d['payment'] : [];

        return (object) [
            'uuid' => self::strOr($d['uuid'] ?? null),
            'number' => self::strOr($d['number'] ?? null),
            'status' => self::strOr($d['status'] ?? null),
            'amountCents' => self::intOr($d['amount_cents'] ?? null),
            'totalCents' => self::intOr(
                $d['total_cents'] ?? null,
                self::intOr($d['amount_cents'] ?? null) + self::intOr($tax['amount_cents'] ?? null),
            ),
            'currency' => self::strOr($d['currency'] ?? null, 'USD'),
            'issuedAt' => $d['issued_at'] ?? null,
            // Absent on a receipt: nothing is due on money already received.
            'dueAt' => $d['due_at'] ?? null,
            'paidAt' => $d['paid_at'] ?? null,
            'paymentReference' => $payment['reference'] ?? ($d['payment_reference'] ?? null),
            'billTo' => is_array($d['bill_to'] ?? null) ? $d['bill_to'] : [],
            'seller' => is_array($d['seller'] ?? null) ? $d['seller'] : [],
            'lines' => $lines,
        ];
    }
}
