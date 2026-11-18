<?php

declare(strict_types=1);

namespace DocHamsterModules\Revolut;

use RuntimeException;

/**
 * Point 38: a payment draft at Revolut from an invoice – the receiver is found by IBAN among the counterparties or added
 * as one, then the draft is created. docHamster never sends it: a person checks and sends it in the Revolut Business app
 * (the API has no way to send or approve a draft). Needs an API key with Counterparties (Read, Write) and Payment drafts
 * (Write).
 */
final class Drafts
{
    public function __construct(private readonly Client $client) {}

    /**
     * @param  array<string, mixed>  $d  account_id, name, iban, bic?, amount (decimal), currency, reference, title
     * @return array{id: string}
     */
    public function create(array $d): array
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) ($d['iban'] ?? '')));
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) !== 1) {
            throw new RuntimeException(__('m-revolut::texts.draft_iban'));
        }
        $counterparty = $this->client->counterpartiesByIban($iban)[0] ?? null;
        if ($counterparty === null) {
            $counterparty = $this->client->createCounterparty(array_filter([
                'company_name' => mb_substr(trim((string) $d['name']), 0, 80),
                'bank_country' => substr($iban, 0, 2),
                'currency' => strtoupper((string) $d['currency']),
                'iban' => $iban,
                'bic' => isset($d['bic']) && trim((string) $d['bic']) !== '' ? strtoupper(trim((string) $d['bic'])) : null,
            ], static fn ($v): bool => $v !== null && $v !== ''));
        }
        $receiver = null;
        foreach ((array) ($counterparty['accounts'] ?? []) as $account) {
            if (is_array($account) && strtoupper(str_replace(' ', '', (string) ($account['iban'] ?? ''))) === $iban) {
                $receiver = $account['id'] ?? null;
            }
        }
        $receiver ??= $counterparty['accounts'][0]['id'] ?? null;
        if (! is_string($counterparty['id'] ?? null) || ! is_string($receiver)) {
            throw new RuntimeException(__('m-revolut::texts.draft_receiver'));
        }
        $draft = $this->client->createPaymentDraft([
            'title' => mb_substr((string) $d['title'], 0, 255),
            'payments' => [[
                'account_id' => (string) $d['account_id'],
                'receiver' => ['counterparty_id' => $counterparty['id'], 'account_id' => $receiver],
                'amount' => round((float) $d['amount'], 2),
                'currency' => strtoupper((string) $d['currency']),
                'reference' => mb_substr((string) $d['reference'], 0, 140),
            ]],
        ]);

        return ['id' => (string) ($draft['id'] ?? '')];
    }
}
