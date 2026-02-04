<?php

namespace Database\Seeders;

use App\Models\Core\DashboardWidget;
use App\Models\Core\Language;
use App\Models\Core\SubscriptionPlan;
use App\Models\Core\Translation;
use Illuminate\Database\Seeder;

class LocalizationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLanguages();
        $this->seedTranslations();
        $this->seedDashboardWidgets();
        $this->seedSubscriptionPlans();
    }

    protected function seedLanguages(): void
    {
        foreach (Language::LANGUAGES as $code => $data) {
            Language::updateOrCreate(
                ['code' => $code],
                array_merge($data, [
                    'is_active' => in_array($code, ['en', 'ar', 'hi']),
                    'is_default' => $code === 'en',
                    'sort_order' => match ($code) {
                        'en' => 1,
                        'ar' => 2,
                        'hi' => 3,
                        default => 10,
                    },
                ])
            );
        }
    }

    protected function seedTranslations(): void
    {
        $translations = [
            // Labels
            'labels' => [
                'en' => [
                    'dashboard' => 'Dashboard',
                    'sales' => 'Sales',
                    'purchases' => 'Purchases',
                    'inventory' => 'Inventory',
                    'accounting' => 'Accounting',
                    'reports' => 'Reports',
                    'settings' => 'Settings',
                    'customer' => 'Customer',
                    'supplier' => 'Supplier',
                    'product' => 'Product',
                    'invoice' => 'Invoice',
                    'quotation' => 'Quotation',
                    'payment' => 'Payment',
                    'total' => 'Total',
                    'subtotal' => 'Subtotal',
                    'tax' => 'Tax',
                    'discount' => 'Discount',
                    'quantity' => 'Quantity',
                    'price' => 'Price',
                    'amount' => 'Amount',
                    'date' => 'Date',
                    'status' => 'Status',
                    'actions' => 'Actions',
                ],
                'ar' => [
                    'dashboard' => 'لوحة التحكم',
                    'sales' => 'المبيعات',
                    'purchases' => 'المشتريات',
                    'inventory' => 'المخزون',
                    'accounting' => 'المحاسبة',
                    'reports' => 'التقارير',
                    'settings' => 'الإعدادات',
                    'customer' => 'العميل',
                    'supplier' => 'المورد',
                    'product' => 'المنتج',
                    'invoice' => 'الفاتورة',
                    'quotation' => 'عرض السعر',
                    'payment' => 'الدفع',
                    'total' => 'الإجمالي',
                    'subtotal' => 'المجموع الفرعي',
                    'tax' => 'الضريبة',
                    'discount' => 'الخصم',
                    'quantity' => 'الكمية',
                    'price' => 'السعر',
                    'amount' => 'المبلغ',
                    'date' => 'التاريخ',
                    'status' => 'الحالة',
                    'actions' => 'الإجراءات',
                ],
                'hi' => [
                    'dashboard' => 'डैशबोर्ड',
                    'sales' => 'बिक्री',
                    'purchases' => 'खरीद',
                    'inventory' => 'इन्वेंटरी',
                    'accounting' => 'लेखांकन',
                    'reports' => 'रिपोर्ट',
                    'settings' => 'सेटिंग्स',
                    'customer' => 'ग्राहक',
                    'supplier' => 'आपूर्तिकर्ता',
                    'product' => 'उत्पाद',
                    'invoice' => 'चालान',
                    'quotation' => 'कोटेशन',
                    'payment' => 'भुगतान',
                    'total' => 'कुल',
                    'subtotal' => 'उप-कुल',
                    'tax' => 'कर',
                    'discount' => 'छूट',
                    'quantity' => 'मात्रा',
                    'price' => 'मूल्य',
                    'amount' => 'राशि',
                    'date' => 'तारीख',
                    'status' => 'स्थिति',
                    'actions' => 'कार्रवाई',
                ],
            ],

            // Buttons
            'button' => [
                'en' => [
                    'save' => 'Save',
                    'cancel' => 'Cancel',
                    'delete' => 'Delete',
                    'edit' => 'Edit',
                    'create' => 'Create',
                    'add' => 'Add',
                    'remove' => 'Remove',
                    'search' => 'Search',
                    'filter' => 'Filter',
                    'export' => 'Export',
                    'import' => 'Import',
                    'print' => 'Print',
                    'download' => 'Download',
                    'submit' => 'Submit',
                    'confirm' => 'Confirm',
                    'back' => 'Back',
                    'next' => 'Next',
                    'previous' => 'Previous',
                    'close' => 'Close',
                    'view' => 'View',
                ],
                'ar' => [
                    'save' => 'حفظ',
                    'cancel' => 'إلغاء',
                    'delete' => 'حذف',
                    'edit' => 'تعديل',
                    'create' => 'إنشاء',
                    'add' => 'إضافة',
                    'remove' => 'إزالة',
                    'search' => 'بحث',
                    'filter' => 'تصفية',
                    'export' => 'تصدير',
                    'import' => 'استيراد',
                    'print' => 'طباعة',
                    'download' => 'تحميل',
                    'submit' => 'إرسال',
                    'confirm' => 'تأكيد',
                    'back' => 'رجوع',
                    'next' => 'التالي',
                    'previous' => 'السابق',
                    'close' => 'إغلاق',
                    'view' => 'عرض',
                ],
                'hi' => [
                    'save' => 'सहेजें',
                    'cancel' => 'रद्द करें',
                    'delete' => 'हटाएं',
                    'edit' => 'संपादित करें',
                    'create' => 'बनाएं',
                    'add' => 'जोड़ें',
                    'remove' => 'निकालें',
                    'search' => 'खोजें',
                    'filter' => 'फ़िल्टर',
                    'export' => 'निर्यात',
                    'import' => 'आयात',
                    'print' => 'प्रिंट',
                    'download' => 'डाउनलोड',
                    'submit' => 'जमा करें',
                    'confirm' => 'पुष्टि करें',
                    'back' => 'वापस',
                    'next' => 'अगला',
                    'previous' => 'पिछला',
                    'close' => 'बंद करें',
                    'view' => 'देखें',
                ],
            ],

            // Status
            'status' => [
                'en' => [
                    'draft' => 'Draft',
                    'pending' => 'Pending',
                    'sent' => 'Sent',
                    'paid' => 'Paid',
                    'partial' => 'Partial',
                    'overdue' => 'Overdue',
                    'cancelled' => 'Cancelled',
                    'voided' => 'Voided',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                ],
                'ar' => [
                    'draft' => 'مسودة',
                    'pending' => 'قيد الانتظار',
                    'sent' => 'مرسل',
                    'paid' => 'مدفوع',
                    'partial' => 'جزئي',
                    'overdue' => 'متأخر',
                    'cancelled' => 'ملغي',
                    'voided' => 'ملغى',
                    'approved' => 'موافق عليه',
                    'rejected' => 'مرفوض',
                    'active' => 'نشط',
                    'inactive' => 'غير نشط',
                ],
                'hi' => [
                    'draft' => 'ड्राफ्ट',
                    'pending' => 'लंबित',
                    'sent' => 'भेजा गया',
                    'paid' => 'भुगतान किया',
                    'partial' => 'आंशिक',
                    'overdue' => 'अतिदेय',
                    'cancelled' => 'रद्द',
                    'voided' => 'शून्य',
                    'approved' => 'स्वीकृत',
                    'rejected' => 'अस्वीकृत',
                    'active' => 'सक्रिय',
                    'inactive' => 'निष्क्रिय',
                ],
            ],

            // Invoice specific
            'invoice' => [
                'en' => [
                    'title' => 'Tax Invoice',
                    'simplified_title' => 'Simplified Tax Invoice',
                    'credit_note_title' => 'Credit Note',
                    'bill_to' => 'Bill To',
                    'ship_to' => 'Ship To',
                    'invoice_number' => 'Invoice Number',
                    'invoice_date' => 'Invoice Date',
                    'due_date' => 'Due Date',
                    'payment_terms' => 'Payment Terms',
                    'balance_due' => 'Balance Due',
                    'amount_paid' => 'Amount Paid',
                    'thank_you' => 'Thank you for your business!',
                ],
                'ar' => [
                    'title' => 'فاتورة ضريبية',
                    'simplified_title' => 'فاتورة ضريبية مبسطة',
                    'credit_note_title' => 'إشعار دائن',
                    'bill_to' => 'فاتورة إلى',
                    'ship_to' => 'الشحن إلى',
                    'invoice_number' => 'رقم الفاتورة',
                    'invoice_date' => 'تاريخ الفاتورة',
                    'due_date' => 'تاريخ الاستحقاق',
                    'payment_terms' => 'شروط الدفع',
                    'balance_due' => 'الرصيد المستحق',
                    'amount_paid' => 'المبلغ المدفوع',
                    'thank_you' => 'شكراً لتعاملكم معنا!',
                ],
                'hi' => [
                    'title' => 'कर चालान',
                    'simplified_title' => 'सरलीकृत कर चालान',
                    'credit_note_title' => 'क्रेडिट नोट',
                    'bill_to' => 'बिल प्राप्तकर्ता',
                    'ship_to' => 'शिप प्राप्तकर्ता',
                    'invoice_number' => 'चालान संख्या',
                    'invoice_date' => 'चालान तिथि',
                    'due_date' => 'देय तिथि',
                    'payment_terms' => 'भुगतान शर्तें',
                    'balance_due' => 'बकाया शेष',
                    'amount_paid' => 'भुगतान राशि',
                    'thank_you' => 'आपके व्यापार के लिए धन्यवाद!',
                ],
            ],
        ];

        foreach ($translations as $group => $languages) {
            foreach ($languages as $languageCode => $items) {
                foreach ($items as $key => $value) {
                    Translation::updateOrCreate(
                        [
                            'organization_id' => null,
                            'language_code' => $languageCode,
                            'group' => $group,
                            'key' => $key,
                        ],
                        ['value' => $value]
                    );
                }
            }
        }
    }

    protected function seedDashboardWidgets(): void
    {
        foreach (DashboardWidget::DEFAULT_WIDGETS as $index => $widget) {
            DashboardWidget::updateOrCreate(
                ['code' => $widget['code']],
                array_merge($widget, ['sort_order' => $index])
            );
        }
    }

    protected function seedSubscriptionPlans(): void
    {
        foreach (SubscriptionPlan::DEFAULT_PLANS as $code => $plan) {
            SubscriptionPlan::updateOrCreate(
                ['code' => $code],
                array_merge($plan, [
                    'code' => $code,
                    'currency_code' => 'USD',
                ])
            );
        }
    }
}
