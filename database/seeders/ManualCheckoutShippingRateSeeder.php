<?php

namespace Database\Seeders;

use App\Models\ShippingRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ManualCheckoutShippingRateSeeder extends Seeder
{
    public function run(): void
    {
        $ratesByProvince = [
            'Pampanga' => [
                ['city' => 'Angeles City', 'fee' => 1000],
                ['city' => 'San Fernando City', 'fee' => 1000],
                ['city' => 'Mabalacat City', 'fee' => 1800],
                ['city' => 'Apalit', 'fee' => 1000],
                ['city' => 'Arayat', 'fee' => 1500],
                ['city' => 'Bacolor', 'fee' => 1500],
                ['city' => 'Candaba', 'fee' => 1500],
                ['city' => 'Floridablanca', 'fee' => 1500],
                ['city' => 'Guagua', 'fee' => 1500],
                ['city' => 'Lubao', 'fee' => 1500],
                ['city' => 'Macabebe', 'fee' => 1500],
                ['city' => 'Magalang', 'fee' => 1500],
                ['city' => 'Masantol', 'fee' => 1000],
                ['city' => 'Mexico', 'fee' => 1000],
                ['city' => 'Minalin', 'fee' => 1500],
                ['city' => 'Porac', 'fee' => 1500],
                ['city' => 'San Luis', 'fee' => 1000],
                ['city' => 'San Simon', 'fee' => 1000],
                ['city' => 'Santa Ana', 'fee' => 1000],
                ['city' => 'Santa Rita', 'fee' => 1000],
                ['city' => 'Santo Tomas', 'fee' => 1000],
                ['city' => 'Sasmuan', 'fee' => 1500],
            ],
            'Metro Manila (NCR)' => [
                ['city' => 'Caloocan', 'fee' => 500],
                ['city' => 'Las Pinas', 'fee' => 1000],
                ['city' => 'Makati', 'fee' => 500],
                ['city' => 'Malabon', 'fee' => 500],
                ['city' => 'Mandaluyong', 'fee' => 500],
                ['city' => 'Manila', 'fee' => 500],
                ['city' => 'Marikina', 'fee' => 500],
                ['city' => 'Muntinlupa', 'fee' => 1000],
                ['city' => 'Navotas', 'fee' => 500],
                ['city' => 'Paranaque', 'fee' => 1000],
                ['city' => 'Pasay', 'fee' => 500],
                ['city' => 'Pasig', 'fee' => 500],
                ['city' => 'Quezon City', 'fee' => 500],
                ['city' => 'San Juan', 'fee' => 500],
                ['city' => 'Taguig', 'fee' => 500],
                ['city' => 'Valenzuela', 'fee' => 500],
                ['city' => 'Pateros', 'fee' => 500],
            ],
            'Bulacan' => [
                ['city' => 'Malolos City', 'fee' => 500],
                ['city' => 'Meycauayan City', 'fee' => 500],
                ['city' => 'San Jose Del Monte City', 'fee' => 500],
                ['city' => 'Angat', 'fee' => 500],
                ['city' => 'Balagtas', 'fee' => 500],
                ['city' => 'Baliuag', 'fee' => 500],
                ['city' => 'Bocaue', 'fee' => 500],
                ['city' => 'Bulakan', 'fee' => 500],
                ['city' => 'Bustos', 'fee' => 500],
                ['city' => 'Calumpit', 'fee' => 500],
                ['city' => 'Dona Remedios Trinidad', 'fee' => 1000],
                ['city' => 'Guiguinto', 'fee' => 500],
                ['city' => 'Hagonoy', 'fee' => 500],
                ['city' => 'Marilao', 'fee' => 500],
                ['city' => 'Norzagaray', 'fee' => 500],
                ['city' => 'Obando', 'fee' => 500],
                ['city' => 'Pandi', 'fee' => 500],
                ['city' => 'Paombong', 'fee' => 500],
                ['city' => 'Plaridel', 'fee' => 500],
                ['city' => 'Pulilan', 'fee' => 500],
                ['city' => 'San Ildefonso', 'fee' => 1500],
                ['city' => 'San Miguel', 'fee' => 1500],
                ['city' => 'San Rafael', 'fee' => 500],
                ['city' => 'Santa Maria', 'fee' => 500],
            ],
            'Rizal' => [
                ['city' => 'Angono', 'fee' => 1000],
                ['city' => 'Baras', 'fee' => 1500],
                ['city' => 'Binangonan', 'fee' => 1000],
                ['city' => 'Cainta', 'fee' => 500],
                ['city' => 'Cardona', 'fee' => 1500],
                ['city' => 'Jalajala', 'fee' => 2000],
                ['city' => 'Morong', 'fee' => 1500],
                ['city' => 'Pililla', 'fee' => 2000],
                ['city' => 'Rodriguez (Montalban)', 'fee' => 1000],
                ['city' => 'San Mateo', 'fee' => 500],
                ['city' => 'Tanay', 'fee' => 1000],
                ['city' => 'Taytay', 'fee' => 500],
                ['city' => 'Teresa', 'fee' => 1000],
            ],
            'Cavite' => [
                ['city' => 'Bacoor City', 'fee' => 1000],
                ['city' => 'Cavite City', 'fee' => 1000],
                ['city' => 'Dasmarinas City', 'fee' => 1000],
                ['city' => 'General Trias City', 'fee' => 1000],
                ['city' => 'Imus City', 'fee' => 1000],
                ['city' => 'Tagaytay City', 'fee' => 2500],
                ['city' => 'Trece Martires City', 'fee' => 1000],
                ['city' => 'Alfonso', 'fee' => 2500],
                ['city' => 'Amadeo', 'fee' => 1000],
                ['city' => 'Carmona City', 'fee' => 1000],
                ['city' => 'General Mariano Alvarez', 'fee' => 1000],
                ['city' => 'Indang', 'fee' => 1500],
                ['city' => 'Kawit', 'fee' => 1000],
                ['city' => 'Magallanes', 'fee' => 1500],
                ['city' => 'Maragondon', 'fee' => 2000],
                ['city' => 'Mendez (Mendez-Nunez)', 'fee' => 2500],
                ['city' => 'Naic', 'fee' => 1500],
                ['city' => 'Noveleta', 'fee' => 1000],
                ['city' => 'Rosario', 'fee' => 1000],
                ['city' => 'Silang', 'fee' => 1500],
                ['city' => 'Tanza', 'fee' => 1000],
                ['city' => 'Ternate', 'fee' => 2000],
            ],
            'Laguna' => [
                ['city' => 'Binan City', 'fee' => 1000],
                ['city' => 'Cabuyao City', 'fee' => 1000],
                ['city' => 'Calamba City', 'fee' => 1000],
                ['city' => 'San Pablo City', 'fee' => 2500],
                ['city' => 'San Pedro City', 'fee' => 1000],
                ['city' => 'Santa Rosa City', 'fee' => 1000],
                ['city' => 'Alaminos', 'fee' => 2500],
                ['city' => 'Bay', 'fee' => 1500],
                ['city' => 'Calauan', 'fee' => 2500],
                ['city' => 'Cavinti', 'fee' => 2300],
                ['city' => 'Famy', 'fee' => 2000],
                ['city' => 'Kalayaan', 'fee' => 2000],
                ['city' => 'Liliw', 'fee' => 2000],
                ['city' => 'Los Banos', 'fee' => 1500],
                ['city' => 'Luisiana', 'fee' => 2000],
                ['city' => 'Lumban', 'fee' => 3500],
                ['city' => 'Mabitac', 'fee' => 2000],
                ['city' => 'Magdalena', 'fee' => 2000],
                ['city' => 'Majayjay', 'fee' => 2000],
                ['city' => 'Nagcarlan', 'fee' => 2000],
                ['city' => 'Paete', 'fee' => 2000],
                ['city' => 'Pagsanjan', 'fee' => 2000],
                ['city' => 'Pakil', 'fee' => 2000],
                ['city' => 'Pangil', 'fee' => 2000],
                ['city' => 'Pila', 'fee' => 2000],
                ['city' => 'Rizal', 'fee' => 1500],
                ['city' => 'Santa Cruz', 'fee' => 2000],
                ['city' => 'Santa Maria', 'fee' => 3500],
                ['city' => 'Siniloan', 'fee' => 2500],
                ['city' => 'Victoria', 'fee' => 2500],
            ],
            'Batangas' => [
                ['city' => 'Batangas City', 'fee' => 3500],
                ['city' => 'Lipa City', 'fee' => 2500],
                ['city' => 'Tanauan City', 'fee' => 2000],
                ['city' => 'Agoncillo', 'fee' => 2500],
                ['city' => 'Alitagtag', 'fee' => 2500],
                ['city' => 'Balayan', 'fee' => 3500],
                ['city' => 'Balete', 'fee' => 2500],
                ['city' => 'Bauan', 'fee' => 2500],
                ['city' => 'Calaca', 'fee' => 3500],
                ['city' => 'Calatagan', 'fee' => 3500],
                ['city' => 'Cuenca', 'fee' => 2000],
                ['city' => 'Ibaan', 'fee' => 2000],
                ['city' => 'Laurel', 'fee' => 2500],
                ['city' => 'Lemery', 'fee' => 2500],
                ['city' => 'Lian', 'fee' => 3500],
                ['city' => 'Lobo', 'fee' => 2000],
                ['city' => 'Mabini', 'fee' => 2500],
                ['city' => 'Malvar', 'fee' => 2000],
                ['city' => 'Mataas Na Kahoy', 'fee' => 2000],
                ['city' => 'Nasugbu', 'fee' => 3500],
                ['city' => 'Padre Garcia', 'fee' => 2500],
                ['city' => 'Rosario', 'fee' => 2500],
                ['city' => 'San Jose', 'fee' => 2000],
                ['city' => 'San Juan', 'fee' => 3500],
                ['city' => 'San Luis', 'fee' => 2500],
                ['city' => 'San Nicolas', 'fee' => 2500],
                ['city' => 'San Pascual', 'fee' => 2500],
                ['city' => 'Santa Teresita', 'fee' => 2500],
                ['city' => 'Santo Tomas', 'fee' => 2000],
                ['city' => 'Taal', 'fee' => 2500],
                ['city' => 'Talisay', 'fee' => 2500],
                ['city' => 'Tuy', 'fee' => 3000],
            ],
        ];

        foreach ($ratesByProvince as $province => $rates) {
            foreach ($rates as $rate) {
                ShippingRate::query()->updateOrCreate(
                    [
                        'sr_province_key' => $this->normalizeKey($province),
                        'sr_city_key' => $this->normalizeKey($rate['city']),
                    ],
                    [
                        'sr_province' => $province,
                        'sr_city' => $rate['city'],
                        'sr_fee' => (float) $rate['fee'],
                        'sr_status' => true,
                    ]
                );
            }
        }
    }

    private function normalizeKey(string $value): string
    {
        $normalized = Str::ascii(Str::lower($value));
        $normalized = preg_replace('/\([^)]*\)/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bcity of\b/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(city|municipality|province)\b/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
