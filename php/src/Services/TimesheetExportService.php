<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

final class TimesheetExportService
{
    private Repository $repository;
    private string $exportDir;

    public function __construct(?Repository $repository = null, ?string $exportDir = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->exportDir = $exportDir ?: dirname(__DIR__, 2) . '/storage/exports';
    }

    /**
     * @param array{tenant_id:int,start:DateTimeImmutable,end:DateTimeImmutable,user_id:?int,timezone?:string,format?:string} $filters
     * @return array{path:string,filename:string,content_type:string}
     */
    public function export(array $filters): array
    {
        $tenant = $this->repository->findTenantById($filters['tenant_id']);
        if ($tenant === null || ($tenant['status'] ?? '') !== 'active') {
            throw new RuntimeException('テナントが無効です。');
        }
        $targetTz = AppTime::timezone();
        if (!empty($filters['timezone'])) {
            try {
                $targetTz = new DateTimeZone((string)$filters['timezone']);
            } catch (\Throwable) {
                $targetTz = AppTime::timezone();
            }
        }
        $rows = $this->repository->fetchWorkSessions($filters['tenant_id'], $filters['start'], $filters['end'], $filters['user_id'] ?? null);

        if (!is_dir($this->exportDir) && !mkdir($concurrentDirectory = $this->exportDir, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('エクスポートディレクトリを作成できませんでした。');
        }

        $format = strtolower(trim((string)($filters['format'] ?? 'excel')));
        if ($format === '') {
            $format = 'excel';
        }
        if (!in_array($format, ['excel', 'pdf', 'csv'], true)) {
            $format = 'excel';
        }

        $ext = $format === 'pdf' ? 'pdf' : ($format === 'csv' ? 'csv' : 'xlsx');
        $downloadFilename = sprintf('timesheets_%s_%s.%s', $filters['start']->format('Ymd'), $filters['end']->format('Ymd'), $ext);
        $storedFilename = sprintf('timesheets_t%d_%s_%s_%s.%s', $filters['tenant_id'], $filters['start']->format('Ymd'), $filters['end']->format('Ymd'), bin2hex(random_bytes(3)), $ext);
        $path = $this->exportDir . '/' . $storedFilename;

        $breaksBySessionId = [];
        if ($rows !== []) {
            $sessionIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
            $sessionIds = array_values(array_unique(array_filter($sessionIds, static fn(int $id): bool => $id > 0)));
            if ($sessionIds !== []) {
                try {
                    $breakRows = $this->repository->listWorkSessionBreaksBySessionIds($sessionIds);
                    foreach ($breakRows as $breakRow) {
                        $sid = (int)$breakRow['work_session_id'];
                        if (!isset($breaksBySessionId[$sid])) {
                            $breaksBySessionId[$sid] = [];
                        }
                        $breaksBySessionId[$sid][] = $breakRow;
                    }
                } catch (\PDOException $e) {
                    // 移行途中でテーブル未作成の場合に全体を落とさない（要: DB適用）
                    if ($e->getCode() !== '42S02') {
                        throw $e;
                    }
                }
            }
        }

        if ($format === 'pdf') {
            $this->writePdf($path, $rows, $breaksBySessionId, $filters['start'], $filters['end'], $targetTz);
            return ['path' => $path, 'filename' => $downloadFilename, 'content_type' => 'application/pdf'];
        }
        if ($format === 'csv') {
            $this->writeCsv($path, $rows, $breaksBySessionId, $targetTz);
            return ['path' => $path, 'filename' => $downloadFilename, 'content_type' => 'text/csv; charset=utf-8'];
        }
        $this->writeXlsx($path, $rows, $breaksBySessionId, $filters['start'], $filters['end'], $targetTz);
        return ['path' => $path, 'filename' => $downloadFilename, 'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /**
     * @param array<int, array{
     *   id:int,
     *   user_id:int,
     *   email:string,
     *   first_name:?string,
     *   last_name:?string,
     *   start_time:DateTimeImmutable,
     *   end_time:?DateTimeImmutable
     * }> $rows
     */
    private function writeCsv(string $path, array $rows, array $breaksBySessionId, DateTimeZone $targetTz): void
    {
        $file = new \SplFileObject($path, 'w');

        $headers = [
            'employee_id',
            'email',
            'last_name',
            'first_name',
            'start_time_local',
            'end_time_local',
            'break_minutes',
            'duration_minutes',
            'net_minutes',
            'break_shortage_minutes',
            'edge_break_warning',
        ];
        $file->fputcsv($headers);

        $totalNetMinutes = 0;
        $edgeMinutes = BreakComplianceService::edgeBreakWarningMinutes();
        foreach ($rows as $row) {
            $start = $row['start_time']->setTimezone($targetTz);
            $end = $row['end_time']?->setTimezone($targetTz);
            $durationMinutes = null;
            $breakMinutes = null;
            $netMinutes = null;
            $breakShortageMinutes = null;
            $edgeBreakWarning = null;
            if ($row['end_time'] !== null) {
                $durationMinutes = $this->diffMinutes($row['start_time'], $row['end_time']);
                $breakMinutes = $this->sumBreakExcludedMinutes(
                    $breaksBySessionId[(int)($row['id'] ?? 0)] ?? [],
                    $row['start_time'],
                    $row['end_time']
                );
                $netMinutes = max(0, $durationMinutes - $breakMinutes);
                $totalNetMinutes += $netMinutes;
                $breakShortageMinutes = BreakComplianceService::breakShortageMinutes($netMinutes, $breakMinutes);
                $edgeBreakWarning = BreakComplianceService::hasEdgeBreakWarning(
                    $breaksBySessionId[(int)($row['id'] ?? 0)] ?? [],
                    $row['start_time'],
                    $row['end_time'],
                    $edgeMinutes
                ) ? 1 : 0;
            }
            $file->fputcsv([
                $row['user_id'],
                $row['email'],
                $row['last_name'],
                $row['first_name'],
                $start->format('Y-m-d H:i:s'),
                $end?->format('Y-m-d H:i:s'),
                $breakMinutes,
                $durationMinutes,
                $netMinutes,
                $breakShortageMinutes,
                $edgeBreakWarning,
            ]);
        }

        $file->fputcsv([]);
        $file->fputcsv(['total_net_minutes', $totalNetMinutes]);
    }

    /**
     * @param array<int, array{
     *   id:int,
     *   user_id:int,
     *   email:string,
     *   first_name:?string,
     *   last_name:?string,
     *   start_time:DateTimeImmutable,
     *   end_time:?DateTimeImmutable
     * }> $rows
     */
    private function writeXlsx(string $path, array $rows, array $breaksBySessionId, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $targetTz): void
    {
        if (!class_exists(ZipStream::class)) {
            throw new RuntimeException('XLSX出力に必要な依存関係が見つかりません（maennchen/zipstream-php）。');
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('エクスポートファイルを作成できませんでした。');
        }

        $esc = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $columnLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];

        $headers = ['従業員ID', '姓', '名', 'メール', '開始', '終了', '休憩(分)', '実労働(分)', '時間', '法定休憩不足(分)', '要注意(端寄り)'];
        $dataRows = [];
        $totalNetMinutes = 0;
        $edgeMinutes = BreakComplianceService::edgeBreakWarningMinutes();
        foreach ($rows as $row) {
            $startLocal = $row['start_time']->setTimezone($targetTz);
            $endLocal = $row['end_time']?->setTimezone($targetTz);
            $durationMinutes = null;
            $breakMinutes = null;
            $netMinutes = null;
            $netFormatted = '';
            $breakShortageMinutes = null;
            $edgeBreakWarning = '';
            if ($row['end_time'] !== null) {
                $durationMinutes = $this->diffMinutes($row['start_time'], $row['end_time']);
                $breakMinutes = $this->sumBreakExcludedMinutes(
                    $breaksBySessionId[(int)($row['id'] ?? 0)] ?? [],
                    $row['start_time'],
                    $row['end_time']
                );
                $netMinutes = max(0, $durationMinutes - $breakMinutes);
                $netFormatted = $this->formatMinutesJa($netMinutes);
                $totalNetMinutes += $netMinutes;
                $breakShortageMinutes = BreakComplianceService::breakShortageMinutes($netMinutes, $breakMinutes);
                $edgeBreakWarning = BreakComplianceService::hasEdgeBreakWarning(
                    $breaksBySessionId[(int)($row['id'] ?? 0)] ?? [],
                    $row['start_time'],
                    $row['end_time'],
                    $edgeMinutes
                ) ? '要注意' : '';
            }

            $dataRows[] = [
                (int)$row['user_id'],
                (string)($row['last_name'] ?? ''),
                (string)($row['first_name'] ?? ''),
                (string)$row['email'],
                $startLocal->format('Y-m-d H:i'),
                $endLocal?->format('Y-m-d H:i') ?? '記録中',
                $breakMinutes !== null ? (int)$breakMinutes : '',
                $netMinutes !== null ? (int)$netMinutes : '',
                $netFormatted,
                $breakShortageMinutes !== null ? (int)$breakShortageMinutes : '',
                $edgeBreakWarning,
            ];
        }

        $dataRows[] = [
            '時間（合計）',
            '',
            '',
            '',
            '',
            '',
            '',
            (int)$totalNetMinutes,
            $this->formatMinutesJa($totalNetMinutes),
            '',
            '',
        ];

        $lastColumn = $columnLetters[count($columnLetters) - 1];
        $lastRow = 2 + count($dataRows); // title row + header row + data rows
        $dimension = sprintf('A1:%s%d', $lastColumn, $lastRow);
        $title = sprintf('勤怠エクスポート（%s 〜 %s）', $start->format('Y-m-d'), $end->format('Y-m-d'));

        $sheetStream = tmpfile();
        if ($sheetStream === false) {
            fclose($handle);
            @unlink($path);
            throw new RuntimeException('一時ファイルを作成できませんでした。');
        }
        $writeAll = static function ($stream, string $chunk): void {
            $length = strlen($chunk);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($stream, substr($chunk, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('エクスポートデータの書き込みに失敗しました。');
                }
                $offset += $written;
            }
        };

        try {
            $writeAll($sheetStream, "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n");
            $writeAll($sheetStream, "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">\n");
            $writeAll($sheetStream, "  <dimension ref=\"" . $esc($dimension) . "\"/>\n");
            $writeAll($sheetStream, "  <sheetData>\n");

            // Title row (row 1)
            $writeAll($sheetStream, "    <row r=\"1\">\n");
            $writeAll($sheetStream, "      <c r=\"A1\" t=\"inlineStr\"><is><t>" . $esc($title) . "</t></is></c>\n");
            $writeAll($sheetStream, "    </row>\n");

            // Header row (row 2)
            $writeAll($sheetStream, "    <row r=\"2\">\n");
            foreach ($headers as $colIdx => $header) {
                $cellRef = $columnLetters[$colIdx] . '2';
                $writeAll($sheetStream, "      <c r=\"" . $esc($cellRef) . "\" t=\"inlineStr\"><is><t>" . $esc($header) . "</t></is></c>\n");
            }
            $writeAll($sheetStream, "    </row>\n");

            // Data rows start from row 3
            $rowNum = 3;
            foreach ($dataRows as $row) {
                $writeAll($sheetStream, "    <row r=\"" . $rowNum . "\">\n");
                foreach ($row as $colIdx => $value) {
                    $cellRef = $columnLetters[$colIdx] . $rowNum;
                    if (is_int($value)) {
                        $writeAll($sheetStream, "      <c r=\"" . $esc($cellRef) . "\" t=\"n\"><v>" . $value . "</v></c>\n");
                    } else {
                        $writeAll($sheetStream, "      <c r=\"" . $esc($cellRef) . "\" t=\"inlineStr\"><is><t>" . $esc((string)$value) . "</t></is></c>\n");
                    }
                }
                $writeAll($sheetStream, "    </row>\n");
                $rowNum++;
            }

            $writeAll($sheetStream, "  </sheetData>\n");
            $writeAll($sheetStream, "</worksheet>\n");
            rewind($sheetStream);
        } catch (\Throwable $e) {
            fclose($sheetStream);
            fclose($handle);
            @unlink($path);
            throw $e;
        }

        $contentTypes = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
        $contentTypes .= "<Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">\n";
        $contentTypes .= "  <Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>\n";
        $contentTypes .= "  <Default Extension=\"xml\" ContentType=\"application/xml\"/>\n";
        $contentTypes .= "  <Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>\n";
        $contentTypes .= "  <Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>\n";
        $contentTypes .= "  <Override PartName=\"/xl/styles.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml\"/>\n";
        $contentTypes .= "</Types>\n";

        $rels = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
        $rels .= "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">\n";
        $rels .= "  <Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/>\n";
        $rels .= "</Relationships>\n";

        $workbook = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
        $workbook .= "<workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">\n";
        $workbook .= "  <sheets>\n";
        $workbook .= "    <sheet name=\"Timesheets\" sheetId=\"1\" r:id=\"rId1\"/>\n";
        $workbook .= "  </sheets>\n";
        $workbook .= "</workbook>\n";

        $workbookRels = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
        $workbookRels .= "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">\n";
        $workbookRels .= "  <Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/>\n";
        $workbookRels .= "  <Relationship Id=\"rId2\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>\n";
        $workbookRels .= "</Relationships>\n";

        $styles = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
        $styles .= "<styleSheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">\n";
        $styles .= "  <fonts count=\"1\"><font><sz val=\"11\"/><color theme=\"1\"/><name val=\"Calibri\"/><family val=\"2\"/></font></fonts>\n";
        $styles .= "  <fills count=\"1\"><fill><patternFill patternType=\"none\"/></fill></fills>\n";
        $styles .= "  <borders count=\"1\"><border><left/><right/><top/><bottom/><diagonal/></border></borders>\n";
        $styles .= "  <cellStyleXfs count=\"1\"><xf numFmtId=\"0\" fontId=\"0\" fillId=\"0\" borderId=\"0\"/></cellStyleXfs>\n";
        $styles .= "  <cellXfs count=\"1\"><xf numFmtId=\"0\" fontId=\"0\" fillId=\"0\" borderId=\"0\" xfId=\"0\"/></cellXfs>\n";
        $styles .= "  <cellStyles count=\"1\"><cellStyle name=\"Normal\" xfId=\"0\" builtinId=\"0\"/></cellStyles>\n";
        $styles .= "</styleSheet>\n";

        try {
            $zip = new ZipStream(
                outputStream: $handle,
                sendHttpHeaders: false,
                enableZip64: false,
                defaultEnableZeroHeader: false,
                defaultCompressionMethod: CompressionMethod::DEFLATE
            );
            $zip->addFile(fileName: '[Content_Types].xml', data: $contentTypes);
            $zip->addFile(fileName: '_rels/.rels', data: $rels);
            $zip->addFile(fileName: 'xl/workbook.xml', data: $workbook);
            $zip->addFile(fileName: 'xl/_rels/workbook.xml.rels', data: $workbookRels);
            $zip->addFile(fileName: 'xl/styles.xml', data: $styles);
            $zip->addFileFromStream(fileName: 'xl/worksheets/sheet1.xml', stream: $sheetStream);
            $zip->finish();
        } catch (\Throwable $e) {
            fclose($sheetStream);
            fclose($handle);
            @unlink($path);
            throw new RuntimeException('XLSXの生成に失敗しました。', 0, $e);
        }

        fclose($sheetStream);
        fclose($handle);
        if (!is_file($path) || filesize($path) === false || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('XLSXの生成に失敗しました。');
        }
    }

    /**
     * @param array<int, array{
     *   id:int,
     *   user_id:int,
     *   email:string,
     *   first_name:?string,
     *   last_name:?string,
     *   start_time:DateTimeImmutable,
     *   end_time:?DateTimeImmutable
     * }> $rows
     */
    private function writePdf(string $path, array $rows, array $breaksBySessionId, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $targetTz): void
    {
        if (!class_exists(\TCPDF::class)) {
            throw new RuntimeException('PDF出力に必要な依存関係が見つかりません（tecnickcom/tcpdf）。');
        }

        /** @var \TCPDF $pdf */
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Attendly');
        $pdf->SetAuthor('Attendly');
        $pdf->SetTitle('勤怠エクスポート');
        $pdf->SetSubject('Timesheets');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage();

        // 日本語を含む可能性があるため、TCPDF同梱のCIDフォントを優先する
        $font = 'helvetica';
        if (defined('K_PATH_FONTS') && is_file(K_PATH_FONTS . 'cid0jp.php')) {
            $font = 'cid0jp';
        }
        $pdf->SetFont($font, '', 10);

        $title = sprintf('勤怠エクスポート（%s 〜 %s）', $start->format('Y-m-d'), $end->format('Y-m-d'));
        $pdf->Write(0, $title, '', 0, 'L', true, 0, false, false, 0);
        $pdf->Ln(2);

        $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $headers = ['従業員ID', '姓', '名', 'メール', '開始', '終了', '休憩(分)', '実労働(分)', '時間', '法定休憩不足(分)', '要注意(端寄り)'];

        $html = '<table border="1" cellpadding="3" cellspacing="0"><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th style="background-color:#f0f0f0;">' . $esc($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $totalNetMinutes = 0;
        $edgeMinutes = BreakComplianceService::edgeBreakWarningMinutes();
        foreach ($rows as $row) {
            $startLocal = $row['start_time']->setTimezone($targetTz);
            $endLocal = $row['end_time']?->setTimezone($targetTz);
            $breakMinutes = '';
            $netMinutes = '';
            $netFormatted = '';
            $breakShortageMinutes = '';
            $edgeBreakWarning = '';
            if ($row['end_time'] !== null) {
                $durationMinutes = $this->diffMinutes($row['start_time'], $row['end_time']);
                $break = $this->sumBreakExcludedMinutes(
                    $breaksBySessionId[(int)($row['id'] ?? 0)] ?? [],
                    $row['start_time'],
                    $row['end_time']
                );
                $net = max(0, $durationMinutes - $break);
                $breakMinutes = (string)$break;
                $netMinutes = (string)$net;
                $netFormatted = $this->formatMinutesJa($net);
                $totalNetMinutes += $net;
                $breakShortageMinutes = (string)BreakComplianceService::breakShortageMinutes($net, $break);
                $edgeBreakWarning = BreakComplianceService::hasEdgeBreakWarning(
                    $breaksBySessionId[(int)($row['id'] ?? 0)] ?? [],
                    $row['start_time'],
                    $row['end_time'],
                    $edgeMinutes
                ) ? '要注意' : '';
            }

            $html .= '<tr>';
            $html .= '<td>' . (int)$row['user_id'] . '</td>';
            $html .= '<td>' . $esc((string)($row['last_name'] ?? '')) . '</td>';
            $html .= '<td>' . $esc((string)($row['first_name'] ?? '')) . '</td>';
            $html .= '<td>' . $esc((string)$row['email']) . '</td>';
            $html .= '<td>' . $esc($startLocal->format('Y-m-d H:i')) . '</td>';
            $html .= '<td>' . $esc($endLocal?->format('Y-m-d H:i') ?? '記録中') . '</td>';
            $html .= '<td>' . $esc($breakMinutes) . '</td>';
            $html .= '<td>' . $esc($netMinutes) . '</td>';
            $html .= '<td>' . $esc($netFormatted) . '</td>';
            $html .= '<td>' . $esc($breakShortageMinutes) . '</td>';
            $html .= '<td>' . $esc($edgeBreakWarning) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        $pdf->SetFont($font, '', 8);
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Ln(2);
        $pdf->SetFont($font, '', 10);
        $pdf->Write(0, '時間（合計）: ' . $this->formatMinutesJa($totalNetMinutes), '', 0, 'L', true, 0, false, false, 0);

        $pdf->Output($path, 'F');
        if (!is_file($path) || filesize($path) === false || filesize($path) === 0) {
            throw new RuntimeException('PDFの生成に失敗しました。');
        }
    }

    /**
     * @param array<int, array{start_time:DateTimeImmutable,end_time:?DateTimeImmutable}> $breaks
     */
    private function sumBreakExcludedMinutes(array $breaks, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): int
    {
        $minutes = 0;
        foreach ($breaks as $break) {
            if (!isset($break['start_time']) || !$break['start_time'] instanceof DateTimeImmutable) {
                continue;
            }
            $start = $break['start_time'];
            $end = isset($break['end_time']) && $break['end_time'] instanceof DateTimeImmutable ? $break['end_time'] : $rangeEnd;
            if ($end <= $start) {
                continue;
            }
            $boundedStart = $start < $rangeStart ? $rangeStart : $start;
            $boundedEnd = $end > $rangeEnd ? $rangeEnd : $end;
            if ($boundedEnd <= $boundedStart) {
                continue;
            }
            $minutes += (int)floor(($boundedEnd->getTimestamp() - $boundedStart->getTimestamp()) / 60);
        }
        return $minutes;
    }

    private function diffMinutes(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        if ($end <= $start) {
            return 0;
        }
        return (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
    }

    private function formatMinutesJa(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0分';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours === 0) {
            return "{$rest}分";
        }
        return sprintf('%d時間%02d分', $hours, $rest);
    }
}
