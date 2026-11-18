<?php

namespace Flexipos\Hr\Tests\Documents;

use Flexipos\Hr\Documents\FileRules;
use Flexipos\Hr\Documents\FilesystemStagedFileReader;
use Flexipos\Hr\Documents\StagedFile;
use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Tests\Support\CountingStagedFileReader;
use Flexipos\Hr\Tests\Support\HrRefusals;
use Flexipos\Hr\Tests\Support\HrTempFiles;

/*
|--------------------------------------------------------------------------
| What a staged file must be (T-038 plan step 8; AC-66 to AC-68 unit halves,
| D-51)
|--------------------------------------------------------------------------
|
| Real temporary files with real magic bytes: the content type is detected by
| finfo from the bytes, so a rename or an empty header proves nothing. The
| maximum is an argument, so a small number stands in for ten mebibytes.
*/

const HR_MAX = 200;
const HR_ALLOWED = ['pdf', 'jpeg', 'png'];

/** @return array<string, string> kind => bytes (each shorter than HR_MAX) */
function hrSampleBytes(): array
{
    return [
        'pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
        'png' => "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89",
        'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
        'exe' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00\xB8\x00\x00\x00\x00\x00\x00\x00@\x00\x00\x00\x00\x00\x00\x00".str_repeat("\x00", 64)."PE\x00\x00",
        'svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>',
        'html' => '<!DOCTYPE html><html><head><title>x</title></head><body><script>alert(1)</script></body></html>',
    ];
}

function hrStage(string $bytes, string $name): StagedFile
{
    return HrTempFiles::stage($bytes, $name);
}

afterEach(function () {
    HrTempFiles::cleanup();
});

// ---- accepted -----------------------------------------------------------------

it('accepts a real PDF, PNG and JPEG with the matching extension', function () {
    $bytes = hrSampleBytes();

    foreach ([['pdf', 'scan.pdf', 'application/pdf'], ['png', 'photo.png', 'image/png'], ['jpeg', 'photo.jpg', 'image/jpeg'], ['jpeg', 'photo.JPEG', 'image/jpeg']] as [$kind, $name, $mime]) {
        $file = hrStage($bytes[$kind], $name);

        expect(FileRules::check($file, new FilesystemStagedFileReader, HR_MAX, HR_ALLOWED))
            ->toBe(['mime' => $mime, 'size' => strlen($bytes[$kind])]);
    }
});

it('accepts a file of exactly the maximum and refuses one byte more', function () {
    $pdf = hrSampleBytes()['pdf'];
    $exact = hrStage($pdf.str_repeat("\n", HR_MAX - strlen($pdf)), 'a.pdf');
    $over = hrStage($pdf.str_repeat("\n", HR_MAX + 1 - strlen($pdf)), 'a.pdf');

    expect(FileRules::check($exact, new FilesystemStagedFileReader, HR_MAX, HR_ALLOWED)['size'])->toBe(HR_MAX);

    HrRefusals::expect(HrRefusal::FileTooLarge, fn () => FileRules::check($over, new FilesystemStagedFileReader, HR_MAX, HR_ALLOWED));
});

// ---- refused by type ------------------------------------------------------------

it('refuses a PDF renamed .png, an executable renamed .pdf, and svg and html', function () {
    $bytes = hrSampleBytes();

    foreach ([
        'a PDF renamed .png' => ['pdf', 'scan.png'],
        'a PNG renamed .pdf' => ['png', 'scan.pdf'],
        'an executable renamed .pdf' => ['exe', 'scan.pdf'],
        'an executable renamed .png' => ['exe', 'scan.png'],
        'an svg' => ['svg', 'logo.svg'],
        'an svg renamed .png' => ['svg', 'logo.png'],
        'an html page' => ['html', 'page.html'],
        'an html page renamed .pdf' => ['html', 'page.pdf'],
    ] as $label => [$kind, $name]) {
        $file = hrStage($bytes[$kind], $name);

        HrRefusals::expect(HrRefusal::FileTypeNotAllowed, fn () => FileRules::check($file, new FilesystemStagedFileReader, HR_MAX, HR_ALLOWED), $label);
    }
});

it('refuses a name with no extension, a trailing dot, or an extension outside the map (D-51)', function () {
    $bytes = hrSampleBytes();

    foreach ([
        'no extension' => ['png', 'scan'],
        'a trailing dot' => ['pdf', 'scan.'],
        'a real PNG named .tiff' => ['png', 'scan.tiff'],
        'a real PDF named .txt' => ['pdf', 'scan.txt'],
        'a real JPEG named .jfif' => ['jpeg', 'scan.jfif'],
        'an empty name' => ['pdf', ''],
    ] as $label => [$kind, $name]) {
        $file = hrStage($bytes[$kind], $name);

        HrRefusals::expect(HrRefusal::FileTypeNotAllowed, fn () => FileRules::check($file, new FilesystemStagedFileReader, HR_MAX, HR_ALLOWED), $label);
    }
});

it('refuses a type the configuration left out, even with the right bytes and name', function () {
    $file = hrStage(hrSampleBytes()['png'], 'photo.png');

    HrRefusals::expect(HrRefusal::FileTypeNotAllowed, fn () => FileRules::check($file, new FilesystemStagedFileReader, HR_MAX, ['pdf']));

    expect(FileRules::check($file, new FilesystemStagedFileReader, HR_MAX, ['png'])['mime'])->toBe('image/png');
});

it('is one list in one place: the allow-list is exactly pdf, jpeg and png', function () {
    expect(array_keys(FileRules::TYPES))->toBe(['pdf', 'jpeg', 'png'])
        ->and(FileRules::TYPES['jpeg']['extensions'])->toBe(['jpg', 'jpeg']);
});

// ---- sizes, decided before any byte is read ---------------------------------------

it('refuses an empty file as file_empty without reading a byte', function () {
    $reader = new CountingStagedFileReader;
    $file = hrStage('', 'scan.pdf');

    HrRefusals::expect(HrRefusal::FileEmpty, fn () => FileRules::check($file, $reader, HR_MAX, HR_ALLOWED));

    expect($reader->sizes)->toBe(1)
        ->and($reader->opens)->toBe(0)
        ->and($reader->hashes)->toBe(0)
        ->and($reader->detections)->toBe(0);
});

it('refuses an over-large file as file_too_large without reading a byte, even if it is a real PDF', function () {
    $reader = new CountingStagedFileReader;
    $pdf = hrSampleBytes()['pdf'];
    $file = hrStage($pdf.str_repeat("\n", HR_MAX + 1 - strlen($pdf)), 'scan.pdf');

    HrRefusals::expect(HrRefusal::FileTooLarge, fn () => FileRules::check($file, $reader, HR_MAX, HR_ALLOWED));

    expect($reader->sizes)->toBe(1)
        ->and($reader->contentReads())->toBe(0);
});

it('detects the content of an accepted file exactly once and never opens or hashes it', function () {
    $reader = new CountingStagedFileReader;

    FileRules::check(hrStage(hrSampleBytes()['pdf'], 'scan.pdf'), $reader, HR_MAX, HR_ALLOWED);

    expect([$reader->sizes, $reader->detections, $reader->opens, $reader->hashes])->toBe([1, 1, 0, 0]);
});

it('never echoes a file name in a refusal', function () {
    $sentinel = 'SENTINEL-3308-NN';
    $file = hrStage(hrSampleBytes()['exe'], $sentinel.'.pdf');

    expect(HrRefusals::capture(fn () => FileRules::check($file, new FilesystemStagedFileReader, HR_MAX, HR_ALLOWED))->getMessage())
        ->not->toContain($sentinel);
});

// ---- the stored display name -------------------------------------------------------

it('strips path separators and control characters from the original name', function () {
    expect(FileRules::originalName('../../etc/passwd.pdf'))->toBe('....etcpasswd.pdf')
        ->and(FileRules::originalName('C:\\Users\\me\\cv.pdf'))->toBe('C:Usersmecv.pdf')
        ->and(FileRules::originalName("sc\x00an\n\t.pdf"))->toBe('scan.pdf')
        ->and(FileRules::originalName('Résumé 2024.pdf'))->toBe('Résumé 2024.pdf');
});

it('truncates the original name to 255 characters, counted in characters', function () {
    expect(mb_strlen(FileRules::originalName(str_repeat('é', 300))))->toBe(255)
        ->and(FileRules::originalName(str_repeat('a', 255)))->toBe(str_repeat('a', 255));
});

it('scrubs invalid UTF-8 from the original name instead of failing', function () {
    expect(FileRules::originalName("bad\xC3\x28name.pdf"))->not->toBe('')
        ->and(preg_match('//u', FileRules::originalName("bad\xC3\x28name.pdf")))->toBe(1);
});

it('reads the extension after the last dot, lower-cased', function () {
    expect(FileRules::extensionOf('a.PDF'))->toBe('pdf')
        ->and(FileRules::extensionOf('a.tar.gz'))->toBe('gz')
        ->and(FileRules::extensionOf('a'))->toBeNull()
        ->and(FileRules::extensionOf('a.'))->toBeNull()
        ->and(FileRules::extensionOf(''))->toBeNull();
});

it('judges the extension of the stored (cleaned) name, so a separator cannot hide one', function () {
    $file = hrStage(hrSampleBytes()['pdf'], 'scan.p/df');

    // "scan.p/df" cleans to "scan.pdf": what is stored is what is judged.
    expect(FileRules::check($file, new FilesystemStagedFileReader, HR_MAX, HR_ALLOWED)['mime'])->toBe('application/pdf');
});
