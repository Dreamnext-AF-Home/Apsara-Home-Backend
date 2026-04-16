<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;

class CustomerAddressController extends Controller
{
    public function index(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $this->ensurePrimaryAddressExists($customer);

        $addresses = CustomerAddress::query()
            ->where('a_cid', (int) $customer->c_userid)
            ->orderByDesc('a_shipping_status')
            ->orderByDesc('a_id')
            ->get()
            ->map(fn (CustomerAddress $address): array => $this->transformAddress($address))
            ->values();

        return response()->json([
            'addresses' => $addresses,
        ]);
    }

    public function store(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $validated = $request->validate([
            'full_name' => 'required|string|max:85',
            'phone' => 'required|string|max:25',
            'address' => 'required|string|max:255',
            'region' => 'required|string|max:35',
            'province' => 'nullable|string|max:45',
            'city' => 'required|string|max:55',
            'barangay' => 'required|string|max:55',
            'zip_code' => 'nullable|string|max:10',
            'address_type' => 'nullable|string|max:10',
            'notes' => 'nullable|string',
            'set_default' => 'nullable|boolean',
        ]);

        $hasExisting = CustomerAddress::query()
            ->where('a_cid', (int) $customer->c_userid)
            ->exists();

        $setDefault = (bool) ($validated['set_default'] ?? false) || !$hasExisting;

        if ($setDefault) {
            CustomerAddress::query()
                ->where('a_cid', (int) $customer->c_userid)
                ->update([
                    'a_shipping_status' => 0,
                    'a_billing_status' => 0,
                ]);
        }

        $address = CustomerAddress::create([
            'a_cid' => (int) $customer->c_userid,
            'a_fullname' => trim((string) $validated['full_name']),
            'a_mobile' => trim((string) $validated['phone']),
            'a_mobile_code' => '0',
            'a_address' => trim((string) $validated['address']),
            'a_country' => '175',
            'a_region' => trim((string) $validated['region']),
            'a_province' => trim((string) ($validated['province'] ?? $validated['region'])),
            'a_city' => trim((string) $validated['city']),
            'a_barangay' => trim((string) $validated['barangay']),
            'a_region_code' => null,
            'a_province_code' => null,
            'a_city_code' => null,
            'a_barangay_code' => null,
            'a_shipping_status' => $setDefault ? 1 : 0,
            'a_billing_status' => $setDefault ? 1 : 0,
            'a_postcode' => isset($validated['zip_code']) ? trim((string) $validated['zip_code']) : null,
            'a_address_type' => isset($validated['address_type']) && $validated['address_type'] !== ''
                ? trim((string) $validated['address_type'])
                : 'Home',
            'a_notes' => isset($validated['notes']) ? trim((string) $validated['notes']) : '',
        ]);

        if ($setDefault) {
            $this->syncCustomerPrimaryAddress($customer, $address);
        }

        return response()->json([
            'message' => 'Shipping address added.',
            'address' => $this->transformAddress($address),
        ], 201);
    }

    public function setDefault(Request $request, int $id)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $address = CustomerAddress::query()
            ->where('a_cid', (int) $customer->c_userid)
            ->where('a_id', $id)
            ->firstOrFail();

        CustomerAddress::query()
            ->where('a_cid', (int) $customer->c_userid)
            ->update([
                'a_shipping_status' => 0,
                'a_billing_status' => 0,
            ]);

        $address->a_shipping_status = 1;
        $address->a_billing_status = 1;
        $address->save();

        $this->syncCustomerPrimaryAddress($customer, $address);

        return response()->json([
            'message' => 'Default shipping address updated.',
            'address' => $this->transformAddress($address),
        ]);
    }

    private function ensurePrimaryAddressExists(Customer $customer): void
    {
        $street = trim((string) ($customer->c_address ?? ''));
        $region = trim((string) ($customer->c_region ?? ''));
        $province = trim((string) ($customer->c_province ?? $customer->c_region ?? ''));
        $city = trim((string) ($customer->c_city ?? ''));
        $barangay = trim((string) ($customer->c_barangay ?? ''));

        if ($street === '') {
            return;
        }

        $query = CustomerAddress::query()
            ->where('a_cid', (int) $customer->c_userid)
            ->where('a_address', $street);

        if ($region !== '') {
            $query->where('a_region', $region);
        }
        if ($province !== '') {
            $query->where('a_province', $province);
        }
        if ($city !== '') {
            $query->where('a_city', $city);
        }
        if ($barangay !== '') {
            $query->where('a_barangay', $barangay);
        }

        $exists = $query->exists();

        if ($exists) {
            return;
        }

        $hasDefault = CustomerAddress::query()
            ->where('a_cid', (int) $customer->c_userid)
            ->where('a_shipping_status', 1)
            ->exists();

        CustomerAddress::create([
            'a_cid' => (int) $customer->c_userid,
            'a_fullname' => $this->customerName($customer),
            'a_mobile' => (string) ($customer->c_mobile ?? '0'),
            'a_mobile_code' => '0',
            'a_address' => $street,
            'a_country' => $this->normalizeAddressCountryValue($customer->c_country ?? null),
            'a_region' => $region,
            'a_province' => $province,
            'a_city' => $city,
            'a_barangay' => $barangay,
            'a_region_code' => (string) ($customer->c_region_code ?? '') ?: null,
            'a_province_code' => (string) ($customer->c_province_code ?? '') ?: null,
            'a_city_code' => (string) ($customer->c_city_code ?? '') ?: null,
            'a_barangay_code' => (string) ($customer->c_barangay_code ?? '') ?: null,
            'a_shipping_status' => $hasDefault ? 0 : 1,
            'a_billing_status' => $hasDefault ? 0 : 1,
            'a_postcode' => (string) ($customer->c_zipcode ?? '') ?: null,
            'a_address_type' => 'Home',
            'a_notes' => '',
        ]);
    }

    private function normalizeAddressCountryValue(?string $country): string
    {
        $value = trim((string) $country);

        if ($value === '' || strcasecmp($value, 'philippines') === 0 || strtoupper($value) === 'PH') {
            return '175';
        }

        if (ctype_digit($value)) {
            return $value;
        }

        return '0';
    }

    private function syncCustomerPrimaryAddress(Customer $customer, CustomerAddress $address): void
    {
        $customer->c_address = $address->a_address;
        $customer->c_barangay = $address->a_barangay;
        $customer->c_city = $address->a_city;
        $customer->c_province = $address->a_province;
        $customer->c_region = $address->a_region;
        $customer->c_zipcode = $address->a_postcode;
        $customer->save();
    }

    private function transformAddress(CustomerAddress $address): array
    {
        $parts = array_values(array_filter([
            (string) $address->a_address,
            (string) $address->a_barangay,
            (string) $address->a_city,
            (string) $address->a_province,
            (string) $address->a_region,
            (string) ($address->a_postcode ?? ''),
        ]));

        return [
            'id' => (int) $address->a_id,
            'full_name' => (string) $address->a_fullname,
            'phone' => (string) $address->a_mobile,
            'address' => (string) $address->a_address,
            'region' => (string) ($address->a_region ?? ''),
            'province' => (string) ($address->a_province ?? ''),
            'city' => (string) ($address->a_city ?? ''),
            'barangay' => (string) $address->a_barangay,
            'zip_code' => (string) ($address->a_postcode ?? ''),
            'address_type' => (string) ($address->a_address_type ?? 'Home'),
            'notes' => (string) ($address->a_notes ?? ''),
            'is_default' => (int) ($address->a_shipping_status ?? 0) === 1,
            'full_address' => implode(', ', $parts),
        ];
    }

    private function customerName(Customer $customer): string
    {
        $name = trim(implode(' ', array_filter([
            $customer->c_fname,
            $customer->c_mname,
            $customer->c_lname,
        ])));

        return $name !== '' ? $name : (string) ($customer->c_username ?? 'Customer');
    }
}
