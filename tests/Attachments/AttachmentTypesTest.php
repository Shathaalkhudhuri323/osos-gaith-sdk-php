<?php

namespace Osos\Gaith\Sdk\Tests\Attachments;

use Osos\Gaith\Sdk\Attachments\AttachmentTypes;
use PHPUnit\Framework\TestCase;

/** Real files through libmagic, the way an upload is checked in production. */
final class AttachmentTypesTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gaith-types-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function file(string $name, string $contents): string
    {
        file_put_contents($this->dir . '/' . $name, $contents);

        return $this->dir . '/' . $name;
    }

    private function all(): array
    {
        return AttachmentTypes::normalize(array_keys(AttachmentTypes::BY_EXTENSION));
    }

    public function testNormalizeTurnsExtensionsIntoMediaTypesAndDropsUnknownOnes(): void
    {
        $this->assertSame(
            ['application/pdf', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/jpeg', 'text/csv'],
            AttachmentTypes::normalize(['pdf', '.XLSX', 'jpg', 'jpeg', 'text/csv', 'exe', '', 42])
        );
    }

    public function testTextFormatsTakeTheirTypeFromTheExtension(): void
    {
        $this->assertSame('text/csv', AttachmentTypes::detect($this->file('leave.csv', "name,days\nAli,3\n"), 'leave.csv', $this->all()));
        $this->assertSame('text/markdown', AttachmentTypes::detect($this->file('notes.md', "# Notes\n\nText.\n"), 'notes.md', $this->all()));
        $this->assertSame('text/plain', AttachmentTypes::detect($this->file('log.txt', "Plain text.\n"), 'log.txt', $this->all()));
    }

    public function testImagesAreRecognisedByTheirBytes(): void
    {
        $bmp = 'BM' . pack('V', 58) . "\0\0\0\0" . pack('V', 54) . pack('V', 40) . pack('V', 1) . pack('V', 1)
            . pack('v', 1) . pack('v', 24) . str_repeat("\0", 24) . "\xff\xff\xff\0";
        $this->assertSame('image/bmp', AttachmentTypes::detect($this->file('a.bmp', $bmp), 'a.bmp', $this->all()));
        $this->assertSame('image/tiff', AttachmentTypes::detect($this->file('a.tif', "II*\0" . pack('V', 8) . str_repeat("\0", 40)), 'scan.tiff', $this->all()));
        $this->assertSame('image/webp', AttachmentTypes::detect($this->file('a.webp', 'RIFF' . pack('V', 30) . 'WEBPVP8 ' . str_repeat("\0", 30)), 'a.webp', $this->all()));
    }

    public function testOfficeContainersNeedTheMatchingExtension(): void
    {
        $zip = new \ZipArchive();
        $path = $this->dir . '/budget.xlsx';
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->close();
        $ole = $this->file('memo.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504));

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', AttachmentTypes::detect($path, 'budget.xlsx', $this->all()));
        $this->assertSame('application/msword', AttachmentTypes::detect($ole, 'memo.doc', $this->all()));
        $this->assertSame('application/vnd.ms-excel', AttachmentTypes::detect($ole, 'memo.xls', $this->all()));
        $this->assertNull(AttachmentTypes::detect($path, 'budget.doc', $this->all()), 'a ZIP is not a legacy .doc');
        $this->assertNull(AttachmentTypes::detect($ole, 'memo.csv', $this->all()), 'binary is not text');
    }

    public function testAProgramRenamedToAnAllowedTypeIsRefused(): void
    {
        $exe = $this->file('payroll.csv', "MZ\x90\0" . str_repeat("\0", 60) . "PE\0\0");

        $this->assertNull(AttachmentTypes::detect($exe, 'payroll.csv', $this->all()));
        $this->assertNull(AttachmentTypes::detect($exe, 'payroll.xlsx', $this->all()));
    }

    public function testOnlyEnabledTypesPass(): void
    {
        $csv = $this->file('leave.csv', "a,b\n1,2\n");

        $this->assertNull(AttachmentTypes::detect($csv, 'leave.csv', AttachmentTypes::normalize(['pdf', 'docx', 'png', 'jpg'])));
    }
}
