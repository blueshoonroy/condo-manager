<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Invoice;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CsvImporter
{
    public function inspect(string $path, string $kind): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'Cannot read this CSV.']);
        }
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');
            if (! $headers) {
                throw new \InvalidArgumentException('The CSV is empty.');
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            $required = $kind === 'bank'
                ? ['POSTED DATE', 'DESCRIPTION', 'AMOUNT', 'CURRENCY', 'FI TRANSACTION REFERENCE', 'CREDIT/DEBIT']
                : ['Client Name', 'Invoice #', 'Date Issued', 'Date Due', 'Invoice Status', 'Date Paid', 'Item Name', 'Item Description', 'Line Total', 'Currency'];
            if (array_diff($required, $headers) || count($headers) !== count(array_unique($headers))) {
                throw new \InvalidArgumentException('CSV headers do not match the expected export.');
            }
            $records = [];
            $errors = [];
            $rowNumber = 1;
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $rowNumber++;
                if ($values === [null]) {
                    continue;
                }
                if ($rowNumber > 20001) {
                    throw new \InvalidArgumentException('Upload at most 20,000 rows per file.');
                }
                try {
                    if (count($values) !== count($headers)) {
                        throw new \InvalidArgumentException('Column count does not match.');
                    }
                    $row = array_combine($headers, $values);
                    if ($kind === 'bank') {
                        $reference = trim($row['FI TRANSACTION REFERENCE']);
                        $amount = Money::cents($row['AMOUNT']);
                        if (! $reference || strlen($reference) > 255 || $row['CURRENCY'] !== 'USD' || ! in_array($row['CREDIT/DEBIT'], ['Credit', 'Debit'], true)
                            || ($row['CREDIT/DEBIT'] === 'Credit' && $amount < 0) || ($row['CREDIT/DEBIT'] === 'Debit' && $amount > 0)) {
                            throw new \InvalidArgumentException('Invalid reference, currency, or credit/debit amount.');
                        }
                        $record = ['reference' => $reference, 'posted_on' => $this->date($row['POSTED DATE'], 'm/d/Y'), 'description' => trim($row['DESCRIPTION']), 'amount_cents' => $amount];
                        if (isset($records[$reference]) && $records[$reference] !== $record) {
                            throw new \InvalidArgumentException('Conflicting duplicate bank reference.');
                        }
                        $existing = DB::table('bank_transactions')->where('reference', $reference)->first();
                        if ($existing && ($existing->posted_on !== $record['posted_on'] || $existing->description !== $record['description'] || (int) $existing->amount_cents !== $amount)) {
                            throw new \InvalidArgumentException('This bank reference conflicts with an existing transaction.');
                        }
                        $records[$reference] = $record;
                    } else {
                        $number = trim($row['Invoice #']);
                        $household = Household::where('client_name', trim($row['Client Name']))->first();
                        $status = strtolower(trim($row['Invoice Status']));
                        $amount = Money::cents($row['Line Total']);
                        if (! $household || ! preg_match('/^[A-Za-z0-9-]{1,80}$/D', $number) || $row['Currency'] !== 'USD' || $amount < 0 || ! in_array($status, ['paid', 'overdue', 'sent', 'viewed', 'unpaid'], true)) {
                            throw new \InvalidArgumentException('Unknown client, unsupported status, invalid invoice number, currency, or amount.');
                        }
                        $record = [
                            'number' => $number, 'household_id' => $household->id, 'unit_id' => $household->unit_id, 'source' => 'freshbooks',
                            'issued_on' => $this->date($row['Date Issued']), 'due_on' => $this->date($row['Date Due']),
                            'paid_on' => trim($row['Date Paid']) !== '' ? $this->date($row['Date Paid']) : null, 'source_status' => $status,
                        ];
                        if (isset($records[$number])) {
                            foreach ($record as $key => $value) {
                                if ($records[$number][$key] !== $value) {
                                    throw new \InvalidArgumentException('Inconsistent rows for the same invoice.');
                                }
                            }
                        } else {
                            $records[$number] = $record + ['total_cents' => 0, 'historical_paid_cents' => 0, 'items' => []];
                        }
                        $records[$number]['total_cents'] += $amount;
                        $records[$number]['historical_paid_cents'] = $status === 'paid' ? $records[$number]['total_cents'] : 0;
                        $records[$number]['items'][] = ['name' => $row['Item Name'], 'description' => $row['Item Description'], 'amount_cents' => $amount];
                    }
                } catch (\Throwable $error) {
                    $errors[] = 'Row '.$rowNumber.': '.$error->getMessage();
                }
            }
            if ($kind === 'invoices') {
                foreach ($records as $record) {
                    $existing = Invoice::where('number', $record['number'])->first();
                    if ($existing && ($existing->source !== 'freshbooks' || $existing->household_id !== $record['household_id'] || $existing->void_reason || DB::table('payment_allocations')->where('invoice_id', $existing->id)->exists())) {
                        $errors[] = 'Invoice '.$record['number'].': existing ownership or portal payment activity requires manual review.';
                    }
                }
            }
            $new = 0;
            $changed = 0;
            $unchanged = 0;
            foreach ($records as $record) {
                $existing = $kind === 'bank' ? DB::table('bank_transactions')->where('reference', $record['reference'])->first() : Invoice::where('number', $record['number'])->first();
                if (! $existing) {
                    $new++;
                } elseif ($kind === 'bank') {
                    $unchanged++;
                } else {
                    $different = false;
                    foreach ($record as $key => $value) {
                        $current = $existing->getRawOriginal($key);
                        if ($key === 'items') {
                            $current = $existing->items;
                        }
                        if ($current != $value) {
                            $different = true;
                        }
                    }
                    $different ? $changed++ : $unchanged++;
                }
            }

            return ['records' => array_values($records), 'errors' => $errors, 'summary' => ['rows' => $rowNumber - 1, 'new' => $new, 'changed' => $changed, 'unchanged' => $unchanged, 'total_cents' => array_sum(array_column($records, $kind === 'bank' ? 'amount_cents' : 'total_cents'))]];
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['file' => $error->getMessage()]);
        } finally {
            fclose($handle);
        }
    }

    public function commit(int $batchId, string $path): array
    {
        return DB::transaction(function () use ($batchId, $path) {
            // One lock serializes imports, billing, and payments that change accounting records.
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $batch = DB::table('import_batches')->where('id', $batchId)->lockForUpdate()->first();
            if (! $batch || $batch->status !== 'preview' || ! hash_equals($batch->checksum, hash_file('sha256', $path))) {
                throw ValidationException::withMessages(['file' => 'This import was already applied or its source changed.']);
            }
            $result = $this->inspect($path, $batch->kind);
            if ($result['errors']) {
                throw ValidationException::withMessages(['file' => $result['errors']]);
            }
            foreach ($result['records'] as $record) {
                if ($batch->kind === 'bank') {
                    if (! DB::table('bank_transactions')->where('reference', $record['reference'])->exists()) {
                        DB::table('bank_transactions')->insert($record + ['import_batch_id' => $batchId, 'created_at' => now(), 'updated_at' => now()]);
                    }
                } else {
                    Invoice::updateOrCreate(['number' => $record['number']], $record + ['import_batch_id' => $batchId]);
                }
            }
            DB::table('import_batches')->where('id', $batchId)->update(['status' => 'applied', 'summary' => json_encode($result['summary']), 'updated_at' => now()]);
            Audit::record('import.applied', 'batch:'.$batchId, $result['summary'], $batch->user_id);

            return $result['summary'];
        });
    }

    private function date(string $value, string $format = 'Y-m-d'): string
    {
        $date = CarbonImmutable::createFromFormat('!'.$format, trim($value));
        if (! $date || $date->format($format) !== trim($value)) {
            throw new \InvalidArgumentException('Invalid date.');
        }

        return $date->toDateString();
    }
}
