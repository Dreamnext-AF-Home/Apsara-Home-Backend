<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function regions()
    {
        $regions = DB::table('tbl_address_region')
            ->select([
                'region_id as id',
                'region_code as code',
                'region_name as name',
            ])
            ->orderBy('region_name')
            ->get();

        return response()->json([
            'data' => $regions,
        ]);
    }

    public function provinces(Request $request)
    {
        $regionCode = trim((string) $request->query('region_code', ''));

        $query = DB::table('tbl_address_province')
            ->select([
                'prov_id as id',
                'prov_code as code',
                'prov_name as name',
                'region_code',
            ])
            ->where('prov_status', 1);

        if ($regionCode !== '') {
            $query->where('region_code', $regionCode);
        }

        $provinces = $query
            ->orderBy('prov_name')
            ->get();

        return response()->json([
            'data' => $provinces,
        ]);
    }

    public function cities(Request $request)
    {
        $regionCode = trim((string) $request->query('region_code', ''));
        $provinceCode = trim((string) $request->query('province_code', ''));

        $query = DB::table('tbl_address_city')
            ->select([
                'city_id as id',
                'city_code as code',
                'city_name as name',
                'region_code',
                'prov_code',
            ])
            ->where('city_status', 1);

        if ($provinceCode !== '') {
            $query->where('prov_code', $provinceCode);
        } elseif ($regionCode !== '') {
            $query->where('region_code', $regionCode);
        }

        $cities = $query
            ->orderBy('city_name')
            ->get();

        return response()->json([
            'data' => $cities,
        ]);
    }

    public function barangays(Request $request)
    {
        $cityCode = trim((string) $request->query('city_code', ''));

        $query = DB::table('tbl_address_barangay')
            ->select([
                'barangay_id as id',
                'barangay_code as code',
                'barangay_name as name',
                'city_code',
                'prov_code',
                'region_code',
            ])
            ->where('barangay_status', 1);

        if ($cityCode !== '') {
            $query->where('city_code', $cityCode);
        }

        $barangays = $query
            ->orderBy('barangay_name')
            ->get();

        return response()->json([
            'data' => $barangays,
        ]);
    }
}
