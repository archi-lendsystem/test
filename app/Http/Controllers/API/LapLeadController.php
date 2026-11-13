<?php

// use Modules\LapLeadCreation\Models\LapLeadCreation;
// use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

// class LeadController extends Controller
// {
//     use AuthorizesRequests;

//     public function index(Request $request)
//     {
//         $this->authorize('viewAny', LapLeadCreation::class);
//         // return leads list
//     }

//     public function show($leadId)
//     {
//         $lead = LapLeadCreation::where('lead_id', $leadId)->firstOrFail();
//         $this->authorize('view', $lead);
//         return response()->json($lead);
//     }

//     public function store(Request $request)
//     {
//         $this->authorize('create', LapLeadCreation::class);
//         // store logic
//     }

//     public function update(Request $request, $leadId)
//     {
//         $lead = LapLeadCreation::where('lead_id', $leadId)->firstOrFail();
//         $this->authorize('update', $lead);
//         // update logic
//     }

//     public function destroy($leadId)
//     {
//         $lead = LapLeadCreation::where('lead_id', $leadId)->firstOrFail();
//         $this->authorize('delete', $lead);
//         // delete logic
//     }

//     // For assign action
//     public function assign(Request $request, $leadId)
//     {
//         $lead = LapLeadCreation::where('lead_id', $leadId)->firstOrFail();
//         $this->authorize('assign', $lead);
//         // assignment logic
//     }
// }