<?php

namespace App\Console\Commands;

use App\Modules\TallySync\Exceptions\TallyXmlUnreadable;
use App\Modules\TallySync\Services\TallySalesInvoiceImporter;
use Illuminate\Console\Command;

/**
 * Read a Tally Sales-voucher XML export and match it to ERP sales orders.
 *
 * DRY RUN BY DEFAULT, per AGENTS.md — a person reads what it concluded and
 * only then re-runs with --write. The verdict is computed identically either
 * way, so the dry run is the write run minus the writing.
 */
class ImportTallySalesInvoices extends Command
{
    protected $signature = 'tally:import-sales-invoices
                            {path : Path to the Tally XML export (UTF-16 or UTF-8)}
                            {--write : Persist. Without it this is a dry run and writes nothing.}';

    protected $description = 'Import Tally Sales Invoice vouchers and match them to ERP sales orders (dry run unless --write)';

    public function handle(TallySalesInvoiceImporter $importer): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Not a readable file: {$path}");

            return self::FAILURE;
        }

        try {
            $result = $importer->import((string) file_get_contents($path), (bool) $this->option('write'));
        } catch (TallyXmlUnreadable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Tally voucher', 'Date', 'Party', 'Reference', 'Verdict', 'ERP order'],
            array_map(fn ($r) => [
                $r['voucher_number'],
                $r['voucher_date'],
                $r['party_ledger_name'],
                $r['customer_po_reference'] ?? '—',
                $r['match_state']->label(),
                $r['sales_order_id'] ? '#'.$r['sales_order_id'] : '—',
            ], $result['rows']),
        );

        foreach ($result['rows'] as $row) {
            if ($row['match_detail'] !== null) {
                $this->line("  <comment>{$row['voucher_number']}</comment>: {$row['match_detail']}");
            }
        }

        $this->newLine();
        $this->info("Read {$result['read']} Sales vouchers · matched {$result['matched']} · unmatched {$result['unmatched']}");

        if (! $result['written']) {
            $this->warn('DRY RUN — nothing was written. Re-run with --write once the verdicts above read correctly.');
        }

        return self::SUCCESS;
    }
}
