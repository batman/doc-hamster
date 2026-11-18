<?php

declare(strict_types=1);

namespace DocHamsterModules\Revolut;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Revolut Business API 1.0 with an API Console key (Bearer) and request signing (Ed25519, see Signer). Used only
 * inside jobs on the net queue (D-020). Reading: accounts, bank details, transactions, expenses and their receipts;
 * writing (only with "Zahlungs-Entwürfe" on): counterparties and payment drafts – a draft is sent only by a person in
 * the Revolut Business app. Errors come back as RuntimeException with Revolut's message.
 */
final class Client
{
    public const LIVE = 'https://b2b.revolut.com';

    public const SANDBOX = 'https://sandbox-b2b.revolut.com';

    public function __construct(private readonly string $apiKey, private readonly ?string $signingKey, private readonly string $environment = 'live') {}

    public static function from(Settings $settings): self
    {
        $key = $settings->apiKey() ?? throw new RuntimeException(__('m-revolut::texts.no_key'));

        return new self($key, $settings->signingKey(), $settings->environment());
    }

    /** @return list<array<string, mixed>> */
    public function accounts(): array
    {
        return $this->list($this->call('GET', '/accounts'));
    }

    /** @return list<array<string, mixed>> */
    public function bankDetails(string $accountId): array
    {
        return $this->list($this->call('GET', '/accounts/'.rawurlencode($accountId).'/bank-details'));
    }

    /**
     * All transactions created in [from, to), newest first – page by page (1000 each, the last created_at as next "to").
     *
     * @return list<array<string, mixed>>
     */
    public function transactions(string $from, ?string $to = null): array
    {
        $all = [];
        $seen = [];
        for ($page = 0; $page < 100; $page++) {
            $batch = $this->list($this->call('GET', '/transactions', array_filter(['from' => $from, 'to' => $to, 'count' => 1000])));
            foreach ($batch as $t) {
                if (! isset($seen[$t['id'] ?? ''])) {
                    $seen[$t['id'] ?? ''] = true;
                    $all[] = $t;
                }
            }
            if (count($batch) < 1000) {
                break;
            }
            $to = (string) end($batch)['created_at'];
        }

        return $all;
    }

    /** @return array<string, mixed> */
    public function transaction(string $id): array
    {
        $data = $this->call('GET', '/transaction/'.rawurlencode($id))->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Expenses (with receipt_ids and the transaction_id they belong to), by expense_date, 500 a page. Empty when the
     * Expenses feature is off for the business (403/422).
     *
     * @return list<array<string, mixed>>
     */
    public function expenses(string $from): array
    {
        $all = [];
        $to = null;
        for ($page = 0; $page < 40; $page++) {
            $response = $this->call('GET', '/expenses', array_filter(['from' => $from, 'to' => $to, 'count' => 500]), allow: [403, 422]);
            if (! $response->successful()) {
                return [];
            }
            $batch = $this->list($response);
            $all = [...$all, ...$batch];
            if (count($batch) < 500) {
                break;
            }
            $to = (string) end($batch)['expense_date'];
        }

        return $all;
    }

    /** @return array{content: string, type: string} */
    public function receipt(string $expenseId, string $receiptId): array
    {
        $response = $this->call('GET', '/expenses/'.rawurlencode($expenseId).'/receipts/'.rawurlencode($receiptId).'/content');

        return ['content' => $response->body(), 'type' => strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]))];
    }

    /** @return list<array<string, mixed>> */
    public function counterpartiesByIban(string $iban): array
    {
        return $this->list($this->call('GET', '/counterparties', ['iban' => $iban]));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createCounterparty(array $body): array
    {
        $response = $this->call('POST', '/counterparty', body: $body, allow: [422]);
        $data = $response->json();
        // Already there (code 3012): Revolut names it.
        if ($response->status() === 422 && is_string($data['params']['counterparty_id'] ?? null)) {
            $found = $this->call('GET', '/counterparty/'.rawurlencode($data['params']['counterparty_id']))->json();

            return is_array($found) ? $found : [];
        }
        if (! $response->successful()) {
            throw new RuntimeException(self::reason($response));
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createPaymentDraft(array $body): array
    {
        $data = $this->call('POST', '/payment-drafts', body: $body)->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $body
     * @param  list<int>  $allow  statuses the caller handles itself
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null, array $allow = []): Response
    {
        $base = $this->environment === 'sandbox' ? self::SANDBOX : self::LIVE;
        $fullPath = '/api/1.0'.$path;
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $json = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Authorization' => 'Bearer '.$this->apiKey, 'Accept' => 'application/json'];
        if ($this->signingKey !== null) {
            $timestamp = (string) (int) floor(microtime(true) * 1000);
            $headers['Revolut-Api-Timestamp'] = $timestamp;
            $headers['Revolut-Api-Signature'] = Signer::sign(Signer::message($timestamp, $method, $fullPath, $queryString, $json), $this->signingKey);
        }
        $url = $base.$fullPath.($queryString === '' ? '' : '?'.$queryString);
        try {
            $request = Http::withHeaders($headers)->withUserAgent('docHamster')->timeout(60)->retry(2, 2000, static fn ($e): bool => $e instanceof ConnectionException, throw: false);
            $response = $json === '' ? $request->send($method, $url) : $request->withBody($json, 'application/json')->send($method, $url);
        } catch (ConnectionException) {
            throw new RuntimeException(__('m-revolut::texts.unreachable'));
        }
        if (! $response->successful() && ! in_array($response->status(), $allow, true)) {
            throw new RuntimeException(self::reason($response));
        }

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private function list(Response $response): array
    {
        $data = $response->json();

        return is_array($data) && array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    private static function reason(Response $response): string
    {
        $message = $response->json('message');

        return match ($response->status()) {
            401 => __('m-revolut::texts.error_401'),
            403 => __('m-revolut::texts.error_403'),
            429 => __('m-revolut::texts.error_429'),
            default => __('m-revolut::texts.error_other', ['status' => $response->status(), 'message' => is_string($message) ? mb_substr($message, 0, 200) : '']),
        };
    }
}
