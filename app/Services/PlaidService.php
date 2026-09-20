<?php

namespace App\Services;

use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PlaidService
{
    public function request(string $endpoint, array $data = []): array
    {
        $environment = config('services.plaid.environment');
        if (! in_array($environment, ['sandbox', 'production'], true) || ! config('services.plaid.secret')) {
            throw new \RuntimeException('PLAID_NOT_CONFIGURED');
        }
        $response = Http::acceptJson()->connectTimeout(5)->timeout(10)->post('https://'.$environment.'.plaid.com/'.$endpoint, $data + [
            'client_id' => config('services.plaid.client_id'), 'secret' => config('services.plaid.secret'),
        ]);
        if (! $response->successful()) {
            $code = $response->json('error_code');
            throw new \RuntimeException(is_string($code) && preg_match('/^[A-Z_]{1,80}$/D', $code) ? $code : 'PLAID_REQUEST_FAILED');
        }

        return $response->json();
    }

    public function connection(): ?object
    {
        return DB::table('plaid_connections')->where('environment', config('services.plaid.environment'))->first();
    }

    public function token(object $connection): string
    {
        if ($connection->environment !== config('services.plaid.environment') || ! $connection->access_token) {
            throw new \RuntimeException('PLAID_ENVIRONMENT_MISMATCH');
        }

        return Crypt::decryptString($connection->access_token);
    }

    public function linkToken(int $user, bool $update): string
    {
        $data = ['client_name' => '1262 Bryn Mawr Association', 'user' => ['client_user_id' => (string) $user], 'country_codes' => ['US'], 'language' => 'en', 'redirect_uri' => config('services.plaid.redirect_uri')];
        if ($update) {
            $connection = $this->connection();
            if (! $connection || ! $connection->access_token) {
                throw new \RuntimeException('NO_BANK_CONNECTION');
            }
            $data['access_token'] = $this->token($connection);
        } else {
            if ($this->connection()?->access_token) {
                throw new \RuntimeException('BANK_ALREADY_CONNECTED');
            }
            $data['products'] = ['transactions'];
            $data['transactions'] = ['days_requested' => 730];
            $data['account_filters'] = ['depository' => ['account_subtypes' => ['checking', 'savings']]];
        }

        return $this->request('link/token/create', $data)['link_token'];
    }

    public function exchange(string $publicToken, int $actor): void
    {
        if ($this->connection()?->access_token) {
            throw new \RuntimeException('BANK_ALREADY_CONNECTED');
        }
        $item = $this->request('item/public_token/exchange', ['public_token' => $publicToken]);
        DB::table('plaid_connections')->updateOrInsert(['environment' => config('services.plaid.environment')], [
            'item_id' => $item['item_id'], 'access_token' => Crypt::encryptString($item['access_token']),
            'account_id' => null, 'cursor' => null, 'status' => 'select_account', 'error_code' => null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        Audit::record('bank.connected', config('services.plaid.environment'), [], $actor);
    }

    public function accounts(): array
    {
        $connection = $this->connection();
        if (! $connection?->access_token) {
            return [];
        }

        return collect($this->request('accounts/get', ['access_token' => $this->token($connection)])['accounts'])
            ->filter(fn ($account) => $account['type'] === 'depository' && ($account['balances']['iso_currency_code'] ?? null) === 'USD')->values()->all();
    }

    public function select(string $accountId, string $startsOn, int $actor): void
    {
        $account = collect($this->accounts())->firstWhere('account_id', $accountId);
        if (! $account) {
            throw ValidationException::withMessages(['bank' => 'Choose a USD checking or savings account from this connection.']);
        }
        DB::transaction(function () use ($account, $startsOn, $actor) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $connection = $this->connection();
            if ($connection->status !== 'select_account') {
                throw ValidationException::withMessages(['bank' => 'An account has already been selected.']);
            }
            $latest = DB::table('bank_transactions')->max('posted_on');
            if ($connection->environment === 'production' && $latest && $startsOn <= $latest) {
                throw ValidationException::withMessages(['starts_on' => 'Start after '.$latest.' to avoid duplicating existing bank history.']);
            }
            DB::table('plaid_connections')->where('id', $connection->id)->update([
                'account_id' => $account['account_id'], 'account_name' => $account['name'], 'mask' => $account['mask'],
                'starts_on' => $startsOn, 'status' => 'connected', 'balance_cents' => null, 'available_cents' => null,
                'balance_fetched_at' => null, 'synced_at' => null, 'updated_at' => now(),
            ]);
            Audit::record('bank.account_selected', 'connection:'.$connection->id, ['starts_on' => $startsOn], $actor);
        });
    }

    public function disconnect(int $actor): void
    {
        $connection = $this->connection();
        if (! $connection?->access_token) {
            return;
        }
        $this->request('item/remove', ['access_token' => $this->token($connection)]);
        DB::table('plaid_connections')->where('id', $connection->id)->update(['access_token' => null, 'status' => 'disconnected', 'updated_at' => now()]);
        Audit::record('bank.disconnected', 'connection:'.$connection->id, [], $actor);
    }

    public function sync(bool $forceBalance = false): void
    {
        $connection = $this->connection();
        if (! $connection?->account_id || ! $connection->access_token || $connection->status === 'disconnected') {
            return;
        }
        $token = $this->token($connection);
        $cursor = $connection->cursor;
        $changes = [];
        for ($page = 0; $page < 10; $page++) {
            $response = $this->request('transactions/sync', ['access_token' => $token, 'cursor' => $cursor ?: '', 'count' => 500]);
            $changes[] = $response;
            $cursor = $response['next_cursor'];
            if (! $response['has_more']) {
                break;
            }
            if ($page === 9) {
                throw new \RuntimeException('SYNC_TOO_MANY_PAGES');
            }
        }
        // Fetch all pages before advancing the cursor, including pending-to-posted removals.
        DB::transaction(function () use ($changes, $cursor, $connection) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $current = DB::table('plaid_connections')->where('id', $connection->id)->lockForUpdate()->first();
            if ($current->cursor !== $connection->cursor || $current->access_token !== $connection->access_token) {
                throw new \RuntimeException('CONNECTION_CHANGED_DURING_SYNC');
            }
            $batch = null;
            if ($connection->environment === 'production') {
                $batch = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'Plaid automatic sync', 'checksum' => hash('sha256', (string) $cursor), 'status' => 'applied', 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($changes as $change) {
                foreach (array_merge($change['added'], $change['modified']) as $transaction) {
                    if ($transaction['account_id'] !== $connection->account_id) {
                        continue;
                    }
                    if (($transaction['iso_currency_code'] ?? null) !== 'USD') {
                        throw new \RuntimeException('UNSUPPORTED_CURRENCY');
                    }
                    $this->applyTransaction($connection, $transaction, $batch);
                }
                foreach ($change['removed'] as $removed) {
                    $row = DB::table('plaid_transactions')->where('connection_id', $connection->id)->where('transaction_id', $removed['transaction_id'])->first();
                    if ($row) {
                        DB::table('plaid_transactions')->where('id', $row->id)->update(['removed' => true, 'updated_at' => now()]);
                        if ($row->bank_transaction_id) {
                            $this->retireBankRow($row->bank_transaction_id);
                        }
                    }
                }
            }
            DB::table('plaid_connections')->where('id', $connection->id)->update(['cursor' => $cursor, 'synced_at' => now(), 'status' => 'connected', 'error_code' => null, 'updated_at' => now()]);
        });
        if ($forceBalance || ! $connection->balance_fetched_at || now()->subHours(6)->gte($connection->balance_fetched_at)) {
            $accounts = $this->request('accounts/balance/get', ['access_token' => $token, 'options' => ['account_ids' => [$connection->account_id]]]);
            $account = collect($accounts['accounts'])->firstWhere('account_id', $connection->account_id);
            if (! $account || ($account['balances']['iso_currency_code'] ?? null) !== 'USD') {
                throw new \RuntimeException('ACCOUNT_NOT_AVAILABLE');
            }
            DB::table('plaid_connections')->where('id', $connection->id)->where('access_token', $connection->access_token)->update([
                'balance_cents' => $account['balances']['current'] === null ? null : $this->cents($account['balances']['current']),
                'available_cents' => $account['balances']['available'] === null ? null : $this->cents($account['balances']['available']),
                'balance_fetched_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function cents(int|float|string $amount): int
    {
        return Money::cents(number_format((float) $amount, 2, '.', ''));
    }

    private function applyTransaction(object $connection, array $transaction, ?int $batch): void
    {
        $key = ['connection_id' => $connection->id, 'transaction_id' => $transaction['transaction_id']];
        $old = DB::table('plaid_transactions')->where($key)->first();
        $values = ['posted_on' => $transaction['date'], 'description' => $transaction['name'], 'amount_cents' => -$this->cents($transaction['amount']), 'pending' => $transaction['pending'], 'removed' => false, 'updated_at' => now()];
        $bankId = $old?->bank_transaction_id;
        if ($connection->environment === 'production' && ! $transaction['pending'] && $transaction['date'] >= $connection->starts_on) {
            $bankValues = ['posted_on' => $values['posted_on'], 'description' => $values['description'], 'amount_cents' => $values['amount_cents'], 'removed_at' => null, 'updated_at' => now()];
            if ($bankId) {
                $bank = DB::table('bank_transactions')->find($bankId);
                $changed = (int) $bank->amount_cents !== $values['amount_cents'] || $bank->posted_on !== $values['posted_on'] || $bank->description !== $values['description'];
                if ($changed && DB::table('payments')->where('bank_transaction_id', $bankId)->exists()) {
                    $bankValues['review_required'] = true;
                }
                DB::table('bank_transactions')->where('id', $bankId)->update($bankValues);
            } else {
                $bankId = DB::table('bank_transactions')->insertGetId($bankValues + ['reference' => 'plaid:'.$connection->id.':'.$transaction['transaction_id'], 'source' => 'plaid', 'import_batch_id' => $batch, 'created_at' => now()]);
            }
        } elseif ($bankId) {
            $this->retireBankRow($bankId);
        }
        DB::table('plaid_transactions')->updateOrInsert($key, $values + ['bank_transaction_id' => $bankId, 'created_at' => $old?->created_at ?? now()]);
    }

    private function retireBankRow(int $id): void
    {
        DB::table('bank_transactions')->where('id', $id)->update([
            'removed_at' => now(), 'review_required' => DB::table('payments')->where('bank_transaction_id', $id)->exists(), 'updated_at' => now(),
        ]);
    }
}
