<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

class ScrambleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Scramble::configure()

        //     ->withDocumentTransformers(function ($document) {

        //         $bearerAuth = SecurityScheme::http(
        //             scheme: 'bearer',
        //             bearerFormat: 'JWT'
        //         );

        //         $document->components->securitySchemes['bearerAuth'] = $bearerAuth;

        //         $document->secure($bearerAuth);
        //     })

        //     ->routes(function () {

        //         Scramble::registerUiRoute('docs/api');

        //         Scramble::registerJsonSpecificationRoute('docs/api.json');
        //     });
    }
}