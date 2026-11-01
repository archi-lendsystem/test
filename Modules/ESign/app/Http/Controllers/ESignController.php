<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Modules\ESign\Models\ESignDocument;
use Illuminate\Support\Facades\Log;

class ESignController extends Controller
{
    private const LOG = '[ESign] ';

    public function status($loanId)
    {
        $document = ESignDocument::where(
            'customer_lending_id',
            $loanId
        )
        ->latest()
        ->first();


        if(!$document){

          Log::channel('payday_audit')->warning(self::LOG . 'status_not_generated', [
                'customer_lending_id' => $loanId,
            ]);
            return response()->json([
                'document_id'=>null,
                'status'=>'not_generated',
                'can_sign'=>false
            ]);
        }

          Log::channel('payday_audit')->info(self::LOG . 'status_fetched', [
            'customer_lending_id' => $loanId,
            'document_id' => $document->id,
            'status' => $document->status,
        ]);


        return response()->json([
            'document_id'=>$document->id,
            'status'=>$document->status,
            'esign_url'=>$document->borrower_sign_url,
            'can_sign'=>$document->status !== 'signed'
        ]);
    }
}