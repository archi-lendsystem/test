<?php

namespace Modules\BLDocumentCheckList\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BLDocumentCheckListSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->checklists() as $checklist) {
                $checklistUuid = $this->seedChecklist($checklist);

                foreach ($this->groupsFor($checklist) as $group) {
                    $groupUuid = $this->seedGroup($checklistUuid, $group);

                    foreach ($this->documentsFor($checklist, $group['name']) as $document) {
                        $this->seedDocument($checklistUuid, $groupUuid, $document);
                    }
                }
            }
        });
    }

    private function seedChecklist(array $checklist): string
    {
        $query = DB::table('b_l_document_check_lists')
            ->where('title', $checklist['title'])
            ->where('type', $checklist['type'])
            ->where('applicable_to', $checklist['applicable_to'])
            ->where('loan_type', $checklist['loan_type'])
            ->where('is_income_considered', $checklist['is_income_considered'])
            ->where('applying_as', $checklist['applying_as']);

        if ($checklist['employment_type'] === null) {
            $query->whereNull('employment_type');
        } else {
            $query->where('employment_type', $checklist['employment_type']);
        }

        if ($checklist['entity_type'] === null) {
            $query->whereNull('entity_type');
        } else {
            $query->where('entity_type', $checklist['entity_type']);
        }

        $existing = $query->first();

        $uuid = $existing->uuid ?? $this->deterministicUuid(
            'checklist|' .
            $checklist['applying_as'] . '|' .
            ($checklist['employment_type'] ?? 'null') . '|' .
            ($checklist['entity_type'] ?? 'null') . '|' .
            (int) $checklist['is_income_considered']
        );

        DB::table('b_l_document_check_lists')->updateOrInsert(
            ['uuid' => $uuid],
            [
                'title' => $checklist['title'],
                'type' => $checklist['type'],
                'applicable_to' => $checklist['applicable_to'],
                'loan_type' => $checklist['loan_type'],
                'is_income_considered' => $checklist['is_income_considered'],
                'applying_as' => $checklist['applying_as'],
                'employment_type' => $checklist['employment_type'],
                'entity_type' => $checklist['entity_type'],
                'rule' => $checklist['rule'],
                'sequence' => $checklist['sequence'],
                'status' => $checklist['status'],
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]
        );

        return $uuid;
    }

    private function seedGroup(string $checklistUuid, array $group): string
    {
        $existing = DB::table('b_l_document_groups')
            ->where('document_checklist_id', $checklistUuid)
            ->where('dg_document_group_name', $group['name'])
            ->first();

        $uuid = $existing->uuid ?? $this->deterministicUuid(
            'group|' . $checklistUuid . '|' . $group['name']
        );

        DB::table('b_l_document_groups')->updateOrInsert(
            ['uuid' => $uuid],
            [
                'document_checklist_id' => $checklistUuid,
                'dg_document_group_name' => $group['name'],
                'dg_group_sequence' => $group['sequence'],
                'dg_mandatory' => $group['mandatory'],
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]
        );

        return $uuid;
    }

    private function seedDocument(
        string $checklistUuid,
        string $groupUuid,
        array $document
    ): void {
        $existing = DB::table('b_l_documents')
            ->where('document_checklist_id', $checklistUuid)
            ->where('document_group_id', $groupUuid)
            ->where('dg_document_class', $document['class'])
            ->where('dg_document_name', $document['name'])
            ->first();

        $uuid = $existing->uuid ?? $this->deterministicUuid(
            'document|' .
            $checklistUuid . '|' .
            $groupUuid . '|' .
            $document['class'] . '|' .
            $document['name']
        );

        DB::table('b_l_documents')->updateOrInsert(
            ['uuid' => $uuid],
            [
                'document_checklist_id' => $checklistUuid,
                'document_group_id' => $groupUuid,
                'dg_document_class' => $document['class'],
                'dg_document_name' => $document['name'],
                'dg_rule' => $document['rule'] ?? 'NA',
                'document_sequence' => $document['sequence'],
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]
        );
    }

    private function checklists(): array
    {
        $base = [
            'title' => 'checklist_for_bl',
            'type' => 'Application Flow',
            'applicable_to' => 'Applicant',
            'loan_type' => 'Business Loan',
            'rule' => 'NA',
            'status' => true,
        ];

        return [
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Sole Proprietor',
                'sequence' => 1,
            ]),
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Partnership',
                'sequence' => 2,
            ]),
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Private Limited',
                'sequence' => 3,
            ]),
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Public Limited',
                'sequence' => 4,
            ]),
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Limited Liability Partnership',
                'sequence' => 5,
            ]),
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'One Person Company',
                'sequence' => 6,
            ]),

            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Sole Proprietor',
                'sequence' => 7,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Partnership',
                'sequence' => 8,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Private Limited',
                'sequence' => 9,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Public Limited',
                'sequence' => 10,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'Limited Liability Partnership',
                'sequence' => 11,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Business',
                'employment_type' => null,
                'entity_type' => 'One Person Company',
                'sequence' => 12,
            ]),

            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Individual',
                'employment_type' => 'Salaried',
                'entity_type' => null,
                'sequence' => 13,
            ]),
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Individual',
                'employment_type' => 'Self Employed Professional',
                'entity_type' => null,
                'sequence' => 14,
            ]),
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Individual',
                'employment_type' => 'Self Employed Non-Professional',
                'entity_type' => null,
                'sequence' => 15,
            ]),
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Individual',
                'employment_type' => 'Pension/Retired',
                'entity_type' => null,
                'sequence' => 16,
            ]),
            array_merge($base, [
                'is_income_considered' => true,
                'applying_as' => 'Individual',
                'employment_type' => 'Other',
                'entity_type' => null,
                'sequence' => 17,
            ]),

            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Individual',
                'employment_type' => 'Salaried',
                'entity_type' => null,
                'sequence' => 18,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Individual',
                'employment_type' => 'Self Employed Professional',
                'entity_type' => null,
                'sequence' => 19,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Individual',
                'employment_type' => 'Self Employed Non-Professional',
                'entity_type' => null,
                'sequence' => 20,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Individual',
                'employment_type' => 'House Wife',
                'entity_type' => null,
                'sequence' => 21,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Individual',
                'employment_type' => 'Pension/Retired',
                'entity_type' => null,
                'sequence' => 22,
            ]),
            array_merge($base, [
                'is_income_considered' => false,
                'applying_as' => 'Individual',
                'employment_type' => 'Other',
                'entity_type' => null,
                'sequence' => 23,
            ]),
        ];
    }

    private function groupsFor(array $checklist): array
    {
        if (! $checklist['is_income_considered']) {
            return [
                ['name' => 'Kyc', 'sequence' => 1, 'mandatory' => 'Mandatory'],
                ['name' => 'Others', 'sequence' => 2, 'mandatory' => 'Optional'],
            ];
        }

        $groups = [
            ['name' => 'Kyc', 'sequence' => 1, 'mandatory' => 'Mandatory'],
            ['name' => 'Address Proof', 'sequence' => 2, 'mandatory' => 'Anyone'],
        ];

        if ($checklist['applying_as'] === 'Business') {
            $groups[] = ['name' => 'Business Proof', 'sequence' => 3, 'mandatory' => 'Mandatory'];
            $groups[] = ['name' => 'Income Proof', 'sequence' => 4, 'mandatory' => 'Mandatory'];
            $groups[] = ['name' => 'Bank Statement', 'sequence' => 5, 'mandatory' => 'Mandatory'];
            $groups[] = ['name' => 'Cash Income Proof', 'sequence' => 6, 'mandatory' => 'Optional'];
            $groups[] = ['name' => 'Others', 'sequence' => 7, 'mandatory' => 'Optional'];

            return $groups;
        }

        if (in_array($checklist['employment_type'], [
            'Self Employed Professional',
            'Self Employed Non-Professional',
        ], true)) {
            $groups[] = ['name' => 'Business Proof', 'sequence' => 3, 'mandatory' => 'Mandatory'];
            $groups[] = ['name' => 'Income Proof', 'sequence' => 4, 'mandatory' => 'Mandatory'];
            $groups[] = ['name' => 'Bank Statement', 'sequence' => 5, 'mandatory' => 'Mandatory'];
            $groups[] = ['name' => 'Others', 'sequence' => 6, 'mandatory' => 'Optional'];

            return $groups;
        }

        $groups[] = ['name' => 'Income Proof', 'sequence' => 3, 'mandatory' => 'Mandatory'];
        $groups[] = ['name' => 'Bank Statement', 'sequence' => 4, 'mandatory' => 'Mandatory'];
        $groups[] = ['name' => 'Others', 'sequence' => 5, 'mandatory' => 'Optional'];

        return $groups;
    }

    private function documentsFor(array $checklist, string $groupName): array
    {
        return match ($groupName) {
            'Kyc' => $this->kycDocuments(),

            'Address Proof' => [
                ['class' => 'aadhar_card', 'name' => 'Aadhar Card', 'sequence' => 1],
                ['class' => 'passport', 'name' => 'Passport', 'sequence' => 2],
                ['class' => 'electricity_bill', 'name' => 'Electricity Bill', 'sequence' => 3],
                ['class' => 'gas_connection', 'name' => 'Gas Connection', 'sequence' => 4],
                ['class' => 'water_bill', 'name' => 'Water Bill', 'sequence' => 5],
                ['class' => 'house_tax_receipt', 'name' => 'House Tax Receipt', 'sequence' => 6],
                ['class' => 'ration_card', 'name' => 'Ration Card', 'sequence' => 7],
                ['class' => 'rent_agreement', 'name' => 'Rent Agreement', 'sequence' => 8],
            ],

            'Business Proof' => $this->businessProofDocuments($checklist),

            'Income Proof' => $this->incomeProofDocuments($checklist),

            'Bank Statement' => [
                ['class' => 'bank_statement', 'name' => 'Bank Statement (1 Year)', 'sequence' => 1],
            ],

            'Cash Income Proof' => [
                ['class' => 'kaccha_pakka_bill', 'name' => 'Kaccha Pakka Bill', 'sequence' => 1],
                ['class' => 'purchase_bill', 'name' => 'Purchase Bill', 'sequence' => 2],
                ['class' => 'sales_bill', 'name' => 'Sales Bill', 'sequence' => 3],
            ],

            'Others' => [
                ['class' => 'others', 'name' => 'Others', 'sequence' => 1],
            ],

            default => [],
        };
    }

    private function kycDocuments(): array
    {
        return [
            ['class' => 'aadhar_card', 'name' => 'Aadhar Card', 'sequence' => 1],
            ['class' => 'pan_card', 'name' => 'Pan Card', 'sequence' => 2],
            ['class' => 'profile_picture', 'name' => 'Profile Picture', 'sequence' => 3],
        ];
    }

    private function businessProofDocuments(array $checklist): array
    {
        if ($checklist['applying_as'] === 'Individual') {
            return match ($checklist['employment_type']) {
                'Self Employed Professional' => [
                    ['class' => 'gst', 'name' => 'GST Certificate', 'sequence' => 1],
                    ['class' => 'udhyam', 'name' => 'Udyam Certificate', 'sequence' => 2],
                    ['class' => 'professional_registration', 'name' => 'Professional Registration Certificate', 'sequence' => 3],
                ],

                'Self Employed Non-Professional' => [
                    ['class' => 'gst', 'name' => 'GST Certificate', 'sequence' => 1],
                    ['class' => 'udhyam', 'name' => 'Udyam Certificate', 'sequence' => 2],
                    ['class' => 'shop_act_license', 'name' => 'Shop Act License', 'sequence' => 3],
                ],

                default => [],
            };
        }

        return match ($checklist['entity_type']) {
            'Sole Proprietor' => [
                ['class' => 'gst', 'name' => 'GST Certificate', 'sequence' => 1],
                ['class' => 'udhyam', 'name' => 'Udyam Certificate', 'sequence' => 2],
            ],

            'Partnership' => [
                ['class' => 'gst', 'name' => 'GST Certificate', 'sequence' => 1],
                ['class' => 'partnership_deed', 'name' => 'Partnership Deed', 'sequence' => 2],
                ['class' => 'firm_pan_card', 'name' => 'Firm PAN Card', 'sequence' => 3],
            ],

            'Private Limited' => [
                ['class' => 'gst', 'name' => 'GST Certificate', 'sequence' => 1],
                ['class' => 'company_pan_card', 'name' => 'Company PAN Card', 'sequence' => 2],
                ['class' => 'udhyam', 'name' => 'Udyam Certificate', 'sequence' => 3],
                ['class' => 'aoa_moa', 'name' => 'AOA / MOA', 'sequence' => 4],
                ['class' => 'coi', 'name' => 'Certificate Of Incorporation (COI)', 'sequence' => 5],
            ],

            'Public Limited' => [
                ['class' => 'gst', 'name' => 'GST Certificate', 'sequence' => 1],
                ['class' => 'company_pan_card', 'name' => 'Company PAN Card', 'sequence' => 2],
                ['class' => 'aoa_moa', 'name' => 'AOA / MOA', 'sequence' => 3],
                ['class' => 'board_resolution', 'name' => 'Board Resolution', 'sequence' => 4],
                ['class' => 'udhyam', 'name' => 'Udyam Certificate', 'sequence' => 5],
            ],

            'Limited Liability Partnership' => [
                ['class' => 'gst', 'name' => 'GST Certificate', 'sequence' => 1],
                ['class' => 'coi', 'name' => 'Certificate Of Incorporation (COI)', 'sequence' => 2],
                ['class' => 'llp_pan_card', 'name' => 'LLP PAN Card', 'sequence' => 3],
                ['class' => 'llp_agreement', 'name' => 'LLP Agreement', 'sequence' => 4],
            ],

            'One Person Company' => [
                ['class' => 'gst', 'name' => 'GST Certificate', 'sequence' => 1],
                ['class' => 'aoa_moa', 'name' => 'AOA / MOA', 'sequence' => 2],
                ['class' => 'company_pan_card', 'name' => 'Company PAN Card', 'sequence' => 3],
                ['class' => 'udhyam', 'name' => 'Udyam Certificate', 'sequence' => 4],
                ['class' => 'din_dsc', 'name' => 'DIN / DSC Certificate', 'sequence' => 5],
                ['class' => 'board_resolution_certificate', 'name' => 'Board Resolution Certificate', 'sequence' => 6],
            ],

            default => [],
        };
    }

    private function incomeProofDocuments(array $checklist): array
    {
        if ($checklist['applying_as'] === 'Business') {
            return [
                ['class' => 'itr', 'name' => 'ITR (2 Years)', 'sequence' => 1],
                ['class' => 'gstr', 'name' => 'GSTR', 'sequence' => 2],
                ['class' => 'pl_balance_sheet', 'name' => 'P&L, Balance Sheet', 'sequence' => 3],
            ];
        }

        return match ($checklist['employment_type']) {
            'Salaried' => [
                ['class' => 'salary_slip', 'name' => 'Salary Slip (3 Months)', 'sequence' => 1],
                ['class' => 'form_16', 'name' => 'Form 16', 'sequence' => 2],
                ['class' => 'itr', 'name' => 'ITR (2 Years)', 'sequence' => 3],
            ],

            'Self Employed Professional',
            'Self Employed Non-Professional' => [
                ['class' => 'itr', 'name' => 'ITR (2 Years)', 'sequence' => 1],
                ['class' => 'gstr', 'name' => 'GSTR', 'sequence' => 2],
                ['class' => 'pl_balance_sheet', 'name' => 'P&L, Balance Sheet', 'sequence' => 3],
            ],

            'Pension/Retired' => [
                ['class' => 'pension_statement', 'name' => 'Pension Statement', 'sequence' => 1],
                ['class' => 'pension_payment_order', 'name' => 'Pension Payment Order', 'sequence' => 2],
                ['class' => 'bank_statement', 'name' => 'Bank Statement Showing Pension Credit', 'sequence' => 3],
            ],

            default => [
                ['class' => 'income_declaration', 'name' => 'Income Declaration', 'sequence' => 1],
                ['class' => 'itr', 'name' => 'ITR (If Available)', 'sequence' => 2],
            ],
        };
    }

    private function deterministicUuid(string $value): string
    {
        $hash = sha1('bl-document-rule-seeder|' . $value);

        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec($hash[16]) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }
}