<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SecureFileUpload implements ValidationRule
{
    protected $allowedMimes = ['xlsx', 'xls', 'csv'];
    protected $maxSize = 10240; // 10MB بالكيلوبايت
    protected $allowedExtensions = ['xlsx', 'xls', 'csv'];

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            $fail('الملف المرفوع غير صحيح.');
            return;
        }

        // التحقق من وجود الملف
        if (!$value->isValid()) {
            $fail('فشل رفع الملف. يرجى المحاولة مرة أخرى.');
            return;
        }

        // التحقق من حجم الملف
        $fileSize = $value->getSize();
        $maxSizeBytes = $this->maxSize * 1024; // تحويل إلى بايت

        if ($fileSize > $maxSizeBytes) {
            $fail("حجم الملف كبير جداً. الحد الأقصى المسموح: {$this->maxSize} كيلوبايت.");
            return;
        }

        // التحقق من أن الملف ليس فارغاً
        if ($fileSize === 0) {
            $fail('الملف فارغ.');
            return;
        }

        // التحقق من الامتداد
        $extension = strtolower($value->getClientOriginalExtension());
        if (!in_array($extension, $this->allowedExtensions, true)) {
            $fail('نوع الملف غير مسموح. الأنواع المسموحة: ' . implode(', ', $this->allowedExtensions));
            return;
        }

        // التحقق من MIME Type
        $mimeType = $value->getMimeType();
        $allowedMimeTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
            'application/vnd.ms-excel', // xls
            'text/csv',
            'text/plain', // بعض الأنظمة تعتبر CSV كـ text/plain
            'application/csv',
        ];

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            $fail('نوع الملف غير مسموح. يرجى رفع ملف Excel أو CSV.');
            return;
        }

        // التحقق من اسم الملف (منع أسماء خطيرة)
        $fileName = $value->getClientOriginalName();
        if (preg_match('/[<>:"|?*\x00-\x1f]/', $fileName)) {
            $fail('اسم الملف يحتوي على أحرف غير مسموحة.');
            return;
        }

        // التحقق من طول اسم الملف
        if (strlen($fileName) > 255) {
            $fail('اسم الملف طويل جداً.');
            return;
        }

        // التحقق من محتوى الملف (قراءة أول بايتات)
        $this->validateFileContent($value, $fail);
    }

    /**
     * التحقق من محتوى الملف
     */
    protected function validateFileContent(UploadedFile $file, Closure $fail): void
    {
        try {
            $handle = fopen($file->getRealPath(), 'rb');
            if (!$handle) {
                $fail('لا يمكن قراءة الملف.');
                return;
            }

            // قراءة أول 512 بايت للتحقق من التوقيع
            $header = fread($handle, 512);
            fclose($handle);

            if (empty($header)) {
                $fail('الملف فارغ أو تالف.');
                return;
            }

            // التحقق من توقيعات ملفات Excel
            $extension = strtolower($file->getClientOriginalExtension());
            
            if ($extension === 'xlsx') {
                // XLSX يبدأ بـ PK (ZIP signature)
                if (substr($header, 0, 2) !== 'PK') {
                    $fail('الملف ليس ملف Excel صحيح (XLSX).');
                    return;
                }
            } elseif ($extension === 'xls') {
                // XLS يبدأ بـ D0 CF 11 E0 (OLE2 signature)
                if (substr(bin2hex($header), 0, 8) !== 'd0cf11e0') {
                    $fail('الملف ليس ملف Excel صحيح (XLS).');
                    return;
                }
            } elseif ($extension === 'csv') {
                // CSV يجب أن يكون نصاً
                if (!mb_check_encoding($header, 'UTF-8') && !mb_check_encoding($header, 'ASCII')) {
                    $fail('الملف CSV غير صحيح.');
                    return;
                }
            }

            // منع ملفات ZIP المخفية (ZIP bombs)
            if (substr($header, 0, 2) === 'PK' && $extension !== 'xlsx') {
                // إذا كان ZIP لكن ليس XLSX، قد يكون محاولة ZIP bomb
                $fail('نوع الملف غير مسموح.');
                return;
            }

        } catch (\Exception $e) {
            \Log::warning('File content validation error', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            // لا نفشل التحقق في حالة خطأ، لكن نسجل التحذير
        }
    }
}

