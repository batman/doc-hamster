<?php

declare(strict_types=1);

namespace App\Setup;

use App\Infrastructure\Environment\Doctor;
use PDO;
use PDOException;

/**
 * Tests the database credentials the installer typed in (D-024) and turns
 * driver errors into one of a few plain reasons the wizard can explain.
 * Creates the database if it doesn't exist and the user may do so.
 * Exception texts are never shown – they can contain host or user names.
 */
final class DatabaseConnector
{
    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $credentials
     * @return array{ok: true, connection: 'mariadb'|'mysql'}|array{ok: false, reason: string}
     */
    public function connect(array $credentials): array
    {
        // Both end up in the DSN string – nothing that could add DSN parameters (`;`).
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $credentials['database']) !== 1
            || preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $credentials['host']) !== 1) {
            return ['ok' => false, 'reason' => 'invalid_input'];
        }

        try {
            $pdo = $this->pdo($credentials, withDatabase: false);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$credentials['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $e) {
            // No right to create it: fine if it already exists, the next step tells.
            if ($this->errorCode($e) !== 1044) {
                return ['ok' => false, 'reason' => $this->reason($e)];
            }
        }

        try {
            $pdo = $this->pdo($credentials, withDatabase: true);
            $statement = $pdo->query('SELECT VERSION()');
            $version = $statement === false ? '' : (string) $statement->fetchColumn();
        } catch (PDOException $e) {
            return ['ok' => false, 'reason' => $this->reason($e)];
        }

        $isMariaDb = stripos($version, 'mariadb') !== false;
        $number = preg_match('/^\d+\.\d+(\.\d+)?/', $version, $match) === 1 ? $match[0] : '0';

        if (! version_compare($number, $isMariaDb ? Doctor::MIN_MARIADB : Doctor::MIN_MYSQL, '>=')) {
            return ['ok' => false, 'reason' => 'too_old'];
        }

        return ['ok' => true, 'connection' => $isMariaDb ? 'mariadb' : 'mysql'];
    }

    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $credentials
     */
    private function pdo(array $credentials, bool $withDatabase): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $credentials['host'], $credentials['port'])
            .($withDatabase ? ';dbname='.$credentials['database'] : '');

        return new PDO($dsn, $credentials['username'], $credentials['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    private function reason(PDOException $e): string
    {
        return match ($this->errorCode($e)) {
            1045 => 'access_denied',
            1044, 1049 => 'no_database',
            2002, 2003, 2005, 2006 => 'unreachable',
            default => 'other',
        };
    }

    private function errorCode(PDOException $e): int
    {
        $driverCode = $e->errorInfo[1] ?? null;

        if (is_int($driverCode)) {
            return $driverCode;
        }

        // Connection errors come without errorInfo; the message starts with "SQLSTATE[HY000] [2002]".
        return preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1 ? (int) $m[1] : 0;
    }
}
