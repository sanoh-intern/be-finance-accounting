<?php

namespace App\Http\Controllers\Api\Admin;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\InvHeaderResource;
use App\Http\Requests\SuperAdminInvHeaderUpdateRequest;
use App\Http\Requests\SuperAdminPaymentDocumentRequest;
use App\Models\InvHeader;
use App\Models\InvLine;
use App\Models\InvDocument;
use App\Models\InvPpn;
use App\Models\InvPph;
use App\Models\Local\Partner;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceReadyMail;
use Barryvdh\DomPDF\Facade\Pdf as PDF;

class SuperAdminInvHeaderController extends Controller
{
    public function getInvHeader()
    {
        $invHeaders = InvHeader::all();
        return InvHeaderResource::collection($invHeaders);
    }

    public function getInvHeaderByBpCode($bp_code)
    {
        $invHeaders = InvHeader::where('bp_code', $bp_code)->get();
        return InvHeaderResource::collection($invHeaders);
    }

    public function getPpn()
    {
        $ppn = InvPpn::select('ppn_id', 'ppn_description')->get();
        return response()->json($ppn);
    }

    public function getPph()
    {
        $pph = InvPph::select('pph_id', 'pph_description')->get();
        return response()->json($pph);
    }

    public function getInvHeaderDetail($inv_no)
    {
        // Fetch only the InvHeader model by inv_no, without any relationships
        $invHeader = InvHeader::where('inv_no', $inv_no)->first();

        if (!$invHeader) {
            return response()->json([
                'message' => 'Invoice header not found'
            ], 404);
        }

        // Return the InvHeader data using the resource
        // The resource will only include InvHeader fields, not related data
        return new InvHeaderResource($invHeader);
    }

    public function update(SuperAdminInvHeaderUpdateRequest $request, $inv_no)
    {
        // Check if status is Rejected but no reason provided
        if ($request->status === 'Rejected' && empty($request->reason)) {
            return response()->json([
                'message' => 'Reason is required when rejecting an invoice'
            ], 422);
        }

        $invHeader = DB::transaction(function () use ($request, $inv_no) {
            $request->validated();

            // Load invLines relationship to avoid null on foreach
            $invHeader = InvHeader::with('invLine')->findOrFail($inv_no);

            // If status is Rejected, skip pph/plan_date logic
            if ($request->status === 'Rejected') {
                // Make sure reason is filled
                if (empty($request->reason)) {
                    throw new \Exception('Reason is required when rejecting an invoice');
                }

                // Update InvHeader without requiring pph fields or plan_date
                $invHeader->update([
                    'status'     => $request->status,
                    'reason'     => $request->reason,
                    'updated_by' => Auth::user()->name,
                ]);

                // Remove inv_supplier_no from every related line
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
                $ppnAmount = $invHeader->tax_amount; // (Set during the 'store' step)

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

                    // Calculate tax amount (PPN)
                    $ppnRate = $invHeader->invPpn->ppn_rate ?? 0;
                    $taxAmount = $invHeader->tax_base_amount * $ppnRate;

                    // Calculate PPH amount
                    $pphRate = $invHeader->invPph->pph_rate ?? 0;
                    $pphAmount = $invHeader->pph_base_amount * $pphRate;

                    // Generate PDF
                    $pdf = PDF::loadView('printreceipt', [
                        'invHeader'       => $invHeader,
                        'partner_address' => $partner->adr_line_1 ?? '',
                        'po_numbers'      => $poNumbers,
                        'tax_amount'      => $taxAmount,
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
                        'filepath'        => $filepath
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
                        'message' => 'Error generating receipt: ' . $e->getMessage() . $e->getLine() . $e->getFile()
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

    public function uploadPaymentDocument(SuperAdminPaymentDocumentRequest $request, $inv_no)
    {
        $invHeader = InvHeader::where('inv_no', $inv_no)
            ->where('status', 'Ready To Payment')
            ->firstOrFail();

        // Store the file
        $file = $request->file('payment_file');
        $filePath = $file->storeAs('public/payments', 'PAYMENT_'.$inv_no.'.pdf');

        // Save file reference
        InvDocument::create([
            'inv_no' => $inv_no,
            'type'   => 'payment',
            'file'   => $filePath
        ]);

        // Update invoice status
        $invHeader->update([
            'status'       => 'Paid',
            'updated_by'   => Auth::user()->name,
            'actual_date'  => now()
        ]);

        return response()->json([
            'success'      => true,
            'message'      => "Payment document uploaded and invoice {$inv_no} marked as Paid",
            'payment_path' => "payments/PAYMENT_{$inv_no}.pdf"
        ]);
    }
}
