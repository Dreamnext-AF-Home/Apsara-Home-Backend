<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    private function toFullRegionCode(string $regionCode): string
    {
        $trimmed = trim($regionCode);
        if ($trimmed === '') {
            return '';
        }

        return str_pad(substr($trimmed, 0, 2), 2, '0', STR_PAD_LEFT) . '0000000';
    }

    private function toShortRegionCode(string $regionCode): string
    {
        return substr(trim($regionCode), 0, 2);
    }

    private function toShortProvinceCode(string $provinceCode): string
    {
        return substr(trim($provinceCode), 0, 4);
    }

    private function toShortCityCode(string $cityCode): string
    {
        return substr(trim($cityCode), 0, 6);
    }

    public function regions()
    {
        $regions = DB::table('tbl_address_region')
            ->select([
                'region_id as id',
                'region_name as name',
                'region_code',
            ])
            ->orderBy('region_name')
            ->get()
            ->map(function ($region) {
                return [
                    'id' => $region->id,
                    'code' => $this->toFullRegionCode((string) $region->region_code),
                    'name' => $region->name,
                ];
            })
            ->values();

        return response()->json([
            'data' => $regions,
        ]);
    }

    public function provinces(Request $request)
    {
        $regionCode = trim((string) $request->query('region_code', ''));
        $shortRegionCode = $this->toShortRegionCode($regionCode);

        $query = DB::table('tbl_address_province')
            ->select([
                'prov_id as id',
                'prov_name as name',
                'region_code',
                'prov_code',
                'psgc_code',
            ])
            ->where('prov_status', 1);

        if ($regionCode !== '') {
            $query->where('region_code', $shortRegionCode);
        }

        $provinces = $query
            ->orderBy('prov_name')
            ->get()
            ->map(function ($province) {
                return [
                    'id' => $province->id,
                    'code' => (string) ($province->psgc_code ?: $province->prov_code),
                    'name' => $province->name,
                    'region_code' => $this->toFullRegionCode((string) $province->region_code),
                ];
            })
            ->values();

        return response()->json([
            'data' => $provinces,
        ]);
    }

    public function cities(Request $request)
    {
        $regionCode = trim((string) $request->query('region_code', ''));
        $provinceCode = trim((string) $request->query('province_code', ''));
        $shortRegionCode = $this->toShortRegionCode($regionCode);
        $shortProvinceCode = $this->toShortProvinceCode($provinceCode);

        $query = DB::table('tbl_address_city')
            ->select([
                'city_id as id',
                'city_name as name',
                'region_code',
                'prov_code',
                'city_code',
                'psgc_code',
            ])
            ->where('city_status', 1);

        if ($provinceCode !== '') {
            $query->where('prov_code', $shortProvinceCode);
        } elseif ($regionCode !== '') {
            $query->where('region_code', $shortRegionCode);
        }

        $cities = $query
            ->orderBy('city_name')
            ->get()
            ->map(function ($city) {
                return [
                    'id' => $city->id,
                    'code' => (string) ($city->psgc_code ?: $city->city_code),
                    'name' => $city->name,
                    'region_code' => $this->toFullRegionCode((string) $city->region_code),
                    'prov_code' => (string) ($city->prov_code ?: ''),
                ];
            })
            ->values();

        return response()->json([
            'data' => $cities,
        ]);
    }

    public function barangays(Request $request)
    {
        $cityCode = trim((string) $request->query('city_code', ''));
        $shortCityCode = $this->toShortCityCode($cityCode);

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
            $query->where('city_code', $shortCityCode);
        }

        $barangays = $query
            ->orderBy('barangay_name')
            ->get()
            ->map(function ($barangay) {
                return [
                    'id' => $barangay->id,
                    'code' => $barangay->code,
                    'name' => $barangay->name,
                    'city_code' => $barangay->city_code,
                    'prov_code' => $barangay->prov_code,
                    'region_code' => $this->toFullRegionCode((string) $barangay->region_code),
                ];
            })
            ->values();

        return response()->json([
            'data' => $barangays,
        ]);
    }
}
