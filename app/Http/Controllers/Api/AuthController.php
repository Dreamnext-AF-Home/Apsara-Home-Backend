<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name'            => 'required|string|max:255',
            'last_name'             => 'required|string|max:255',
            'middle_name'           => 'nullable|string|max:255',
            'name'                  => 'required|string|max:255',
            'email'                 => ['required', 'email', Rule::unique('tbl_customer', 'c_email')],
            'username'              => ['required', 'string', 'max:255', Rule::unique('tbl_customer', 'c_username')],
            'phone'                 => 'nullable|string|max:20',
            'birth_date'            => 'nullable|date',
            'gender'                => 'nullable|in:male,female,other',
            'occupation'            => 'nullable|string|max:155',
            'work_location'         => 'nullable|in:local,overseas',
            'country'               => 'nullable|string|max:45',
            'referred_by'           => 'nullable|string|max:255',
            'password'              => 'required|string|min:8|confirmed',
            'address'               => 'nullable|string|max:500',
            'barangay'              => 'nullable|string|max:255',
            'city'                  => 'nullable|string|max:255',
            'province'              => 'nullable|string|max:255',
            'region'                => 'nullable|string|max:255',
            'zip_code'              => 'nullable|string|max:20',
        ]);

        $customer = Customer::create([
            'c_fname'        => $validated['first_name'],
            'c_lname'        => $validated['last_name'],
            'c_mname'        => $validated['middle_name'] ?? null,
            'c_username'     => $validated['username'],
            'c_email'        => $validated['email'],
            'c_mobile'       => $validated['phone'] ?? '0',
            'c_bdate'        => $validated['birth_date'] ?? null,
            'c_gender'       => $this->mapGenderToInt($validated['gender'] ?? null),
            'c_occupation'   => $validated['occupation'] ?? 'None',
            'c_country'      => $validated['country'] ?? (($validated['work_location'] ?? 'local') === 'overseas' ? 'Overseas' : 'Philippines'),
            'c_password'     => Hash::make($validated['password']),
            'c_password_pin' => $validated['password'],
            'c_date_started' => now(),
            'c_address'      => $validated['address'] ?? null,
            'c_barangay'     => $validated['barangay'] ?? null,
            'c_city'         => $validated['city'] ?? null,
            'c_province'     => $validated['province'] ?? null,
            'c_region'       => $validated['region'] ?? null,
            'c_zipcode'      => $validated['zip_code'] ?? null,
        ]);

        $token = $customer->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'user' => $this->transformCustomer($customer),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = trim($request->email);
        $customer = Customer::query()
            ->where('c_email', $identifier)
            ->orWhere('c_username', $identifier)
            ->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $password = (string) $request->password;
        $hashMatch = Hash::check($password, (string) $customer->c_password);
        $legacyDirectMatch = hash_equals((string) $customer->c_password, $password);
        $pinMatch = hash_equals((string) $customer->c_password_pin, $password);

        if (! $hashMatch && ! $legacyDirectMatch && ! $pinMatch) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $customer->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $this->transformCustomer($customer),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        $customer = $request->user();

        return response()->json($this->transformCustomer($customer));
    }

    public function updateMe(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('tbl_customer', 'c_username')->ignore($customer->c_userid, 'c_userid'),
            ],
            'phone' => 'nullable|string|max:25',
        ]);

        [$firstName, $middleName, $lastName] = $this->splitName((string) $validated['name']);

        $customer->c_fname = $firstName;
        $customer->c_mname = $middleName;
        $customer->c_lname = $lastName;

        if (array_key_exists('username', $validated) && $validated['username'] !== null) {
            $customer->c_username = $validated['username'];
        }

        if (array_key_exists('phone', $validated) && $validated['phone'] !== null) {
            $customer->c_mobile = $validated['phone'];
        }

        $customer->save();

        return response()->json($this->transformCustomer($customer));
    }

    private function transformCustomer(Customer $customer): array
    {
        $fullName = trim(implode(' ', array_filter([
            $customer->c_fname,
            $customer->c_mname,
            $customer->c_lname,
        ])));

        return [
            'id' => (int) $customer->c_userid,
            'name' => $fullName,
            'email' => $customer->c_email,
            'username' => $customer->c_username,
            'phone' => $customer->c_mobile,
        ];
    }

    private function splitName(string $name): array
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return ['', null, null];
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], null, null];
        }

        if (count($parts) === 2) {
            return [$parts[0], null, $parts[1]];
        }

        $first = array_shift($parts);
        $last = array_pop($parts);
        $middle = implode(' ', $parts);

        return [$first ?? '', $middle !== '' ? $middle : null, $last ?? null];
    }

    private function mapRole(int $level): string
    {
    return match ($level) {
            1 => 'super_admin',
            2 => 'admin',
            3 => 'csr',
            4 => 'web_content',
            default => 'staff',
    } ;
}

    private function mapGenderToInt(?string $gender): int
    {
        return match ($gender) {
            'male' => 1,
            'female' => 2,
            'other' => 3,
            default => 0,
        };
    }

}
