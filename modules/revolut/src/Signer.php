<?php

declare(strict_types=1);

namespace DocHamsterModules\Revolut;

use RuntimeException;

/**
 * Request signing of the Revolut Business API (API Console keys with a signing key): the message is timestamp (Unix ms)
 * + METHOD + path from "/api" + query string without "?" + minified JSON body, without separators; signed with Ed25519,
 * Base64. The key pair is made here with libsodium; the public key is handed out as SPKI PEM ("public.pem").
 */
final class Signer
{
    /** DER prefix of an Ed25519 SubjectPublicKeyInfo (RFC 8410), followed by the 32-byte key. */
    private const SPKI_PREFIX = '302a300506032b6570032100';

    /** @return array{secret: string, pem: string} */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);

        return ['secret' => sodium_crypto_sign_secretkey($pair), 'pem' => self::pem($public)];
    }

    public static function pem(string $publicKey): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$publicKey), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function message(string $timestamp, string $method, string $path, string $query, string $body): string
    {
        return $timestamp.strtoupper($method).$path.$query.$body;
    }

    public static function sign(string $message, string $secretKey): string
    {
        if ($secretKey === '') {
            throw new RuntimeException('Signing key missing.');
        }

        return base64_encode(sodium_crypto_sign_detached($message, $secretKey));
    }
}
