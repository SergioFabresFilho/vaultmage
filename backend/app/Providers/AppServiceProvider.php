<?php

namespace App\Providers;

use App\Contracts\OcrClient;
use App\Contracts\VisionClientInterface;
use App\Services\GoogleOcrClient;
use App\Services\GoogleVisionClientAdapter;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ImageAnnotatorClient::class, function () {
            $options = [];

            $credentials = config('services.google_vision.credentials');
            if ($credentials) {
                // Accept either a file path or an inline JSON string
                $options['credentials'] = file_exists($credentials)
                    ? $credentials
                    : (json_decode($credentials, true) ?? $credentials);
            }

            $projectId = config('services.google_vision.project_id');
            if ($projectId) {
                $options['projectId'] = $projectId;
            }

            return new ImageAnnotatorClient($options);
        });

        $this->app->singleton(VisionClientInterface::class, GoogleVisionClientAdapter::class);
        $this->app->singleton(OcrClient::class, GoogleOcrClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
