<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeSyncController extends Controller
{
    /**
     * INITIALIZE
     * - Truncate table
     * - Insert all valid records
     * - Log BEFORE updating
     * - Return { created, skipped }
     */
    public function initialize(Request $request)
    {
        $payload = $request->json()->all();

        if (!is_array($payload)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payload must be a JSON array of objects.',
            ], 422);
        }

        // Existing empnos BEFORE truncating (for log "missing" list)
        $existingEmpnos = DB::table('get_employee_info')
            ->pluck('empno')
            ->filter()
            ->values()
            ->all();

        $seenEmpnos = [];
        $validRows = [];
        $createdEmpnos = [];
        $skippedRecords = [];
        $createdCount = 0;
        $skippedCount = 0;

        foreach ($payload as $index => $row) {
            if (!is_array($row)) {
                $skippedCount++;
                $skippedRecords[] = [
                    'index'  => $index,
                    'reason' => 'Row is not an object',
                ];
                continue;
            }

            $mapped = $this->mapRow($row);
            $empno = $mapped['empno'] ?? null;

            if (empty($empno)) {
                $skippedCount++;
                $skippedRecords[] = [
                    'index'  => $index,
                    'reason' => 'Missing EMPLOYEE ID NUMBER',
                ];
                continue;
            }

            if (in_array($empno, $seenEmpnos, true)) {
                $skippedCount++;
                $skippedRecords[] = [
                    'index'  => $index,
                    'empno'  => $empno,
                    'reason' => 'Duplicate EMPLOYEE ID NUMBER in payload',
                ];
                continue;
            }

            $seenEmpnos[] = $empno;
            $validRows[] = $mapped;
            $createdEmpnos[] = $empno;
            $createdCount++;
        }

        // "Missing" = rows that existed before, but are not present in this payload
        $missingEmpnos = array_values(array_diff($existingEmpnos, $seenEmpnos));

        $summary = [
            'mode'    => 'initialize',
            'created' => $createdCount,
            'updated' => 0,
            'skipped' => $skippedCount,
            'missing' => $missingEmpnos, // DB rows not present in new JSON
        ];

        // Log BEFORE performing truncation/insert
        DB::table('employee_sync_logs')->insert([
            'payload'    => json_encode($payload),
            'summary'    => json_encode($summary),
            'created_at' => now(),
        ]);

        // 🔧 FIX: truncate OUTSIDE the transaction
        DB::table('get_employee_info')->truncate();

        // Now apply changes (only inserts) inside a transaction
        DB::transaction(function () use ($validRows) {
            foreach ($validRows as $row) {
                $row['created_at'] = now();
                $row['updated_at'] = now();
                DB::table('get_employee_info')->insert($row);
            }
        });

        return response()->json([
            'status'  => 'ok',
            'summary' => $summary,
            'details' => [
                'created' => $createdEmpnos,
                'skipped' => $skippedRecords,
                'missing' => $missingEmpnos,
            ],
        ]);
    }

    /**
     * SYNC
     * - Create new records
     * - Update existing
     * - Skip missing/duplicate empno
     * - Identify DB rows not present in incoming JSON
     * - Log BEFORE performing updates
     */
    public function sync(Request $request)
    {
        $payload = $request->json()->all();

        if (!is_array($payload)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payload must be a JSON array of objects.',
            ], 422);
        }

        // All existing empnos from DB
        $existingEmpnos = DB::table('get_employee_info')
            ->pluck('empno')
            ->filter()
            ->values()
            ->all();

        $existingSet = array_flip($existingEmpnos);

        $seenInPayload      = [];
        $incomingEmpnosAll  = []; // for missing DB rows check
        $preparedRows       = []; // rows we will actually apply

        $createdCount  = 0;
        $updatedCount  = 0;
        $skippedCount  = 0;

        $createdEmpnos = [];
        $updatedEmpnos = [];
        $skippedRecords = [];

        foreach ($payload as $index => $row) {
            if (!is_array($row)) {
                $skippedCount++;
                $skippedRecords[] = [
                    'index'  => $index,
                    'reason' => 'Row is not an object',
                ];
                continue;
            }

            $mapped = $this->mapRow($row);
            $empno  = $mapped['empno'] ?? null;

            if (empty($empno)) {
                $skippedCount++;
                $skippedRecords[] = [
                    'index'  => $index,
                    'reason' => 'Missing EMPLOYEE ID NUMBER',
                ];
                continue;
            }

            $incomingEmpnosAll[] = $empno;

            if (in_array($empno, $seenInPayload, true)) {
                $skippedCount++;
                $skippedRecords[] = [
                    'index'  => $index,
                    'empno'  => $empno,
                    'reason' => 'Duplicate EMPLOYEE ID NUMBER in payload',
                ];
                continue;
            }

            $seenInPayload[] = $empno;

            // Determine if this will be created or updated (for logging)
            if (isset($existingSet[$empno])) {
                $updatedCount++;
                $updatedEmpnos[] = $empno;
            } else {
                $createdCount++;
                $createdEmpnos[] = $empno;
            }

            // Make sure that if the record exists in JSON it becomes ACTIVE again
            $mapped['status_description'] = 'ACTIVE';

            $preparedRows[] = $mapped;
        }

        // Missing DB rows: they were not found in the JSON payload
        $missingEmpnos = array_values(array_diff($existingEmpnos, $incomingEmpnosAll));

        $summary = [
            'mode'    => 'sync',
            'created' => $createdCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
            'missing' => $missingEmpnos,
        ];

        // Log BEFORE doing the DB writes
        DB::table('employee_sync_logs')->insert([
            'payload'    => json_encode($payload),
            'summary'    => json_encode($summary),
            'created_at' => now(),
        ]);

        // Apply DB changes
        DB::transaction(function () use ($preparedRows, $existingSet, $missingEmpnos) {

            // 1) Create / Update rows from payload
            foreach ($preparedRows as $row) {
                $empno = $row['empno'];

                if (isset($existingSet[$empno])) {
                    $row['updated_at'] = now();

                    DB::table('get_employee_info')
                        ->where('empno', $empno)
                        ->update($row);
                } else {
                    $row['created_at'] = now();
                    $row['updated_at'] = now();

                    DB::table('get_employee_info')->insert($row);
                }
            }

            // 2) Mark missing employees as INACTIVE
            if (!empty($missingEmpnos)) {
                DB::table('get_employee_info')
                    ->whereIn('empno', $missingEmpnos)
                    ->update([
                        'status_description' => 'INACTIVE',
                        'updated_at'         => now(),
                    ]);
            }
        });

        return response()->json([
            'status'  => 'ok',
            'summary' => $summary,
            'details' => [
                'created' => $createdEmpnos,
                'updated' => $updatedEmpnos,
                'skipped' => $skippedRecords,
                'missing' => $missingEmpnos,
            ],
        ]);
    }



    // mapRow + toDate stay exactly as you had them
    protected function mapRow(array $row): array
    {
        $get = function (array $keys) use ($row) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== '') {
                    return $row[$key];
                }
            }
            return null;
        };

        $empno = $get(['EMPLOYEE ID NUMBER']);
        $empno = $empno !== null ? trim((string) $empno) : null;

        $birthRaw = $get([
            "DATE OF BIRTH\n \n (DD-MMM-YYYY)",
            'DATE OF BIRTH',
        ]);

        return [
            'empno'                      => $empno,
            'fname'                      => $get(["FIRST NAME\n (JUAN)", 'FIRST NAME']),
            'mname'                      => $get(["MIDDLE NAME\n (PEREZ)", 'MIDDLE NAME']),
            'sname'                      => $get(["LASTNAME\n (CRUZ)", 'LASTNAME']),
            'ename'                      => $get(['EXT.', 'EXT']),
            'division_name'              => $get(['DIVISION']),
            'unit_name'                  => $get(['SECTION/UNIT']),
            'area_assignment_name'       => $get(["OFFICE LOCATION/\nOFFICIAL STATION", 'OFFICE LOCATION/OFFICIAL STATION']),
            'eaddress'                   => $get(['ACTIVE AND WORKING EMAIL ADDRESS']),
            'sex'                        => $get(['GENDER']),
            'birthdate'                  => $this->toDate($birthRaw),
            'fund_source_name'           => $get([
                'FUND SOURCE FOR CONTRACTUAL, CONTRACT OF SERVICE AND JOB ORDER (BASED ON CREATION)',
                'FUND SOURCE',
            ]),
            'classification_employment_name' => $get([
                'CLASSIFICATION OF EMPLOYMENT (PERMANENT, COTERMINOUS, CASUAL, CONTRACTUAL, CONTRACT OF SERVICE, JOB ORDER)',
                'CLASSIFICATION OF EMPLOYMENT',
            ]),
            'salary_history_id'          => $get(['SG']),
            'position'                   => $get(['POSITION TITLE']),
            'salary'                     => $get(['MONTHLY SALARY']),
        ];
    }

    protected function toDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            return (string) $value;
        }

        $timestamp = @strtotime((string) $value);

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }
}
