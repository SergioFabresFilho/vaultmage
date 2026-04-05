<?php

namespace App\Providers;

use App\Contracts\OcrClient;
use App\Contracts\VisionClientInterface;
use App\Models\User;
use App\Services\GoogleOcrClient;
use App\Services\GoogleVisionClientAdapter;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;

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
            $credentialsBase64 = config('services.google_vision.credentials_base64');

            if ($credentialsBase64) {
                $decoded = base64_decode($credentialsBase64, true);

                if ($decoded === false) {
                    throw new \InvalidArgumentException('GOOGLE_VISION_CREDENTIALS_BASE64 is not valid base64.');
                }

                $decodedJson = json_decode($decoded, true);

                if (! is_array($decodedJson)) {
                    throw new \InvalidArgumentException('GOOGLE_VISION_CREDENTIALS_BASE64 does not decode to valid JSON credentials.');
                }

                $options['credentials'] = $decodedJson;
            }

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
        Gate::define('viewApiDocs', fn (?User $user = null) => app()->environment(['local', 'testing']));

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        Queue::exceptionOccurred(function (JobExceptionOccurred $event): void {
            Log::error('Queue job exception occurred', [
                'job' => $event->job->resolveName(),
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'exception_class' => $event->exception::class,
                'message' => $event->exception->getMessage(),
                'trace' => mb_substr($event->exception->getTraceAsString(), 0, 4000),
            ]);
        });

        Queue::failing(function (JobFailed $event): void {
            Log::error('Queue job failed', [
                'job' => $event->job->resolveName(),
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'exception_class' => $event->exception::class,
                'message' => $event->exception->getMessage(),
                'trace' => mb_substr($event->exception->getTraceAsString(), 0, 4000),
            ]);
        });
    }
}
