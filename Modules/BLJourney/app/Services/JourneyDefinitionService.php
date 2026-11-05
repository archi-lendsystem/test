<?php

namespace Modules\BLJourney\Services;

use Illuminate\Contracts\Config\Repository;
use LogicException;
use Modules\BLJourney\Exceptions\JourneyDefinitionException;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Product\Models\Product;

class JourneyDefinitionService
{
    public function __construct(private Repository $config)
    {
    }

    public function forProduct(Product $product): array
    {
        return $this->resolve((string) $product->code);
    }

    public function forLead(BLLeadCreation $lead): array
    {
        $lead->loadMissing('product');

        if (!$lead->product || !$lead->product_id) {
            throw new JourneyDefinitionException(
                'LEAD_PRODUCT_UNRESOLVED',
                'This lead has no valid product. Its product mapping must be corrected.'
            );
        }

        // Existing leads also use the current product configuration.
        return $this->forProduct($lead->product);
    }

    public function resolve(string $productCode): array
    {
        $code = strtoupper(trim($productCode));
        $configuration = $this->configuration();

        if (!array_key_exists($code, $configuration['products'])) {
            throw new JourneyDefinitionException(
                'PRODUCT_JOURNEY_NOT_CONFIGURED',
                'A journey is not configured for the selected product.',
                422
            );
        }

        $keys = $configuration['products'][$code];

        if (
            !is_array($keys)
            || count($keys) < 2
            || array_values($keys) !== $keys
            || $keys[0] !== 'lead-details'
            || end($keys) !== 'submit'
        ) {
            throw new LogicException(
                "Journey {$code} must be an ordered list starting with lead-details and ending with submit."
            );
        }

        $steps = [];
        $seen = [];

        foreach ($keys as $position => $key) {
            if (
                !is_string($key)
                || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $key)
                || isset($seen[$key])
            ) {
                throw new LogicException(
                    "Journey {$code} contains an invalid or duplicate step key."
                );
            }

            $seen[$key] = true;
            $step = $configuration['steps'][$key] ?? null;

            if (
                !is_array($step)
                || !is_string($step['label'] ?? null)
                || trim($step['label']) === ''
                || !is_bool($step['requires_lead'] ?? null)
                || !in_array(
                    $step['submission_requirement'] ?? null,
                    ['none', 'soft', 'hard'],
                    true
                )
            ) {
                throw new LogicException(
                    "Journey step {$key} has invalid metadata."
                );
            }

            if ($step['requires_lead'] !== ($key !== 'lead-details')) {
                throw new LogicException(
                    "Journey step {$key} has an invalid lead requirement."
                );
            }

            if (
                in_array($key, ['lead-details', 'submit'], true)
                && $step['submission_requirement'] !== 'none'
            ) {
                throw new LogicException(
                    "Journey boundary {$key} cannot validate itself as a previous step."
                );
            }

            if ($step['submission_requirement'] !== 'none') {
                $completion = $step['completion'] ?? [];

                if (!is_array($completion)) {
                    throw new LogicException(
                        "Invalid completion metadata for step {$key}."
                    );
                }

                $this->validateCompletion($key, $completion);
            }

            $steps[] = array_merge($step, [
                'key' => $key,
                'position' => $position + 1,
                'route_step' => $key,
                'draft_route_step' => $key === 'lead-details'
                    ? 'creation'
                    : $key,
            ]);
        }

        return [
            'product_code' => $code,
            'steps' => $steps,
        ];
    }

    public function publicDefinition(array $definition): array
    {
        $definition['steps'] = array_map(
            static function (array $step): array {
                // Internal completion events are not needed by the frontend.
                unset($step['completion']);

                return $step;
            },
            $definition['steps']
        );

        return $definition;
    }

    public function submissionRequirements(BLLeadCreation $lead): array
    {
        $requirements = [];

        foreach ($this->forLead($lead)['steps'] as $step) {
            if ($step['submission_requirement'] !== 'none') {
                $requirements[] = array_merge(
                    $step['completion'],
                    [
                        'key' => $step['key'],
                        'type' => $step['submission_requirement'],
                    ]
                );
            }
        }

        return $requirements;
    }

    public function assertApplicable(
        BLLeadCreation $lead,
        string $stepKey
    ): void {
        $availableSteps = array_column(
            $this->forLead($lead)['steps'],
            'key'
        );

        if (!in_array($stepKey, $availableSteps, true)) {
            throw new JourneyDefinitionException(
                'STEP_NOT_APPLICABLE',
                'This step is not available in the lead journey.',
                422
            );
        }
    }

    public function validateAll(): int
    {
        $configuration = $this->configuration();
        $count = 0;

        foreach (array_keys($configuration['products']) as $code) {
            if (
                !is_string($code)
                || $code === ''
                || strtoupper(trim($code)) !== $code
            ) {
                throw new LogicException(
                    'Journey product codes must be normalized uppercase strings.'
                );
            }

            $this->resolve($code);
            $count++;
        }

        return $count;
    }

    private function configuration(): array
    {
        $configuration = $this->config->get('product_journeys');

        if (
            !is_array($configuration)
            || !is_array($configuration['steps'] ?? null)
            || !$configuration['steps']
            || !is_array($configuration['products'] ?? null)
            || !$configuration['products']
        ) {
            throw new LogicException(
                'Journey configuration requires non-empty steps and products.'
            );
        }

        return $configuration;
    }

    private function validateCompletion(
        string $key,
        array $completion
    ): void {
        foreach (['label', 'message', 'required_action'] as $field) {
            if (
                !is_string($completion[$field] ?? null)
                || trim($completion[$field]) === ''
            ) {
                throw new LogicException(
                    "Missing {$field} for required step {$key}."
                );
            }
        }

        foreach (['stage_names', 'action_types'] as $field) {
            if (
                !is_array($completion[$field] ?? null)
                || !$completion[$field]
            ) {
                throw new LogicException(
                    "Missing completion {$field} for step {$key}."
                );
            }

            foreach ($completion[$field] as $value) {
                if (!is_string($value) || trim($value) === '') {
                    throw new LogicException(
                        "Invalid completion {$field} for step {$key}."
                    );
                }
            }
        }
    }
}