<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Enums\GLAccountType;
use App\Modules\Finance\Models\Enums\JournalEntryStatus;
use App\Modules\Finance\Models\JournalEntryLine;

/**
 * All reports are computed from posted journal entry lines only — a draft
 * entry has no accounting effect. "balance" throughout is signed
 * (total_debit - total_credit): positive for a normal debit balance
 * (asset/expense), negative for a normal credit balance
 * (liability/equity/revenue). P&L and the balance sheet flip sign where
 * needed so the numbers they show read as plain positive amounts.
 */
class FinancialReportService
{
    /**
     * @return array<int, array{account_id: int, code: string, name: string, type: string, total_debit: string, total_credit: string, balance: string}>
     */
    public function trialBalance(): array
    {
        $rows = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('gl_accounts', 'gl_accounts.id', '=', 'journal_entry_lines.gl_account_id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->selectRaw('
                gl_accounts.id as account_id,
                gl_accounts.code as code,
                gl_accounts.name as name,
                gl_accounts.type as type,
                SUM(journal_entry_lines.debit) as total_debit,
                SUM(journal_entry_lines.credit) as total_credit
            ')
            ->groupBy('gl_accounts.id', 'gl_accounts.code', 'gl_accounts.name', 'gl_accounts.type')
            ->orderBy('gl_accounts.code')
            ->get();

        return $rows->map(function ($row) {
            $totalDebit = bcadd((string) $row->total_debit, '0', 4);
            $totalCredit = bcadd((string) $row->total_credit, '0', 4);

            return [
                'account_id' => $row->account_id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => bcsub($totalDebit, $totalCredit, 4),
            ];
        })->all();
    }

    /**
     * @return array{lines: array<int, array{account_id: int, code: string, name: string, amount: string}>, total_revenue: string, total_expense: string, net_income: string}
     */
    public function profitAndLoss(): array
    {
        $trialBalance = $this->trialBalance();

        $revenueLines = [];
        $totalRevenue = '0.0000';
        $expenseLines = [];
        $totalExpense = '0.0000';

        foreach ($trialBalance as $row) {
            if ($row['type'] === GLAccountType::Revenue->value) {
                // Revenue is credit-normal: a positive revenue balance shows
                // up as a negative signed balance, flip it back to positive.
                $amount = bcmul($row['balance'], '-1', 4);
                $totalRevenue = bcadd($totalRevenue, $amount, 4);
                $revenueLines[] = ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'amount' => $amount];
            } elseif ($row['type'] === GLAccountType::Expense->value) {
                $amount = $row['balance'];
                $totalExpense = bcadd($totalExpense, $amount, 4);
                $expenseLines[] = ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'amount' => $amount];
            }
        }

        return [
            'revenue' => $revenueLines,
            'expense' => $expenseLines,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_income' => bcsub($totalRevenue, $totalExpense, 4),
        ];
    }

    /**
     * @return array{assets: array<int, array{account_id: int, code: string, name: string, amount: string}>, liabilities: array<int, array{account_id: int, code: string, name: string, amount: string}>, equity: array<int, array{account_id: int, code: string, name: string, amount: string}>, total_assets: string, total_liabilities: string, total_equity: string, net_income: string}
     */
    public function balanceSheet(): array
    {
        $trialBalance = $this->trialBalance();

        $assets = [];
        $totalAssets = '0.0000';
        $liabilities = [];
        $totalLiabilities = '0.0000';
        $equity = [];
        $totalEquity = '0.0000';

        foreach ($trialBalance as $row) {
            if ($row['type'] === GLAccountType::Asset->value) {
                $totalAssets = bcadd($totalAssets, $row['balance'], 4);
                $assets[] = ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'amount' => $row['balance']];
            } elseif ($row['type'] === GLAccountType::Liability->value) {
                $amount = bcmul($row['balance'], '-1', 4);
                $totalLiabilities = bcadd($totalLiabilities, $amount, 4);
                $liabilities[] = ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'amount' => $amount];
            } elseif ($row['type'] === GLAccountType::Equity->value) {
                $amount = bcmul($row['balance'], '-1', 4);
                $totalEquity = bcadd($totalEquity, $amount, 4);
                $equity[] = ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'amount' => $amount];
            }
        }

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            // Not yet closed to equity by a period-end entry — shown as a
            // memo line so assets = liabilities + equity + net_income holds.
            'net_income' => $this->profitAndLoss()['net_income'],
        ];
    }
}
