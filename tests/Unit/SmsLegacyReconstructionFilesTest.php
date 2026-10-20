<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/scripts/sms-legacy-reconstruction-cli.php';

it('reserves a private receipt before execution and publishes it without replacement', function (): void {
    $directory = sys_get_temp_dir().'/sms-receipt-'.bin2hex(random_bytes(8));
    mkdir($directory,0700); $path = $directory.'/receipt.json';
    try {
        $out = SmsLegacyReconstructionFiles::reserve($path);
        expect(is_resource($out))->toBeTrue()->and(file_exists($path))->toBeFalse()->and(file_exists($path.'.partial'))->toBeTrue();
        SmsLegacyReconstructionFiles::json($path,['applied' => true,'changed' => 3],$out);
        expect(json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR))->toBe(['applied' => true,'changed' => 3])
            ->and(file_exists($path.'.partial'))->toBeFalse();
        expect(fn () => SmsLegacyReconstructionFiles::reserve($path))->toThrow(RuntimeException::class,'overwrite');
    } finally {
        foreach ([$path,$path.'.partial'] as $file) if (is_file($file)) unlink($file);
        rmdir($directory);
    }
});

it('keeps a completed partial receipt if final publication fails without replacing existing evidence', function (): void {
    $directory = sys_get_temp_dir().'/sms-receipt-'.bin2hex(random_bytes(8));
    mkdir($directory,0700); $path = $directory.'/receipt.json';
    try {
        $out = SmsLegacyReconstructionFiles::reserve($path);
        file_put_contents($path,'existing evidence');
        // Suppress only the expected filesystem warning; the deliberate RuntimeException must still surface.
        set_error_handler(static fn () => true, E_WARNING);
        try { expect(fn () => SmsLegacyReconstructionFiles::json($path,['applied' => true],$out))->toThrow(RuntimeException::class,'publish'); }
        finally { restore_error_handler(); }
        expect(file_get_contents($path))->toBe('existing evidence')
            ->and(json_decode(file_get_contents($path.'.partial'),true,512,JSON_THROW_ON_ERROR))->toBe(['applied' => true]);
    } finally {
        foreach ([$path,$path.'.partial'] as $file) if (is_file($file)) unlink($file);
        rmdir($directory);
    }
});

it('pins the complete canonical identity tracking and flags including NULL and leading zeros', function (): void {
    $row = ['PK' => '1','Phone' => '0202555010','Creation_Date' => '2026-01-01','Latest_Export_Date' => '2026-10-15',
        'SMS_Attempts' => '1','Identity_SHA256' => str_repeat('a',64),'Contacted' => '0','Invalid_Phone' => '0','Opt_Out' => '0','Response_Date' => null];
    $hash = SmsLegacyReconstruction::fingerprint([$row],'tracking');
    foreach (['Phone' => '2025550100','SMS_Attempts' => 2,'Identity_SHA256' => str_repeat('b',64),'Opt_Out' => 1,'Response_Date' => '2026-10-07','Latest_Export_Date' => null] as $key => $value) {
        expect(SmsLegacyReconstruction::fingerprint([array_replace($row,[$key => $value])],'tracking'))->not->toBe($hash);
    }
    expect(SmsLegacyReconstruction::proposed(1))->toBe(['date' => null,'attempts' => 0])
        ->and(SmsLegacyReconstruction::proposed(2))->toBe(['date' => '2026-10-07','attempts' => 1]);
    expect(fn () => SmsLegacyReconstruction::proposed(3))->toThrow(RuntimeException::class);
});
