<?php

declare(strict_types=1);

namespace App\Xero;

use App\Auth\Auth;
use App\Helpers\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

class XeroApiClient
{
    private const BASE_URL = 'https://api.xero.com/api.xro/2.0';

    private Client $http;
    private OAuthClient $oauth;
    private string $userId;
    private string $tenantId;

    public function __construct(string $userId = '', string $tenantId = '')
    {
        $this->http = new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]);

        $this->oauth    = new OAuthClient();
        $this->userId   = $userId   ?: Auth::userId();
        $this->tenantId = $tenantId ?: Auth::activeTenantId();
    }

    private function headers(): ?array
    {
        $accessToken = $this->oauth->getValidAccessToken($this->userId, $this->tenantId);

        if (!$accessToken) {
            Logger::error('xero_api', "No valid token for user {$this->userId} / tenant {$this->tenantId}");
            return null;
        }

        return [
            'Authorization'  => "Bearer {$accessToken}",
            'Xero-tenant-id' => $this->tenantId,
            'Accept'         => 'application/json',
        ];
    }

    private function getHeaderValue(?ResponseInterface $response, string $name): string
    {
        if (!$response) {
            return '';
        }

        $values = $response->getHeader($name);
        return $values[0] ?? '';
    }

    private function backoffSeconds(int $attempt): int
    {
        $schedule = [2, 5, 10, 20];
        return $schedule[min($attempt - 1, count($schedule) - 1)];
    }

    private function requestJson(string $method, string $path, array $query = [], int $maxAttempts = 4): ?array
    {
        $headers = $this->headers();
        if (!$headers) {
            return null;
        }

        $url = self::BASE_URL . $path;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->http->request($method, $url, [
                    'headers' => $headers,
                    'query'   => $query,
                ]);

                $decoded = json_decode((string) $response->getBody(), true);

                if (!is_array($decoded)) {
                    Logger::error('xero_api', "Invalid JSON from {$path}");
                    return null;
                }

                return $decoded;

            } catch (ClientException $e) {
                $response = $e->getResponse();
                $status   = $response ? $response->getStatusCode() : 0;

                if ($status === 429) {
                    $wait = $this->backoffSeconds($attempt);

                    Logger::warning('xero_api', "429 from Xero for {$path}, retrying", [
                        'attempt'                => $attempt,
                        'max_attempts'           => $maxAttempts,
                        'wait_seconds'           => $wait,
                        'tenant_id'              => $this->tenantId,
                        'x_minlimit_remaining'   => $this->getHeaderValue($response, 'X-MinLimit-Remaining'),
                        'x_daylimit_remaining'   => $this->getHeaderValue($response, 'X-DayLimit-Remaining'),
                        'retry_after'            => $this->getHeaderValue($response, 'Retry-After'),
                    ]);

                    if ($attempt < $maxAttempts) {
                        sleep($wait);
                        continue;
                    }
                }

                Logger::error('xero_api', "Client error for {$path}: " . $e->getMessage(), [
                    'status'    => $status,
                    'tenant_id' => $this->tenantId,
                ]);
                return null;

            } catch (ServerException|TransferException $e) {
                $wait = $this->backoffSeconds($attempt);

                Logger::warning('xero_api', "Transient Xero/API transport error for {$path}, retrying", [
                    'attempt'      => $attempt,
                    'max_attempts' => $maxAttempts,
                    'wait_seconds' => $wait,
                    'tenant_id'    => $this->tenantId,
                    'message'      => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
                    sleep($wait);
                    continue;
                }

                Logger::error('xero_api', "Final failure for {$path}: " . $e->getMessage());
                return null;

            } catch (\Throwable $e) {
                Logger::error('xero_api', "Unexpected failure for {$path}: " . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    public function getInvoice(string $invoiceId): ?array
    {
        $body = $this->requestJson('GET', "/Invoices/{$invoiceId}");
        if (!$body) {
            return null;
        }

        $invoice = $body['Invoices'][0] ?? null;

        Logger::info('xero_api', "Invoice fetched: {$invoiceId}", [
            'status' => $invoice['Status'] ?? 'unknown',
            'number' => $invoice['InvoiceNumber'] ?? '',
        ]);

        return $invoice;
    }

    public function getPayment(string $paymentId): ?array
    {
        $body = $this->requestJson('GET', "/Payments/{$paymentId}");
        if (!$body) {
            return null;
        }

        $payment = $body['Payments'][0] ?? null;

        if ($payment) {
            Logger::info('xero_api', "Payment fetched: {$paymentId}", [
                'account_id' => $payment['Account']['AccountID'] ?? 'unknown',
                'amount'     => $payment['Amount'] ?? 0,
            ]);
        }

        return $payment;
    }

    public function getBankAccounts(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = OrgStorage::getBankAccounts($this->tenantId);
            if ($cached !== null) {
                Logger::info('xero_api', "Bank accounts served from cache for {$this->tenantId}");
                return $cached;
            }
        }

        $body = $this->requestJson('GET', '/Accounts', [
            'where' => 'Type=="BANK"',
        ]);

        if (!$body) {
            return [];
        }

        $raw = $body['Accounts'] ?? [];
        $accounts = [];

        foreach ($raw as $acct) {
            $accounts[] = [
                'account_id'    => $acct['AccountID']    ?? '',
                'name'          => $acct['Name']         ?? '',
                'code'          => $acct['Code']         ?? '',
                'currency_code' => $acct['CurrencyCode'] ?? '',
                'status'        => $acct['Status']       ?? '',
            ];
        }

        OrgStorage::saveBankAccounts($this->tenantId, $accounts);
        Logger::info('xero_api', "Bank accounts fetched and cached for {$this->tenantId}: " . count($accounts) . " account(s)");

        return $accounts;
    }

    public function getChartAccounts(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = OrgStorage::getChartAccounts($this->tenantId);
            if ($cached !== null) {
                Logger::info('xero_api', "Chart accounts served from cache for {$this->tenantId}");
                return $cached;
            }
        }

        $body = $this->requestJson('GET', '/Accounts');

        if (!$body) {
            return [];
        }

        $raw = $body['Accounts'] ?? [];
        $accounts = [];

        foreach ($raw as $acct) {
            $accounts[] = [
                'account_id'    => $acct['AccountID'] ?? '',
                'code'          => $acct['Code'] ?? '',
                'name'          => $acct['Name'] ?? '',
                'type'          => $acct['Type'] ?? '',
                'class'         => $acct['Class'] ?? '',
                'system_account'=> $acct['SystemAccount'] ?? '',
                'status'        => $acct['Status'] ?? '',
                'currency_code' => $acct['CurrencyCode'] ?? '',
            ];
        }

        OrgStorage::saveChartAccounts($this->tenantId, $accounts);
        Logger::info('xero_api', "Chart accounts fetched and cached for {$this->tenantId}: " . count($accounts) . " account(s)");

        return $accounts;
    }

    public function getOrganisation(): ?array
    {
        $body = $this->requestJson('GET', '/Organisations');
        if (!$body) {
            return null;
        }

        $org = $body['Organisations'][0] ?? null;
        if (!$org) {
            return null;
        }

        $address = $this->extractAddressText($org['Addresses'] ?? []);

        Logger::info('xero_api', "Organisation fetched: {$org['Name']}");

        return [
            'name'    => $org['Name'] ?? '',
            'address' => $address,
            'email'   => $this->extractOrganisationEmail($org),
            'phone'   => $this->extractOrganisationPhone($org['Phones'] ?? []),
        ];
    }

    public function getInvoices(string $where = '', int $page = 1): array
    {
        $query = ['order' => 'Date DESC', 'page' => $page];
        if ($where) {
            $query['where'] = $where;
        }

        $body = $this->requestJson('GET', '/Invoices', $query);
        return $body['Invoices'] ?? [];
    }

    public function getAwaitingPayment(int $page = 1): array
    {
        return $this->getInvoices('Status=="AUTHORISED"', $page);
    }

    public function getRecentlyPaid(int $days = 7, int $page = 1): array
    {
        $since = date('Y, n, j', strtotime("-{$days} days"));
        $where = "Status==\"PAID\"&&FullyPaidOnDate>=DateTime({$since})";
        return $this->getInvoices($where, $page);
    }

    public function extractReceiptData(array $invoice): array
    {
        $paymentDate = null;
        if (!empty($invoice['Payments'])) {
            $paymentDate = $this->parseXeroDate($invoice['Payments'][0]['Date'] ?? '');
        }

        $contact      = $invoice['Contact'] ?? [];
        $contactEmail = $contact['EmailAddress'] ?? '';
        $contactAddress = $this->extractAddressText($contact['Addresses'] ?? []);

        return [
            'invoice_id'     => $invoice['InvoiceID'] ?? '',
            'invoice_number' => $invoice['InvoiceNumber'] ?? '',
            'status'         => $invoice['Status'] ?? '',
            'contact_name'   => $contact['Name'] ?? '',
            'contact_first_name' => $this->extractContactFirstName($contact),
            'contact_email'  => $contactEmail,
            'contact_address' => $contactAddress,
            'invoice_date'   => $this->parseXeroDate($invoice['Date'] ?? ''),
            'due_date'       => $this->parseXeroDate($invoice['DueDate'] ?? ''),
            'payment_date'   => $paymentDate,
            'sub_total'      => (float) ($invoice['SubTotal'] ?? 0),
            'total_tax'      => (float) ($invoice['TotalTax'] ?? 0),
            'total'          => (float) ($invoice['Total'] ?? 0),
            'amount_due'     => (float) ($invoice['AmountDue'] ?? 0),
            'amount_paid'    => (float) ($invoice['AmountPaid'] ?? 0),
            'currency_code'  => $invoice['CurrencyCode'] ?? 'USD',
            'invoice_reference' => trim((string) ($invoice['Reference'] ?? '')),
            'payment_reference' => trim((string) ($invoice['Payments'][0]['Reference'] ?? '')),
            'line_items'     => array_slice($invoice['LineItems'] ?? [], 0, 1),
            'tenant_id'      => $this->tenantId,
        ];
    }

    private function extractContactFirstName(array $contact): string
    {
        $firstName = trim((string) ($contact['FirstName'] ?? ''));
        if ($firstName !== '') {
            return $firstName;
        }

        $name = trim((string) ($contact['Name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name);
        return $parts[0] ?? '';
    }

    private function extractAddressText(array $addresses): string
    {
        $fallback = '';

        foreach ($addresses as $addr) {
            $formatted = $this->flattenAddressParts([
                $addr['AddressLine1'] ?? '',
                $addr['AddressLine2'] ?? '',
                $addr['City'] ?? '',
                $addr['Region'] ?? '',
                $addr['PostalCode'] ?? '',
                $addr['Country'] ?? '',
            ]);

            if ($formatted === '') {
                continue;
            }

            if (in_array($addr['AddressType'] ?? '', ['POBOX', 'STREET'], true)) {
                return $formatted;
            }

            if ($fallback === '') {
                $fallback = $formatted;
            }
        }

        return $fallback;
    }

    private function flattenAddressParts(array $parts): string
    {
        $clean = [];

        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return implode(', ', $clean);
    }

    private function extractOrganisationEmail(array $org): string
    {
        $candidates = [
            $org['EmailAddress'] ?? '',
            $org['ReturnsEmailAddress'] ?? '',
            $org['PayPalEmailAddress'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $email = trim((string) $candidate);
            if ($email !== '') {
                return $email;
            }
        }

        return '';
    }

    private function extractOrganisationPhone(array $phones): string
    {
        if (empty($phones)) {
            return '';
        }

        $preferredOrder = ['DEFAULT', 'DDI', 'MOBILE', 'OFFICE'];

        foreach ($preferredOrder as $type) {
            foreach ($phones as $phone) {
                if (($phone['PhoneType'] ?? '') !== $type) {
                    continue;
                }

                $formatted = $this->formatPhoneParts($phone);
                if ($formatted !== '') {
                    return $formatted;
                }
            }
        }

        foreach ($phones as $phone) {
            $formatted = $this->formatPhoneParts($phone);
            if ($formatted !== '') {
                return $formatted;
            }
        }

        return '';
    }

    private function formatPhoneParts(array $phone): string
    {
        $parts = array_filter([
            trim((string) ($phone['PhoneCountryCode'] ?? '')),
            trim((string) ($phone['PhoneAreaCode'] ?? '')),
            trim((string) ($phone['PhoneNumber'] ?? '')),
            trim((string) ($phone['PhoneExtension'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        if (empty($parts)) {
            return '';
        }

        $formatted = implode(' ', $parts);

        if (!empty($phone['PhoneCountryCode'])) {
            $formatted = '+' . ltrim($formatted, '+');
        }

        return $formatted;
    }

    private function parseXeroDate(string $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        if (preg_match('/\/Date\((\d+)/', $raw, $m)) {
            return date('Y-m-d', (int) ($m[1] / 1000));
        }

        if (strtotime($raw) !== false) {
            return date('Y-m-d', strtotime($raw));
        }

        return $raw;
    }
}