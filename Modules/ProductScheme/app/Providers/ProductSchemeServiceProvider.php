<?php

namespace Modules\ProductScheme\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class ProductSchemeServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'ProductScheme';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'productscheme';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Register module services.
     */
    public function register(): void
    {
        parent::register();

        /*
        |--------------------------------------------------------------------------
        | Product Scheme Custom Config
        |--------------------------------------------------------------------------
        |
        | Required for:
        | config('product_configuration.sub_loan_types')
        | config('product_configuration.repayment_frequencies')
        | config('product_configuration.form_fields')
        |
        */
        $this->mergeConfigFrom(
            module_path($this->name, 'config/product_configuration.php'),
            'product_configuration'
        );
    }
}
