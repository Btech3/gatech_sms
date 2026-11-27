<?php

namespace App\Services\Settings;

use Illuminate\Validation\Rule;

class SystemSettingsCatalog
{
    /**
     * @return array<string, array{group: string, rules: array<int, mixed>}>
     */
    public function definitions(): array
    {
        return [
            'attendance.threshold_percent' => [
                'group' => 'General',
                'rules' => ['required', 'integer', 'between:0,100'],
            ],
            'attendance.marking_window_minutes' => [
                'group' => 'General',
                'rules' => ['required', 'integer', 'between:0,10080'],
            ],
            'admissions.new_application_sla_hours' => [
                'group' => 'General',
                'rules' => ['required', 'integer', 'between:1,720'],
            ],
            'payment.currencies' => [
                'group' => 'Payment',
                'rules' => ['required', 'array', 'min:1'],
            ],
            'payment.methods' => [
                'group' => 'Payment',
                'rules' => ['required', 'array', 'min:1'],
            ],
            'invoice.numbering_prefix' => [
                'group' => 'Payment',
                'rules' => ['required', 'string', 'max:20'],
            ],
            'receipt.numbering_prefix' => [
                'group' => 'Payment',
                'rules' => ['required', 'string', 'max:20'],
            ],
            'payment.allow_overpayment' => [
                'group' => 'Payment',
                'rules' => ['required', 'boolean'],
            ],
            'discount.approval_threshold_percent' => [
                'group' => 'Payment',
                'rules' => ['required', 'numeric', 'between:0,100'],
            ],
            'refund.policy' => [
                'group' => 'Payment',
                'rules' => ['required', Rule::in(['same_method', 'manual_review', 'non_refundable'])],
            ],
            'notification.channels' => [
                'group' => 'Notification',
                'rules' => ['required', 'array', 'min:1'],
            ],
            'document.allowed_file_types' => [
                'group' => 'Document',
                'rules' => ['required', 'array', 'min:1'],
            ],
            'document.max_file_size_kb' => [
                'group' => 'Document',
                'rules' => ['required', 'integer', 'between:1,20480'],
            ],
            'document.required_documents' => [
                'group' => 'Document',
                'rules' => ['required', 'array'],
            ],
            'document.letter_template' => [
                'group' => 'Document',
                'rules' => ['nullable', 'string', 'max:100000'],
            ],
            'audit.retention_years' => [
                'group' => 'General',
                'rules' => ['required', 'integer', 'between:1,50'],
            ],
        ];
    }

    /**
     * @return array{group: string, rules: array<int, mixed>}
     */
    public function definition(string $key): array
    {
        $definition = $this->definitions()[$key] ?? null;
        abort_if($definition === null, 404, 'The requested system setting is not supported.');

        return $definition;
    }

    /**
     * @return array<int, mixed>
     */
    public function valueRules(string $key): array
    {
        $definition = $this->definition($key);
        $rules = $definition['rules'];

        if ($key === 'payment.currencies') {
            $rules[] = 'distinct';
            $rules[] = 'min:1';
        }

        return $rules;
    }
}
