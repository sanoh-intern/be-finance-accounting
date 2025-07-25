<?php

namespace App\Jobs;

use App\Models\ERP\InvReceipt;
use App\Models\InvLine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncInvoiceLinesMonthlyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting monthly invoice lines synchronization job (payment_doc_date filled, current/last month of this year)...');

        try {
            $currentYear = now()->year;
            $currentMonth = now()->month;
            $previousMonth = now()->subMonthNoOverflow()->month;

            $sqlsrvData = InvReceipt::whereNotNull('payment_doc_date')
                ->whereYear('payment_doc_date', $currentYear)
                ->where(function ($query) use ($currentMonth, $previousMonth) {
                    $query->whereMonth('payment_doc_date', $currentMonth)
                          ->orWhereMonth('payment_doc_date', $previousMonth);
                })
                ->orderByDesc('payment_doc_date')
                ->get();

            $processedCount = 0;
            foreach ($sqlsrvData as $data) {
                InvLine::updateOrCreate(
                    [
                        'po_no' => $data->po_no,
                        'gr_no' => $data->gr_no,
                    ],
                    [
                        'po_no' => $data->po_no,
                        'bp_id' => $data->bp_id,
                        'bp_name' => $data->bp_name,
                        'currency' => $data->currency,
                        'po_type' => $data->po_type,
                        'po_reference' => $data->po_reference,
                        'po_line' => $data->po_line,
                        'po_sequence' => $data->po_sequence,
                        'po_receipt_sequence' => $data->po_receipt_sequence,
                        'actual_receipt_date' => $data->actual_receipt_date,
                        'actual_receipt_year' => $data->actual_receipt_year,
                        'actual_receipt_period' => $data->actual_receipt_period,
                        'receipt_no' => $data->receipt_no,
                        'receipt_line' => $data->receipt_line,
                        'gr_no' => $data->gr_no,
                        'packing_slip' => $data->packing_slip,
                        'item_no' => $data->item_no,
                        'ics_code' => $data->ics_code,
                        'ics_part' => $data->ics_part,
                        'part_no' => $data->part_no,
                        'item_desc' => $data->item_desc,
                        'item_group' => $data->item_group,
                        'item_type' => $data->item_type,
                        'item_type_desc' => $data->item_type_desc,
                        'request_qty' => $data->request_qty,
                        'actual_receipt_qty' => $data->actual_receipt_qty,
                        'approve_qty' => $data->approve_qty,
                        'unit' => $data->unit,
                        'receipt_amount' => $data->receipt_amount,
                        'receipt_unit_price' => $data->receipt_unit_price,
                        'is_final_receipt' => $data->is_final_receipt,
                        'is_confirmed' => $data->is_confirmed,
                        'inv_doc_no' => $data->inv_doc_no,
                        'inv_doc_date' => $data->inv_doc_date,
                        'inv_qty' => $data->inv_qty,
                        'inv_amount' => $data->inv_amount,
                        'inv_supplier_no' => $data->inv_supplier_no,
                        'inv_due_date' => $data->inv_due_date,
                        'payment_doc' => $data->payment_doc,
                        'payment_doc_date' => $data->payment_doc_date
                    ]
                );
                $processedCount++;
            }

            Log::info('Monthly invoice lines synchronized successfully via job. Records processed: ' . $processedCount);

        } catch (\Exception $e) {
            Log::error('An error occurred during monthly synchronization job: ' . $e->getMessage(), ['exception' => $e]);
        }

        Log::info('Monthly synchronization job process finished.');
    }
}
