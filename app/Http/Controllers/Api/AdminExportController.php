<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileUploadRequest;
use App\Jobs\ImportExcelJob;
use App\Jobs\ExportExcelJob;
use App\Jobs\ExportPdfJob;
use App\Models\FamilyMember;
use App\Models\Identity;
use App\Models\ImportJob;
use App\Models\ExportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader as OpenSpoutReader;
use OpenSpout\Reader\XLSX\Options as ReaderOptions;

class AdminExportController extends Controller
{
    // Helper function to get column name from index (1 = A, 2 = B, ..., 26 = Z, 27 = AA, ...)
    private function getColumnName(int $index): string
    {
        if ($index <= 0) {
            return 'A';
        }
        
        $columnName = '';
        $index--; // Convert to 0-based
        
        while ($index >= 0) {
            $columnName = chr(65 + ($index % 26)) . $columnName;
            $index = intval($index / 26) - 1;
        }
        
        return $columnName ?: 'A';
    }
    
    // Helper function to increment column (A -> B, Z -> AA, etc.)
    private function incrementColumn(string $column): string
    {
        $col = strtoupper(trim($column));
        if (empty($col)) {
            return 'A';
        }
        
        $len = strlen($col);
        $carry = true;
        $result = str_split($col);
        
        for ($i = $len - 1; $i >= 0 && $carry; $i--) {
            $char = ord($result[$i]);
            if ($char < 90) { // Not Z
                $result[$i] = chr($char + 1);
                $carry = false;
            } else { // Z
                $result[$i] = 'A';
                $carry = true;
            }
        }
        
        if ($carry) {
            return 'A' . implode('', $result);
        }
        
        return implode('', $result);
    }

    /**
     * Read RTL/LTR direction from Excel file by reading XML
     * Returns 'rtl' if rightToLeft="1" is found, 'ltr' otherwise
     */
    private function readDirectionFromExcelFile(string $filePath): string
    {
        // Excel files are ZIP archives containing XML files
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            Log::warning('Failed to open Excel file for direction reading', ['file' => $filePath]);
            return 'ltr'; // Default to LTR if can't read
        }

        // Find and read the worksheet XML file
        $sheetIndex = 0;
        $sheetXmlPath = "xl/worksheets/sheet" . ($sheetIndex + 1) . ".xml";
        
        if ($zip->locateName($sheetXmlPath) === false) {
            $zip->close();
            return 'ltr'; // Default to LTR
        }

        // Read the XML content
        $xmlContent = $zip->getFromName($sheetXmlPath);
        $zip->close();
        
        if ($xmlContent === false) {
            return 'ltr'; // Default to LTR
        }

        try {
            // Use DOMDocument to parse XML safely
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;
            
            // Load XML with error handling
            $oldErrorHandling = libxml_use_internal_errors(true);
            $loaded = $dom->loadXML($xmlContent);
            libxml_use_internal_errors($oldErrorHandling);
            
            if (!$loaded) {
                return 'ltr'; // Default to LTR
            }

            // Check for rightToLeft attribute in sheetView
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            
            $sheetView = $xpath->query('//x:sheetView')->item(0);
            
            if ($sheetView instanceof \DOMElement) {
                $rightToLeft = $sheetView->getAttribute('rightToLeft');
                if ($rightToLeft === '1' || $rightToLeft === 'true') {
                    return 'rtl';
                }
            }
            
            return 'ltr'; // Default to LTR if not found or not set
        } catch (\Exception $e) {
            Log::error('Error reading direction from XML', [
                'error' => $e->getMessage()
            ]);
            return 'ltr'; // Default to LTR on error
        }
    }

    /**
     * Add RTL support to Excel file by modifying XML directly (much faster than PhpSpreadsheet)
     * Uses DOMDocument to ensure valid XML structure
     */
    private function addRTLToExcelFile(string $filePath): void
    {
        // Excel files are ZIP archives containing XML files
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            Log::warning('Failed to open Excel file for RTL modification', ['file' => $filePath]);
            return;
        }

        // Find and modify the worksheet XML file
        $sheetIndex = 0;
        $sheetXmlPath = "xl/worksheets/sheet" . ($sheetIndex + 1) . ".xml";
        
        if ($zip->locateName($sheetXmlPath) === false) {
            $zip->close();
            Log::warning('Worksheet XML not found', ['path' => $sheetXmlPath]);
            return;
        }

        // Read the XML content
        $xmlContent = $zip->getFromName($sheetXmlPath);
        if ($xmlContent === false) {
            $zip->close();
            return;
        }

        try {
            // Use DOMDocument to parse and modify XML safely
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;
            
            // Load XML with error handling
            $oldErrorHandling = libxml_use_internal_errors(true);
            $loaded = $dom->loadXML($xmlContent);
            libxml_use_internal_errors($oldErrorHandling);
            
            if (!$loaded) {
                $zip->close();
                Log::error('Failed to parse XML content', ['path' => $sheetXmlPath]);
                return;
            }

            // Get or create sheetViews element
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            
            $sheetViews = $xpath->query('//x:sheetViews')->item(0);
            
            if (!$sheetViews) {
                // Create sheetViews element
                $worksheet = $dom->documentElement;
                $sheetViews = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'sheetViews');
                
                // Insert before sheetData or at the end
                $sheetData = $xpath->query('//x:sheetData')->item(0);
                if ($sheetData) {
                    $worksheet->insertBefore($sheetViews, $sheetData);
                } else {
                    $worksheet->appendChild($sheetViews);
                }
            }

            // Get or create sheetView element
            $sheetView = $xpath->query('.//x:sheetView', $sheetViews)->item(0);
            
            if (!$sheetView) {
                // Create sheetView element
                $sheetView = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'sheetView');
                if ($sheetView instanceof \DOMElement) {
                    $sheetView->setAttribute('workbookViewId', '0');
                }
                $sheetViews->appendChild($sheetView);
            }

            // Set rightToLeft attribute
            if ($sheetView instanceof \DOMElement) {
                $sheetView->setAttribute('rightToLeft', '1');
            }

            // Get modified XML
            $modifiedXml = $dom->saveXML();
            
            if ($modifiedXml === false) {
                $zip->close();
                Log::error('Failed to generate modified XML');
                return;
            }

            // Write the modified XML back to the ZIP
            $zip->deleteName($sheetXmlPath);
            $zip->addFromString($sheetXmlPath, $modifiedXml);
            $zip->close();
            
        } catch (\Exception $e) {
            $zip->close();
            Log::error('Error modifying XML for RTL', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    // Field definitions with their order and labels
    private function getFieldDefinitions(): array
    {
        return [
            'row_number' => ['label' => 'رقم', 'order' => 0, 'aliases' => ['م.', 'رقم', 'الترقيم', 'رقم السطر']],
            'full_name' => ['label' => 'الاسم الرباعي', 'order' => 1, 'aliases' => ['الاسم', 'الاسم الرباعي', 'اسم', 'الاسم الكامل']],
            'national_id' => ['label' => 'رقم الهوية', 'order' => 2, 'aliases' => ['رقم الهوية', 'الهوية', 'رقم الهوية الوطنية', 'هوية']],
            'phone' => ['label' => 'رقم الجوال', 'order' => 3, 'aliases' => ['رقم الجوال', 'الجوال', 'الهاتف', 'الرقم الأساسي']],
            'backup_phone' => ['label' => 'رقم احتياطي', 'order' => 3.5, 'aliases' => ['رقم احتياطي', 'جوال احتياطي', 'هاتف احتياطي']],
            'marital_status' => ['label' => 'الحالة الاجتماعية', 'order' => 4, 'aliases' => ['الحالة الاجتماعية', 'الحالة']],
            'spouse_name' => ['label' => 'اسم الزوج/الزوجة', 'order' => 5, 'aliases' => ['اسم الزوج/الزوجة', 'اسم الزوجة', 'اسم الزوج', 'الزوجة', 'الزوج']],
            'spouse_phone' => ['label' => 'جوال الزوج/الزوجة', 'order' => 6, 'aliases' => ['جوال الزوج/الزوجة', 'جوال الزوجة', 'جوال الزوج']],
            'spouse_national_id' => ['label' => 'هوية الزوج/الزوجة', 'order' => 7, 'aliases' => ['هوية الزوج/الزوجة', 'رقم هوية الزوجة', 'هوية الزوجة', 'هوية الزوج']],
            'primary_address' => ['label' => 'عنوان السكن الحالي', 'order' => 8, 'aliases' => ['عنوان السكن الحالي', 'العنوان الحالي', 'المحل', 'العنوان']],
            'previous_address' => ['label' => 'عنوان السكن السابق', 'order' => 9, 'aliases' => ['عنوان السكن السابق', 'العنوان السابق']],
            'region' => ['label' => 'المنطقة', 'order' => 10, 'aliases' => ['المنطقة', 'منطقة']],
            'locality' => ['label' => 'المحلية', 'order' => 11, 'aliases' => ['المحلية', 'محلية']],
            'branch' => ['label' => 'الشعبة', 'order' => 12, 'aliases' => ['الشعبة', 'شعبة']],
            'mosque' => ['label' => 'المسجد', 'order' => 13, 'aliases' => ['المسجد', 'مسجد']],
            'housing_type' => ['label' => 'طبيعة السكن', 'order' => 14, 'aliases' => ['طبيعة السكن', 'السكن']],
            'job_title' => ['label' => 'المهنة', 'order' => 15, 'aliases' => ['المهنة', 'الوظيفة']],
            'health_status' => ['label' => 'الحالة الصحية', 'order' => 16, 'aliases' => ['الحالة الصحية', 'الصحة']],
            'family_members_count' => ['label' => 'عدد أفراد الأسرة', 'order' => 17, 'aliases' => ['عدد أفراد الأسرة', 'عدد الافراد', 'عدد الأفراد']],
            'status' => ['label' => 'الحالة', 'order' => 18],
            'notes' => ['label' => 'ملاحظات', 'order' => 19],
            'entered_at' => ['label' => 'تاريخ الإدخال', 'order' => 20],
            'updated_at' => ['label' => 'تاريخ آخر تحديث', 'order' => 21],
            'family_member_name' => ['label' => 'أفراد الأسرة (الاسم)', 'order' => 22],
            'family_member_relation' => ['label' => 'أفراد الأسرة (صلة القرابة)', 'order' => 23],
            'family_member_national_id' => ['label' => 'أفراد الأسرة (رقم الهوية)', 'order' => 24],
            'family_member_phone' => ['label' => 'أفراد الأسرة (الجوال)', 'order' => 25],
            'family_member_birth_date' => ['label' => 'أفراد الأسرة (تاريخ الميلاد)', 'order' => 26],
            'family_member_health_status' => ['label' => 'أفراد الأسرة (الحالة الصحية)', 'order' => 27],
            'family_member_education_status' => ['label' => 'أفراد الأسرة (الحالة الدراسية)', 'order' => 28, 'aliases' => ['أفراد الأسرة (الحالة الدراسية)', 'الحالة الدراسية']],
            'family_member_needs_care' => ['label' => 'أفراد الأسرة (يحتاج رعاية)', 'order' => 29],
            'family_member_is_guardian' => ['label' => 'أفراد الأسرة (يعتبر عائلاً)', 'order' => 30],
            'family_member_notes' => ['label' => 'أفراد الأسرة (ملاحظات)', 'order' => 31],
        ];
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        // Increase execution time for large exports
        set_time_limit(600);
        ini_set('memory_limit', '512M'); // OpenSpout uses less memory
        
        // Disable output buffering for faster streaming
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        try {
            $search = $request->input('search', '');
            $status = $request->input('status', '');
            $direction = $request->input('direction', 'rtl');
            
            // Handle fields
            $selectedFields = $request->input('fields', []);
            if (is_string($selectedFields)) {
                $selectedFields = trim($selectedFields, '"\'');
                $decoded = json_decode($selectedFields, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $selectedFields = $decoded;
                } else {
                    $selectedFields = [];
                }
            }
            if (!is_array($selectedFields)) {
                $selectedFields = [];
            }

            // Build query - optimize for large exports
            $needsFamilyMembers = !empty(array_intersect($selectedFields, [
                'family_member_name', 'family_member_relation', 'family_member_national_id',
                'family_member_phone', 'family_member_birth_date', 'family_member_health_status',
                'family_member_education_status', 'family_member_needs_care', 'family_member_is_guardian', 'family_member_notes'
            ]));
            
            $query = Identity::query()
                ->when($needsFamilyMembers, function ($q) {
                    return $q->with(['familyMembers' => function ($query) {
                        $query->select(['id', 'identity_id', 'member_name', 'relation', 'national_id', 
                                       'phone', 'birth_date', 'health_status', 'education_status', 'needs_care', 'is_guardian', 'notes']);
                    }]);
                })
                ->select([
                    'id', 'national_id', 'full_name', 'phone', 'backup_phone', 'marital_status', 'family_members_count',
                    'spouse_name', 'spouse_phone', 'spouse_national_id', 'primary_address', 'previous_address',
                    'region', 'locality', 'branch', 'mosque', 'housing_type', 'job_title', 'health_status', 'notes', 'needs_review',
                    'entered_at', 'updated_at', 'created_at'
                ])
                ->when(!empty($search), function ($query) use ($search) {
                    return $query->where(function ($builder) use ($search) {
                        $builder->where('national_id', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                })
                ->when($status === 'pending', function ($query) {
                    return $query->where('needs_review', true);
                })
                ->when($status === 'verified', function ($query) {
                    return $query->where('needs_review', false);
                })
                ->latest('updated_at');

            $fieldDefinitions = $this->getFieldDefinitions();
            
            if (empty($selectedFields) || !is_array($selectedFields)) {
                $selectedFields = array_keys($fieldDefinitions);
            }

            $validFields = [];
            foreach ($selectedFields as $field) {
                if (isset($fieldDefinitions[$field])) {
                    $validFields[] = $field;
                }
            }
            
            if (empty($validFields)) {
                $validFields = array_keys($fieldDefinitions);
            }
            
            usort($validFields, function($a, $b) use ($fieldDefinitions) {
                return $fieldDefinitions[$a]['order'] <=> $fieldDefinitions[$b]['order'];
            });
            
            $selectedFields = $validFields;

            // Build headers
            $headers = [];
            foreach ($selectedFields as $field) {
                $headers[] = $fieldDefinitions[$field]['label'];
            }

            $filename = 'المستفيدين_' . date('Y-m-d_His') . '.xlsx';
            
            // Use hybrid approach: OpenSpout for speed + PhpSpreadsheet only for RTL metadata
            // Step 1: Write data quickly with OpenSpout to temp file
            $tempFile = sys_get_temp_dir() . '/export_' . uniqid() . '.xlsx';
            
            $options = new Options();
            $writer = new Writer($options);
            $writer->openToFile($tempFile);
            
            // Header style
            $headerStyle = (new Style())
                ->setFontBold()
                ->setFontSize(12);

            // Write header
            $writer->addRow(Row::fromValues($headers), $headerStyle);

            // Process data in large chunks for maximum speed
            $chunkSize = 2000;
            $rowNumber = 1;
            $totalProcessed = 0;
            
            $query->chunk($chunkSize, function ($identities) use ($writer, &$rowNumber, &$totalProcessed, $selectedFields, $needsFamilyMembers) {
                foreach ($identities as $identity) {
                    // Pre-process family members
                    $familyNames = '';
                    $familyRelations = '';
                    $familyNationalIds = '';
                    $familyPhones = '';
                    $familyBirthDates = '';
                    $familyHealthStatuses = '';
                    $familyEducationStatuses = '';
                    $familyNeedsCare = '';
                    $familyIsGuardian = '';
                    $familyNotes = '';
                    
                    if ($needsFamilyMembers && $identity->relationLoaded('familyMembers') && $identity->familyMembers->isNotEmpty()) {
                        $members = $identity->familyMembers;
                        $familyNames = $members->pluck('member_name')->filter()->join(' | ');
                        $familyRelations = $members->pluck('relation')->filter()->join(' | ');
                        $familyNationalIds = $members->pluck('national_id')->filter()->join(' | ');
                        $familyPhones = $members->pluck('phone')->filter()->join(' | ');
                        $familyBirthDates = $members->map(function ($member) {
                return $member->birth_date ? $member->birth_date->format('Y-m-d') : '';
                        })->filter()->join(' | ');
                        $familyHealthStatuses = $members->pluck('health_status')->filter()->join(' | ');
                        $familyEducationStatuses = $members->pluck('education_status')->filter()->join(' | ');
                        $familyNeedsCare = $members->map(function ($member) {
                return $member->needs_care ? 'نعم' : 'لا';
            })->join(' | ');
                        $familyIsGuardian = $members->map(function ($member) {
                return $member->is_guardian ? 'نعم' : 'لا';
            })->join(' | ');
                        $familyNotes = $members->pluck('notes')->filter()->join(' | ');
                    }

                    // Build data row
            $data = [];
            foreach ($selectedFields as $field) {
                        $value = match($field) {
                            'row_number' => $rowNumber,
                            'full_name' => $identity->full_name ?? '',
                            'national_id' => $identity->national_id ?? '',
                            'phone' => $identity->phone ?? '',
                            'backup_phone' => $identity->backup_phone ?? '',
                            'marital_status' => $identity->marital_status ?? '',
                            'spouse_name' => $identity->spouse_name ?? '',
                            'spouse_phone' => $identity->spouse_phone ?? '',
                            'spouse_national_id' => $identity->spouse_national_id ?? '',
                            'primary_address' => $identity->primary_address ?? '',
                            'previous_address' => $identity->previous_address ?? '',
                            'region' => $identity->region ?? '',
                            'locality' => $identity->locality ?? '',
                            'branch' => $identity->branch ?? '',
                            'mosque' => $identity->mosque ?? '',
                            'housing_type' => $identity->housing_type ?? '',
                            'job_title' => $identity->job_title ?? '',
                            'health_status' => $identity->health_status ?? '',
                            'family_members_count' => $identity->family_members_count ?? 0,
                            'status' => $identity->needs_review ? 'بانتظار المراجعة' : 'موثق',
                            'notes' => $identity->notes ?? '',
                            'entered_at' => $identity->entered_at?->format('Y-m-d H:i:s') ?? '',
                            'updated_at' => $identity->updated_at?->format('Y-m-d H:i:s') ?? '',
                            'family_member_name' => $familyNames,
                            'family_member_relation' => $familyRelations,
                            'family_member_national_id' => $familyNationalIds,
                            'family_member_phone' => $familyPhones,
                            'family_member_birth_date' => $familyBirthDates,
                            'family_member_health_status' => $familyHealthStatuses,
                            'family_member_education_status' => $familyEducationStatuses ?? '',
                            'family_member_needs_care' => $familyNeedsCare,
                            'family_member_is_guardian' => $familyIsGuardian,
                            'family_member_notes' => $familyNotes,
                            default => '',
                        };
                $data[] = $value;
            }

                    // Write row directly (streaming - no memory buildup)
                    $writer->addRow(Row::fromValues($data));
            $rowNumber++;
                    $totalProcessed++;
            }

                // Force garbage collection periodically
                if ($rowNumber % 5000 === 0) {
                gc_collect_cycles();
                }
            });
            
            $writer->close();
            
            // Log total processed for verification
            Log::info('Export completed', [
                'total_records' => $totalProcessed,
                'chunk_size' => $chunkSize,
                'direction' => $direction
            ]);
            
            // Step 2: Add RTL support by modifying XML directly (much faster than PhpSpreadsheet)
            if ($direction === 'rtl') {
                $this->addRTLToExcelFile($tempFile);
                    }
            
            // Step 3: Stream the final file
            return response()->stream(function () use ($tempFile) {
                readfile($tempFile);
                @unlink($tempFile); // Clean up temp file
            }, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ]);
        } catch (\Exception $e) {
            Log::error('Export Excel Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            
            return response()->stream(function () use ($e) {
                echo json_encode([
                    'error' => true,
                    'message' => 'حدث خطأ أثناء التصدير: ' . $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE);
            }, 500, [
                'Content-Type' => 'application/json; charset=utf-8',
            ]);
        }
    }

    public function importExcel(FileUploadRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $fileSize = $file->getSize(); // Size in bytes
            $filePath = $file->getRealPath();
            $selectedFields = json_decode($request->input('fields', '[]'), true) ?: [];
            $direction = $request->input('direction', 'rtl');
            
            // للملفات الكبيرة جداً فقط (أكثر من 10MB أو ~20,000 صف)، استخدم async
            // 10MB = 10 * 1024 * 1024 bytes
            $largeFileThreshold = 10 * 1024 * 1024; // 10MB
            $estimatedRows = $fileSize / 500; // تقدير تقريبي: ~500 bytes per row
            
            // فقط للملفات الكبيرة جداً (20,000+ صف) نستخدم async
            if ($fileSize > $largeFileThreshold && $estimatedRows > 20000) {
                // استخدام async import للملفات الكبيرة جداً فقط
                $asyncResponse = $this->importExcelAsync($request);
                $responseData = json_decode($asyncResponse->getContent(), true);
                if (isset($responseData['data']['job_id'])) {
                    $responseData['message'] = 'تم بدء عملية الاستيراد في الخلفية. الملف كبير جداً (' . round($estimatedRows) . ' صف تقريباً) وسيتم معالجته بشكل غير متزامن.';
                    return response()->json($responseData, $asyncResponse->getStatusCode());
                }
                return $asyncResponse;
            }
            
            // للملفات الصغيرة والمتوسطة (حتى 20,000 صف)، استخدم sync مباشر
            
            // Increase execution time and memory for large file imports
            set_time_limit(600); // 10 minutes for very large files
            ini_set('memory_limit', '512M'); // OpenSpout uses less memory
            
            // تقليل Logging في الإنتاج لتحسين الأداء
            if (config('app.debug')) {
                Log::info('Import request received (sync)', [
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $fileSize,
                    'estimated_rows' => round($estimatedRows),
                ]);
            }

            // تقليل Logging
            if (config('app.debug')) {
                Log::info('File validated', [
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                ]);
            }

            // Validate direction
            if (!in_array($direction, ['rtl', 'ltr'])) {
                $direction = 'rtl'; // Default to RTL if invalid
            }
            
            Log::info('Using direction from user selection', ['direction' => $direction]);

            // Step 2: Use OpenSpout Reader for fast reading
            $readerOptions = new ReaderOptions();
            $reader = new OpenSpoutReader($readerOptions);
            $reader->open($filePath);
            
            // Get field definitions
            $fieldDefinitions = $this->getFieldDefinitions();
            $allFieldLabels = array_map(fn($def) => $def['label'], $fieldDefinitions);
            $allAliases = [];
            foreach ($fieldDefinitions as $def) {
                if (isset($def['aliases']) && is_array($def['aliases'])) {
                    $allAliases = array_merge($allAliases, $def['aliases']);
                }
            }
            $allPossibleHeaders = array_merge($allFieldLabels, $allAliases);
            
            // Read rows to find header
            $headerRow = [];
            $headerRowNumber = 0;
            $maxRowsToCheck = 20;
            $rowIndex = 0;
            
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowIndex++;
                    if ($rowIndex > $maxRowsToCheck) {
                        break 2;
                    }
                    
                $rowData = [];
                    $cells = $row->getCells();
                    foreach ($cells as $cell) {
                        $value = $cell->getValue();
                        $rowData[] = trim($value ?? '');
                    }
                    
                    // Remove empty trailing cells
                    while (!empty($rowData) && empty(end($rowData))) {
                        array_pop($rowData);
                    }
                    
                    // Check if this row matches headers
                    $matchedHeaders = 0;
                    foreach ($rowData as $cellValue) {
                        if (!empty($cellValue)) {
                            foreach ($allPossibleHeaders as $possibleHeader) {
                                if ($cellValue === $possibleHeader) {
                                    $matchedHeaders++;
                                    break;
                                }
                            }
                    }
                }
                
                // If more than 2 cells match known headers, this is likely the header row
                if ($matchedHeaders > 2) {
                    $headerRow = $rowData;
                        $headerRowNumber = $rowIndex;
                    // تقليل Logging
                    if (config('app.debug')) {
                        Log::info('Header row found', [
                            'row_number' => $rowIndex,
                            'matched_headers' => $matchedHeaders,
                        ]);
                    }
                        break 2;
                    }
                }
            }
            
            // If no header row found, use first row
            if (empty($headerRow)) {
                Log::warning('No header row found, using first row', []);
                $reader->close();
                $reader->open($filePath);
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $cells = $row->getCells();
                $rowData = [];
                        foreach ($cells as $cell) {
                            $value = $cell->getValue();
                            $rowData[] = trim($value ?? '');
                        }
                        // Remove empty trailing cells
                        while (!empty($rowData) && empty(end($rowData))) {
                            array_pop($rowData);
                        }
                        $headerRow = $rowData;
                        $headerRowNumber = 1;
                        break 2;
                    }
                }
            }
            
            // Determine actual column count
            $highestColumnIndex = count($headerRow);
            
            // تقليل Logging
            if (config('app.debug')) {
                Log::info('Header row determined', [
                    'header_row_number' => $headerRowNumber,
                    'header_count' => count($headerRow),
                ]);
            }
            
            // If no fields selected, use all fields
            if (empty($selectedFields)) {
                $selectedFields = array_keys($fieldDefinitions);
            }

            // Map headers to field keys with flexible matching
            // Build columnMap based on original header row positions
            $columnMap = [];
            $usedColumns = []; // Track which columns have been mapped to avoid duplicates
            
            // تقليل Logging
            
            foreach ($headerRow as $colIndex => $header) {
                $headerTrimmed = trim($header ?? '');
                if (empty($headerTrimmed)) {
                    Log::debug("Skipping empty header", ['col_index' => $colIndex]);
                    continue;
                }
                
                // Skip if this column is already mapped
                if (in_array($colIndex, $usedColumns)) {
                    Log::debug("Skipping already mapped column", ['col_index' => $colIndex, 'header' => $headerTrimmed]);
                    continue;
                }
                
                $matched = false;
                foreach ($fieldDefinitions as $fieldKey => $def) {
                    // Skip if this field is already mapped (except for phone which can have multiple sources)
                    if (isset($columnMap[$fieldKey]) && $fieldKey !== 'phone') {
                        continue;
                    }
                    
                    // Exact match with label
                    if ($headerTrimmed === $def['label']) {
                        $columnMap[$fieldKey] = $colIndex;
                        $usedColumns[] = $colIndex;
                        $matched = true;
                        // تقليل Logging
                        break;
                    }
                    
                    // Match with aliases if available
                    if (isset($def['aliases']) && is_array($def['aliases'])) {
                        foreach ($def['aliases'] as $alias) {
                            if ($headerTrimmed === $alias) {
                                $columnMap[$fieldKey] = $colIndex;
                                $usedColumns[] = $colIndex;
                                $matched = true;
                                // تقليل Logging
                                break 2; // Break both loops
                            }
                        }
                    }
                }
                
                // تقليل Logging
            }
            
            // Special handling for phone: prefer "الرقم الأساسي" over "رقم احتياطي"
            // If both exist, use "الرقم الأساسي" for phone
            $primaryPhoneCol = null;
            $backupPhoneCol = null;
            foreach ($headerRow as $colIndex => $header) {
                $headerTrimmed = trim($header);
                if ($headerTrimmed === 'الرقم الأساسي') {
                    $primaryPhoneCol = $colIndex;
                } elseif ($headerTrimmed === 'رقم احتياطي') {
                    $backupPhoneCol = $colIndex;
                }
            }
            
            // Map phone to primary number if available, otherwise backup
            if ($primaryPhoneCol !== null && !isset($columnMap['phone'])) {
                $columnMap['phone'] = $primaryPhoneCol;
            } elseif ($backupPhoneCol !== null && !isset($columnMap['phone'])) {
                $columnMap['phone'] = $backupPhoneCol;
            }
            
            // Note: OpenSpout always reads from left to right, regardless of RTL metadata in Excel
            // When we export with RTL, we only set RTL metadata but don't reverse the data
            // So when importing, we read the data as-is (LTR) and don't need to reverse anything
            // The RTL direction parameter is only used for display purposes in Excel, not for data reading
            
            // تقليل Logging - فقط في debug mode
            if (config('app.debug')) {
                Log::info('Column mapping completed', [
                    'mapped_count' => count($columnMap),
                ]);
            }
            
            // Check if required fields are mapped - CRITICAL: fail early if not found
            if (empty($columnMap)) {
                $reader->close();
                Log::error('Column mapping is empty!', [
                    'headers' => $headerRow,
                    'field_definitions' => array_map(fn($def) => $def['label'], $fieldDefinitions),
                ]);
                return response()->json([
                    'message' => 'فشل في مطابقة أعمدة الملف. تأكد من أن أسماء الأعمدة في الملف تطابق الأسماء المتوقعة. الأعمدة الموجودة: ' . implode('، ', array_filter($headerRow)),
                ], 422);
            }
            
            if (!isset($columnMap['national_id'])) {
                $reader->close();
                Log::error('national_id column not found in file', [
                    'headers' => $headerRow,
                    'mapped_columns' => $columnMap,
                ]);
                return response()->json([
                    'message' => 'لم يتم العثور على عمود "رقم الهوية" في الملف. الأعمدة الموجودة: ' . implode('، ', array_filter($headerRow)),
                ], 422);
            }
            
            if (!isset($columnMap['full_name'])) {
                $reader->close();
                Log::error('full_name column not found in file', [
                    'headers' => $headerRow,
                    'mapped_columns' => $columnMap,
                ]);
                return response()->json([
                    'message' => 'لم يتم العثور على عمود "الاسم الرباعي" في الملف. الأعمدة الموجودة: ' . implode('، ', array_filter($headerRow)),
                ], 422);
            }
            
            // تقليل Logging

            // Process rows using OpenSpout Reader
            $imported = 0;
            $updated = 0;
            $created = 0;
            $errors = [];
            
            // Process in batches to optimize database operations
            $batchSize = 500; // batch size ثابت لتحسين الأداء والاستقرار
            $processedRows = 0;
            $currentRowIndex = 0;
            $batchData = []; // Store rows for batch processing
            
            // حساب total_rows تقريبي (للملفات الكبيرة التي تستخدم async)
            $fileSize = filesize($filePath);
            $estimatedTotal = max(0, (int)(($fileSize / 500) - $headerRowNumber));

            // إعادة فتح الملف للمعالجة (بعد العثور على header)
            $reader->close();
            $reader->open($filePath);
            
            // Process rows from file
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $currentRowIndex++;
                    
                    // Skip rows before header
                    if ($currentRowIndex <= $headerRowNumber) {
                        continue;
                    }
                    
                    // Read row data
                    // OpenSpout always reads from left to right, regardless of RTL metadata
                    // When we export, we don't reverse the data, so we read it as-is
                $rowData = [];
                    $cells = $row->getCells();
                    foreach ($cells as $cell) {
                        $value = $cell->getValue();
                        $rowData[] = trim($value ?? '');
                    }
                    
                    // Pad or trim row data to match header count
                    while (count($rowData) < $highestColumnIndex) {
                        $rowData[] = '';
                    }
                    $rowData = array_slice($rowData, 0, $highestColumnIndex);
                
                // Skip empty rows
                $rowHasData = !empty(array_filter($rowData, fn($v) => !empty(trim($v ?? ''))));
                if (!$rowHasData) {
                    continue;
                }
                
                // Additional check: Skip if this row looks like a header row
                // (contains only text that matches our field labels)
                $headerMatches = 0;
                foreach ($rowData as $cellValue) {
                    $cellValueTrimmed = trim($cellValue ?? '');
                    if (empty($cellValueTrimmed)) continue;
                    
                    // Check if this cell value matches any of our field labels
                    foreach ($fieldDefinitions as $def) {
                        if ($cellValueTrimmed === $def['label']) {
                            $headerMatches++;
                            break;
                        }
                        // Also check aliases
                        if (isset($def['aliases'])) {
                            foreach ($def['aliases'] as $alias) {
                                if ($cellValueTrimmed === $alias) {
                                    $headerMatches++;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                
                // If more than 3 cells match header labels, this is likely a header row
                if ($headerMatches > 3) {
                    continue; // Skip header-like rows
                }
                
                $processedRows++;
                
                    // Get values from mapped columns
                    // This reads from the file based on header matching, regardless of selectedFields
                    $getValue = function($fieldKey) use ($rowData, $columnMap) {
                        if (!isset($columnMap[$fieldKey])) {
                            return '';
                        }
                        $colIndex = $columnMap[$fieldKey];
                        $value = $rowData[$colIndex] ?? '';
                        return trim($value ?? '');
                    };

                    // Required fields check
                    // national_id and full_name are always required (even if not in selectedFields, we try to read them)
                    $nationalId = $getValue('national_id');
                    $fullName = $getValue('full_name');
                    
                    // تنظيف رقم الهوية من الأحرف والمسافات
                    if (!empty($nationalId)) {
                        $nationalId = preg_replace('/[^0-9]/', '', $nationalId);
                    }
                
                // تقليل Logging - فقط في debug mode
                    
                    // Check if required fields are present
                    $missingFields = [];
                    if (empty($nationalId)) {
                        $missingFields[] = 'رقم الهوية';
                    }
                    if (empty($fullName)) {
                        $missingFields[] = 'الاسم الرباعي';
                    }

                    if (!empty($missingFields)) {
                    $errorMsg = "السطر {$currentRowIndex}: بيانات ناقصة (" . implode('، ', $missingFields) . ")";
                        $errors[] = $errorMsg;
                    // تقليل Logging
                    continue;
                }
                
                // Store row data for batch processing
                $batchData[] = [
                    'row_index' => $currentRowIndex,
                            'national_id' => $nationalId,
                    'getValue' => $getValue,
                    'rowData' => $rowData,
                ];
                
                // Process batch when it reaches batchSize
                if (count($batchData) >= $batchSize) {
                    $this->processImportBatch($batchData, $selectedFields, $fieldDefinitions, $imported, $updated, $created, $errors);
                    $batchData = []; // Clear batch
                    
                    // Garbage collection كل 10 batches
                    if ($processedRows % (10 * $batchSize) === 0) {
                        gc_collect_cycles();
                    }
                }
                }
            }
            
            // Process remaining rows in batch
            if (!empty($batchData)) {
                $this->processImportBatch($batchData, $selectedFields, $fieldDefinitions, $imported, $updated, $created, $errors);
            }
            
            $reader->close();

            // Log final summary
            Log::info('Import completed', [
                'imported' => $imported,
                'created' => $created,
                'updated' => $updated,
                'errors_count' => count($errors),
                'total_rows' => $processedRows,
            ]);
            
            $message = "تم معالجة {$imported} سجل بنجاح.";
            if ($created > 0) {
                $message .= " تم إنشاء {$created} سجل جديد.";
            }
            if ($updated > 0) {
                $message .= " تم تحديث {$updated} سجل موجود.";
            }
            if (!empty($errors)) {
                $message .= " حدثت أخطاء في " . count($errors) . " سطر.";
            }

            return response()->json([
                'message' => $message,
                'data' => [
                    'imported' => $imported,
                    'created' => $created,
                    'updated' => $updated,
                    'errors' => array_slice($errors, 0, 50), // Limit errors to first 50 to avoid huge response
                    'errors_count' => count($errors),
                    'total_rows' => $processedRows,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Import validation error', [
                'errors' => $e->errors(),
            ]);
            return response()->json([
                'message' => 'خطأ في التحقق من الملف: ' . implode(', ', array_map(fn($err) => implode(', ', $err), $e->errors())),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Import Excel Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'message' => 'حدث خطأ أثناء قراءة الملف: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start async import - upload file and queue job
     */
    public function importExcelAsync(FileUploadRequest $request): JsonResponse
    {
        try {
            // File validation is handled by FileUploadRequest

            $file = $request->file('file');
            $selectedFields = json_decode($request->input('fields', '[]'), true) ?: [];
            $direction = $request->input('direction', 'rtl');

            // Ensure imports directory exists
            $importsDir = storage_path('app/imports');
            if (!is_dir($importsDir)) {
                mkdir($importsDir, 0755, true);
            }
            
            // Save file directly to storage
            $fileName = 'import_' . Str::random(40) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $fullPath = $importsDir . DIRECTORY_SEPARATOR . $fileName;
            
            // Move uploaded file to storage
            if (!$file->move($importsDir, $fileName)) {
                // Fallback: use storeAs
                $filePath = $file->storeAs('imports', $fileName, 'local');
                if ($filePath) {
                    $fullPath = storage_path('app/' . $filePath);
                } else {
                    throw new \Exception('فشل في حفظ الملف');
                }
            }
            
            // Verify file exists
            if (!file_exists($fullPath)) {
                throw new \Exception('الملف لم يتم حفظه بشكل صحيح: ' . $fullPath);
            }
            
            Log::info('File saved for async import', [
                'file_path' => $fullPath,
                'file_size' => filesize($fullPath),
                'exists' => file_exists($fullPath),
            ]);

            // حساب عدد الصفوف الفعلي قبل إنشاء Job
            // نعد جميع الصفوف (سيتم تحديثه لاحقاً بعد العثور على header)
            $totalRowsCount = 0;
            $readerOptions = new ReaderOptions();
            $tempReader = new OpenSpoutReader($readerOptions);
            $tempReader->open($fullPath);
            
            foreach ($tempReader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $totalRowsCount++;
                }
            }
            $tempReader->close();
            
            // Create import job record مع total_rows
            // نطرح 1 كافتراض (header في الصف الأول)، وسيتم تحديثه لاحقاً عند العثور على header الفعلي
            // هذا يعطي تقدير أولي، وسيتم تحديثه بدقة في executeImport
            $importJob = ImportJob::create([
                'job_id' => Str::uuid()->toString(),
                'file_path' => $fullPath,
                'file_name' => $file->getClientOriginalName(),
                'selected_fields' => $selectedFields,
                'direction' => $direction,
                'status' => 'pending',
                'total_rows' => max(0, $totalRowsCount - 1), // تقدير أولي (سيتم تحديثه بدقة لاحقاً)
            ]);

            // Dispatch job
            $job = new ImportExcelJob(
                $importJob->job_id,
                $fullPath,
                $file->getClientOriginalName(),
                $selectedFields,
                $direction
            );

            dispatch($job);

            return response()->json([
                'message' => 'تم بدء عملية الاستيراد',
                'data' => [
                    'job_id' => $importJob->job_id,
                    'status' => 'pending',
                ],
            ], 202);

        } catch (\Exception $e) {
            Log::error('Async import error: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get import job status
     */
    public function getImportStatus(string $jobId): JsonResponse
    {
        $importJob = ImportJob::where('job_id', $jobId)->first();

        if (!$importJob) {
            return response()->json([
                'message' => 'لم يتم العثور على عملية الاستيراد',
            ], 404);
        }

        return response()->json([
            'data' => [
                'job_id' => $importJob->job_id,
                'status' => $importJob->status,
                'total_rows' => $importJob->total_rows,
                'processed_rows' => $importJob->processed_rows,
                'imported' => $importJob->imported,
                'created' => $importJob->created,
                'updated' => $importJob->updated,
                'errors_count' => $importJob->errors_count,
                'errors' => $importJob->errors,
                'message' => $importJob->message,
                'error_message' => $importJob->error_message,
                'progress_percentage' => $importJob->progress_percentage,
                'estimated_time_remaining' => $importJob->estimated_time_remaining,
                'started_at' => $importJob->started_at?->toIso8601String(),
                'completed_at' => $importJob->completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Execute import process (extracted for use in Job)
     */
    public function executeImport(string $filePath, array $selectedFields, string $direction, ?string $importJobId = null): array
    {
        // Increase execution time and memory
        set_time_limit(600); // 10 minutes
        ini_set('memory_limit', '512M'); // OpenSpout uses less memory

        $importJob = $importJobId ? ImportJob::where('job_id', $importJobId)->first() : null;

        try {
            // Use OpenSpout Reader for fast reading
            $readerOptions = new ReaderOptions();
            $reader = new OpenSpoutReader($readerOptions);
            $reader->open($filePath);
            
            // Get field definitions
            $fieldDefinitions = $this->getFieldDefinitions();
            $allFieldLabels = array_map(fn($def) => $def['label'], $fieldDefinitions);
            $allAliases = [];
            foreach ($fieldDefinitions as $def) {
                if (isset($def['aliases']) && is_array($def['aliases'])) {
                    $allAliases = array_merge($allAliases, $def['aliases']);
                }
            }
            $allPossibleHeaders = array_merge($allFieldLabels, $allAliases);
            
            // Read rows to find header
            $headerRow = [];
            $headerRowNumber = 0;
            $maxRowsToCheck = 20;
            $rowIndex = 0;
            
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowIndex++;
                    if ($rowIndex > $maxRowsToCheck) {
                        break 2;
                    }
                    
                    $rowData = [];
                    $cells = $row->getCells();
                    foreach ($cells as $cell) {
                        $value = $cell->getValue();
                        $rowData[] = trim($value ?? '');
                    }
                    
                    while (!empty($rowData) && empty(end($rowData))) {
                        array_pop($rowData);
                    }
                    
                    $matchedHeaders = 0;
                    foreach ($rowData as $cellValue) {
                        if (!empty($cellValue)) {
                            foreach ($allPossibleHeaders as $possibleHeader) {
                                if ($cellValue === $possibleHeader) {
                                    $matchedHeaders++;
                                    break;
                                }
                            }
                        }
                    }
                    
                    if ($matchedHeaders > 2) {
                        $headerRow = $rowData;
                        $headerRowNumber = $rowIndex;
                        break 2;
                    }
                }
            }
            
            if (empty($headerRow)) {
                $reader->close();
                $reader->open($filePath);
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $cells = $row->getCells();
                        $rowData = [];
                        foreach ($cells as $cell) {
                            $value = $cell->getValue();
                            $rowData[] = trim($value ?? '');
                        }
                        while (!empty($rowData) && empty(end($rowData))) {
                            array_pop($rowData);
                        }
                        $headerRow = $rowData;
                        $headerRowNumber = 1;
                        break 2;
                    }
                }
            }
            
            $highestColumnIndex = count($headerRow);
            
            if (empty($selectedFields)) {
                $selectedFields = array_keys($fieldDefinitions);
            }

            // Map headers to field keys
            $columnMap = [];
            $usedColumns = [];
            
            foreach ($headerRow as $colIndex => $header) {
                $headerTrimmed = trim($header ?? '');
                if (empty($headerTrimmed) || in_array($colIndex, $usedColumns)) {
                        continue;
                    }

                $matched = false;
                foreach ($fieldDefinitions as $fieldKey => $def) {
                    if (isset($columnMap[$fieldKey]) && $fieldKey !== 'phone') {
                        continue;
                    }
                    
                    if ($headerTrimmed === $def['label']) {
                        $columnMap[$fieldKey] = $colIndex;
                        $usedColumns[] = $colIndex;
                        $matched = true;
                        break;
                    }
                    
                    if (isset($def['aliases']) && is_array($def['aliases'])) {
                        foreach ($def['aliases'] as $alias) {
                            if ($headerTrimmed === $alias) {
                                $columnMap[$fieldKey] = $colIndex;
                                $usedColumns[] = $colIndex;
                                $matched = true;
                                break 2;
                            }
                        }
                    }
                }
            }
            
            // Phone handling
            $primaryPhoneCol = null;
            $backupPhoneCol = null;
            foreach ($headerRow as $colIndex => $header) {
                $headerTrimmed = trim($header);
                if ($headerTrimmed === 'الرقم الأساسي') {
                    $primaryPhoneCol = $colIndex;
                } elseif ($headerTrimmed === 'رقم احتياطي') {
                    $backupPhoneCol = $colIndex;
                }
            }
            
            if ($primaryPhoneCol !== null && !isset($columnMap['phone'])) {
                $columnMap['phone'] = $primaryPhoneCol;
            } elseif ($backupPhoneCol !== null && !isset($columnMap['phone'])) {
                $columnMap['phone'] = $backupPhoneCol;
            }
            
            if (empty($columnMap) || !isset($columnMap['national_id']) || !isset($columnMap['full_name'])) {
                $reader->close();
                throw new \Exception('فشل في مطابقة أعمدة الملف');
            }

            // Process rows
            $imported = 0;
            $updated = 0;
            $created = 0;
            $errors = [];
            // Process in batches
            $batchSize = 500; // batch size ثابت
            $processedRows = 0;
            $currentRowIndex = 0;
            $batchData = [];
            
            // تحديث total_rows بالعدد الصحيح بعد العثور على header row
            // (يتم تحديثه دائماً لضمان الدقة حتى لو كان موجوداً مسبقاً)
            if ($importJob) {
                // عد الصفوف فعلياً من الملف
                $totalRowsCount = 0;
                $tempReader = new OpenSpoutReader($readerOptions);
                $tempReader->open($filePath);
                
                foreach ($tempReader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $totalRowsCount++;
                    }
                }
                $tempReader->close();
                
                // طرح header row (الآن نعرف موقعه بدقة)
                $actualTotal = max(0, $totalRowsCount - $headerRowNumber);
                
                // Log للتحقق
                if (config('app.debug')) {
                    Log::info('Updating total_rows', [
                        'total_rows_count' => $totalRowsCount,
                        'header_row_number' => $headerRowNumber,
                        'actual_total' => $actualTotal,
                        'previous_total' => $importJob->total_rows,
                    ]);
                }
                
                // تحديث total_rows دائماً لضمان الدقة
                $importJob->update(['total_rows' => $actualTotal]);
                $importJob->refresh();
            }

            // إعادة فتح الملف للمعالجة (بعد العثور على header)
            $reader->close();
            $reader->open($filePath);
            
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $currentRowIndex++;
                    
                    if ($currentRowIndex <= $headerRowNumber) {
                        continue;
                    }
                    
                    $rowData = [];
                    $cells = $row->getCells();
                    foreach ($cells as $cell) {
                        $value = $cell->getValue();
                        $rowData[] = trim($value ?? '');
                    }
                    
                    while (count($rowData) < $highestColumnIndex) {
                        $rowData[] = '';
                    }
                    $rowData = array_slice($rowData, 0, $highestColumnIndex);
                
                    $rowHasData = !empty(array_filter($rowData, fn($v) => !empty(trim($v ?? ''))));
                    if (!$rowHasData) {
                        continue;
                    }
                    
                    $headerMatches = 0;
                    foreach ($rowData as $cellValue) {
                        $cellValueTrimmed = trim($cellValue ?? '');
                        if (empty($cellValueTrimmed)) continue;
                        
                        foreach ($fieldDefinitions as $def) {
                            if ($cellValueTrimmed === $def['label']) {
                                $headerMatches++;
                                break;
                            }
                            if (isset($def['aliases'])) {
                                foreach ($def['aliases'] as $alias) {
                                    if ($cellValueTrimmed === $alias) {
                                        $headerMatches++;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                    
                    if ($headerMatches > 3) {
                        continue;
                    }
                    
                    $processedRows++;
                    
                    $getValue = function($fieldKey) use ($rowData, $columnMap) {
                        if (!isset($columnMap[$fieldKey])) {
                            return '';
                        }
                        $colIndex = $columnMap[$fieldKey];
                        $value = $rowData[$colIndex] ?? '';
                        return trim($value ?? '');
                    };

                    $nationalId = $getValue('national_id');
                    $fullName = $getValue('full_name');
                    
                    // تنظيف رقم الهوية من الأحرف والمسافات
                    if (!empty($nationalId)) {
                        $nationalId = preg_replace('/[^0-9]/', '', $nationalId);
                    }
                    
                    $missingFields = [];
                    if (empty($nationalId)) {
                        $missingFields[] = 'رقم الهوية';
                    }
                    if (empty($fullName)) {
                        $missingFields[] = 'الاسم الرباعي';
                    }
                    
                    if (!empty($missingFields)) {
                        $errorMsg = "السطر {$currentRowIndex}: بيانات ناقصة (" . implode('، ', $missingFields) . ")";
                        $errors[] = $errorMsg;
                        continue;
                    }
                    
                    $batchData[] = [
                        'row_index' => $currentRowIndex,
                        'national_id' => $nationalId,
                        'getValue' => $getValue,
                        'rowData' => $rowData,
                    ];
                    
                    if (count($batchData) >= $batchSize) {
                        $this->processImportBatch($batchData, $selectedFields, $fieldDefinitions, $imported, $updated, $created, $errors);
                        $batchData = [];
                        
                        // Garbage collection للملفات الكبيرة
                        if ($processedRows % (10 * $batchSize) === 0) {
                            gc_collect_cycles();
                        }
                        
                        // Update progress كل batch (500 صف) لتحسين الخط الزمني
                        if ($importJob && ($processedRows % $batchSize === 0)) {
                            $importJob->update([
                                'processed_rows' => $processedRows,
                                'imported' => $imported,
                                'created' => $created,
                                'updated' => $updated,
                                'errors_count' => count($errors),
                            ]);
                            // Refresh للـ model للحصول على progress_percentage المحدث
                            $importJob->refresh();
                        }
                    }
                }
            }
            
            if (!empty($batchData)) {
                $this->processImportBatch($batchData, $selectedFields, $fieldDefinitions, $imported, $updated, $created, $errors);
            }
            
            // تحديث نهائي للـ progress
            if ($importJob) {
                // تحديث total_rows بالعدد الفعلي المعالج (إذا كان أكبر من المقدّر)
                $finalTotalRows = max($importJob->total_rows ?? 0, $processedRows);
                
                $importJob->update([
                    'processed_rows' => $processedRows,
                    'imported' => $imported,
                    'created' => $created,
                    'updated' => $updated,
                    'errors_count' => count($errors),
                    'total_rows' => $finalTotalRows, // استخدام العدد الأكبر (المقدّر أو الفعلي)
                ]);
                $importJob->refresh();
            }
            
            $reader->close();
            
            $message = "تم معالجة {$imported} سجل بنجاح.";
            if ($created > 0) {
                $message .= " تم إنشاء {$created} سجل جديد.";
            }
            if ($updated > 0) {
                $message .= " تم تحديث {$updated} سجل موجود.";
            }
            if (!empty($errors)) {
                $message .= " حدثت أخطاء في " . count($errors) . " سطر.";
            }

            return [
                'imported' => $imported,
                'created' => $created,
                'updated' => $updated,
                'errors' => array_slice($errors, 0, 50),
                'errors_count' => count($errors),
                'total_rows' => $processedRows,
                'message' => $message,
            ];

        } catch (\Exception $e) {
            if ($reader ?? null) {
                $reader->close();
            }
            throw $e;
        }
    }

    /**
     * Process a batch of import rows with optimized database operations
     */
    private function processImportBatch(array $batchData, array $selectedFields, array $fieldDefinitions, int &$imported, int &$updated, int &$created, array &$errors): void
    {
        // Extract all national_ids for batch lookup
        $nationalIds = array_column($batchData, 'national_id');
        
        // Fetch all existing identities in one query
        $existingIdentities = Identity::whereIn('national_id', $nationalIds)
            ->get()
            ->keyBy('national_id');
        
        // تجميع البيانات للـ bulk operations
        $toInsert = [];
        $toUpdate = [];
        $identityIdsMap = []; // لتتبع identity IDs بعد الإدراج
        
        // Process each row in the batch
        foreach ($batchData as $rowData) {
            try {
                $getValue = $rowData['getValue'];
                $nationalId = $rowData['national_id'];
                $currentRowIndex = $rowData['row_index'];
                
                $identity = $existingIdentities->get($nationalId);

                $identityData = [];
                $identityData['national_id'] = $nationalId;
                $identityData['full_name'] = $getValue('full_name');
                
                // Phone: only include if selected in import fields
                if (in_array('phone', $selectedFields)) {
                    $identityData['phone'] = !empty($getValue('phone')) ? $getValue('phone') : null;
                } elseif ($identity) {
                    $identityData['phone'] = $identity->phone;
                } else {
                    $identityData['phone'] = null;
                }
                
                // Backup phone: only include if selected in import fields
                if (in_array('backup_phone', $selectedFields)) {
                    $identityData['backup_phone'] = !empty($getValue('backup_phone')) ? $getValue('backup_phone') : null;
                } elseif ($identity) {
                    $identityData['backup_phone'] = $identity->backup_phone;
                } else {
                    $identityData['backup_phone'] = null;
                }
                
                if (in_array('marital_status', $selectedFields)) $identityData['marital_status'] = $getValue('marital_status');
                if (in_array('spouse_name', $selectedFields)) $identityData['spouse_name'] = $getValue('spouse_name');
                if (in_array('spouse_phone', $selectedFields)) $identityData['spouse_phone'] = $getValue('spouse_phone');
                if (in_array('spouse_national_id', $selectedFields)) $identityData['spouse_national_id'] = $getValue('spouse_national_id');
                if (in_array('primary_address', $selectedFields)) $identityData['primary_address'] = $getValue('primary_address');
                if (in_array('previous_address', $selectedFields)) $identityData['previous_address'] = $getValue('previous_address');
                if (in_array('region', $selectedFields)) $identityData['region'] = $getValue('region');
                if (in_array('locality', $selectedFields)) $identityData['locality'] = $getValue('locality');
                if (in_array('branch', $selectedFields)) $identityData['branch'] = $getValue('branch');
                if (in_array('mosque', $selectedFields)) $identityData['mosque'] = $getValue('mosque');
                if (in_array('housing_type', $selectedFields)) $identityData['housing_type'] = $getValue('housing_type');
                if (in_array('job_title', $selectedFields)) $identityData['job_title'] = $getValue('job_title');
                if (in_array('health_status', $selectedFields)) $identityData['health_status'] = $getValue('health_status');
                if (in_array('notes', $selectedFields)) $identityData['notes'] = $getValue('notes');
                $identityData['needs_review'] = false;

                if ($identity) {
                    $identityData['entered_at'] = $identity->entered_at ?? now();
                    $identityData['updated_at'] = now();
                    $toUpdate[] = [
                        'id' => $identity->id,
                        'data' => $identityData,
                        'row_index' => $currentRowIndex,
                        'getValue' => $getValue,
                    ];
                } else {
                    $identityData['entered_at'] = now();
                    $identityData['created_at'] = now();
                    $identityData['updated_at'] = now();
                    $toInsert[] = [
                        'data' => $identityData,
                        'row_index' => $currentRowIndex,
                        'getValue' => $getValue,
                    ];
                }
            } catch (\Exception $e) {
                $errorMsg = "السطر {$rowData['row_index']}: " . $e->getMessage();
                $errors[] = $errorMsg;
                if (count($errors) <= 10) {
                    Log::error("Import row error", [
                        'row' => $rowData['row_index'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        // Bulk insert - التأكد من حفظ البيانات
        if (!empty($toInsert)) {
            try {
                DB::beginTransaction();
                
                $insertData = array_map(fn($item) => $item['data'], $toInsert);
                
                // إزالة المكررات من toInsert (إذا كان نفس national_id موجود أكثر من مرة في الـ batch)
                $uniqueInsertData = [];
                $seenNationalIds = [];
                $insertDataMap = []; // لتتبع البيانات الأصلية لكل national_id
                $duplicateInBatch = []; // لتتبع المكررات في نفس الـ batch
                
                foreach ($insertData as $index => $data) {
                    $nationalId = $data['national_id'];
                    if (!isset($seenNationalIds[$nationalId])) {
                        $uniqueInsertData[] = $data;
                        $seenNationalIds[$nationalId] = true;
                        $insertDataMap[$nationalId] = $toInsert[$index];
                    } else {
                        // إذا كان مكرراً في نفس الـ batch، نحفظه للمعالجة لاحقاً
                        $duplicateInBatch[] = [
                            'data' => $data,
                            'item' => $toInsert[$index],
                        ];
                    }
                }
                
                // إدراج البيانات الفريدة فقط
                if (!empty($uniqueInsertData)) {
                    Identity::insert($uniqueInsertData);
                    
                    // جلب الـ IDs المدرجة حديثاً
                    $insertedNationalIds = array_column($uniqueInsertData, 'national_id');
                    $insertedIdentities = Identity::whereIn('national_id', $insertedNationalIds)
                        ->get()
                        ->keyBy('national_id');
                    
                    // معالجة أفراد الأسرة للـ inserted records
                    foreach ($uniqueInsertData as $data) {
                        $nationalId = $data['national_id'];
                        $identity = $insertedIdentities->get($nationalId);
                        if ($identity && isset($insertDataMap[$nationalId])) {
                            $this->processFamilyMembers($identity, $insertDataMap[$nationalId]['getValue'], $selectedFields, $fieldDefinitions);
                            $created++;
                            $imported++;
                        }
                    }
                }
                
                // معالجة المكررات في نفس الـ batch (تحديث السجلات المدرجة للتو)
                foreach ($duplicateInBatch as $dup) {
                    $nationalId = $dup['data']['national_id'];
                    $identity = Identity::where('national_id', $nationalId)->first();
                    if ($identity) {
                        // تحديث السجل الموجود (الذي تم إدراجه للتو أو كان موجوداً مسبقاً)
                        $identity->update($dup['data']);
                        $this->processFamilyMembers($identity, $dup['item']['getValue'], $selectedFields, $fieldDefinitions);
                        $updated++;
                        $imported++;
                    } else {
                        $errors[] = "السطر {$dup['item']['row_index']}: رقم الهوية {$nationalId} مكرر في الملف. سيتم استخدام السجل الأول.";
                    }
                }
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                // إذا فشل بسبب duplicate، نجرب insertOrIgnore
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    // معالجة المكررات بشكل فردي
                    foreach ($toInsert as $item) {
                        try {
                            $identity = Identity::where('national_id', $item['data']['national_id'])->first();
                            if ($identity) {
                                // تحديث السجل الموجود
                                $identity->update($item['data']);
                                $this->processFamilyMembers($identity, $item['getValue'], $selectedFields, $fieldDefinitions);
                                $updated++;
                                $imported++;
                            } else {
                                // إدراج جديد
                                $identity = Identity::create($item['data']);
                                $this->processFamilyMembers($identity, $item['getValue'], $selectedFields, $fieldDefinitions);
                                $created++;
                                $imported++;
                            }
                        } catch (\Exception $e2) {
                            $errors[] = "السطر {$item['row_index']}: " . $e2->getMessage();
                        }
                    }
                } else {
                    throw $e;
                }
            }
        }
        
        // Bulk update - التأكد من حفظ البيانات
        if (!empty($toUpdate)) {
            try {
                DB::beginTransaction();
                
                // تجميع الـ updates
                foreach ($toUpdate as $item) {
                    Identity::where('id', $item['id'])->update($item['data']);
                    $updated++;
                }
                
                // جلب جميع الـ identities المحدثة في query واحدة
                $updatedIds = array_column($toUpdate, 'id');
                $updatedIdentities = Identity::whereIn('id', $updatedIds)
                    ->get()
                    ->keyBy('id');
                
                // معالجة أفراد الأسرة
                foreach ($toUpdate as $item) {
                    $identity = $updatedIdentities->get($item['id']);
                    if ($identity) {
                        $this->processFamilyMembers($identity, $item['getValue'], $selectedFields, $fieldDefinitions);
                        $imported++;
                    }
                }
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }
    }
    
    /**
     * معالجة أفراد الأسرة
     */
    private function processFamilyMembers($identity, $getValue, array $selectedFields, array $fieldDefinitions): void
    {
        // Handle family members if selected
        $familyFields = ['family_member_name', 'family_member_relation', 'family_member_national_id', 
                       'family_member_phone', 'family_member_birth_date', 'family_member_health_status',
                       'family_member_education_status', 'family_member_needs_care', 'family_member_is_guardian', 'family_member_notes'];
        
        if (array_intersect($familyFields, $selectedFields)) {
            $familyNames = !empty($getValue('family_member_name')) ? explode(' | ', $getValue('family_member_name')) : [];
            $familyRelations = !empty($getValue('family_member_relation')) ? explode(' | ', $getValue('family_member_relation')) : [];
            $familyNationalIds = !empty($getValue('family_member_national_id')) ? explode(' | ', $getValue('family_member_national_id')) : [];
            $familyPhones = !empty($getValue('family_member_phone')) ? explode(' | ', $getValue('family_member_phone')) : [];
            $familyBirthDates = !empty($getValue('family_member_birth_date')) ? explode(' | ', $getValue('family_member_birth_date')) : [];
            $familyHealthStatuses = !empty($getValue('family_member_health_status')) ? explode(' | ', $getValue('family_member_health_status')) : [];
            $familyEducationStatuses = !empty($getValue('family_member_education_status')) ? explode(' | ', $getValue('family_member_education_status')) : [];
            $familyNeedsCare = !empty($getValue('family_member_needs_care')) ? explode(' | ', $getValue('family_member_needs_care')) : [];
            $familyIsGuardian = !empty($getValue('family_member_is_guardian')) ? explode(' | ', $getValue('family_member_is_guardian')) : [];
            $familyNotes = !empty($getValue('family_member_notes')) ? explode(' | ', $getValue('family_member_notes')) : [];

            $identity->familyMembers()->delete();

            $maxCount = max(
                count($familyNames), count($familyRelations), count($familyNationalIds),
                count($familyPhones), count($familyBirthDates), count($familyHealthStatuses),
                count($familyEducationStatuses), count($familyNeedsCare), count($familyIsGuardian), count($familyNotes)
            );

            $familyMembersData = [];
            for ($i = 0; $i < $maxCount; $i++) {
                $memberName = trim($familyNames[$i] ?? '');
                if (empty($memberName)) continue;

                $birthDate = !empty($familyBirthDates[$i]) ? trim($familyBirthDates[$i]) : null;
                if ($birthDate) {
                    try {
                        $birthDate = \Carbon\Carbon::parse($birthDate)->format('Y-m-d');
                    } catch (\Exception $e) {
                        $birthDate = null;
                    }
                }

                $familyMembersData[] = [
                    'identity_id' => $identity->id,
                    'member_name' => $memberName,
                    'relation' => trim($familyRelations[$i] ?? ''),
                    'national_id' => trim($familyNationalIds[$i] ?? ''),
                    'phone' => trim($familyPhones[$i] ?? ''),
                    'birth_date' => $birthDate,
                    'health_status' => trim($familyHealthStatuses[$i] ?? ''),
                    'education_status' => trim($familyEducationStatuses[$i] ?? ''),
                    'is_guardian' => in_array(strtolower(trim($familyIsGuardian[$i] ?? '')), ['نعم', 'yes', '1', 'true']),
                    'needs_care' => in_array(strtolower(trim($familyNeedsCare[$i] ?? '')), ['نعم', 'yes', '1', 'true']),
                    'notes' => trim($familyNotes[$i] ?? ''),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            
            if (!empty($familyMembersData)) {
                FamilyMember::insert($familyMembersData);
            }

            $identity->update([
                'family_members_count' => count($familyMembersData),
            ]);
        }
    }

    /**
     * Start async export - queue job
     */
    public function exportExcelAsync(Request $request): JsonResponse
    {
        try {
            $search = $request->input('search', '');
            $status = $request->input('status', '');
            $direction = $request->input('direction', 'rtl');
            
            // Handle fields
            $selectedFields = $request->input('fields', []);
            if (is_string($selectedFields)) {
                $selectedFields = trim($selectedFields, '"\'');
                $decoded = json_decode($selectedFields, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $selectedFields = $decoded;
                } else {
                    $selectedFields = [];
                }
            }
            if (!is_array($selectedFields)) {
                $selectedFields = [];
            }

            $fieldDefinitions = $this->getFieldDefinitions();
            
            if (empty($selectedFields) || !is_array($selectedFields)) {
                $selectedFields = array_keys($fieldDefinitions);
            }

            $validFields = [];
            foreach ($selectedFields as $field) {
                if (isset($fieldDefinitions[$field])) {
                    $validFields[] = $field;
                }
            }
            
            if (empty($validFields)) {
                $validFields = array_keys($fieldDefinitions);
            }
            
            usort($validFields, function($a, $b) use ($fieldDefinitions) {
                return $fieldDefinitions[$a]['order'] <=> $fieldDefinitions[$b]['order'];
            });
            
            $selectedFields = $validFields;

            $filename = 'المستفيدين_' . date('Y-m-d_His') . '.xlsx';

            // Create export job record
            $exportJob = ExportJob::create([
                'job_id' => Str::uuid()->toString(),
                'file_name' => $filename,
                'selected_fields' => $selectedFields,
                'direction' => $direction,
                'search' => $search,
                'status' => 'pending',
            ]);

            // Dispatch job
            $job = new ExportExcelJob(
                $exportJob->job_id,
                $selectedFields,
                $direction,
                $search ?: null,
                $status ?: null
            );

            dispatch($job);

            return response()->json([
                'message' => 'تم بدء عملية التصدير',
                'data' => [
                    'job_id' => $exportJob->job_id,
                    'status' => 'pending',
                ],
            ], 202);

        } catch (\Exception $e) {
            Log::error('Async export error: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start async PDF export - queue job
     */
    public function exportPdfAsync(Request $request): JsonResponse
    {
        try {
            $search = $request->input('search', '');
            $status = $request->input('status', '');
            $direction = $request->input('direction', 'rtl');
            
            // Handle fields
            $selectedFields = $request->input('fields', []);
            if (is_string($selectedFields)) {
                $selectedFields = trim($selectedFields, '"\'');
                $decoded = json_decode($selectedFields, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $selectedFields = $decoded;
                } else {
                    $selectedFields = [];
                }
            }
            if (!is_array($selectedFields)) {
                $selectedFields = [];
            }

            $fieldDefinitions = $this->getFieldDefinitions();
            
            if (empty($selectedFields) || !is_array($selectedFields)) {
                $selectedFields = array_keys($fieldDefinitions);
            }

            $validFields = [];
            foreach ($selectedFields as $field) {
                if (isset($fieldDefinitions[$field])) {
                    $validFields[] = $field;
                }
            }
            
            if (empty($validFields)) {
                $validFields = array_keys($fieldDefinitions);
            }
            
            usort($validFields, function($a, $b) use ($fieldDefinitions) {
                return $fieldDefinitions[$a]['order'] <=> $fieldDefinitions[$b]['order'];
            });
            
            $selectedFields = $validFields;

            $filename = 'المستفيدين_' . date('Y-m-d_His') . '.pdf';

            // Create export job record
            $exportJob = ExportJob::create([
                'job_id' => Str::uuid()->toString(),
                'file_name' => $filename,
                'selected_fields' => $selectedFields,
                'direction' => $direction,
                'search' => $search,
                'status' => 'pending',
            ]);

            // Dispatch job
            $job = new ExportPdfJob(
                $exportJob->job_id,
                $selectedFields,
                $direction,
                $search ?: null,
                $status ?: null
            );

            dispatch($job);

            return response()->json([
                'message' => 'تم بدء عملية التصدير',
                'data' => [
                    'job_id' => $exportJob->job_id,
                    'status' => 'pending',
                ],
            ], 202);

        } catch (\Exception $e) {
            Log::error('Async PDF export error: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get export job status
     */
    public function getExportStatus(string $jobId): JsonResponse
    {
        $exportJob = ExportJob::where('job_id', $jobId)->first();

        if (!$exportJob) {
            return response()->json([
                'message' => 'لم يتم العثور على عملية التصدير',
            ], 404);
        }

        return response()->json([
            'data' => [
                'job_id' => $exportJob->job_id,
                'status' => $exportJob->status,
                'total_rows' => $exportJob->total_rows,
                'processed_rows' => $exportJob->processed_rows,
                'file_url' => $exportJob->file_url,
                'file_name' => $exportJob->file_name,
                'message' => $exportJob->message,
                'error_message' => $exportJob->error_message,
                'progress_percentage' => $exportJob->progress_percentage,
                'estimated_time_remaining' => $exportJob->estimated_time_remaining,
                'started_at' => $exportJob->started_at?->toIso8601String(),
                'completed_at' => $exportJob->completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Core export logic, callable by both sync and async methods.
     * @return array Summary of export results
     */
    public function executeExport(array $selectedFields, string $direction, ?string $search = null, ?string $status = null, ?string $exportJobId = null): array
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');
        
        $exportJob = $exportJobId ? ExportJob::where('job_id', $exportJobId)->first() : null;

        try {
            // Build query - optimize for large exports
            $needsFamilyMembers = !empty(array_intersect($selectedFields, [
                'family_member_name', 'family_member_relation', 'family_member_national_id',
                'family_member_phone', 'family_member_birth_date', 'family_member_health_status',
                'family_member_education_status', 'family_member_needs_care', 'family_member_is_guardian', 'family_member_notes'
            ]));
            
            $query = Identity::query()
                ->when($needsFamilyMembers, function ($q) {
                    return $q->with(['familyMembers' => function ($query) {
                        $query->select(['id', 'identity_id', 'member_name', 'relation', 'national_id', 
                                       'phone', 'birth_date', 'health_status', 'education_status', 'needs_care', 'is_guardian', 'notes']);
                    }]);
                })
                ->select([
                    'id', 'national_id', 'full_name', 'phone', 'backup_phone', 'marital_status', 'family_members_count',
                    'spouse_name', 'spouse_phone', 'spouse_national_id', 'primary_address', 'previous_address',
                    'region', 'locality', 'branch', 'mosque', 'housing_type', 'job_title', 'health_status', 'notes', 'needs_review',
                    'entered_at', 'updated_at', 'created_at'
                ])
                ->when(!empty($search), function ($query) use ($search) {
                    return $query->where(function ($builder) use ($search) {
                        $builder->where('national_id', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                })
                ->when($status === 'pending', function ($query) {
                    return $query->where('needs_review', true);
                })
                ->when($status === 'verified', function ($query) {
                    return $query->where('needs_review', false);
                })
                ->latest('updated_at');

            // Count total rows
            $totalRows = $query->count();
            
            if ($exportJob) {
                $exportJob->update(['total_rows' => $totalRows]);
            }

            $fieldDefinitions = $this->getFieldDefinitions();

            // Build headers
            $headers = [];
            foreach ($selectedFields as $field) {
                $headers[] = $fieldDefinitions[$field]['label'];
            }

            $filename = 'المستفيدين_' . date('Y-m-d_His') . '.xlsx';
            
            // Ensure exports directory exists
            $exportsDir = storage_path('app/exports');
            if (!is_dir($exportsDir)) {
                mkdir($exportsDir, 0755, true);
            }
            
            $filePath = $exportsDir . DIRECTORY_SEPARATOR . 'export_' . ($exportJobId ?: uniqid()) . '_' . $filename;
            
            $options = new Options();
            $writer = new Writer($options);
            $writer->openToFile($filePath);
            
            // Header style
            $headerStyle = (new Style())
                ->setFontBold()
                ->setFontSize(12);

            // Write header
            $writer->addRow(Row::fromValues($headers), $headerStyle);

            // Process data in large chunks for maximum speed
            $chunkSize = 2000;
            $rowNumber = 1;
            $totalProcessed = 0;
            
            $query->chunk($chunkSize, function ($identities) use ($writer, &$rowNumber, &$totalProcessed, $selectedFields, $needsFamilyMembers, $exportJob, $exportJobId) {
                foreach ($identities as $identity) {
                    // Pre-process family members
                    $familyNames = '';
                    $familyRelations = '';
                    $familyNationalIds = '';
                    $familyPhones = '';
                    $familyBirthDates = '';
                    $familyHealthStatuses = '';
                    $familyNeedsCare = '';
                    $familyIsGuardian = '';
                    $familyNotes = '';
                    
                    if ($needsFamilyMembers && $identity->relationLoaded('familyMembers') && $identity->familyMembers->isNotEmpty()) {
                        $members = $identity->familyMembers;
                        $familyNames = $members->pluck('member_name')->filter()->join(' | ');
                        $familyRelations = $members->pluck('relation')->filter()->join(' | ');
                        $familyNationalIds = $members->pluck('national_id')->filter()->join(' | ');
                        $familyPhones = $members->pluck('phone')->filter()->join(' | ');
                        $familyBirthDates = $members->map(function ($member) {
                            return $member->birth_date ? $member->birth_date->format('Y-m-d') : '';
                        })->filter()->join(' | ');
                        $familyHealthStatuses = $members->pluck('health_status')->filter()->join(' | ');
                        $familyEducationStatuses = $members->pluck('education_status')->filter()->join(' | ');
                        $familyNeedsCare = $members->map(function ($member) {
                return $member->needs_care ? 'نعم' : 'لا';
            })->join(' | ');
                        $familyIsGuardian = $members->map(function ($member) {
                            return $member->is_guardian ? 'نعم' : 'لا';
                        })->join(' | ');
                        $familyNotes = $members->pluck('notes')->filter()->join(' | ');
                    }

                    // Build data row
                    $data = [];
                    foreach ($selectedFields as $field) {
                        $value = match($field) {
                            'row_number' => $rowNumber,
                            'full_name' => $identity->full_name ?? '',
                            'national_id' => $identity->national_id ?? '',
                            'phone' => $identity->phone ?? '',
                            'backup_phone' => $identity->backup_phone ?? '',
                            'marital_status' => $identity->marital_status ?? '',
                            'spouse_name' => $identity->spouse_name ?? '',
                            'spouse_phone' => $identity->spouse_phone ?? '',
                            'spouse_national_id' => $identity->spouse_national_id ?? '',
                            'primary_address' => $identity->primary_address ?? '',
                            'previous_address' => $identity->previous_address ?? '',
                            'region' => $identity->region ?? '',
                            'locality' => $identity->locality ?? '',
                            'branch' => $identity->branch ?? '',
                            'mosque' => $identity->mosque ?? '',
                            'housing_type' => $identity->housing_type ?? '',
                            'job_title' => $identity->job_title ?? '',
                            'health_status' => $identity->health_status ?? '',
                            'family_members_count' => $identity->family_members_count ?? 0,
                            'status' => $identity->needs_review ? 'بانتظار المراجعة' : 'موثق',
                            'notes' => $identity->notes ?? '',
                            'entered_at' => $identity->entered_at?->format('Y-m-d H:i:s') ?? '',
                            'updated_at' => $identity->updated_at?->format('Y-m-d H:i:s') ?? '',
                            'family_member_name' => $familyNames,
                            'family_member_relation' => $familyRelations,
                            'family_member_national_id' => $familyNationalIds,
                            'family_member_phone' => $familyPhones,
                            'family_member_birth_date' => $familyBirthDates,
                            'family_member_health_status' => $familyHealthStatuses,
                            'family_member_education_status' => $familyEducationStatuses ?? '',
                            'family_member_needs_care' => $familyNeedsCare,
                            'family_member_is_guardian' => $familyIsGuardian,
                            'family_member_notes' => $familyNotes,
                            default => '',
                        };
                        $data[] = $value;
                    }

                    // Write row directly (streaming - no memory buildup)
                    $writer->addRow(Row::fromValues($data));
                    $rowNumber++;
                    $totalProcessed++;
                    
                    // Update progress periodically
                    if ($exportJob && $totalProcessed % 500 === 0) {
                        $exportJob->update(['processed_rows' => $totalProcessed]);
                    }
                }

                // Force garbage collection periodically
                if ($rowNumber % 5000 === 0) {
                    gc_collect_cycles();
                }
            });
            
            $writer->close();
            
            // Add RTL support by modifying XML directly
            if ($direction === 'rtl') {
                $this->addRTLToExcelFile($filePath);
            }
            
            // Generate download URL using job_id instead of filename to avoid encoding issues
            $fileUrl = url('/api/admin/export/download/' . $exportJobId);
            
            $message = "تم تصدير {$totalProcessed} سجل بنجاح.";

            return [
                'total_rows' => $totalProcessed,
                'file_path' => $filePath,
                'file_url' => $fileUrl,
                'message' => $message,
            ];
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Core PDF export logic, callable by async method.
     * @return array Summary of export results
     */
    public function executeExportPdf(array $selectedFields, string $direction, ?string $search = null, ?string $status = null, ?string $exportJobId = null): array
    {
        set_time_limit(1800); // 30 minutes
        ini_set('memory_limit', '2048M'); // 2GB for very large PDFs
        
        $exportJob = $exportJobId ? ExportJob::where('job_id', $exportJobId)->first() : null;

        try {
            // Build query - same as Excel export
            $needsFamilyMembers = !empty(array_intersect($selectedFields, [
                'family_member_name', 'family_member_relation', 'family_member_national_id',
                'family_member_phone', 'family_member_birth_date', 'family_member_health_status',
                'family_member_education_status', 'family_member_needs_care', 'family_member_is_guardian', 'family_member_notes'
            ]));
            
            $query = Identity::query()
                ->when($needsFamilyMembers, function ($q) {
                    return $q->with(['familyMembers' => function ($query) {
                        $query->select(['id', 'identity_id', 'member_name', 'relation', 'national_id', 
                                       'phone', 'birth_date', 'health_status', 'education_status', 'needs_care', 'is_guardian', 'notes']);
                    }]);
                })
                ->select([
                    'id', 'national_id', 'full_name', 'phone', 'backup_phone', 'marital_status', 'family_members_count',
                    'spouse_name', 'spouse_phone', 'spouse_national_id', 'primary_address', 'previous_address',
                    'region', 'locality', 'branch', 'mosque', 'housing_type', 'job_title', 'health_status', 'notes', 'needs_review',
                    'entered_at', 'updated_at', 'created_at'
                ])
                ->when(!empty($search), function ($query) use ($search) {
                    return $query->where(function ($builder) use ($search) {
                        $builder->where('national_id', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                })
                ->when($status === 'pending', function ($query) {
                    return $query->where('needs_review', true);
                })
                ->when($status === 'verified', function ($query) {
                    return $query->where('needs_review', false);
                })
                ->latest('updated_at');

            // Count total rows
            $totalRows = $query->count();
            
            if ($exportJob) {
                $exportJob->update(['total_rows' => $totalRows]);
            }

            $fieldDefinitions = $this->getFieldDefinitions();

            // Build headers
            $headers = [];
            foreach ($selectedFields as $field) {
                if (isset($fieldDefinitions[$field])) {
                    $headers[] = $fieldDefinitions[$field]['label'];
                }
            }

            // Ensure exports directory exists
            $exportsDir = storage_path('app/exports');
            if (!is_dir($exportsDir)) {
                mkdir($exportsDir, 0755, true);
            }
            
            $filename = 'المستفيدين_' . date('Y-m-d_His') . '.pdf';
            $filePath = $exportsDir . DIRECTORY_SEPARATOR . 'export_' . ($exportJobId ?: uniqid()) . '_' . $filename;

            // Include TCPDF library - try multiple paths for queue compatibility
            $tcpdfPath = null;
            
            // Get base path - handle both web and queue contexts
            $basePath = base_path();
            if (empty($basePath) || !is_dir($basePath)) {
                // Fallback: try to detect from current file location
                $basePath = realpath(__DIR__ . '/../../..');
            }
            
            $possiblePaths = [
                $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'tecnickcom' . DIRECTORY_SEPARATOR . 'tcpdf' . DIRECTORY_SEPARATOR . 'tcpdf.php',
                realpath(__DIR__ . '/../../../vendor/tecnickcom/tcpdf/tcpdf.php'),
                realpath(app_path('../vendor/tecnickcom/tcpdf/tcpdf.php')),
                realpath(__DIR__ . '/../../../../vendor/tecnickcom/tcpdf/tcpdf.php'),
                getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'tecnickcom' . DIRECTORY_SEPARATOR . 'tcpdf' . DIRECTORY_SEPARATOR . 'tcpdf.php',
            ];
            
            foreach ($possiblePaths as $path) {
                if ($path && is_string($path) && file_exists($path)) {
                    $tcpdfPath = $path;
                    Log::info('TCPDF library found', ['path' => $tcpdfPath]);
                    break;
                }
            }
            
            if (!$tcpdfPath || !file_exists($tcpdfPath)) {
                $errorMsg = 'TCPDF library not found. Tried paths: ' . implode(', ', array_filter($possiblePaths));
                Log::error('TCPDF library not found', [
                    'tried_paths' => $possiblePaths,
                    'base_path' => $basePath,
                    'app_path' => app_path(),
                    'current_dir' => __DIR__,
                    'cwd' => getcwd(),
                ]);
                throw new \Exception($errorMsg);
            }
            
            // Use require (not require_once) to ensure it loads in queue context
            if (!class_exists('TCPDF', false)) {
                require($tcpdfPath);
            }
            
            // Verify TCPDF was loaded
            if (!class_exists('TCPDF', false)) {
                throw new \Exception('TCPDF class not found after including library from: ' . $tcpdfPath);
            }
            
            // Define TCPDF constants if not defined
            if (!defined('PDF_UNIT')) {
                define('PDF_UNIT', 'mm');
            }
            if (!defined('PDF_PAGE_FORMAT')) {
                define('PDF_PAGE_FORMAT', 'A4');
            }
            
            // Check if TCPDF class exists
            if (!class_exists('TCPDF')) {
                throw new \Exception('TCPDF class not found after including library from: ' . $tcpdfPath);
            }
            
            // Create PDF using TCPDF - excellent Arabic support
            $pdf = new \TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator('لجنة طوارئ الشجاعية');
            $pdf->SetAuthor('لجنة طوارئ الشجاعية');
            $pdf->SetTitle('تقرير المستفيدين');
            $pdf->SetSubject('تقرير المستفيدين');
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set margins (left, top, right)
            $leftMargin = 10;
            $topMargin = 10;
            $rightMargin = 10;
            $pdf->SetMargins($leftMargin, $topMargin, $rightMargin);
            $pdf->SetAutoPageBreak(TRUE, 10);
            
            // Set RTL if direction is RTL
            // Note: TCPDF's setRTL affects text direction, but we'll handle table layout manually
            if ($direction === 'rtl') {
                $pdf->setRTL(true);
            }
            
            // Set font - use DejaVu Sans which supports Arabic
            $pdf->SetFont('dejavusans', '', 8);
            
            // Add a page
            $pdf->AddPage();
            
            // Header
            $pdf->SetFont('dejavusans', 'B', 16);
            $pdf->Cell(0, 10, 'لجنة طوارئ الشجاعية', 0, 1, 'C');
            $pdf->SetFont('dejavusans', 'B', 14);
            $pdf->Cell(0, 8, 'تقرير المستفيدين', 0, 1, 'C');
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->Cell(0, 6, 'تاريخ التصدير: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
            $pdf->Ln(5);
            
            // Calculate column widths
            $pageWidth = $pdf->getPageWidth() - 20; // Minus margins
            $numColumns = count($headers);
            $colWidth = $pageWidth / $numColumns;
            
            // Table header
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->SetFillColor(14, 165, 233); // Blue background
            $pdf->SetTextColor(255, 255, 255); // White text
            $pdf->SetDrawColor(221, 221, 221); // Gray border
            
            // For RTL, we need to reverse the column order and start from the right
            if ($direction === 'rtl') {
                // Reverse headers so first column appears on the right
                $displayHeaders = array_reverse($headers);
                // Calculate total width of all columns
                $totalWidth = $colWidth * count($displayHeaders);
                // Start position: right margin minus total width (using stored margin value)
                $x = $pdf->getPageWidth() - $rightMargin - $totalWidth;
                // Reset X position to start from calculated position
                $pdf->SetX($x);
                // Add columns in reversed order (first column will be on the right)
                foreach ($displayHeaders as $header) {
                    $pdf->Cell($colWidth, 8, $header, 1, 0, 'C', true);
                }
            } else {
                // LTR: start from left margin (using stored margin value)
                $pdf->SetX($leftMargin);
                foreach ($headers as $header) {
                    $pdf->Cell($colWidth, 8, $header, 1, 0, 'C', true);
                }
            }
            $pdf->Ln();
            
            // Reset text color for data rows
            $pdf->SetTextColor(0, 0, 0); // Black text
            $pdf->SetFont('dejavusans', '', 7);

            // For very large datasets (>5000), we'll limit to Excel export only
            // PDF export is not suitable for datasets this large due to memory limitations
            if ($totalRows > 5000) {
                throw new \Exception('تصدير PDF غير متاح للبيانات الكبيرة (' . number_format($totalRows) . ' سجل). يرجى استخدام تصدير Excel بدلاً من ذلك.');
            }
            
            // Process data in chunks for smaller datasets
            $chunkSize = 500;
            $rowNumber = 1;
            $totalProcessed = 0;
            $fill = false; // For alternating row colors
            
            $query->chunk($chunkSize, function ($identities) use (&$pdf, &$rowNumber, &$totalProcessed, &$fill, $selectedFields, $needsFamilyMembers, $exportJob, $exportJobId, $totalRows, $colWidth, $direction, $leftMargin, $rightMargin) {
                foreach ($identities as $identity) {
                    // Pre-process family members (same as Excel)
                    $familyNames = '';
                    $familyRelations = '';
                    $familyNationalIds = '';
                    $familyPhones = '';
                    $familyBirthDates = '';
                    $familyHealthStatuses = '';
                    $familyEducationStatuses = '';
                    $familyNeedsCare = '';
                    $familyIsGuardian = '';
                    $familyNotes = '';
                    
                    if ($needsFamilyMembers && $identity->relationLoaded('familyMembers') && $identity->familyMembers->isNotEmpty()) {
                        $members = $identity->familyMembers;
                        $familyNames = $members->pluck('member_name')->filter()->join(' | ');
                        $familyRelations = $members->pluck('relation')->filter()->join(' | ');
                        $familyNationalIds = $members->pluck('national_id')->filter()->join(' | ');
                        $familyPhones = $members->pluck('phone')->filter()->join(' | ');
                        $familyBirthDates = $members->map(function ($member) {
                            return $member->birth_date ? $member->birth_date->format('Y-m-d') : '';
                        })->filter()->join(' | ');
                        $familyHealthStatuses = $members->pluck('health_status')->filter()->join(' | ');
                        $familyEducationStatuses = $members->pluck('education_status')->filter()->join(' | ');
                        $familyNeedsCare = $members->map(function ($member) {
                            return $member->needs_care ? 'نعم' : 'لا';
                        })->join(' | ');
                        $familyIsGuardian = $members->map(function ($member) {
                            return $member->is_guardian ? 'نعم' : 'لا';
                        })->join(' | ');
                        $familyNotes = $members->pluck('notes')->filter()->join(' | ');
                    }

                    // Alternate row colors
                    if ($fill) {
                        $pdf->SetFillColor(249, 249, 249); // Light gray
                    } else {
                        $pdf->SetFillColor(255, 255, 255); // White
                    }
                    $fill = !$fill;
                    
                    // Build row data
                    $rowData = [];
                    foreach ($selectedFields as $field) {
                        $value = match($field) {
                            'row_number' => (string)$rowNumber,
                            'full_name' => $identity->full_name ?? '',
                            'national_id' => $identity->national_id ?? '',
                            'phone' => $identity->phone ?? '',
                            'backup_phone' => $identity->backup_phone ?? '',
                            'marital_status' => $identity->marital_status ?? '',
                            'spouse_name' => $identity->spouse_name ?? '',
                            'spouse_phone' => $identity->spouse_phone ?? '',
                            'spouse_national_id' => $identity->spouse_national_id ?? '',
                            'primary_address' => $identity->primary_address ?? '',
                            'previous_address' => $identity->previous_address ?? '',
                            'region' => $identity->region ?? '',
                            'locality' => $identity->locality ?? '',
                            'branch' => $identity->branch ?? '',
                            'mosque' => $identity->mosque ?? '',
                            'housing_type' => $identity->housing_type ?? '',
                            'job_title' => $identity->job_title ?? '',
                            'health_status' => $identity->health_status ?? '',
                            'family_members_count' => (string)($identity->family_members_count ?? 0),
                            'status' => $identity->needs_review ? 'بانتظار المراجعة' : 'موثق',
                            'notes' => $identity->notes ?? '',
                            'entered_at' => $identity->entered_at?->format('Y-m-d H:i:s') ?? '',
                            'updated_at' => $identity->updated_at?->format('Y-m-d H:i:s') ?? '',
                            'family_member_name' => $familyNames,
                            'family_member_relation' => $familyRelations,
                            'family_member_national_id' => $familyNationalIds,
                            'family_member_phone' => $familyPhones,
                            'family_member_birth_date' => $familyBirthDates,
                            'family_member_health_status' => $familyHealthStatuses,
                            'family_member_education_status' => $familyEducationStatuses ?? '',
                            'family_member_needs_care' => $familyNeedsCare,
                            'family_member_is_guardian' => $familyIsGuardian,
                            'family_member_notes' => $familyNotes,
                            default => '',
                        };
                        $rowData[] = $value;
                    }
                    
                    // For RTL, reverse row data to match reversed headers
                    if ($direction === 'rtl') {
                        // Reverse row data to match reversed headers
                        $displayRowData = array_reverse($rowData);
                        // Calculate total width of all columns
                        $totalWidth = $colWidth * count($displayRowData);
                        // Start position: right margin minus total width (using stored margin value)
                        $x = $pdf->getPageWidth() - $rightMargin - $totalWidth;
                        // Reset X position to start from calculated position
                        $pdf->SetX($x);
                        // Add columns in reversed order (first column will be on the right)
                        foreach ($displayRowData as $value) {
                            // Determine alignment: R for Arabic text, L for numbers/English
                            $isArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $value);
                            $align = $isArabic ? 'R' : 'L';
                            $pdf->Cell($colWidth, 6, $value, 1, 0, $align, true);
                        }
                    } else {
                        // LTR: start from left margin (using stored margin value)
                        $pdf->SetX($leftMargin);
                        foreach ($rowData as $value) {
                            // Determine alignment: R for Arabic text, L for numbers/English
                            $isArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $value);
                            $align = $isArabic ? 'R' : 'L';
                            $pdf->Cell($colWidth, 6, $value, 1, 0, $align, true);
                        }
                    }
                    $pdf->Ln();
                    
                    $rowNumber++;
                    $totalProcessed++;
                }
                
                // Update progress after each chunk
                if ($exportJob) {
                    $progressPercentage = $totalRows > 0 ? min(95, intval(($totalProcessed / $totalRows) * 95)) : 0;
                    $exportJob->update([
                        'processed_rows' => $totalProcessed,
                        'progress_percentage' => $progressPercentage,
                    ]);
                }
                
                // Force garbage collection after each chunk to free memory
                gc_collect_cycles();
            });

            // Update progress: Saving file (99%)
            if ($exportJob) {
                $exportJob->update([
                    'progress_percentage' => 99,
                    'message' => 'جاري حفظ الملف...',
                ]);
            }
            
            // Save PDF to file
            $pdf->Output($filePath, 'F');
            
            // Generate download URL
            $fileUrl = url('/api/admin/export/download/' . $exportJobId);
            
            $message = "تم تصدير {$totalProcessed} سجل بنجاح.";

            return [
                'total_rows' => $totalProcessed,
                'file_path' => $filePath,
                'file_url' => $fileUrl,
                'message' => $message,
            ];
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Download exported file by job_id
     */
    public function downloadExport(string $jobId): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $exportJob = ExportJob::where('job_id', $jobId)->first();
        
        if (!$exportJob) {
            abort(404, 'عملية التصدير غير موجودة');
        }
        
        if ($exportJob->status !== 'completed') {
            abort(400, 'عملية التصدير لم تكتمل بعد');
        }
        
        if (empty($exportJob->file_path) || !file_exists($exportJob->file_path)) {
            abort(404, 'الملف غير موجود');
        }

        $contentType = str_ends_with($exportJob->file_name, '.pdf') 
            ? 'application/pdf' 
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->download(
            $exportJob->file_path, 
            $exportJob->file_name,
            [
                'Content-Type' => $contentType,
            ]
        );
    }
}
