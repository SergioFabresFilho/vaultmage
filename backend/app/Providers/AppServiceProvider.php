<?php

namespace App\Providers;

use App\Services\CardOcrParser;
use App\Services\CloudVisionService;
use App\Services\ScryfallService;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ImageAnnotatorClient::class, function () {
            $options = ['projectId' => config('services.google_vision.project_id')];

            $credentials = config('services.google_vision.credentials');
            if ($credentials) {
                $options['credentials'] = $credentials;
            }

            return new ImageAnnotatorClient($options);
        });

        $this->app->singleton(CloudVisionService::class, function ($app) {
            return new CloudVisionService($app->make(ImageAnnotatorClient::class));
        });

        $this->app->singleton(ScryfallService::class);
        $this->app->singleton(CardOcrParser::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
