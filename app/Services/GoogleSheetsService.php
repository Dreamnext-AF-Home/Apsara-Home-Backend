<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    private Client $client;
    private Sheets $service;
    private string $spreadsheetId;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setApplicationName(env('APP_NAME', 'Laravel'));
        $this->client->setScopes([Sheets::SPREADSHEETS]);
        $this->client->setAuthConfig($this->getAuthConfig());
        $this->service = new Sheets($this->client);
        $this->spreadsheetId = env('GOOGLE_SHEETS_SPREADSHEET_ID', '');
    }

    private function getAuthConfig(): array
    {
        return [
            'type' => env('GOOGLE_TYPE', 'service_account'),
            'project_id' => env('GOOGLE_PROJECT_ID', ''),
            'private_key_id' => env('GOOGLE_PRIVATE_KEY_ID', ''),
            'private_key' => $this->formatPrivateKey(env('GOOGLE_PRIVATE_KEY', '')),
            'client_email' => env('GOOGLE_CLIENT_EMAIL', ''),
            'client_id' => env('GOOGLE_CLIENT_ID', ''),
            'auth_uri' => env('GOOGLE_AUTH_URI', 'https://oauth2.googleapis.com/token'),
            'token_uri' => env('GOOGLE_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
            'auth_provider_x509_cert_url' => env('GOOGLE_AUTH_PROVIDER_X509_CERT_URL', 'https://www.googleapis.com/oauth2/v1/certs'),
            'client_x509_cert_url' => env('GOOGLE_CLIENT_X509_CERT_URL', ''),
        ];
    }

    private function formatPrivateKey(string $key): string
    {
        // Convert \n to actual newlines
        return str_replace('\\n', "\n", $key);
    }

    public function appendData(array $data, string $sheetName = 'Sheet1', string $range = 'A1'): bool
    {
        try {
            $valueRange = new ValueRange([
                'values' => $data
            ]);

            $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                $sheetName . '!' . $range,
                $valueRange,
                ['valueInputOption' => 'RAW']
            );

            return true;
        } catch (\Exception $e) {
            Log::error('Google Sheets append error: ' . $e->getMessage());
            return false;
        }
    }

    public function updateData(array $data, string $sheetName = 'Sheet1', string $range = 'A1'): bool
    {
        try {
            $valueRange = new ValueRange([
                'values' => $data
            ]);

            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $sheetName . '!' . $range,
                $valueRange,
                ['valueInputOption' => 'RAW']
            );

            return true;
        } catch (\Exception $e) {
            Log::error('Google Sheets update error: ' . $e->getMessage());
            return false;
        }
    }

    public function clearSheet(string $sheetName = 'Sheet1', string $range = 'A1:Z1000'): bool
    {
        try {
            $this->service->spreadsheets_values->clear(
                $this->spreadsheetId,
                $sheetName . '!' . $range
            );

            return true;
        } catch (\Exception $e) {
            Log::error('Google Sheets clear error: ' . $e->getMessage());
            return false;
        }
    }

    public function readData(string $sheetName = 'Sheet1', string $range = 'A1:Z1000'): ?array
    {
        try {
            $response = $this->service->spreadsheets_values->get(
                $this->spreadsheetId,
                $sheetName . '!' . $range
            );

            return $response->getValues();
        } catch (\Exception $e) {
            Log::error('Google Sheets read error: ' . $e->getMessage());
            return null;
        }
    }
}
