<?php

namespace App\Http\Controllers\Api\Finance;

use Carbon\Carbon;
use App\Models\Local\Partner;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InvHeader;
use App\Models\InvLine;
use Illuminate\Support\Facades\Mail;
use App\Http\Requests\FinanceInvHeaderUpdateRequest;
use App\Http\Requests\FinancePaymentDocumentRequest;
use App\Http\Requests\FinanceInvHeaderStoreRequest;
use App\Http\Resources\InvHeaderResource;
use App\Mail\InvoiceReadyMail;
use App\Models\InvPph;
use App\Models\InvPpn;
use App\Models\InvDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use App\Mail\InvoiceCreateMail;

class FinanceInvHeaderController extends Controller
{
    public function getInvHeader()
    {
        // Eager load the invLine relationship for all invoice headers
        $invHeaders = InvHeader::with('invLine')->orderBy('created_at', 'desc')->get();
        return InvHeaderResource::collection($invHeaders);
    }

    public function getInvHeaderByBpCode($bp_code)
    {
        $invHeaders = InvHeader::where('bp_code', $bp_code)->get();
        return InvHeaderResource::collection($invHeaders);
    }

    public function getPph()
    {
        $pph = InvPph::select('pph_id', 'pph_description')->get();
        return response()->json($pph);
    }

    public function getPpn()
    {
        $ppn = InvPpn::select('ppn_id', 'ppn_description')->get();
        return response()->json($ppn);
    }

    public function getInvHeaderDetail($inv_no)
    {
        // Fetch InvHeader with related invLine and ppn
        $invHeader = InvHeader::with(['invLine'])->where('inv_no', $inv_no)->first();

        if (!$invHeader) {
            return response()->json([
                'message' => 'Invoice header not found'
            ], 404);
        }

        // Return the InvHeader data including related invLine and ppn
        return new InvHeaderResource($invHeader);
    }

    public function store(FinanceInvHeaderStoreRequest $request)
    {
        $invHeader = DB::transaction(function () use ($request) {
            $request->validated();

            $total_dpp = 0;

            // Gather total DPP from selected inv lines
            $firstInvLine = null;
            foreach ($request->inv_line_detail as $line) {
                $invLine = InvLine::find($line);
                if (!$invLine) {
                    throw new \Exception("InvLine with ID {$line} not found.");
                }
                if (!$firstInvLine) {
                    $firstInvLine = $invLine;
                }
                $total_dpp += $invLine->approve_qty * $invLine->receipt_unit_price;
            }

            // Use bp_id from the first selected InvLine as bp_code for InvHeader
            if (!$firstInvLine) {
                throw new \Exception("No InvLine selected.");
            }
            $bp_code = $firstInvLine->bp_id;

            // Fetch the chosen PPN record
            $ppn = InvPpn::find($request->ppn_id);
            $ppnRate = $ppn ? $ppn->ppn_rate : null;

            if ($ppnRate === null) {
                return response()->json([
                    'message' => 'PPN Rate not found',
                ], 404);
            }

            // Calculate amounts
            $tax_base_amount = $total_dpp;
            $tax_amount      = $tax_base_amount + ($tax_base_amount * $ppnRate);
            $total_amount    = $tax_amount;

            // Create InvHeader
            $invHeader = InvHeader::create([
                'inv_no'          => $request->inv_no,
                'bp_code'         => $bp_code, // Use bp_id from InvLine
                'inv_date'        => $request->inv_date,
                'inv_faktur'      => $request->inv_faktur,
                'inv_faktur_date' => $request->inv_faktur_date,
                'total_dpp'       => $total_dpp,
                'ppn_id'          => $request->ppn_id,
                'tax_base_amount' => $tax_base_amount,
                'tax_amount'      => $tax_amount,
                'total_amount'    => $total_amount,
                'status'          => 'New',
                'created_by'      => Auth::user()->name,
            ]);

            // Handle file uploads
            $files = [];
            if ($request->hasFile('invoice_file')) {
                $files[] = [
                    'type' => 'invoice',
                    'path' => $request->file('invoice_file')
                        ->storeAs('invoices', 'INVOICE_'.$request->inv_no.'.pdf')
                ];
            }
            if ($request->hasFile('fakturpajak_file')) {
                $files[] = [
                    'type' => 'fakturpajak',
                    'path' => $request->file('fakturpajak_file')
                        ->storeAs('faktur', 'FAKTURPAJAK_'.$request->inv_no.'.pdf')
                ];
            }
            if ($request->hasFile('suratjalan_file')) {
                $files[] = [
                    'type' => 'suratjalan',
                    'path' => $request->file('suratjalan_file')
                        ->storeAs('suratjalan', 'SURATJALAN_'.$request->inv_no.'.pdf')
                ];
            }
            if ($request->hasFile('po_file')) {
                $files[] = [
                    'type' => 'po',
                    'path' => $request->file('po_file')
                        ->storeAs('po', 'PO_'.$request->inv_no.'.pdf')
                ];
            }

            // Save file references with type
            foreach ($files as $file) {
                InvDocument::create([
                    'inv_no' => $request->inv_no,
                    'type' => $file['type'],
                    'file' => $file['path']
                ]);
            }

            // Update inv_line references
            foreach ($request->inv_line_detail as $line) {
                InvLine::where('inv_line_id', $line)->update([
                    'inv_supplier_no' => $request->inv_no,
                    'inv_due_date'    => $request->inv_date,
                ]);
            }

            $partner = Partner::where('bp_code', $invHeader->bp_code)->select('adr_line_1')->first();

            // Send email
            Mail::to('neyvagheida@gmail.com')->send(new InvoiceCreateMail([
                'partner_address' => $partner->adr_line_1 ?? '',
                'bp_code'         => $invHeader->bp_code,
                'inv_no'          => $request->inv_no,
                'status'          => $invHeader->status,
                'total_amount'    => $invHeader->total_amount,
                'plan_date'       => $invHeader->plan_date,
            ]));


            return $invHeader;
        });

        // Return the newly created InvHeader outside the transaction
        return new InvHeaderResource($invHeader);
    }

    public function update(FinanceInvHeaderUpdateRequest $request, $inv_no)
    {
        // Check if status is Rejected but no reason provided
        if ($request->status === 'Rejected' && empty($request->reason)) {
            return response()->json([
                'message' => 'Reason is required when rejecting an invoice'
            ], 422);
        }

        $invHeader = DB::transaction(function () use ($request, $inv_no) {
            $request->validated();

            // Eager load invPpn and invPph relationships
            $invHeader = InvHeader::with(['invLine', 'invPpn', 'invPph'])->findOrFail($inv_no);

            // If status is Rejected, skip pph/plan_date logic
            if ($request->status === 'Rejected') {
                if (empty($request->reason)) {
                    throw new \Exception('Reason is required when rejecting an invoice');
                }

                // Update InvHeader without requiring PPH or plan_date
                $invHeader->update([
                    'status'     => $request->status,
                    'reason'     => $request->reason,
                    'updated_by' => Auth::user()->name,
                ]);

                // Remove inv_supplier_no from invLines
                foreach ($invHeader->invLine as $line) {
                    $line->update([
                        'inv_supplier_no' => null,
                        'inv_due_date'    => null,
                    ]);
                }

            } else {
                // 1) Fetch chosen PPH record
                $pph = InvPph::find($request->pph_id);
                $pphRate = $pph ? $pph->pph_rate : null;

                if ($pphRate === null) {
                    return response()->json([
                        'message' => 'PPH Rate not found',
                    ], 404);
                }

                // 2) Manually entered pph_base_amount
                $pphBase = $request->pph_base_amount;

                // 3) Remove (uncheck) lines from invoice if needed
                if (is_array($request->inv_line_remove)) {
                    foreach ($request->inv_line_remove as $lineId) {
                        InvLine::where('inv_line_id', $lineId)->update([
                            'inv_supplier_no' => null,
                        ]);
                    }
                }

                // 4) Recalculate pph_amount
                $pphAmount = $pphBase + ($pphBase * $pphRate);

                // 5) Use the existing "tax_amount" column as "ppn_amount"
                $ppnAmount = $invHeader->tax_amount;

                // 6) total_amount = "ppn_amount minus pph_amount"
                $totalAmount = $ppnAmount - $pphAmount;

                // 7) Update the InvHeader record
                $invHeader->update([
                    'pph_id'          => $request->pph_id,
                    'pph_base_amount' => $pphBase,
                    'pph_amount'      => $pphAmount,
                    'total_amount'    => $totalAmount,
                    'status'          => $request->status,
                    'plan_date'       => $request->plan_date,
                    'reason'          => $request->reason,
                    'updated_by'      => Auth::user()->name,
                ]);
            }

            return $invHeader;
        });

        // 8) Respond based on status
        switch ($request->status) {
            case 'Ready To Payment':
                try {
                    // Generate receipt number with prefix
                    $today = Carbon::parse($invHeader->updated_at)->format('Y-m-d');
                    $receiptCount = InvHeader::whereDate('updated_at', $today)
                        ->where('status', 'Ready To Payment')
                        ->count();
                    $receiptNumber = 'SANOH' . Carbon::parse($invHeader->updated_at)->format('Ymd') . '/' . ($receiptCount + 1);

                    // Get partner address
                    $partner = Partner::where('bp_code', $invHeader->bp_code)->select("adr_line_1")->first();

                    // Get PO numbers from inv_lines
                    $poNumbers = InvLine::where('inv_supplier_no', $inv_no)
                        ->pluck('po_no')
                        ->unique()
                        ->implode(', ');

                    // Calculate tax amount (VAT, 11% of total_dpp)
                    $taxAmount = $invHeader->total_dpp * 0.11;

                    // Get PPh base amount from the model
                    $pphBaseAmount = $invHeader->pph_base_amount;

                    // Calculate PPh amount (pph_base_amount * pph_rate)
                    $pphRate = $invHeader->invPph->pph_rate ?? 0;
                    $pphAmount = $pphBaseAmount * $pphRate;

                    // Generate PDF
                    $pdf = PDF::loadView('printreceipt', [
                        'invHeader'       => $invHeader,
                        'partner_address' => $partner->adr_line_1 ?? '',
                        'po_numbers'      => $poNumbers,
                        'tax_amount'      => $taxAmount,
                        'pph_base_amount' => $pphBaseAmount,
                        'pph_amount'      => $pphAmount,
                    ]);

                    // Define the storage path
                    $filepath = storage_path("app/public/receipts/RECEIPT_{$inv_no}.pdf");

                    // Ensure directory exists
                    if (!file_exists(dirname($filepath))) {
                        mkdir(dirname($filepath), 0777, true);
                    }

                    // Save the PDF
                    $pdf->save($filepath);

                    // Send email with attachment
                    Mail::to('neyvagheida@gmail.com')->send(new InvoiceReadyMail([
                        'partner_address' => $partner->adr_line_1 ?? '',
                        'bp_code'         => $invHeader->bp_code,
                        'inv_no'          => $invHeader->inv_no,
                        'status'          => $invHeader->status,
                        'total_amount'    => $invHeader->total_amount,
                        'plan_date'       => $invHeader->plan_date,
                        'filepath'        => $filepath,
                        'tax_amount'      => $taxAmount,
                        'pph_base_amount' => $pphBaseAmount,
                        'pph_amount'      => $pphAmount,
                    ]));

                    // Update invoice with receipt path and number
                    $invHeader->update([
                        'receipt_path'   => "receipts/RECEIPT_{$inv_no}.pdf",
                        'receipt_number' => $receiptNumber
                    ]);

                    return response()->json([
                        'message'        => "Invoice {$inv_no} Is Ready To Payment",
                        'receipt_path'   => "receipts/RECEIPT_{$inv_no}.pdf",
                        'receipt_number' => $receiptNumber
                    ]);

                } catch (\Exception $e) {
                    return response()->json([
                        'message' => 'Error generating receipt: ' . $e->getMessage()
                    ], 500);
                }
            case 'Rejected':
                return response()->json([
                    'message' => "Invoice {$inv_no} Rejected: {$request->reason}"
                ]);
            default:
                return response()->json([
                    'message' => "Invoice {$inv_no} updated"
                ]);
        }
    }

    public function updateStatusToInProcess($inv_no)
    {
        $invHeader = InvHeader::where('inv_no', $inv_no)->where('status', 'New')->firstOrFail();

        $invHeader->update([
            'status' => 'In Process',
            'updated_by' => Auth::user()->name,
        ]);

        return response()->json([
            'message' => "Invoice {$inv_no} status updated to In Process"
        ]);
    }

    public function uploadPaymentDocument(FinancePaymentDocumentRequest $request, $inv_no)
    {
        $invHeader = InvHeader::where('inv_no', $inv_no)
            ->where('status', 'Ready To Payment')
            ->firstOrFail();

        // Update invoice status and actual_date (no file upload)
        $invHeader->update([
            'status'      => 'Paid',
            'updated_by'  => Auth::user()->name,
            'actual_date' => $request->actual_date,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Invoice {$inv_no} marked as Paid",
            'actual_date' => $request->actual_date
        ]);
    }
}
