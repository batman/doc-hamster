<?php

declare(strict_types=1);

use App\Setup\EnvFile;
use Dotenv\Dotenv;

it('replaces and appends keys so that phpdotenv reads every value back unchanged', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "APP_NAME=DocHamster\nDB_PASSWORD=old\n# comment\n");
    $password = 'a"b$c\\d\'e #f ${APP_NAME} ü';

    (new EnvFile($path))->set(['DB_PASSWORD' => $password, 'DB_HOST' => 'db.example.com', 'EMPTY' => '']);

    $parsed = Dotenv::parse((string) file_get_contents($path));
    unlink($path);

    expect($parsed)->toBe([
        'APP_NAME' => 'DocHamster',
        'DB_PASSWORD' => $password,
        'DB_HOST' => 'db.example.com',
        'EMPTY' => '',
    ]);
});

it('refuses multi-line values and invalid keys', function (): void {
    $env = new EnvFile(sys_get_temp_dir().'/never-written.env');

    expect(fn () => $env->set(['KEY' => "a\nINJECTED=1"]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $env->set(['bad key' => 'x']))->toThrow(InvalidArgumentException::class);
});
