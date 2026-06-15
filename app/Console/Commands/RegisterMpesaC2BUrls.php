<?php

namespace App\Console\Commands;

use App\Services\MpesaService;
use Illuminate\Console\Command;

class RegisterMpesaC2BUrls extends Command
{
    protected $signature = 'mpesa:register-c2b
                            {--confirmation= : Override the confirmation URL (defaults to APP_URL + /api/mpesa/c2b/confirmation)}
                            {--validation=   : Override the validation URL (defaults to APP_URL + /api/mpesa/c2b/validation)}
                            {--response-type=Completed : Completed or Cancelled}';

    protected $description = 'Register Safaricom Daraja C2B confirmation/validation URLs for the paybill shortcode';

    public function handle(MpesaService $mpesa): int
    {
        $confirmation = $this->option('confirmation') ?: route('mpesa.c2b.confirmation');
        $validation   = $this->option('validation')   ?: route('mpesa.c2b.validation');
        $responseType = $this->option('response-type');

        if (str_contains($confirmation, 'localhost') || str_contains($confirmation, '127.0.0.1') || str_starts_with($confirmation, 'http://')) {
            $this->error('Confirmation URL must be a publicly reachable HTTPS URL. Got: ' . $confirmation);
            $this->line('Set APP_URL in .env to your public https domain, or pass --confirmation=https://...');
            return self::FAILURE;
        }

        $this->info('Registering C2B URLs with Safaricom Daraja…');
        $this->line('  Shortcode:        ' . config('services.mpesa.shortcode'));
        $this->line('  Environment:      ' . config('services.mpesa.environment'));
        $this->line('  Confirmation URL: ' . $confirmation);
        $this->line('  Validation URL:   ' . $validation);
        $this->line('  Response Type:    ' . $responseType);

        $result = $mpesa->registerC2BUrls($confirmation, $validation, $responseType);

        if ($result['success']) {
            $this->info("\n✓ Registered successfully.");
            $this->line('Response: ' . ($result['message'] ?? 'OK'));
            return self::SUCCESS;
        }

        $this->error("\n✗ Registration failed.");
        $this->line($result['message'] ?? 'No error message returned');
        if (isset($result['data'])) {
            $this->line('Raw response: ' . json_encode($result['data'], JSON_PRETTY_PRINT));
        }
        return self::FAILURE;
    }
}
