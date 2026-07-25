<?php
/**
 * At-rest encryption for OAuth tokens (see ms_calendar_connections). Uses
 * AES-256-GCM with a random nonce per value; the key never touches the
 * database, only CRM_TOKEN_ENC_KEY (env var, generate with
 * `openssl rand -base64 32`).
 */
require_once __DIR__ . '/../config.php';

class CryptoException extends Exception {}

function token_encryption_enabled(): bool
{
    return CRM_TOKEN_ENC_KEY !== '';
}

function token_enc_key_bytes(): string
{
    $key = base64_decode(CRM_TOKEN_ENC_KEY, true);
    if ($key === false || strlen($key) !== 32) {
        throw new CryptoException('CRM_TOKEN_ENC_KEY is not a valid base64-encoded 32-byte key.');
    }
    return $key;
}

/** Encrypt a plaintext string, returning a self-contained base64 blob (nonce + tag + ciphertext). */
function token_encrypt(string $plaintext): string
{
    $key = token_enc_key_bytes();
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new CryptoException('Token encryption failed.');
    }
    return base64_encode($nonce . $tag . $ciphertext);
}

/** Decrypt a blob produced by token_encrypt(). Throws on tampering or wrong key. */
function token_decrypt(string $blob): string
{
    $key = token_enc_key_bytes();
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) {
        throw new CryptoException('Malformed encrypted token.');
    }
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plaintext === false) {
        throw new CryptoException('Token decryption failed (wrong key or tampered data).');
    }
    return $plaintext;
}
