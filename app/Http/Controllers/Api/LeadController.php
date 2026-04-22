<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LeadController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'         => 'nullable|string|max:255',
            'address'      => 'nullable|string',
            'website'      => 'nullable|string|max:500',
            'phone'        => 'nullable|string|max:50',
            'description'  => 'nullable|string',
            'rating'       => 'nullable|numeric|between:0,9.9',
            'reviews'      => 'nullable|integer',
            'category'     => 'nullable|string|max:255',
            'keywords'     => 'nullable|string',
            'price_level'  => 'nullable|string|max:10',
            'opening_hours'=> 'nullable|array',
            'email'        => 'nullable|string',
            'facebook'     => 'nullable|string|max:500',
            'twitter'      => 'nullable|string|max:500',
            'instagram'    => 'nullable|string|max:500',
            'contact'      => 'nullable|string|max:500',
            'search_query' => 'nullable|string|max:255',
            'location'     => 'nullable|string|max:255',
            'searched'     => 'nullable|in:YES,NO',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $duplicate = $this->findDuplicate($data);
        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate lead. A lead with the same information already exists.',
                'existing_id' => $duplicate->id,
            ], 409);
        }

        if (isset($data['opening_hours'])) {
            $data['opening_hours'] = json_encode($data['opening_hours']);
        }

        $id = DB::table('tbl_leads')->insertGetId(array_merge($data, [
            'created_at' => now(),
        ]));

        $lead = DB::table('tbl_leads')->where('id', $id)->first();

        return response()->json([
            'success' => true,
            'message' => 'Lead saved successfully.',
            'data'    => $lead,
        ], 201);
    }

    public function storeBatch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'leads'                    => 'required|array|min:1',
            'leads.*.name'             => 'nullable|string|max:255',
            'leads.*.address'          => 'nullable|string',
            'leads.*.website'          => 'nullable|string|max:500',
            'leads.*.phone'            => 'nullable|string|max:50',
            'leads.*.description'      => 'nullable|string',
            'leads.*.rating'           => 'nullable|numeric|between:0,9.9',
            'leads.*.reviews'          => 'nullable|integer',
            'leads.*.category'         => 'nullable|string|max:255',
            'leads.*.keywords'         => 'nullable|string',
            'leads.*.price_level'      => 'nullable|string|max:10',
            'leads.*.opening_hours'    => 'nullable|array',
            'leads.*.email'            => 'nullable|string',
            'leads.*.facebook'         => 'nullable|string|max:500',
            'leads.*.twitter'          => 'nullable|string|max:500',
            'leads.*.instagram'        => 'nullable|string|max:500',
            'leads.*.contact'          => 'nullable|string|max:500',
            'leads.*.search_query'     => 'nullable|string|max:255',
            'leads.*.location'         => 'nullable|string|max:255',
            'leads.*.searched'         => 'nullable|in:YES,NO',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $now = now();
        $toInsert  = [];
        $skipped   = [];

        foreach ($request->input('leads') as $index => $lead) {
            $duplicate = $this->findDuplicate($lead);
            if ($duplicate) {
                $skipped[] = [
                    'index'       => $index,
                    'name'        => $lead['name'] ?? null,
                    'existing_id' => $duplicate->id,
                    'reason'      => 'Duplicate lead already exists.',
                ];
                continue;
            }

            if (isset($lead['opening_hours'])) {
                $lead['opening_hours'] = json_encode($lead['opening_hours']);
            }
            $lead['created_at'] = $now;
            $toInsert[] = $lead;
        }

        if (!empty($toInsert)) {
            DB::table('tbl_leads')->insert($toInsert);
        }

        return response()->json([
            'success'  => true,
            'message'  => count($toInsert) . ' lead(s) saved, ' . count($skipped) . ' duplicate(s) skipped.',
            'inserted' => count($toInsert),
            'skipped'  => $skipped,
        ], 201);
    }

    private function findDuplicate(array $data): ?object
    {
        $query = DB::table('tbl_leads');

        $fields = ['name', 'phone', 'email', 'website', 'address', 'facebook', 'instagram', 'twitter'];
        $hasAnyField = false;

        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            if (!empty($value)) {
                $query->where($field, $value);
                $hasAnyField = true;
            } else {
                $query->whereNull($field);
            }
        }

        if (!$hasAnyField) {
            return null;
        }

        return $query->first();
    }
}
